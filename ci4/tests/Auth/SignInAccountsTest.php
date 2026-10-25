<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\RateLimiter;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The sign-in pages' accounts and throttling (legacy src/users.php): password
 * checks, registration rules, a new own password, the first admin, the rate
 * limits (window, per-key isolation, clearing, pruning) and every write
 * failing loudly.
 */
final class SignInAccountsTest extends CIUnitTestCase
{
    private const PW = 'long-enough';

    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testVerify(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $id = $this->account('Eda', 'editor', 'active', 4);
        $accounts = $this->accounts();
        $this->assertNull($accounts->verify('nobody', self::PW));
        $this->assertNull($accounts->verify('eda', 'wrong-password'));
        $this->assertSame('$2y$04$', substr($this->users()->find($id)['password_hash'] ?? '', 0, 7), 'a wrong password changes nothing');
        $user = $accounts->verify('  EDA ', self::PW);
        $this->assertSame($id, $user['id'] ?? null);
        // A weaker hash is replaced on a good password, and the stored epoch is left alone (legacy).
        $this->assertFalse(password_needs_rehash($user['password_hash'] ?? '', PASSWORD_DEFAULT));
        $this->assertSame('', $user['session_epoch'] ?? null);
        $this->assertTrue(password_verify(self::PW, $this->users()->find($id)['password_hash'] ?? ''));
    }

    #[DataProvider('drivers')]
    public function testAnUnknownNameCostsAsMuchAsAWrongPassword(string $driver): void
    {
        // No name enumeration by timing: one hash at the default cost either way (stricter than legacy's cost-10 dummy).
        $this->database = TestDatabase::create($driver);
        $this->users()->create('eda', password_hash(self::PW, PASSWORD_DEFAULT), 'editor', 'active', '2026-01-01 00:00:00');
        $accounts = $this->accounts();
        $time = static function (callable $fn): float {
            $best = INF;
            for ($i = 0; $i < 3; $i++) {
                $t = hrtime(true);
                $fn();
                $best = min($best, hrtime(true) - $t);
            }

            return $best;
        };
        $unknown = $time(fn () => $this->assertNull($accounts->verify('nobody', 'wrong-password')));
        $wrong = $time(fn () => $this->assertNull($accounts->verify('eda', 'wrong-password')));
        $ratio = $unknown / $wrong;
        $this->assertGreaterThan(0.5, $ratio, sprintf('unknown %.1f ms, wrong password %.1f ms', $unknown / 1e6, $wrong / 1e6));
        $this->assertLessThan(2.0, $ratio, sprintf('unknown %.1f ms, wrong password %.1f ms', $unknown / 1e6, $wrong / 1e6));
    }

    #[DataProvider('drivers')]
    public function testAdmit(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $pending = $this->account('Pam', 'viewer', 'pending');
        $active = $this->account('vera', 'viewer', 'active');
        putenv('APOTHEKE_CLOCK=2026-03-04 05:06:07');
        try {
            $this->accounts()->admit(['id' => $pending, 'status' => 'pending']);
            $this->fail('a pending account may not sign in');
        } catch (UserError $e) {
            $this->assertSame([403, 'Your account is waiting for an admin’s approval.'], [$e->getCode(), $e->getMessage()]);
        }
        $this->assertNull($this->lastLogin($pending));
        $this->accounts()->admit(['id' => $active, 'status' => 'active']);
        $this->assertSame('2026-03-04 05:06:07', $this->lastLogin($active));
    }

    #[DataProvider('drivers')]
    public function testRegisterRules(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->account('Eda', 'editor', 'active');
        $accounts = $this->accounts();
        $cases = [
            ['', self::PW, self::PW, 'Enter a user name.'],
            ['two words', self::PW, self::PW, 'User names use letters, digits and . _ @ - (at most 40 characters, no spaces).'],
            [str_repeat('a', 41), self::PW, self::PW, 'User names use letters, digits and . _ @ - (at most 40 characters, no spaces).'],
            ['EDA', self::PW, self::PW, 'The user name “EDA” is taken.'],
            ['rex', 'short', 'short', 'Use at least 8 characters for the password.'],
            ['rex', str_repeat('x', 201), str_repeat('x', 201), 'That password is too long.'],
            ['rex', self::PW, self::PW . 'x', 'The two passwords do not match.'],
        ];
        foreach ($cases as [$name, $pw, $confirm, $message]) {
            try {
                $accounts->register($name, $pw, $confirm, 'editor', false);
                $this->fail($message);
            } catch (UserError $e) {
                $this->assertSame([422, $message], [$e->getCode(), $e->getMessage()]);
            }
        }
        $this->assertSame(1, $this->users()->count(), 'nothing was written');
        $this->assertSame('viewer', $accounts->registerRole('admin'));
        $this->assertSame('viewer', $accounts->registerRole('guest'));
        $this->assertSame('viewer', $accounts->registerRole('no-such-role'));
        $this->assertSame('editor', $accounts->registerRole('editor'));
        $rex = $accounts->register(' rex ', self::PW, self::PW, 'admin', true);
        $this->assertSame(['rex', 'viewer', 'pending'], [$rex['username'], $rex['role'], $rex['status']]);
        $this->assertTrue(password_verify(self::PW, $rex['password_hash']));
    }

