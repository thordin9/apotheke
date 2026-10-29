<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\TestDatabase;

/**
 * POST /register checks the name as sent (N-261, N-291): a null character at
 * either end is refused, never trimmed away into a valid name. Each test runs
 * in its own process so session_start() works.
 */
final class RegisterFormTest extends CIUnitTestCase
{
    use FeatureTestTrait;

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
    public function testANullCharacterAtEitherEndOfANewNameIsRefused(string $driver): void
    {
        $this->database = $database = TestDatabase::create($driver);
        $db = $database->db;
        $db->table('users')->insert(['username' => 'ada', 'username_key' => 'ada', 'password_hash' => password_hash('long-enough', PASSWORD_BCRYPT, ['cost' => 4]), 'role' => 'admin', 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
        (new SettingsService(new SettingsRepository($db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null, new CommandLine()))
            ->store(['auth_enabled' => true, 'auth_register_disabled' => false]);
        Services::injectMock('apothekeConfig', $database->config);
        Services::injectMock('apothekeDb', $db);
        foreach (["\0rez", "rez\0"] as $name) {
            $this->liveSession('csrf-test-token');
            $response = $this->withSession(['csrf' => 'csrf-test-token'])->post('register', ['csrf' => 'csrf-test-token', 'username' => $name, 'new' => 'long-enough', 'confirm' => 'long-enough']);
            $response->assertStatus(422);
            $this->assertStringContainsString('User names use letters, digits and . _ @ - (at most 40 characters, no spaces).', (string)$response->response()->getBody(), json_encode($name) ?: '');
            $this->assertSame(['ada'], array_column($db->query('SELECT username FROM users ORDER BY id')->getResultArray(), 'username'), json_encode($name) ?: '');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            Services::reset(true);
            Services::injectMock('apothekeConfig', $database->config);
            Services::injectMock('apothekeDb', $db);
        }
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
        service('superglobals')->setCookie('apothekesid', $id);   // the request reads its cookies from here
    }
}
