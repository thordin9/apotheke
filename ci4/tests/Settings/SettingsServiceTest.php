<?php

declare(strict_types=1);

namespace Tests\Settings;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\ActorGate;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Security\SecretBox;
use Apotheke\Core\Security\SecretKey;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * SettingsService and SettingsRepository on a migrated database, once per
 * driver (SQLite always, PostgreSQL 17 when configured).
 */
final class SettingsServiceTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @var list<string> */
    private array $logged = [];

    /** The at-rest key variables, isolated so no test depends on the runner's environment. */
    private const KEY_ENV = ['APP_KEY', 'APP_KEY_FILE', 'APOTHEKE_SODIUM'];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::KEY_ENV as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        putenv('APOTHEKE_CLOCK');
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->savedEnv = [];
        parent::tearDown();
    }

    /** @return iterable<string,array{string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    #[DataProvider('drivers')]
    public function testDefaultsAfterMigrationAndTheTokenIsNeverListed(string $driver): void
    {
        $service = $this->service($driver);
        $all = $service->all();
        $this->assertCount(count((new SettingsSchema())->keys()) - 2, $all, 'every key but the two secrets');
        $this->assertArrayNotHasKey('autofill_token', $all);
        $this->assertArrayNotHasKey('password_hash', $all);
        $this->assertSame('Apotheke', $service->get('app_name'));
        $this->assertSame(60, $service->get('page_size'));
        $this->assertNull($service->get('default_min_qty'));
        $this->assertFalse($service->get('auth_enabled'));
        $this->assertSame('', $service->autofillToken());
        $this->assertArrayNotHasKey('autofill_token', $service->export()['settings']);
    }

    #[DataProvider('drivers')]
    public function testUnknownAndWriteOnlyKeysThrow(string $driver): void
    {
        $service = $this->service($driver);
        try {
            $service->get('autofill_token');
            $this->fail('token readable through get()');
        } catch (LogicException $e) {
            $this->assertSame('Setting autofill_token is write-only', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unknown setting nope');
        $service->get('nope');
    }

    #[DataProvider('drivers')]
    public function testSaveValidatesEverythingFirstAndStoresJson(string $driver): void
    {
        putenv('APOTHEKE_CLOCK=2020-06-15 12:00:00');
        $service = $this->service($driver);
        $this->assertSame(['page_size' => 'Items per page: between 1 and 500.'], $service->save(['app_name' => 'New', 'page_size' => '0']));
        $this->assertSame('Apotheke', $service->get('app_name'), 'nothing saved when one value is invalid');
        $this->assertSame([], $service->save(['app_name' => '  Πρίζα  box ', 'page_size' => '25', 'default_tags' => 'a, #b; A', 'accent' => 'c8502d']));
        $this->assertSame('Πρίζα box', $service->get('app_name'));
        $this->assertSame(25, $service->get('page_size'));
        $this->assertSame('a, b', $service->get('default_tags'));
        $this->assertSame('#C8502D', $service->get('accent'));
        $row = $this->database->db->table('settings')->where('name', 'app_name')->get()->getRowArray();
        $this->assertSame('"Πρίζα box"', $row['value']);
        $this->assertSame('2020-06-15 12:00:00', $row['updated_at']);
        // A second instance (the next request) reads the stored values.
        $this->assertSame(25, $this->fresh()->get('page_size'));
    }

    #[DataProvider('drivers')]
    public function testTheCacheLivesInTheInstanceOnly(string $driver): void
    {
        $service = $this->service($driver);
        $this->assertSame(60, $service->get('page_size'));
        $this->database->db->table('settings')->where('name', 'page_size')->update(['value' => '7']);
        $this->assertSame(60, $service->get('page_size'), 'cached for this request');
        $this->assertSame(7, $this->fresh()->get('page_size'), 'a new request sees the change');
    }

    #[DataProvider('drivers')]
    public function testInvalidOrMissingRowsFallBackToTheDefault(string $driver): void
    {
        $service = $this->service($driver);
        $this->database->db->table('settings')->where('name', 'page_size')->update(['value' => '"lots"']);
        $this->database->db->table('settings')->where('name', 'jpeg_quality')->delete();
        $fresh = $this->fresh();
        $this->assertSame(60, $fresh->get('page_size'));
        $this->assertSame(82, $fresh->get('jpeg_quality'));
        unset($service);
    }

    #[DataProvider('drivers')]
    public function testAutofillTokenSetterAndGetter(string $driver): void
    {
        $service = $this->service($driver);
        $service->setAutofillToken('  placeholder-token  ');
        $this->assertSame('placeholder-token', $service->autofillToken());
        $this->assertSame('placeholder-token', $this->fresh()->autofillToken());
        foreach (['', 'has space', str_repeat('x', 513), "tab\there", 'ümlaut'] as $bad) {
            try {
                $service->setAutofillToken($bad);
                $this->fail('accepted ' . $bad);
            } catch (UserError $e) {
                $this->assertSame('Token: 1–512 printable characters, no spaces.', $e->getMessage());
                $this->assertSame(422, $e->getCode());
            }
        }
        $service->setAutofillToken(str_repeat('~', 512));
        $service->clearAutofillToken();
        $this->assertSame('', $service->autofillToken());
    }

    #[DataProvider('drivers')]
    public function testTheTokenIsClearedWhenTheAddressMoves(string $driver): void
    {
        $service = $this->service($driver);
        $this->assertSame([], $service->save(['autofill_url' => 'http://192.0.2.1:8080']));
        $service->setAutofillToken('placeholder-token');
        $this->assertSame([], $service->save(['autofill_url' => 'HTTP://192.0.2.1:8080/']));
        $this->assertFalse($service->tokenCleared(), 'same address after normalising');
        $this->assertSame('placeholder-token', $service->autofillToken());
        $this->assertSame([], $service->save(['autofill_url' => 'http://192.0.2.2:8080', 'autofill_timeout' => '20'], ['autofill_token' => 'new-token']));
        $this->assertFalse($service->tokenCleared(), 'a token written in the same save replaces it');
        $this->assertSame('new-token', $service->autofillToken());
        $this->assertSame([], $service->save(['autofill_url' => 'https://autofill.example']));
        $this->assertTrue($service->tokenCleared());
        $this->assertSame('', $service->autofillToken());
        // No APP_KEY: the token stays plaintext and the notice is logged once, as on legacy (S-L5).
        $this->assertSame(['apotheke: set APP_KEY to encrypt stored secrets', 'apotheke autofill: token cleared: the service address changed'], $this->logged);
        // Nothing to clear: no flag, no log line.
        $this->assertSame([], $service->save(['autofill_url' => 'http://192.0.2.9']));
        $this->assertFalse($service->tokenCleared());
        $this->assertCount(2, $this->logged);
        // Reset and import move the address too.
        $service->setAutofillToken('placeholder-token');
        $service->resetSection('autofill');
        $this->assertTrue($service->tokenCleared());
        $service->setAutofillToken('placeholder-token');
        $r = $service->import('{"format":"apotheke-settings","settings":{"autofill_url":"http://192.0.2.3"}}');
        $this->assertTrue($r['token_cleared']);
        $this->assertSame('', $service->autofillToken());
    }

    #[DataProvider('drivers')]
    public function testAutofillCapFields(string $driver): void
    {
        $service = $this->service($driver);
        $this->assertSame(50, $service->get('autofill_timeout'));
        $this->assertSame(0, $service->get('autofill_image_size'));
        $this->assertSame([], $service->save(['autofill_timeout' => '3', 'autofill_image_size' => '64', 'autofill_model' => ' llava ']));
        $this->assertSame([3, 64, 'llava'], [$service->get('autofill_timeout'), $service->get('autofill_image_size'), $service->get('autofill_model')]);
        $this->assertSame(['autofill_timeout' => 'Timeout (seconds): between 3 and 60.'], $service->save(['autofill_timeout' => '61']));
        $this->assertSame(['autofill_image_size' => 'Image size: between 64 and 4096 px.'], $service->save(['autofill_image_size' => '63']));
        $this->assertSame([], $service->save(['autofill_image_size' => '4096']));
        $this->assertSame([], $service->save(['autofill_image_size' => '']));
        $this->assertSame(0, $service->get('autofill_image_size'));
        if (!$service->schema()->has('autofill_max_image_bytes')) {
            return;   // v2.6.0 key: covered once the sync lands (migration 0026)
        }
        $this->assertSame('auto', $service->get('autofill_max_image_bytes'));
        $this->assertSame([], $service->save(['autofill_max_image_bytes' => '983040']));
        $this->assertSame(983040, $this->fresh()->get('autofill_max_image_bytes'));
        $this->assertArrayHasKey('autofill_max_image_bytes', $service->save(['autofill_max_image_bytes' => '65535']));
        $this->assertSame([], $service->save(['autofill_max_image_bytes' => 'AUTO']));
        $this->assertSame('auto', $service->get('autofill_max_image_bytes'));
        $this->assertSame('auto', $service->export()['settings']['autofill_max_image_bytes']);
    }

    #[DataProvider('drivers')]
    public function testResetSection(string $driver): void
    {
        $service = $this->service($driver);
        $brand = new LocalBrandStore($this->database->dataDir() . '/brand');
        $brand->put('logo.svg', '<svg/>');
        $service->store(['logo' => 'svg']);
        $this->assertSame([], $service->save(['accent' => '#0f766e', 'page_size' => '9', 'app_name' => 'Box']));
        $service->resetSection('appearance');
        $this->assertSame('#4f46e5', $service->get('accent'));
        $this->assertSame('', $service->get('logo'));
        $this->assertFalse($brand->has('logo.svg'));
        $this->assertSame(9, $service->get('page_size'), 'other sections untouched');
        $service->resetSection('list');
        $this->assertSame(60, $service->get('page_size'));
        $service->resetSection('system');   // no editable keys: nothing to do
        try {
            $service->resetSection('nope');
            $this->fail('unknown section accepted');
        } catch (UserError $e) {
            $this->assertSame(['Unknown section.', 404], [$e->getMessage(), $e->getCode()]);
        }
    }

    #[DataProvider('drivers')]
    public function testExportAndImport(string $driver): void
    {
        putenv('APOTHEKE_CLOCK=2020-06-15 12:00:00');
        $service = $this->service($driver);
        $service->setAutofillToken('placeholder-token');
        $export = $service->export();
        $this->assertSame(['format', 'version', 'exported_at', 'settings'], array_keys($export));
        $this->assertSame('2020-06-15 12:00:00', $export['exported_at']);
        foreach (array_keys($export['settings']) as $key) {
            $this->assertFalse($service->schema()->isProtected($key), $key);
        }
        $r = $service->import((string)json_encode(['format' => 'apotheke-settings', 'settings' => [
            'app_name' => 'Imported', 'page_size' => 33, 'search_typos' => false, 'accent' => 'red', 'default_min_qty' => null,
            'autofill_token' => 'other', 'password_hash' => 'x', 'logo' => 'svg', 'nonsense' => 1, 'list_view' => ['x'],
        ]]));
        $this->assertSame(4, $r['applied']);
        $this->assertSame(['accent' => 'Accent colour: a colour like #C8502D.', 'list_view' => 'View: choose one of the options.'], $r['errors']);
        $this->assertSame(['autofill_token', 'password_hash', 'logo', 'nonsense'], $r['ignored']);
        $this->assertFalse($r['token_cleared']);
        $this->assertSame(['Imported', 33, false], [$service->get('app_name'), $service->get('page_size'), $service->get('search_typos')]);
        $this->assertSame('placeholder-token', $service->autofillToken(), 'import never writes the token');
        // Round trip, by the command line (or an admin).
        $again = $this->fresh([], new CommandLine())->import((string)json_encode($service->export()));
        $this->assertSame([], $again['errors']);
        $this->assertSame(count($service->export()['settings']), $again['applied']);
    }

    /** SEC-M1: sign-in settings come in only from an admin or the command line (main #71). */
    #[DataProvider('drivers')]
    public function testImportSkipsSignInSettingsUnlessTheActorIsAdmin(string $driver): void
    {
        $service = $this->service($driver);
        $file = (string)json_encode(['format' => 'apotheke-settings', 'settings' => [
            'app_name' => 'Shed', 'auth_register_disabled' => false, 'auth_guest_disabled' => false,
            'auth_register_role' => 'editor', 'auth_register_approval' => true, 'session_days' => 7,
            'trust_proxy' => true, 'auth_enabled' => false,
        ]]);
        $security = ['auth_register_disabled', 'auth_guest_disabled', 'auth_register_role', 'auth_register_approval', 'session_days', 'trust_proxy'];
        $r = $service->import($file);
        $this->assertSame(1, $r['applied']);
        foreach ([...$security, 'auth_enabled'] as $key) {
            $this->assertContains($key, $r['ignored'], $key);
        }
        $fresh = $this->fresh();
        $this->assertSame('Shed', $fresh->get('app_name'));
        $this->assertSame([true, true, 'viewer', false, 30, false], array_map($fresh->get(...), $security));

        $admin = $this->fresh([], new CommandLine())->import($file);
        $this->assertSame(7, $admin['applied']);
        $this->assertSame(['auth_enabled'], $admin['ignored'], 'sign-in mode is not a key a file can set');
        $this->assertSame([false, false, 'editor', true, 7, true], array_map($this->fresh()->get(...), $security));
    }

    #[DataProvider('drivers')]
    public function testImportRefusesForeignAndOversizedFiles(string $driver): void
    {
        $service = $this->service($driver);
        foreach (['', 'not json', '[]', '{"format":"other","settings":{}}', '{"format":"apotheke-settings","settings":"x"}'] as $json) {
            try {
                $service->import($json);
                $this->fail('accepted ' . $json);
            } catch (UserError $e) {
                $this->assertSame('That is not an Apotheke settings file.', $e->getMessage());
            }
        }
        $big = '{"format":"apotheke-settings","settings":{},"pad":"' . str_repeat('x', SettingsService::IMPORT_MAX_BYTES) . '"}';
        try {
            $service->import($big);
            $this->fail('oversized file accepted');
        } catch (UserError $e) {
            $this->assertSame('That settings file is too large.', $e->getMessage());
        }
        $this->assertSame(0, $service->import($big, null)['applied'], 'no limit when the caller says so (CLI)');
    }

    /** L-740: an uploaded settings file is read up to the cap plus one byte, never whole, and is then refused as too large. */
    public function testAnUploadedSettingsFileIsReadOnlyUpToTheCap(): void
    {
        $path = sys_get_temp_dir() . '/apotheke-settings-import-' . bin2hex(random_bytes(4)) . '.json';
        try {
            $small = '{"format":"apotheke-settings","settings":{}}';
            file_put_contents($path, $small);
            $this->assertSame($small, SettingsService::readImportFile($path));
            file_put_contents($path, str_repeat(' ', 4 * SettingsService::IMPORT_MAX_BYTES));
            $this->assertSame(SettingsService::IMPORT_MAX_BYTES + 1, strlen(SettingsService::readImportFile($path)));
            $this->assertSame(11, strlen(SettingsService::readImportFile($path, 10)));
            $this->assertSame('', SettingsService::readImportFile($path . '.missing'));
        } finally {
            @unlink($path);
        }
    }

    #[DataProvider('drivers')]
    public function testRegistrationRoleFollowsTheRolesTable(string $driver): void
    {
        $service = $this->service($driver);
        $this->assertSame(['auth_register_role' => 'Role for new registrations: choose a role other than admin.'], $service->save(['auth_register_role' => 'helper']));
        $this->database->db->table('roles')->insert(['role_key' => 'helper', 'name' => 'Helper', 'perms' => '["view"]', 'builtin' => 0, 'created_at' => '2020-06-15 12:00:00']);
        $fresh = $this->fresh();
        $this->assertSame(['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer', 'helper' => 'Helper'], (new SettingsRepository($this->database->db))->accountRoles());
        $this->assertSame([], $fresh->save(['auth_register_role' => 'helper']));
        $this->assertSame(['auth_register_role' => 'Role for new registrations: choose a role other than admin.'], $fresh->save(['auth_register_role' => 'guest']));
    }

    #[DataProvider('drivers')]
    public function testSignInSummary(string $driver): void
    {
        $service = $this->service($driver);
        $this->assertFalse($service->signInEnabled());
        $this->assertSame(0, $service->accountCount());
        $service->store(['auth_enabled' => true]);
        $this->assertFalse($service->signInEnabled(), 'on, but no account and no APP_PASSWORD');
        $withPassword = $this->fresh(['app_password' => 'placeholder-password']);
        $this->assertTrue($withPassword->signInEnabled());
        $this->database->db->table('users')->insert(['username' => 'admin', 'username_key' => 'admin', 'password_hash' => 'x', 'role' => 'admin', 'created_at' => '2020-06-15 12:00:00']);
        $fresh = $this->fresh();
        $this->assertTrue($fresh->signInEnabled());
        $this->assertSame(1, $fresh->accountCount());
    }

    public function testAMissingTableGivesDefaults(): void
    {
        $this->database = TestDatabase::create('sqlite', false);
        $repository = new SettingsRepository($this->database->db);
        $this->assertSame([], $repository->rows());
        $this->assertNull($repository->accountRoles());
        $this->assertSame(0, $repository->userCount());
        $service = $this->fresh();
        $this->assertSame('Apotheke', $service->get('app_name'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not save the settings.');
        $service->save(['app_name' => 'X']);
    }

    #[DataProvider('drivers')]
    public function testWithAKeyTheTokenIsSealedAtRest(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $service = $this->fresh([], null, $this->box(base64_encode(self::KEY_A)));
        $service->setAutofillToken('placeholder-token');
        $row = $this->tokenRow();
        $this->assertStringStartsWith('"enc:v1:', $row);
        $this->assertStringNotContainsString('placeholder-token', $row);
        $this->assertSame('placeholder-token', $this->fresh([], null, $this->box(base64_encode(self::KEY_A)))->autofillToken());
        $this->assertSame([], $this->logged, 'a usable key logs nothing');
        // Export and list never carry the token, sealed or not.
        $this->assertArrayNotHasKey('autofill_token', $service->export()['settings']);
        $service->clearAutofillToken();
        $this->assertSame('""', $this->tokenRow());
    }

    #[DataProvider('drivers')]
    public function testALegacyPlaintextRowIsSealedOnRead(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        (new SettingsRepository($this->database->db))->replace(['autofill_token' => '"placeholder-token"'], '2026-01-01 00:00:00');
        $this->assertSame('placeholder-token', $this->fresh([], null, $this->box(base64_encode(self::KEY_A)))->autofillToken());
        $this->assertStringStartsWith('"enc:v1:', $this->tokenRow());
        $this->assertSame('placeholder-token', $this->fresh([], null, $this->box(base64_encode(self::KEY_A)))->autofillToken());
        // Without a key a plaintext row is read as is and left alone.
        (new SettingsRepository($this->database->db))->replace(['autofill_token' => '"placeholder-token"'], '2026-01-01 00:00:00');
        $this->assertSame('placeholder-token', $this->fresh()->autofillToken());
        $this->assertSame('"placeholder-token"', $this->tokenRow());
    }

    /** #97 N-35, N-36: a seal that cannot be saved is swallowed (the read still works) and logged with the class only. */
    #[DataProvider('drivers')]
    public function testAFailedSealIsLoggedAndSwallowed(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        (new SettingsRepository($this->database->db))->replace(['autofill_token' => '"placeholder-token"'], '2026-01-01 00:00:00');
        $this->failSettingsInsert();
        $this->assertSame('placeholder-token', $this->fresh([], null, $this->box(base64_encode(self::KEY_A)))->autofillToken());
        $this->assertSame('"placeholder-token"', $this->tokenRow(), 'the legacy row stays');
        $this->assertSame(['[apotheke] could not seal a stored secret (Apotheke\\Core\\Exceptions\\WriteFailed)'], $this->logged);
    }

    /** #97 N-34: inside an open transaction the seal is skipped, so it cannot fail the outer write. */
    #[DataProvider('drivers')]
    public function testNoSealInsideAnOpenTransaction(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        (new SettingsRepository($db))->replace(['autofill_token' => '"placeholder-token"'], '2026-01-01 00:00:00');
        $this->failSettingsInsert();
        $service = $this->fresh([], null, $this->box(base64_encode(self::KEY_A)));
        Writes::transaction($db, function () use ($service, $db): void {
            $this->assertSame('placeholder-token', $service->autofillToken());
            Writes::check($db->table('categories')->insert(['name' => 'Kept', 'name_key' => 'kept', 'sort_key' => 'kept', 'created_at' => '2026-01-01 00:00:00']), 'outer');
        });
        $this->assertSame(1, $db->table('categories')->where('name', 'Kept')->countAllResults(), 'the outer write committed');
        $this->assertSame('"placeholder-token"', $this->tokenRow());
        $this->assertSame([], $this->logged, 'no seal was tried');
    }

    #[DataProvider('drivers')]
    public function testAWrongKeyReadsAsUnsetAndTheAddressMoveStillClearsIt(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->assertSame([], $this->fresh()->save(['autofill_url' => 'http://192.0.2.1:8080']));
        $this->fresh([], null, $this->box(base64_encode(self::KEY_A)))->setAutofillToken('placeholder-token');
        $other = $this->fresh([], null, $this->box(base64_encode(self::KEY_B)));
        $this->assertSame('', $other->autofillToken());
        $this->assertSame(['apotheke: setting autofill_token could not be decrypted and is treated as unset.'], $this->logged);
        $this->assertSame([], $other->save(['autofill_url' => 'http://192.0.2.2:8080']));
        $this->assertTrue($other->tokenCleared(), 'the stored row moves with the address even when it does not open');
        $this->assertSame('""', $this->tokenRow());
    }

    /** #89 N-3: the backup roots resolve originals_dir through ApothekeConfig (legacy secret_backup_roots()). */
    public function testBackupRootsFollowTheOriginalsDirectory(): void
    {
        $base = ['/srv/data', '/srv/data/images', '/srv/data/thumbs', '/srv/data/files', '/srv/data/originals'];
        $this->assertSame([], SettingsService::backupRoots(['data_dir' => '']));
        $this->assertSame($base, SettingsService::backupRoots(['data_dir' => '/srv/data/']));
        $this->assertSame([...$base, '/srv/originals'], SettingsService::backupRoots(['data_dir' => '/srv/data', 'originals_dir' => '/srv/originals/']));
        $this->assertSame([...$base, ApothekeConfig::repoRoot() . '/keep'], SettingsService::backupRoots(['data_dir' => '/srv/data', 'originals_dir' => 'keep']));
    }

    /** #89 N-1: password_hash is a secret but not recoverable, so it is never sealed (legacy secret_is_at_rest()). */
    #[DataProvider('drivers')]
    public function testThePasswordHashIsNotSealed(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $service = $this->fresh([], null, $this->box(base64_encode(self::KEY_A)));
        $this->assertTrue($service->isAtRest('autofill_token'));
        $this->assertFalse($service->isAtRest('password_hash'));
        $hash = password_hash('placeholder-password', PASSWORD_DEFAULT);
        $service->store(['password_hash' => $hash]);
        $this->assertSame((string)json_encode($hash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (new SettingsRepository($this->database->db))->rows()['password_hash'] ?? null);
        $this->fresh([], null, $this->box(base64_encode(self::KEY_A)))->all();
        $this->assertSame((string)json_encode($hash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (new SettingsRepository($this->database->db))->rows()['password_hash'] ?? null, 'a read does not seal it either');
    }

    /** #89 N-11: without an injected box the service reads APP_KEY from the environment. */
    #[DataProvider('drivers')]
    public function testTheEnvironmentKeyIsUsedWhenNoBoxIsGiven(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        putenv('APP_KEY=' . base64_encode(self::KEY_A));
        $this->fresh()->setAutofillToken('placeholder-token');
        $this->assertStringStartsWith('"enc:v1:', $this->tokenRow());
        $this->assertSame('placeholder-token', $this->fresh([], null, $this->box(base64_encode(self::KEY_A)))->autofillToken());
    }

    /** @return iterable<string,array{string,SecretKey,string,string}> */
    public static function unusableKeys(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield "$driver malformed" => [$driver, SecretKey::unusable('malformed'), 'APP_KEY is not a 32-byte key', 'APP_KEY is not a 32-byte key'];
            yield "$driver sodium" => [$driver, SecretKey::unusable('sodium'), 'The sodium extension is missing', 'sodium extension is missing'];
        }
    }

    /** #78 M-1: a configured key that cannot be used refuses the save with a 422 and keeps the old row. */
    #[DataProvider('unusableKeys')]
    public function testAnUnusableKeyRefusesTheSave(string $driver, SecretKey $key, string $label, string $phrase): void
    {
        $this->database = TestDatabase::create($driver);
        (new SettingsRepository($this->database->db))->replace(['autofill_token' => '"enc:v1:preserved"'], '2026-01-01 00:00:00');
        $service = $this->fresh([], null, new SecretBox(static fn (): SecretKey => $key, true, function (string $message): void {
            $this->logged[] = $message;
        }));
        try {
            $service->setAutofillToken('placeholder-token');
            $this->fail('saved with an unusable key');
        } catch (UserError $e) {
            $this->assertSame($label . '. The secret was not saved.', $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
        $this->assertSame('"enc:v1:preserved"', $this->tokenRow());
        $this->assertSame(['autofill_token' => $label . '. The secret was not saved.'], $service->save(['autofill_timeout' => '20'], ['autofill_token' => 'placeholder-token']));
        $this->assertContains('apotheke: ' . $phrase . '; stored secrets will not be saved.', $this->logged);
        $this->assertNotContains('apotheke: set APP_KEY to encrypt stored secrets', $this->logged);
        $this->assertNotSame('20', (new SettingsRepository($this->database->db))->rows()['autofill_timeout'] ?? null, 'nothing of that save is written');
        // Clearing writes no secret, so it still works.
        $service->clearAutofillToken();
        $this->assertSame('""', $this->tokenRow());
    }

    private const KEY_A = 'placeholder-key-a-32-bytes-long!';

    private const KEY_B = 'placeholder-key-b-32-bytes-long!';

    private function box(string $appKey): SecretBox
    {
        return SecretBox::fromEnvironment(['APP_KEY' => $appKey], [], function (string $message): void {
            $this->logged[] = $message;
        });
    }

    /** Every insert into settings fails, as a broken disk or constraint would. */
    private function failSettingsInsert(): void
    {
        $db = $this->database->db;
        if ($db->DBDriver === 'Postgre') {
            $db->query("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            $db->query('CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON settings FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()');

            return;
        }
        $db->query("CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON settings BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    }

    private function tokenRow(): string
    {
        return (string)((new SettingsRepository($this->database->db))->rows()['autofill_token'] ?? '');
    }

    private function service(string $driver): SettingsService
    {
        $this->database = TestDatabase::create($driver);

        return $this->fresh();
    }

    /** @param array<string,mixed> $config */
    private function fresh(array $config = [], ?ActorGate $actor = null, ?SecretBox $secrets = null): SettingsService
    {
        return new SettingsService(
            new SettingsRepository($this->database->db),
            new SettingsSchema(),
            new LocalBrandStore($this->database->dataDir() . '/brand'),
            new Clock(),
            $config + $this->database->config->values(),
            function (string $message): void {
                $this->logged[] = $message;
            },
            $actor,
            $secrets,
        );
    }
}