    #[DataProvider('drivers')]
    public function testChangeOwnPassword(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $id = $this->account('eda', 'editor', 'active');
        $accounts = $this->accounts();
        $me = ['id' => $id, 'username' => 'eda'];
        foreach ([['wrong-password', 'newer-password', 403, 'The current password is not correct.'], [self::PW, 'short', 422, 'Use at least 8 characters for the password.']] as [$current, $new, $code, $message]) {
            try {
                $accounts->changeOwnPassword($me, $current, $new, $new);
                $this->fail($message);
            } catch (UserError $e) {
                $this->assertSame([$code, $message], [$e->getCode(), $e->getMessage()]);
            }
        }
        $epoch = $accounts->changeOwnPassword($me, self::PW, 'newer-password', 'newer-password');
        $row = $this->users()->find($id);
        $this->assertSame($epoch, $row['session_epoch'] ?? null);
        $this->assertNotSame('', $epoch);
        $this->assertTrue(password_verify('newer-password', $row['password_hash'] ?? ''));
    }

    #[DataProvider('drivers')]
    public function testTheFirstAdmin(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $accounts = $this->accounts();
        $accounts->ensureFirstAdmin(false, 'placeholder-pw');
        $accounts->ensureFirstAdmin(true, '');
        $this->assertSame(0, $this->users()->count());
        try {
            $accounts->ensureFirstAdmin(true, 'short');
            $this->fail('a short APP_PASSWORD stops');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('short:', substr($e->getMessage(), 0, 3));
            $this->assertStringStartsWith('APP_PASSWORD is too short', $e->getMessage());
        }
        $accounts->ensureFirstAdmin(true, 'placeholder-pw');
        $admin = $this->users()->findByName('admin');
        $this->assertSame(['admin', 'active'], [$admin['role'] ?? null, $admin['status'] ?? null]);
        $this->assertTrue(password_verify('placeholder-pw', $admin['password_hash'] ?? ''));
        $accounts->ensureFirstAdmin(true, 'another-placeholder');
        $this->assertSame(1, $this->users()->count(), 'only on an install without accounts');
    }

