<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Commands\HostedAdminResetCommand;
use Apotheke\Auth\FirstAdminClaim;
use Apotheke\Auth\HostedAdminReset;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\HostedLimits;
use Apotheke\Core\Hosted\PlanLimits;
use Closure;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * On a hosted installation, existing admin accounts can be removed so the first-admin claim can be used.
 * Off without APOTHEKE_HOSTED. Credentials in tests are built at run time.
 */
final class HostedAdminResetTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:bool}> driver, batch (one batch per unit, as on D1) */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver, false];
            if ($driver !== 'd1') {
                yield $driver . ' batch' => [$driver, true];
            }
        }
    }

    /** @return iterable<string,array{0:string}> the PDO drivers */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            if ($driver !== 'd1') {
                yield $driver => [$driver];
            }
        }
    }

    /** @return iterable<string,array{0:bool}> */
    public static function pgModes(): iterable
    {
        yield 'connection' => [false];
        yield 'batch' => [true];
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    private static function hosted(): HostedLimits
    {
        return HostedLimits::hosted(static fn (): PlanLimits => PlanLimits::fromBridge(['ok' => true, 'plan' => 't', 'limits' => []]) ?? throw new \LogicException('fixture'));
    }

    /** @param (Closure(string):void)|null $observe */
    private function reset(bool $batch = false, ?HostedLimits $hosted = null, ?Closure $observe = null): HostedAdminReset
    {
        $this->assertNotNull($this->database);
        $hosted ??= self::hosted();
        $units = $batch ? UnitsOfWork::on(new \Apotheke\Core\Database\LocalBatchExecutor($this->database->db)) : null;

        return new HostedAdminReset($this->database->db, $hosted, $units, $observe);
    }

    /**
     * @return array{admins:int, claim:bool}
     */
    private function resetState(?BaseConnection $db = null): array
    {
        $this->assertNotNull($this->database);
        $db ??= $this->database->db;
        $admins = (int)(Writes::rows($db, "SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")[0]['n'] ?? 0);
        $claim = Writes::rows($db, 'SELECT 1 AS one FROM settings WHERE name = ?', [FirstAdminClaim::SETTING]) !== [];

        return ['admins' => $admins, 'claim' => $claim];
    }

    /** @param array{admins:int, claim:bool} $state */
    private function assertClaimTracksAdmins(array $state, string $when): void
    {
        $this->assertSame($state['admins'] > 0, $state['claim'], $when . ': ' . json_encode($state));
    }

    /**
     * Another PostgreSQL client that waits for the active-admins lock, then snapshots admin and claim counts.
     *
     * @return Closure():string
     */
    private function startLockSnapshot(int $timeoutMs = 5000): Closure
    {
        $this->assertNotNull($this->database);
        $keys = UnitsOfWork::lockKeys('users', UserRepository::ACTIVE_ADMINS);
        $env = $this->database->clientEnv() + ['PATH' => (string)getenv('PATH')];
        $proc = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/_support/lock-snapshot.php', (string)json_encode($keys), FirstAdminClaim::SETTING, (string)$timeoutMs],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env,
        );
        if (!is_resource($proc)) {
            throw new \RuntimeException('cannot start the lock snapshot client');
        }
        $out = (string)fgets($pipes[1]);
        if ($out !== "waiting\n") {
            $err = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            throw new \RuntimeException('the lock snapshot client failed: ' . $out . $err);
        }

        return static function () use ($proc, $pipes): string {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);

            return $out;
        };
    }

    /** @param array{0:int|string,1:int|string} $keys */
    private function anotherClientIsWaitingFor(array $keys, int $timeoutMs = 5000): void
    {
        $this->assertNotNull($this->database);
        $deadline = hrtime(true) + $timeoutMs * 1_000_000;
        $sql = 'SELECT 1 AS one FROM pg_locks WHERE locktype = \'advisory\' AND NOT granted'
            . ' AND classid = ((?::bigint + 4294967296) % 4294967296) AND objid = ((?::bigint + 4294967296) % 4294967296) AND objsubid = 2';
        while (true) {
            if (Writes::rows($this->database->db, $sql, [$keys[0], $keys[1]]) !== []) {
                return;
            }
            if (hrtime(true) >= $deadline) {
                $this->fail('the other client did not wait for the active-admins lock');
            }
            usleep(5_000);
        }
    }

    /** @return list<array{username:string,role:string,status:string}> */
    private function accounts(): array
    {
        $this->assertNotNull($this->database);

        return array_map(static fn (array $r): array => ['username' => (string)$r['username'], 'role' => (string)$r['role'], 'status' => (string)$r['status']],
            Writes::rows($this->database->db, 'SELECT username, role, status FROM users ORDER BY id'));
    }


    /** @return list<string> */
    private function sessionKeys(): array
    {
        $this->assertNotNull($this->database);

        return array_map(static fn (array $r): string => (string)$r['id'], Writes::rows($this->database->db, 'SELECT id FROM sessions ORDER BY id'));
    }

    private function claimRow(): ?string
    {
        $this->assertNotNull($this->database);
        $v = Writes::rows($this->database->db, 'SELECT value FROM settings WHERE name = ?', [FirstAdminClaim::SETTING])[0]['value'] ?? null;

        return is_string($v) ? $v : null;
    }

    private function putClaim(): void
    {
        $this->assertNotNull($this->database);
        $value = (string)json_encode(['sha256' => hash('sha256', 'claim-fixture'), 'expires' => 2000000000]);
        $this->database->db->query('DELETE FROM settings WHERE name = ?', [FirstAdminClaim::SETTING]);
        $this->database->db->query('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [FirstAdminClaim::SETTING, $value, '2026-01-01 00:00:00']);
    }

    /** @return string the sessions.id key (SHA-256 of $sid) */
    private function putSession(string $sid, int $userId, ?string $data = null): string
    {
        $this->assertNotNull($this->database);
        $key = hash('sha256', $sid);
        $data ??= 'uid|i:' . $userId . ';auth_epoch|s:16:"0123456789abcdef";last_activity|i:1760000000;';
        $this->database->db->query(
            'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
            [$key, $data, 2000000000, '', 0],
        );

        return $key;
    }

    #[DataProvider('modes')]
    public function testItRemovesAdminsAndLeavesOtherAccounts(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $users = new UserRepository($this->database->db);
        $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $users->create('waiting', 'x', 'admin', 'pending', '2026-01-01 00:00:00');
        $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');

        $this->reset($batch)->run();
        $this->assertSame([['username' => 'looker', 'role' => 'viewer', 'status' => 'active']], $this->accounts());
        $this->reset($batch)->run();
        $this->assertSame([['username' => 'looker', 'role' => 'viewer', 'status' => 'active']], $this->accounts(), 'a second run is a no-op');
    }


    #[DataProvider('modes')]
    public function testItRemovesTheClaimRowAndAdminSessions(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $users = new UserRepository($this->database->db);
        $boss = $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $waiting = $users->create('waiting', 'x', 'admin', 'pending', '2026-01-01 00:00:00');
        $looker = $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $this->putClaim();
        $this->putSession('sess-boss-aaaaaaaaaaaa', $boss);
        $this->putSession('sess-wait-aaaaaaaaaaaa', $waiting);
        $look = $this->putSession('sess-look-aaaaaaaaaaaa', $looker);
        // A payload whose uid only looks neighbouring must stay (uid|i:1; must not match uid|i:11;).
        $other = $this->putSession('sess-other-aaaaaaaaaaaa', (int)((string)$boss . '1'));

        $this->reset($batch)->run();
        $this->assertNull($this->claimRow(), 'the first-admin claim row is gone');
        $this->assertEqualsCanonicalizing([$look, $other], $this->sessionKeys(), 'only non-admin session records remain');
        $this->assertSame([['username' => 'looker', 'role' => 'viewer', 'status' => 'active']], $this->accounts());

        $this->putClaim();
        $this->reset($batch)->run();
        $this->assertNull($this->claimRow(), 'a leftover claim is still removed when no admin remains');
        $this->assertEqualsCanonicalizing([$look, $other], $this->sessionKeys());
    }

    #[DataProvider('modes')]
    public function testSessionMatchIsAnchoredOnTheUidKey(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $users = new UserRepository($this->database->db);
        $boss = $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $tail = 'last_activity|i:1760000000;';
        $first = $this->putSession('sess-frst-aaaaaaaaaaaa', $boss);
        $later = $this->putSession('sess-latr-aaaaaaaaaaaa', $boss, 'csrf|s:4:"tok1";uid|i:' . $boss . ';' . $tail);
        $afterArray = $this->putSession('sess-arry-aaaaaaaaaaaa', $boss, 'flash|a:0:{}uid|i:' . $boss . ';' . $tail);
        $prefixed = $this->putSession('sess-pfxd-aaaaaaaaaaaa', $boss, 'otheruid|i:' . $boss . ';' . $tail);
        $prefixedLater = $this->putSession('sess-pfx2-aaaaaaaaaaaa', $boss, 'csrf|s:4:"tok1";otheruid|i:' . $boss . ';' . $tail);
        $longer = $this->putSession('sess-long-aaaaaaaaaaaa', $boss, 'uid|i:' . $boss . '0;' . $tail);

        $this->reset($batch)->run();

        $this->assertEqualsCanonicalizing([$prefixed, $prefixedLater, $longer], $this->sessionKeys());
        foreach ([$first, $later, $afterArray] as $gone) {
            $this->assertNotContains($gone, $this->sessionKeys());
        }
    }

    #[DataProvider('modes')]
    public function testAfterAResetTheFirstAdminClaimCanBeCreated(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        (new UserRepository($this->database->db))->create('admin', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $claims = new FirstAdminClaim(
            $this->database->db,
            new UserRepository($this->database->db, units: $batch ? UnitsOfWork::on(new \Apotheke\Core\Database\LocalBatchExecutor($this->database->db)) : null, limits: self::hosted()),
            new \Apotheke\Auth\Accounts(new UserRepository($this->database->db), new \Apotheke\Auth\RoleRepository($this->database->db), new \Apotheke\Core\Libraries\Clock()),
            new \Apotheke\Core\Libraries\Clock(),
            self::hosted(),
            72,
            $batch ? UnitsOfWork::on(new \Apotheke\Core\Database\LocalBatchExecutor($this->database->db)) : null,
        );
        try {
            $claims->create();
            $this->fail('create() should refuse while an active admin exists');
        } catch (UserError $e) {
            $this->assertSame([409, FirstAdminClaim::ADMIN_EXISTS], [$e->getCode(), $e->getMessage()]);
        }

        $this->reset($batch)->run();
        $made = $claims->create();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $made['token']);
        $pw = 'pw-' . bin2hex(random_bytes(8));
        $user = $claims->claim($made['token'], 'admin', $pw, $pw);
        $this->assertSame(['admin', 'admin', 'active'], [$user['username'], $user['role'], $user['status']]);
        $this->assertTrue(password_verify($pw, $user['password_hash']));
    }

    public function testOffWithoutHosted(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $boss = (new UserRepository($this->database->db))->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $this->putClaim();
        $sess = $this->putSession('sess-boss-aaaaaaaaaaaa', $boss);
        $off = $this->reset(false, HostedLimits::off());
        $this->assertFalse($off->enabled());
        try {
            $off->run();
            $this->fail('not refused when self-hosted');
        } catch (UserError $e) {
            $this->assertSame([404, 'Page not found.'], [$e->getCode(), $e->getMessage()]);
        }
        $this->assertSame([['username' => 'boss', 'role' => 'admin', 'status' => 'active']], $this->accounts());
        $this->assertNotNull($this->claimRow(), 'self-hosted: the claim row stays');
        $this->assertSame([$sess], $this->sessionKeys(), 'self-hosted: session records stay');
    }

    /** PostgreSQL: reset waits on the active-admins key, then removes the admin added under that lock. */
    #[DataProvider('pgModes')]
    public function testResetWaitsForAnAdminBeingAddedOnPostgres(bool $batch): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL only');
        }
        $this->database = TestDatabase::create('pgsql');
        $wait = $this->database->holdInAnotherClient([
            ['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('users', UserRepository::ACTIVE_ADMINS)],
            ["INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES ('boss', 'boss', 'x', 'admin', 'active', '2026-01-01 00:00:00')", []],
        ], 400);
        try {
            $this->reset($batch)->run();
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame([], $this->accounts());
    }

    #[DataProvider('modes')]
    public function testTheClaimRowAndTheAdminsLeaveInTheSameUnit(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        (new UserRepository($this->database->db))->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $this->putClaim();
        $other = $this->database->connectAgain();
        $seen = [];
        $this->reset($batch, null, function (string $when) use ($other, &$seen): void {
            if ($when === 'in-unit' || $when === 'in-unit-end') {
                // Same connection, inside the unit: after the lock at start, after the writes at end.
                $seen[$when] = $this->resetState();
                if ($when === 'in-unit') {
                    $this->assertSame(['admins' => 1, 'claim' => true], $seen[$when], $when . ': ' . json_encode($seen[$when]));
                } else {
                    $this->assertSame(['admins' => 0, 'claim' => false], $seen[$when], $when . ': ' . json_encode($seen[$when]));
                }

                return;
            }
            $seen[$when] = $this->resetState($other);
            $this->assertClaimTracksAdmins($seen[$when], $when);
        })->run();
        $this->assertSame(['admins' => 1, 'claim' => true], $seen['in-unit'] ?? null);
        $this->assertSame(['admins' => 0, 'claim' => false], $seen['in-unit-end'] ?? null);
        $this->assertSame(['admins' => 1, 'claim' => true], $seen['before'] ?? null);
        $this->assertSame(['admins' => 0, 'claim' => false], $seen['after'] ?? null);
        $this->assertSame(['admins' => 0, 'claim' => false], $this->resetState($other));
    }

    /** PostgreSQL: a second client waiting on the active-admins lock sees the claim leave with the admins. */
    public function testAWaitingClientSeesTheClaimGoWithTheAdminsOnPostgres(): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL only');
        }
        $this->database = TestDatabase::create('pgsql');
        (new UserRepository($this->database->db))->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $this->putClaim();
        $keys = UnitsOfWork::lockKeys('users', UserRepository::ACTIVE_ADMINS);
        $wait = $this->startLockSnapshot();
        $seen = [];
        try {
            $this->reset(false, null, function (string $when) use ($keys, &$seen): void {
                if ($when === 'in-unit') {
                    $seen[$when] = $this->resetState();
                    $this->assertSame(['admins' => 1, 'claim' => true], $seen[$when], $when . ': ' . json_encode($seen[$when]));
                    $this->anotherClientIsWaitingFor($keys);

                    return;
                }
                if ($when === 'in-unit-end') {
                    $seen[$when] = $this->resetState();
                    $this->assertSame(['admins' => 0, 'claim' => false], $seen[$when], $when . ': ' . json_encode($seen[$when]));
                }
            })->run();
        } finally {
            $out = $wait();
        }
        $this->assertSame(['admins' => 1, 'claim' => true], $seen['in-unit'] ?? null);
        $this->assertSame(['admins' => 0, 'claim' => false], $seen['in-unit-end'] ?? null);
        $this->assertMatchesRegularExpression('/^snapshot 0 0$/m', $out);
        $this->assertStringContainsString('committed', $out);
        $this->assertSame(['admins' => 0, 'claim' => false], $this->resetState());
    }

    #[DataProvider('drivers')]
    public function testTheCommandRemovesAdminsOnlyWhenHosted(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $users = new UserRepository($this->database->db);
        $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');

        $run = function (HostedAdminReset $reset): array {
            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');
            $this->assertIsResource($out);
            $this->assertIsResource($err);
            $rc = (new HostedAdminResetCommand(Services::logger(), new Commands(Services::logger()), static fn (): HostedAdminReset => $reset))->runWith($out, $err);
            rewind($out);
            rewind($err);

            return [$rc, (string)stream_get_contents($out), (string)stream_get_contents($err)];
        };

        $this->assertSame([1, '', "accounts:hosted-reset-admins is only for a hosted installation (APOTHEKE_HOSTED).\n"], $run($this->reset(false, HostedLimits::off())));
        $this->assertSame([
            ['username' => 'boss', 'role' => 'admin', 'status' => 'active'],
            ['username' => 'looker', 'role' => 'viewer', 'status' => 'active'],
        ], $this->accounts(), 'self-hosted: nothing is changed');

        $this->assertSame([0, "Admin accounts were reset. The first-admin claim can be created.\n", ''], $run($this->reset()));
        $this->assertSame([['username' => 'looker', 'role' => 'viewer', 'status' => 'active']], $this->accounts());
        $this->assertSame([0, "Admin accounts were reset. The first-admin claim can be created.\n", ''], $run($this->reset()));
        $this->assertArrayHasKey('accounts:hosted-reset-admins', Services::commands(false)->getCommands());
    }
}
