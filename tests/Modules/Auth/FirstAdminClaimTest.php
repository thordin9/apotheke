<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\Commands\ClaimCreateCommand;
use Apotheke\Auth\FirstAdminClaim;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\HostedLimits;
use Apotheke\Core\Hosted\PlanLimits;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Operator console C1: the first-admin claim. Used once, valid for a set time, only its hash stored, and off
 * (404) without APOTHEKE_HOSTED. Credentials are built at run time.
 */
final class FirstAdminClaimTest extends CIUnitTestCase
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

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    private static function hosted(array $limits = []): HostedLimits
    {
        return HostedLimits::hosted(static fn (): PlanLimits => PlanLimits::fromBridge(['ok' => true, 'plan' => 't', 'limits' => $limits]) ?? throw new \LogicException('fixture'));
    }

    private function claims(bool $batch = false, ?HostedLimits $hosted = null, int $hours = 72): FirstAdminClaim
    {
        $this->assertNotNull($this->database);
        $db = $this->database->db;
        $hosted ??= self::hosted();
        $units = $batch ? UnitsOfWork::on(new LocalBatchExecutor($db)) : null;
        $users = new UserRepository($db, units: $units, limits: $hosted);
        $clock = new Clock();

        return new FirstAdminClaim($db, $users, new Accounts($users, new RoleRepository($db), $clock), $clock, $hosted, $hours, $units);
    }

    private static function secret(): string
    {
        return 'pw-' . bin2hex(random_bytes(8));
    }

    /** @return list<array{username:string,role:string,status:string}> */
    private function accounts(): array
    {
        $this->assertNotNull($this->database);

        return array_map(static fn (array $r): array => ['username' => (string)$r['username'], 'role' => (string)$r['role'], 'status' => (string)$r['status']],
            Writes::rows($this->database->db, 'SELECT username, role, status FROM users ORDER BY id'));
    }

    private function claimRow(): ?string
    {
        $this->assertNotNull($this->database);
        $v = Writes::rows($this->database->db, 'SELECT value FROM settings WHERE name = ?', [FirstAdminClaim::SETTING])[0]['value'] ?? null;

        return is_string($v) ? $v : null;
    }

    /** @param \Closure():mixed $fn */
    private function refused(\Closure $fn, int $code, string $message): void
    {
        try {
            $fn();
            $this->fail('not refused: ' . $message);
        } catch (UserError $e) {
            $this->assertSame([$code, $message], [$e->getCode(), $e->getMessage()]);
        }
    }

    #[DataProvider('modes')]
    public function testAClaimWorksOnceAndMakesAnAdmin(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $claims = $this->claims($batch);
        $made = $claims->create();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/D', $made['token']);
        $row = (string)$this->claimRow();
        $this->assertStringNotContainsString($made['token'], $row, 'only the hash is stored');
        $this->assertSame(hash('sha256', $made['token']), json_decode($row, true)['sha256']);

        $pw = self::secret();
        $user = $claims->claim($made['token'], 'owner', $pw, $pw);
        $this->assertSame(['owner', 'admin', 'active'], [$user['username'], $user['role'], $user['status']]);
        $this->assertTrue(password_verify($pw, $user['password_hash']));
        $this->assertNull($this->claimRow(), 'the claim is gone once used');

        $pw2 = self::secret();
        $this->refused(fn () => $this->claims($batch)->claim($made['token'], 'second', $pw2, $pw2), 403, 'This claim link is not valid. Ask for a new one.');
        $this->assertSame([['username' => 'owner', 'role' => 'admin', 'status' => 'active']], $this->accounts());
    }

    /** A second use that read the claim before the first one wrote: the unit's delete finds nothing and adds nobody. */
    #[DataProvider('modes')]
    public function testASecondUseInsideTheUnitIsRefused(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $made = $this->claims($batch)->create();
        // The other use lands after this use read the claim and before its unit (the plan's limits are read in between).
        $first = function () use ($made, $batch): PlanLimits {
            $pw = self::secret();
            $this->claims($batch)->claim($made['token'], 'first', $pw, $pw);

            return PlanLimits::none();
        };
        $pw = self::secret();
        $this->refused(fn () => $this->claims($batch, HostedLimits::hosted($first))->claim($made['token'], 'second', $pw, $pw),
            410, 'This claim link has already been used.');
        $this->assertSame([['username' => 'first', 'role' => 'admin', 'status' => 'active']], $this->accounts());
    }

    /** PostgreSQL, two processes: the other one uses the claim and commits a moment later; this use waits and is refused. */
    #[DataProvider('pgModes')]
    public function testParallelUsesOnPostgresMakeOneAdmin(bool $batch): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL only');
        }
        $this->database = TestDatabase::create('pgsql');
        $made = $this->claims($batch)->create();
        $wait = $this->database->holdInAnotherClient([
            ['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('users')],
            ['DELETE FROM settings WHERE name = ?', [FirstAdminClaim::SETTING]],
            ["INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES ('first', 'first', 'x', 'admin', 'active', '2026-01-01 00:00:00')", []],
        ], 400);
        $pw = self::secret();
        try {
            $this->refused(fn () => $this->claims($batch)->claim($made['token'], 'second', $pw, $pw), 410, 'This claim link has already been used.');
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame(['first'], array_column($this->accounts(), 'username'));
    }

    /** @return iterable<string,array{0:bool}> */
    public static function pgModes(): iterable
    {
        yield 'connection' => [false];
        yield 'batch' => [true];
    }

    #[DataProvider('modes')]
    public function testAClaimExpires(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        putenv('APOTHEKE_CLOCK=2026-06-01 10:00:00');
        $made = $this->claims($batch, hours: 2)->create();
        $this->assertSame('2026-06-01 12:00:00 UTC', $made['expires_at']);
        $pw = self::secret();
        putenv('APOTHEKE_CLOCK=2026-06-01 12:00:00');
        $this->refused(fn () => $this->claims($batch)->claim($made['token'], 'late', $pw, $pw), 410, 'This claim link has expired. Ask for a new one.');
        $this->assertSame([], $this->accounts());
        putenv('APOTHEKE_CLOCK=2026-06-01 11:59:59');
        $this->assertSame('admin', $this->claims($batch)->claim($made['token'], 'intime', $pw, $pw)['role']);
    }

    #[DataProvider('modes')]
    public function testANewClaimReplacesTheOldOneAndAWrongTokenIsRefused(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $claims = $this->claims($batch);
        $old = $claims->create();
        $new = $claims->create();
        $this->assertNotSame($old['token'], $new['token']);
        $this->assertSame(1, (int)Writes::rows($this->database->db, 'SELECT COUNT(*) AS n FROM settings WHERE name = ?', [FirstAdminClaim::SETTING])[0]['n']);
        $pw = self::secret();
        foreach ([$old['token'], '', strrev($new['token'])] as $wrong) {
            $this->refused(fn () => $claims->claim($wrong, 'owner', $pw, $pw), 403, 'This claim link is not valid. Ask for a new one.');
        }
        // A bad name or password keeps the claim for another try.
        $this->refused(fn () => $claims->claim($new['token'], 'owner', $pw, $pw . 'x'), 422, 'The two passwords do not match.');
        $this->assertNotNull($this->claimRow());
        $this->assertSame('owner', $claims->claim($new['token'], 'owner', $pw, $pw)['username']);
    }

    public function testTheAccountLimitAppliesToTheClaim(): void
    {
        $this->database = TestDatabase::create('sqlite');
        (new UserRepository($this->database->db))->create('someone', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $claims = $this->claims(false, self::hosted(['users' => ['hard' => 1]]));
        $made = $claims->create();
        $pw = self::secret();
        $this->refused(fn () => $claims->claim($made['token'], 'owner', $pw, $pw), 422, 'Your plan allows 1 user accounts.');
        $this->assertNotNull($this->claimRow(), 'nothing of the unit was written');
    }

    public function testOffWithoutHostedAndNeverExported(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $off = $this->claims(false, HostedLimits::off());
        $this->assertFalse($off->enabled());
        $this->refused(static fn () => $off->create(), 404, 'Page not found.');
        $pw = self::secret();
        $this->refused(static fn () => $off->claim('x', 'owner', $pw, $pw), 404, 'Page not found.');
        $this->assertNull($this->claimRow());

        $this->claims()->create();
        $settings = new SettingsService(new SettingsRepository($this->database->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($this->database->dataDir())),
            new Clock(), $this->database->config->values(), static fn (string $m): null => null, new CommandLine());
        $this->assertStringNotContainsString(FirstAdminClaim::SETTING, (string)json_encode($settings->export()));
    }

    public function testTheCommandPrintsTheTokenOnlyWhenHosted(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $run = function (FirstAdminClaim $claims): array {
            $out = fopen('php://memory', 'w+');
            $err = fopen('php://memory', 'w+');
            $this->assertIsResource($out);
            $this->assertIsResource($err);
            $rc = (new ClaimCreateCommand(Services::logger(), new Commands(Services::logger()), static fn (): FirstAdminClaim => $claims))->runWith($out, $err);
            rewind($out);
            rewind($err);

            return [$rc, (string)stream_get_contents($out), (string)stream_get_contents($err)];
        };
        [$rc, $out, $err] = $run($this->claims(false, HostedLimits::off()));
        $this->assertSame([1, ''], [$rc, $out]);
        $this->assertStringContainsString('only for a hosted installation', $err);
        $this->assertNull($this->claimRow());

        [$rc, $out, $err] = $run($this->claims());
        $this->assertSame(0, $rc);
        $token = trim($out);
        $this->assertSame(hash('sha256', $token), json_decode((string)$this->claimRow(), true)['sha256']);
        $this->assertStringContainsString('Valid once, until', $err);
        $this->assertStringNotContainsString($token, $err);
        $this->assertArrayHasKey('accounts:claim-create', Services::commands(false)->getCommands());
    }
}
