<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\RateLimiter;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\SignInPace;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
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

    /**
     * Every failed sign-in ends at the deadline after the attempt began: an
     * unknown name, a wrong password on a current (cost-12) hash and one on a
     * cost-10 hash carried over from main. A fake clock (real time plus the
     * time "slept") and a recording sleeper keep the test fast.
     */
    #[DataProvider('drivers')]
    public function testAFailedSignInAnswersAtTheDeadline(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->account('old', 'editor', 'active', 10);
        $this->account('new', 'editor', 'active', 12);
        $offset = 0;
        $calls = [];
        $sleeps = [];
        $now = static function () use (&$offset, &$calls): int {
            return $calls[] = hrtime(true) + $offset;
        };
        $sleep = static function (int $us) use (&$offset, &$sleeps): void {
            $sleeps[] = $us;
            $offset += $us * 1000;
        };
        $deadline = 3000;
        $accounts = new Accounts($this->users(), new RoleRepository($this->db()), new Clock(), new SignInPace($deadline, $now, $sleep));
        foreach (['unknown name' => ['nobody', 'wrong-password'], 'cost-12 wrong password' => ['new', 'wrong-password'],
            'cost-10 wrong password' => ['old', 'wrong-password']] as $case => [$name, $password]) {
            $calls = $sleeps = [];
            $started = $accounts->startSignIn();
            $this->assertNull($accounts->signIn($started, $name, $password), $case);
            $this->assertCount(1, $sleeps, $case . ': one wait');
            $this->assertCount(2, $calls, $case . ': start and deadline check');
            $worked = $calls[1] - $started;
            $this->assertLessThan($deadline * 1_000_000, $worked, $case . ': the check itself fits in the deadline');
            $answered = $worked + $sleeps[0] * 1000;
            $this->assertGreaterThan($deadline * 1_000_000 - 1000, $answered, $case);
            $this->assertLessThanOrEqual($deadline * 1_000_000, $answered, $case . ': answered at the deadline');
        }
        // A good password does not wait, and the cost-10 hash is still replaced.
        $calls = $sleeps = [];
        $user = $accounts->signIn($accounts->startSignIn(), 'old', self::PW);
        $this->assertSame('old', $user['username'] ?? null);
        $this->assertSame([], $sleeps);
        $this->assertFalse(password_needs_rehash($this->users()->findByName('old')['password_hash'] ?? '', PASSWORD_DEFAULT));
    }

    /**
     * A password with a NUL byte: an unknown name and an existing one (cost 10
     * and 12, wrong password or the right one followed by a NUL) all give null
     * through the same deadline, and nothing is written.
     */
    #[DataProvider('drivers')]
    public function testANulBytePasswordFailsAtTheDeadline(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->account('old', 'editor', 'active', 10);
        $this->account('new', 'editor', 'active', 12);
        $sleeps = [];
        $accounts = new Accounts($this->users(), new RoleRepository($this->db()), new Clock(), new SignInPace(3000, null, static function (int $us) use (&$sleeps): void {
            $sleeps[] = $us;
        }));
        $before = $this->snapshot();
        foreach (['unknown name' => ['nobody', "wrong\0password"], 'cost-10 wrong' => ['old', "wrong\0password"],
            'cost-12 wrong' => ['new', "wrong\0password"], 'cost-10 right then NUL' => ['old', self::PW . "\0x"],
            'cost-12 right then NUL' => ['new', self::PW . "\0x"]] as $case => [$name, $password]) {
            $sleeps = [];
            $this->assertNull($accounts->signIn($accounts->startSignIn(), $name, $password), $case);
            $this->assertCount(1, $sleeps, $case . ': padded to the deadline');
        }
        $this->assertSame($before, $this->snapshot(), 'no hash was replaced');
    }

    public function testTheDummyHashIsAtTheDefaultCost(): void
    {
        $this->assertFalse(password_needs_rehash(Accounts::DUMMY_HASH, PASSWORD_DEFAULT));
        $this->assertSame(PASSWORD_DEFAULT, password_get_info(Accounts::DUMMY_HASH)['algo']);
    }

    /** Config\SignIn's failDeadlineMs from the environment reaches Services::accounts(), clamped to 400..10000. */
    #[DataProvider('deadlineOverrides')]
    public function testTheDeadlineSettingReachesTheAccountsService(?string $env, int $expected): void
    {
        $this->database = TestDatabase::create('sqlite');
        \Config\Services::injectMock('apothekeDb', $this->db());
        $saved = $_ENV['signin.failDeadlineMs'] ?? null;
        try {
            if ($env === null) {
                unset($_ENV['signin.failDeadlineMs']);
            } else {
                $_ENV['signin.failDeadlineMs'] = $env;
            }
            \CodeIgniter\Config\Factories::reset('config');
            $this->assertSame($expected, \Config\Services::accounts(false)->failDeadlineMs());
        } finally {
            if ($saved === null) {
                unset($_ENV['signin.failDeadlineMs']);
            } else {
                $_ENV['signin.failDeadlineMs'] = $saved;
            }
            \CodeIgniter\Config\Factories::reset('config');
            \Config\Services::reset(true);
        }
    }

    /** @return iterable<string,array{0:?string,1:int}> */
    public static function deadlineOverrides(): iterable
    {
        yield 'default' => [null, 1000];
        yield 'set' => ['2500', 2500];
        yield 'too short' => ['50', 400];
        yield 'too long' => ['600000', 10000];
    }

    public function testSignInPace(): void
    {
        $t = 0;
        $slept = [];
        $pace = new SignInPace(1000, static function () use (&$t): int {
            return $t;
        }, static function (int $us) use (&$slept): void {
            $slept[] = $us;
        });
        $start = $pace->start();
        $t = 250_000_000;
        $this->assertSame(750_000, $pace->pad($start));
        $t = 1_000_000_000;
        $this->assertSame(0, $pace->pad($start), 'at the deadline: no wait');
        $t = 1_200_000_000;
        $this->assertSame(0, $pace->pad($start), 'past the deadline: no wait');
        $this->assertSame([750_000], $slept);
        $this->assertSame(SignInPace::MIN_MS, (new SignInPace(5))->deadlineMs, 'never shorter than legacy\'s 400 ms');
        $this->assertSame(SignInPace::MAX_MS, (new SignInPace(PHP_INT_MAX))->deadlineMs);
        $this->assertSame(SignInPace::DEFAULT_MS, (new SignInPace())->deadlineMs);
        $this->assertSame(SignInPace::DEFAULT_MS, config(\Config\SignIn::class)->failDeadlineMs);
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
            ['rex', "long-\0-enough", "long-\0-enough", 'Passwords cannot contain a null character.'],
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
        foreach ([['wrong-password', 'newer-password', 403, 'The current password is not correct.'], [self::PW, 'short', 422, 'Use at least 8 characters for the password.'],
            [self::PW, "newer-\0-password", 422, 'Passwords cannot contain a null character.'], [self::PW . "\0", 'newer-password', 403, 'The current password is not correct.']] as [$current, $new, $code, $message]) {
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

    #[DataProvider('drivers')]
    public function testTheAddressCapCountsEveryName(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $now = 1_800_000_000;
        $limiter = new RateLimiter($this->db(), '10.0.0.1', $now);
        for ($i = 0; $i < 30; $i++) {
            $limiter->hit('login_address');
        }
        $this->assertLimited(fn () => $limiter->hit('login_address'), 'Too many attempts from this address – please wait 15 minutes and try again.');
        $this->assertSame(30, $this->attempts('login_address'), 'a refused attempt takes its row back');
        $this->assertSame(substr(hash('sha256', '10.0.0.1'), 0, 32), $limiter->bucket('login_address', 'eda'), 'the address only, as legacy');
        (new RateLimiter($this->db(), '10.0.0.2', $now))->hit('login_address');
        (new RateLimiter($this->db(), '10.0.0.1', $now + 901))->hit('login_address');
        $limiter->clear('login_address');
        $this->assertSame(0, $this->bucketCount($limiter->bucket('login_address', null)));
        $this->assertSame(1, $this->attempts('login_address'), 'the other address keeps its attempt');
    }

    /** The attempt is recorded before the count, so one recorded meanwhile by another request counts against this one. */
    #[DataProvider('drivers')]
    public function testAnAttemptRecordedMeanwhileCounts(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $now = 1_800_000_000;
        $limiter = new RateLimiter($this->db(), '10.0.0.1', $now);
        for ($i = 0; $i < 9; $i++) {
            $limiter->hit('login', 'eda');
        }
        // A request that has recorded its attempt but not counted yet.
        $this->db()->table('auth_attempts')->insert(['kind' => 'login', 'client' => $limiter->bucket('login', 'eda'), 'at' => $now]);
        $this->assertLimited(fn () => $limiter->hit('login', 'eda'), 'Too many attempts for this account from this address – please wait 15 minutes and try again.');
        $this->assertSame(10, $this->attempts('login'));
    }

    /** A refused attempt whose row cannot be taken back throws, and the attempt stays counted. */
    #[DataProvider('drivers')]
    public function testAFailedReleaseFailsLoudly(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $limiter = new RateLimiter($this->db(), '10.0.0.1', 1_800_000_000);
        for ($i = 0; $i < 10; $i++) {
            $limiter->hit('login', 'eda');
        }
        $this->failOn('auth_attempts', 'DELETE');
        try {
            $limiter->hit('login', 'eda');
            $this->fail('the failed write must throw');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: auth_attempts release', $e->getMessage());
        }
        $this->assertSame(11, $this->attempts('login'));
    }

    /** Legacy address_scope() and client_key(): per-address buckets count an IPv6 client by its /64 (N-125, N-174). */
    #[DataProvider('drivers')]
    public function testPerAddressBucketsScopeIpv6ToItsPrefix(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        foreach ([
            '192.0.2.7' => '192.0.2.7',
            '2001:db8:1:2:aaaa::1' => '2001:db8:1:2::/64',
            '::ffff:192.0.2.7' => '::ffff:192.0.2.7',
            '::c000:207' => '::c000:207',
            '64:ff9b::c000:207' => '64:ff9b::c000:207',
            'cli' => 'cli',
        ] as $ip => $scope) {
            $this->assertSame($scope, RateLimiter::addressScope($ip), $ip);
        }
        $a = new RateLimiter($this->db(), '2001:db8:1:2::1', 1_800_000_000);
        $b = new RateLimiter($this->db(), '2001:db8:1:2::ffff', 1_800_000_000);
        $this->assertSame(substr(hash('sha256', '2001:db8:1:2::/64'), 0, 32), $a->bucket('login_address', null));
        $this->assertSame($a->bucket('login_address', null), $b->bucket('login_address', null));
        $this->assertSame($a->bucket('register', null), $b->bucket('register', null));
        $this->assertNotSame($a->bucket('login', 'eda'), $b->bucket('login', 'eda'), 'per account: the full address');
        for ($i = 0; $i < 30; $i++) {
            $a->hit('login_address');
        }
        $this->assertLimited(fn () => $b->hit('login_address'), 'Too many attempts from this address – please wait 15 minutes and try again.');
        (new RateLimiter($this->db(), '2001:db8:1:3::1', 1_800_000_000))->hit('login_address');
    }

    /** L-120: forget() takes back one row by id; the rest of the address bucket stays. */
    #[DataProvider('drivers')]
    public function testForgetTakesBackOnlyThatAttempt(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $limiter = new RateLimiter($this->db(), '10.0.0.1', 1_800_000_000);
        $first = $limiter->hit('login_address');
        $mine = $limiter->hit('login_address');
        $this->assertGreaterThan($first, $mine);
        $limiter->forget($mine);
        $this->assertSame([$first], array_map('intval', array_column($this->db()->query('SELECT id FROM auth_attempts')->getResultArray(), 'id')));
        $this->failOn('auth_attempts', 'DELETE');
        try {
            $limiter->forget($first);
            $this->fail('the failed write must throw');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: auth_attempts forget', $e->getMessage());
        }
    }

    /** N-162: the count's result is freed before a refused attempt's row is deleted (SQLite holds a read lock until then). */
    public function testTheCountIsFreedBeforeTheRelease(): void
    {
        $log = [];
        $result = $this->createMock(BaseResult::class);
        $result->method('getRowArray')->willReturn(['n' => 11]);
        $result->method('freeResult')->willReturnCallback(static function () use (&$log): void {
            $log[] = 'free';
        });
        $builder = $this->createMock(BaseBuilder::class);
        $builder->method('insert')->willReturn(true);
        $db = $this->createMock(BaseConnection::class);
        $db->method('table')->willReturn($builder);
        $db->method('insertID')->willReturn(7);
        $db->method('query')->willReturnCallback(static function (string $sql) use (&$log, $result): mixed {
            $log[] = strtok($sql, ' ');
            return str_starts_with($sql, 'SELECT') ? $result : true;
        });
        $this->assertLimited(fn () => (new RateLimiter($db, '10.0.0.1', 1_800_000_000))->hit('login', 'eda'), 'Too many attempts for this account from this address – please wait 15 minutes and try again.');
        $this->assertSame(['DELETE', 'SELECT', 'free', 'DELETE'], $log);
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

    /** N-160, N-221: a name with a null character anywhere, before trimming, finds no account (legacy user_by_name()). */
    #[DataProvider('drivers')]
    public function testANameWithANullCharacterFindsNoAccount(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->account('ada', 'admin', 'active', 4);
        $this->assertNotNull($this->users()->findByName(' ADA '));
        foreach (["ada\0x", "\0ada", "ada\0", "ADA\0"] as $name) {
            $this->assertNull($this->users()->findByName($name), (string)json_encode($name));
            $this->assertNull($this->accounts()->verify($name, self::PW), (string)json_encode($name));
        }
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
