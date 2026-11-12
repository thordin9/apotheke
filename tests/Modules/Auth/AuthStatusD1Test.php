<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Commands\AuthStatus;
use Apotheke\Core\Database\D1Executor;
use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Migrations\D1SqliteStandIn;

require_once dirname(__DIR__) . '/Migrations/D1SqliteStandIn.php';

/**
 * `auth status` in storage mode (db.driver d1): the entrypoint's check reads the
 * Installation's database over the D1 bridge, with the same answers and exit
 * codes as on SQLite and PostgreSQL (0 on, 3 off, 4 APP_PASSWORD too short,
 * 1 no database), and it writes nothing.
 */
final class AuthStatusD1Test extends CIUnitTestCase
{
    private const FAILURE = 'Could not connect to the database (d1 – d1.internal).';

    public function testANewInstallationFollowsAppPassword(): void
    {
        foreach (['long-enough' => [0, "on\n", ''], '' => [3, "off\n", ''], 'shortpw' => [4, '', "APP_PASSWORD is too short: use at least 8 characters (not only spaces), or leave it empty.\n"]] as $password => $expected) {
            $d1 = new D1SqliteStandIn();
            $this->assertSame($expected, $this->authStatus($d1, (string)$password), 'APP_PASSWORD of ' . strlen((string)$password) . ' characters');
            $this->assertSame([], $d1->batches, 'nothing written');
            $this->assertSame([], $d1->execLog, 'nothing migrated');
        }
    }

    public function testTheStoredSettingDecides(): void
    {
        $d1 = new D1SqliteStandIn();
        $d1->query('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $d1->query('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL)');
        $d1->query("INSERT INTO settings (name, value) VALUES ('auth_enabled', 'true')");
        $d1->query("INSERT INTO users (username) VALUES ('admin')");
        $this->assertSame([0, "on\n", ''], $this->authStatus($d1, ''), 'on with an account');
        $this->assertSame([0, "on\n", ''], $this->authStatus($d1, 'shortpw'), 'an account exists: APP_PASSWORD is not used');

        $d1->query('DELETE FROM users');
        $this->assertSame([3, "off\n", ''], $this->authStatus($d1, ''), 'on, no account and no APP_PASSWORD');
        $this->assertSame(4, $this->authStatus($d1, 'shortpw')[0], 'on, no account: APP_PASSWORD decides');
        $this->assertSame([0, "on\n", ''], $this->authStatus($d1, 'long-enough'));

        $d1->query("UPDATE settings SET value = 'false' WHERE name = 'auth_enabled'");
        $this->assertSame([3, "off\n", ''], $this->authStatus($d1, 'long-enough'), 'turned off later: the entrypoint refuses to start');
        $this->assertSame([], $d1->batches, 'nothing written');
    }

    public function testNoBridgeIsTheNoDatabaseExit(): void
    {
        $down = new class implements D1Executor {
            public function run(array $statements): array
            {
                throw new WriteFailed('Database write failed: the batch');
            }

            public function query(string $sql, array $params = []): array
            {
                throw new WriteFailed('Database write failed: the batch');
            }

            public function exec(string $sql): array
            {
                throw new WriteFailed('Database write failed: the batch');
            }
        };
        $this->assertSame([1, '', self::FAILURE . "\n"], $this->authStatus($down, 'long-enough'));

        // The real executor: a failed request or an answer that is not ok, and never the database's own text.
        foreach ([static fn (): string => throw new WriteFailed('Database write failed: the batch'), static fn (): string => '{"ok":false,"error":"secret detail"}', static fn (): string => 'not json'] as $transport) {
            $this->assertSame([1, '', self::FAILURE . "\n"], $this->authStatus(new D1HttpExecutor(D1HttpExecutor::DEFAULT_BASE, $transport, 5), 'long-enough'));
        }
    }

    /** @return array{0:int,1:string,2:string} */
    private function authStatus(D1Executor $d1, string $password): array
    {
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        $this->assertIsResource($out);
        $this->assertIsResource($err);
        $exit = (new AuthStatus(AuthStatus::d1($d1, self::FAILURE), $password, $out, $err))->run();
        rewind($out);
        rewind($err);

        return [$exit, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }
}
