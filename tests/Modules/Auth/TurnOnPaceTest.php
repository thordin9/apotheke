<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\SignInPace;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\TestDatabase;

/**
 * POST /settings/password with sign-in off and accounts present (turning
 * sign-in back on): every refusal spends one password check (N-304) and waits
 * for SignInPace's deadline, also a right password for a non-admin (L-310,
 * L-330). The pace records its waits instead of sleeping. Each test runs in
 * its own process so session_start() works.
 */
final class TurnOnPaceTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const PW = 'long-enough';

    private ?TestDatabase $database = null;

    /** @var list<int> microseconds the pace would have slept */
    private array $sleeps = [];

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unset($_COOKIE['apothekesid']);
        service('superglobals')->unsetCookie('apothekesid');
        $this->database?->drop();
        $this->database = null;
        Services::reset(true);
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testEveryRefusalChecksThePasswordAndWaitsForTheDeadline(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        foreach (['ada' => 'admin', 'vic' => 'viewer'] as $name => $role) {
            $this->database->db->table('users')->insert(['username' => $name, 'username_key' => $name, 'password_hash' => password_hash(self::PW, PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
        }
        $work = [];
        foreach (['null-character name' => ["\0ada", self::PW], 'admin, wrong password' => ['ada', 'wrong-password'],
            'viewer, right password' => ['vic', self::PW], 'viewer, wrong password' => ['vic', 'wrong-password']] as $case => [$name, $password]) {
            for ($i = 0; $i < 2; $i++) {
                [$status, $ms] = $this->turnOn($name, $password);
                $this->assertSame(403, $status, $case);
                $this->assertCount(1, $this->sleeps, $case . ': one wait');
                $this->assertGreaterThan(2_000_000, $this->sleeps[0], $case . ': the rest of the 3 s deadline from the start of the request');
                $work[$case][] = $ms;
            }
        }
        // The time before the wait is the password check: a skipped one (bcrypt at the default cost) shows.
        $fastest = array_map('min', $work);
        foreach ($fastest as $case => $ms) {
            $this->assertGreaterThan($fastest['admin, wrong password'] / 2, $ms, $case . ' spends a password check: ' . json_encode($work));
        }
        [$status] = $this->turnOn('ada', self::PW);
        $this->assertSame(303, $status, 'the admin turns sign-in on');
        $this->assertSame([], $this->sleeps, 'a success does not wait');
    }

    /** @return array{0:int,1:float} status and milliseconds without the (recorded) wait */
    private function turnOn(string $name, string $password): array
    {
        $database = $this->database;
        $this->assertNotNull($database);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        Services::reset(true);
        Services::injectMock('apothekeConfig', $database->config);
        Services::injectMock('apothekeDb', $database->db);
        $this->sleeps = [];
        $sleeps = &$this->sleeps;
        Services::injectMock('accounts', new Accounts(new UserRepository($database->db), new RoleRepository($database->db), new Clock(), new SignInPace(3000, null, static function (int $us) use (&$sleeps): void {
            $sleeps[] = $us;
        })));
        $this->liveSession('csrf-test-token');
        $t = hrtime(true);
        $response = $this->withSession(['csrf' => 'csrf-test-token'])->post('settings/password', ['csrf' => 'csrf-test-token', 'mode' => 'change', 'username' => $name, 'new' => $password, 'confirm' => $password]);

        return [$response->response()->getStatusCode(), (hrtime(true) - $t) / 1e6];
    }

    /** A stored session holding $csrf, named by the request's apothekesid cookie (as in AutofillTest). */
    private function liveSession(string $csrf): void
    {
        $save = (string)getenv('DATA_DIR') . '/sessions';
        if (!is_dir($save)) {
            mkdir($save, 0775, true);
        }
        $id = bin2hex(random_bytes(16));
        file_put_contents($save . '/sess_' . $id, 'csrf|s:' . strlen($csrf) . ':"' . $csrf . '";last_activity|i:' . time() . ';');
        $_COOKIE['apothekesid'] = $id;
        service('superglobals')->setCookie('apothekesid', $id);
    }
}
