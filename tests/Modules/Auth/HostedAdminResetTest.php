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
use CodeIgniter\CLI\Commands;
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

    private function reset(bool $batch = false, ?HostedLimits $hosted = null): HostedAdminReset
    {
        $this->assertNotNull($this->database);
        $hosted ??= self::hosted();
        $units = $batch ? UnitsOfWork::on(new \Apotheke\Core\Database\LocalBatchExecutor($this->database->db)) : null;

        return new HostedAdminReset($this->database->db, $hosted, $units);
    }

    /** @return list<array{username:string,role:string,status:string}> */
    private function accounts(): array
    {
        $this->assertNotNull($this->database);

        return array_map(static fn (array $r): array => ['username' => (string)$r['username'], 'role' => (string)$r['role'], 'status' => (string)$r['status']],
            Writes::rows($this->database->db, 'SELECT username, role, status FROM users ORDER BY id'));
    }

    #[DataProvider('modes')]
    public function testItRemovesAdminsAndLeavesOtherAccounts(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $users = new UserRepository($this->database->db);
        $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $users->create('waiting', 'x', 'admin', 'pending', '2026-01-01 00:00:00');
        $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');

        $this->assertSame(2, $this->reset($batch)->run());
        $this->assertSame([['username' => 'looker', 'role' => 'viewer', 'status' => 'active']], $this->accounts());
        $this->assertSame(0, $this->reset($batch)->run(), 'a second run is a no-op');
        $this->assertSame([['username' => 'looker', 'role' => 'viewer', 'status' => 'active']], $this->accounts());
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

        $this->assertSame(1, $this->reset($batch)->run());
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
        (new UserRepository($this->database->db))->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $off = $this->reset(false, HostedLimits::off());
        $this->assertFalse($off->enabled());
        try {
            $off->run();
            $this->fail('not refused when self-hosted');
        } catch (UserError $e) {
            $this->assertSame([404, 'Page not found.'], [$e->getCode(), $e->getMessage()]);
        }
        $this->assertSame([['username' => 'boss', 'role' => 'admin', 'status' => 'active']], $this->accounts());
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
            $this->assertSame(1, $this->reset($batch)->run());
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame([], $this->accounts());
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

        $this->assertSame([0, "Removed 1 admin account. The first-admin claim can be created.\n", ''], $run($this->reset()));
        $this->assertSame([['username' => 'looker', 'role' => 'viewer', 'status' => 'active']], $this->accounts());
        $this->assertSame([0, "No admin account to reset. The first-admin claim can be created.\n", ''], $run($this->reset()));
        $this->assertArrayHasKey('accounts:hosted-reset-admins', Services::commands(false)->getCommands());
    }
}
