<?php

declare(strict_types=1);

namespace Tests\Migrations;

use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\D1Pdo;
use Apotheke\Migrations\D1MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/** P4-3 PR 3: the D1 migration dialect (skip PRAGMAs/user_version/VACUUM INTO; exec multi; never DROP parent). */
final class D1MigrationRunnerTest extends CIUnitTestCase
{
    /** Records exec/query/run calls and returns canned rows, so no real workerd is needed. */
    private function fakeExecutor(array $queryRows = [], ?callable $onExec = null): D1HttpExecutor
    {
        return new D1HttpExecutor('http://d1.internal', static function (string $m, string $url, string $body) use ($queryRows, $onExec): string {
            $path = parse_url($url, PHP_URL_PATH);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if ($path === '/exec') {
                if ($onExec !== null) {
                    $onExec($payload['sql']);
                }

                return '{"ok":true,"count":1}';
            }
            if ($path === '/query') {
                return json_encode(['ok' => true, 'rows' => $queryRows], JSON_THROW_ON_ERROR);
            }
            // /batch
            $n = is_array($payload) ? count($payload) : 1;

            return json_encode(['ok' => true, 'results' => array_fill(0, $n, ['rows' => []])], JSON_THROW_ON_ERROR);
        });
    }

    public function testConnectionPragmasAndUserVersionAreSkipped(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA user_version = 3');
        $pdo->exec("VACUUM INTO '/tmp/backup.sqlite'");
        $this->assertSame([], $ran, 'no connection/user_version/VACUUM statement reaches D1');
        $this->assertCount(3, $pdo->skippedStatements());
    }

    public function testUserVersionReadYieldsZeroWithoutReachingD1(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $v = $pdo->query('PRAGMA user_version')->fetchColumn();
        $this->assertSame(0, $v);
    }

    public function testMultiStatementHeredocGoesToExec(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec("CREATE TABLE a(id INTEGER PRIMARY KEY);\nCREATE TABLE b(id INTEGER PRIMARY KEY);");
        $this->assertCount(1, $ran);
        $this->assertStringContainsString('CREATE TABLE a', $ran[0]);
        $this->assertStringContainsString('CREATE TABLE b', $ran[0]);
    }

    public function testDropParentIsRefused(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/never DROP|must not DROP/');
        $pdo->exec('DROP TABLE items');
    }

    public function testDropParentIfExistsIsRefused(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $this->expectException(RuntimeException::class);
        $pdo->exec('DROP TABLE IF EXISTS items');
    }

    public function testNonParentDropIsAllowed(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('DROP TABLE IF EXISTS apotheke_stage_items');
        $this->assertCount(1, $ran);
    }

    public function testRunnerAppliesPendingArrayMigrationAndRecordsIt(): void
    {
        $dir = sys_get_temp_dir() . '/coder2-migtest-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/0001_test.php', "<?php return ['sqlite' => 'CREATE TABLE t1(id INTEGER PRIMARY KEY); CREATE TABLE t2(id INTEGER PRIMARY KEY);'];\n");
        $batchBodies = [];
        $d1 = new D1HttpExecutor('http://d1.internal', static function (string $m, string $url, string $body) use (&$batchBodies): string {
            $path = parse_url($url, PHP_URL_PATH);
            if ($path === '/query') {
                // schema_migrations existence + applied: report empty (fresh)
                return '{"ok":true,"rows":[{"n":0}]}';
            }
            if ($path === '/exec') {
                return '{"ok":true,"count":1}';
            }
            $batchBodies[] = json_decode($body, true);

            return '{"ok":true,"results":[{"rows":[]}]}';
        });
        $runner = new D1MigrationRunner($dir);
        $done = $runner->migrate($d1);
        $this->assertSame(['0001'], $done);
        // The INSERT into schema_migrations went through /batch.
        $last = end($batchBodies);
        $this->assertStringContainsString('schema_migrations', $last[0]['sql']);
        $this->assertSame('0001', $last[0]['params'][0]);
        @unlink($dir . '/0001_test.php');
        @rmdir($dir);
    }

    public function testRunnerSkipsAlreadyApplied(): void
    {
        $d1 = new D1HttpExecutor('http://d1.internal', static function (string $m, string $url, string $body): string {
            if (parse_url($url, PHP_URL_PATH) === '/query') {
                $sql = json_decode($body, true)['sql'] ?? '';
                if (str_contains($sql, 'sqlite_master')) {
                    // table exists
                    return '{"ok":true,"rows":[{"n":1}]}';
                }

                return '{"ok":true,"rows":[{"version":"0001","applied_at":"2026-01-01"}]}';
            }

            return '{"ok":true,"results":[]}';
        });
        $runner = new D1MigrationRunner(__DIR__ . '/../../../migrations');
        // applied() must reflect the rows (table exists path).
        $applied = $runner->applied($d1);
        $this->assertSame(['0001' => '2026-01-01'], $applied);
    }
}