    #[DataProvider('drivers')]
    public function testRateLimits(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $now = 1_800_000_000;
        $limiter = new RateLimiter($this->db(), '10.0.0.1', $now);
        for ($i = 0; $i < 10; $i++) {
            $limiter->hit('login', 'Eda');
        }
        $this->assertLimited(fn () => $limiter->hit('login', ' eda '), 'Too many attempts for this account from this address – please wait 15 minutes and try again.');
        $this->assertSame(10, $this->attempts('login'), 'a refused attempt is not counted');
        // Per key: another account, or another address, is not affected.
        $limiter->hit('login', 'vera');
        (new RateLimiter($this->db(), '10.0.0.2', $now))->hit('login', 'eda');
        // The window: an attempt exactly 900 s old still counts, one 901 s old does not.
        $this->assertLimited(fn () => (new RateLimiter($this->db(), '10.0.0.1', $now + 900))->hit('login', 'eda'), 'Too many attempts for this account from this address – please wait 15 minutes and try again.');
        (new RateLimiter($this->db(), '10.0.0.1', $now + 901))->hit('login', 'eda');
        // Clearing after a sign-in empties only that bucket.
        $limiter->clear('login', 'EDA');
        $this->assertSame(0, $this->bucketCount($limiter->bucket('login', 'eda')));
        $this->assertSame(1, $this->bucketCount($limiter->bucket('login', 'vera')));
        // Registration counts per address only.
        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('register');
        }
        $this->assertLimited(fn () => $limiter->hit('register', 'anyone'), 'Too many attempts from this address – please wait 60 minutes and try again.');
        (new RateLimiter($this->db(), '10.0.0.2', $now))->hit('register');
        (new RateLimiter($this->db(), '10.0.0.1', $now + 3601))->hit('register');
        // Every check prunes attempts older than a day.
        (new RateLimiter($this->db(), '10.0.0.9', $now + 3601 + 86400 + 1))->hit('register');
        $this->assertSame(1, $this->attempts('register') + $this->attempts('login'));
        $this->assertSame(substr(hash('sha256', "10.0.0.1\0eda"), 0, 32), $limiter->bucket('login', ' EDA'));
        $this->assertSame(substr(hash('sha256', '10.0.0.1'), 0, 32), $limiter->bucket('register', 'eda'));
    }

    /** @return iterable<string,array{0:string,1:string,2:string}> */
    public static function failures(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            foreach ([['users', 'INSERT', 'register'], ['users', 'UPDATE', 'rehash'], ['users', 'UPDATE', 'last login'], ['users', 'UPDATE', 'password'],
                ['users', 'INSERT', 'first admin'], ['auth_attempts', 'INSERT', 'attempt'], ['auth_attempts', 'DELETE', 'prune'], ['auth_attempts', 'DELETE', 'clear']] as [$table, $op, $what]) {
                yield "$driver: $what" => [$driver, $table, $op, $what];
            }
        }
    }

    #[DataProvider('failures')]
    public function testEveryWriteFailsLoudly(string $driver, string $table, string $op, string $what): void
    {
        $this->database = TestDatabase::create($driver);
        $this->assertFalse((bool)$this->db()->DBDebug);
        $id = $this->account('eda', 'editor', 'active', 4);
        $limiter = new RateLimiter($this->db(), '10.0.0.1', 1_800_000_000);
        $limiter->hit('login', 'eda');
        // Older than a day only for the prune case (the prune runs before the insert, outside a transaction, as legacy).
        $this->db()->query("INSERT INTO auth_attempts (kind, client, at) VALUES ('login', 'old', " . ($what === 'prune' ? 1 : 1_799_999_000) . ')');
        $write = match ($what) {
            'register' => fn () => $this->accounts()->register('rex', self::PW, self::PW, 'viewer', false),
            'rehash' => fn () => $this->accounts()->verify('eda', self::PW),
            'last login' => fn () => $this->accounts()->admit(['id' => $id, 'status' => 'active']),
            'password' => fn () => $this->accounts()->changeOwnPassword(['id' => $id, 'username' => 'eda'], self::PW, 'newer-password', 'newer-password'),
            'first admin' => function () use ($id): void {
                $this->db()->query('DELETE FROM users WHERE id = ' . $id);
                $this->accounts()->ensureFirstAdmin(true, 'placeholder-pw');
            },
            'attempt' => fn () => $limiter->hit('login', 'vera'),
            'prune' => fn () => $limiter->hit('login', 'vera'),
            'clear' => fn () => $limiter->clear('login', 'eda'),
        };
        $before = $this->snapshot();
        if ($what === 'first admin') {
            $this->db()->query('DELETE FROM users WHERE id = ' . $id);
            $before = $this->snapshot();
        }
        $this->failOn($table, $op);
        try {
            $write();
            $this->fail('the failed write must throw');
        } catch (WriteFailed $e) {
            $this->assertStringStartsWith('Database write failed', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot(), 'nothing changed');
    }

    public function testFindHidesOnlyDatabaseErrors(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->db()->query('DROP TABLE users');
        $this->assertNull($this->users()->find(1), 'a missing table reads as no account (#93)');
        $broken = $this->createMock(BaseConnection::class);
        $broken->method('table')->willThrowException(new \LogicException('a bug'));
        $this->expectException(\LogicException::class);
        (new UserRepository($broken))->find(1);
    }

    private function assertLimited(callable $hit, string $message): void
    {
        try {
            $hit();
            $this->fail('over the limit');
        } catch (UserError $e) {
            $this->assertSame([429, $message], [$e->getCode(), $e->getMessage()]);
        }
    }

    private function failOn(string $table, string $op): void
    {
        if ($this->db()->DBDriver === 'Postgre') {
            $this->db()->query("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            $this->db()->query("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()");

            return;
        }
        $this->db()->query("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    }

    private function snapshot(): string
    {
        return (string)json_encode([
            $this->db()->query('SELECT id, username, password_hash, status, session_epoch, last_login_at FROM users ORDER BY id')->getResultArray(),
            $this->db()->query('SELECT kind, client, at FROM auth_attempts ORDER BY id')->getResultArray(),
        ]);
    }

    private function accounts(): Accounts
    {
        return new Accounts($this->users(), new RoleRepository($this->db()), new Clock());
    }

    private function users(): UserRepository
    {
        return new UserRepository($this->db());
    }

    private function account(string $name, string $role, string $status, int $cost = 10): int
    {
        return $this->users()->create($name, password_hash(self::PW, PASSWORD_BCRYPT, ['cost' => $cost]), $role, $status, '2026-01-01 00:00:00');
    }

    private function lastLogin(int $id): ?string
    {
        $v = $this->db()->query('SELECT last_login_at FROM users WHERE id = ?', [$id])->getRowArray()['last_login_at'] ?? null;

        return $v === null ? null : (string)$v;
    }

    private function attempts(string $kind): int
    {
        return (int)($this->db()->query('SELECT COUNT(*) AS n FROM auth_attempts WHERE kind = ?', [$kind])->getRowArray()['n'] ?? 0);
    }

    private function bucketCount(string $key): int
    {
        return (int)($this->db()->query('SELECT COUNT(*) AS n FROM auth_attempts WHERE client = ?', [$key])->getRowArray()['n'] ?? 0);
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }
}
