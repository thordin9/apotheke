<?php

declare(strict_types=1);

namespace Tests\Modules\Backup;

use Apotheke\Auth\CurrentUser;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\UserRepository;
use Apotheke\Backup\Backups;
use Apotheke\Backup\Controllers\Api\BackupsController;
use Apotheke\Backup\Restorer;
use Apotheke\Backup\StoredBackups;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MemoryR2;
use Tests\Support\TestDatabase;

/**
 * The backups the installation keeps: the one made before each restore, in parts under backups/ on local disk or
 * object storage, the oldest beyond the limit deleted, and the list and download routes behind the backup permission.
 */
final class StoredBackupsTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @var array<array-key,mixed> */
    private array $requestSession = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        foreach ($this->files as $f) {
            @unlink($f);
        }
        $this->database?->drop();
        $this->database = null;
        $this->requestSession = $this->files = [];
        Services::reset(true);
        parent::tearDown();
    }

    /** @return iterable<string,array{0:string}> */
    public static function stores(): iterable
    {
        yield 'local disk' => ['local'];
        yield 'object storage' => ['r2'];
    }

    #[DataProvider('stores')]
    public function testABackupIsKeptInPartsAndReadBackWhole(string $where): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->seed('Lamp');
        [$store, $r2] = $this->store($where);
        $stored = $this->stored($store, 5, 1000);
        $m = $stored->store(StoredBackups::KIND_PRE_RESTORE, 'ada');
        $this->assertMatchesRegularExpression('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $m['id']);
        $this->assertSame(['pre-restore', 'ada'], [$m['kind'], $m['by']]);
        $this->assertGreaterThan(1, $m['parts'], 'several parts');
        $zip = implode('', iterator_to_array($stored->bytes($m), false));
        $this->assertSame($m['size'], strlen($zip));
        $this->assertSame($m['sha256'], hash('sha256', $zip));
        $this->assertSame([$m], $stored->all());
        $path = $this->files[] = sys_get_temp_dir() . '/apotheke-kept-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, $zip);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($path));
        $this->assertStringContainsString('"Lamp"', (string)$z->getFromName('backup.json'));
        $z->close();
        if ($r2 !== null) {
            $this->assertSame('backups/' . $m['id'] . '/manifest.json', $r2->keys('backups/')[0]);
            $this->assertCount($m['parts'] + 1, $r2->keys('backups/' . $m['id'] . '/'));
        } else {
            $this->assertFileExists($this->database->dataDir() . '/backups/' . $m['id'] . '/manifest.json');
        }
    }

    #[DataProvider('stores')]
    public function testOnlyTheNewestAreKept(string $where): void
    {
        $this->database = TestDatabase::create('sqlite');
        [$store, $r2] = $this->store($where);
        $stored = $this->stored($store, 2);
        $ids = [];
        foreach (['2026-11-10 10:00:00', '2026-11-11 10:00:00', '2026-11-12 10:00:00'] as $at) {
            putenv('APOTHEKE_CLOCK=' . $at);
            $ids[] = $stored->store(StoredBackups::KIND_PRE_RESTORE, null)['id'];
        }
        $this->assertSame([$ids[2], $ids[1]], array_column($stored->all(), 'id'), 'newest first, the oldest deleted');
        $this->assertNull($stored->get($ids[0]));
        if ($r2 !== null) {
            $this->assertSame([], $r2->keys('backups/' . $ids[0] . '/'), 'its parts too');
        } else {
            $this->assertDirectoryDoesNotExist($this->database->dataDir() . '/backups/' . $ids[0]);
        }
    }

    public function testABackupThatCannotBeMadeKeepsNothing(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $r2 = new MemoryR2();
        $failing = new class ($r2->storage()) implements Storage {
            public function __construct(private readonly Storage $inner)
            {
            }

            public function read(string $key): ?string
            {
                return $this->inner->read($key);
            }

            public function write(string $key, string $bytes): void
            {
                if (str_ends_with($key, 'manifest.json')) {
                    throw new \RuntimeException('full');
                }
                $this->inner->write($key, $bytes);
            }

            public function writeStream(string $key, mixed $stream): void
            {
                $this->inner->writeStream($key, $stream);
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }

            public function modifiedAt(string $key): ?int
            {
                return $this->inner->modifiedAt($key);
            }
        };
        try {
            $this->stored($failing, 5)->store(StoredBackups::KIND_PRE_RESTORE, null);
            $this->fail('the backup was kept');
        } catch (UserError $e) {
            $this->assertSame(StoredBackups::FAILED, $e->getMessage());
        }
        $this->assertSame([], $r2->keys('backups/'));
        $this->assertSame([], glob($this->database->dataDir() . '/tmp/stored-*') ?: []);
    }

    /** A restore first keeps the catalog it replaces, and its activity row names who restored and that backup. */
    public function testARestoreKeepsTheCatalogBeforeItAndLogsWhoRestored(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->seed('Incoming');
        $zip = $this->files[] = sys_get_temp_dir() . '/apotheke-in-' . bin2hex(random_bytes(4)) . '.zip';
        $this->backups()->create($zip);
        $this->database->db->query("UPDATE items SET name = 'Before'");
        $stored = $this->stored(new LocalStorage($this->database->dataDir()), 5);
        $restorer = $this->restorer($stored);
        $restorer->restore($zip, false, null, null, 'ada');
        $before = $restorer->backupBefore();
        $this->assertNotNull($before);
        $this->assertSame('ada', $before['by']);
        $this->assertSame(['Incoming'], array_column($this->database->db->query('SELECT name FROM items')->getResultArray(), 'name'));
        $row = $this->database->db->query("SELECT item_id, item_name, details, actor FROM activity WHERE action = ?", [Restorer::RESTORED_ACTION])->getResultArray();
        $this->assertSame([['item_id' => null, 'item_name' => Restorer::CATALOG, 'details' => '{"items":1,"backup":"' . $before['id'] . '"}', 'actor' => 'ada']], $row);

        // The kept backup is the catalog as it was: restoring it brings "Before" back.
        $kept = $this->files[] = sys_get_temp_dir() . '/apotheke-kept-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($kept, implode('', iterator_to_array($stored->bytes($before), false)));
        $this->restorer(null)->restore($kept, false);
        $this->assertSame(['Before'], array_column($this->database->db->query('SELECT name FROM items')->getResultArray(), 'name'));
    }

    public function testARestoreIsNotStartedWhenTheCatalogCannotBeBackedUp(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->seed('Incoming');
        $zip = $this->files[] = sys_get_temp_dir() . '/apotheke-in-' . bin2hex(random_bytes(4)) . '.zip';
        $this->backups()->create($zip);
        $this->database->db->query("UPDATE items SET name = 'Before'");
        // A data folder that cannot be made (a file is in its place): no temp file, so no backup.
        file_put_contents($this->database->dataDir() . '/blocked', '');
        $broken = new Backups($this->database->db, new Clock(), $this->settings(), $this->database->dataDir() . '/blocked/data', $this->database->config->originalsDir());
        try {
            $this->restorer(new StoredBackups(new LocalStorage($this->database->dataDir()), $broken, new Clock(), 5))->restore($zip, false);
            $this->fail('the restore ran without a backup');
        } catch (UserError $e) {
            $this->assertSame(StoredBackups::FAILED, $e->getMessage());
        }
        $this->assertSame(['Before'], array_column($this->database->db->query('SELECT name FROM items')->getResultArray(), 'name'));
    }

    /** The list and the download: the backup permission. Everyone else gets 403; the guest and nobody are refused. */
    public function testTheListAndDownloadNeedTheBackupPermission(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $ada = $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true, 'auth_guest_disabled' => false]);
        $routes = ['api/v1/backups', 'api/v1/backups/20261112-100000-abcdef'];
        foreach ($routes as $route) {
            $this->assertContains($this->filter($route)?->getStatusCode(), [401, 303], 'the guest: ' . $route);
        }
        foreach (self::ROLES_WITHOUT_BACKUP as $role) {
            $this->signInAs($this->account('u-' . $role, $role));
            foreach ($routes as $route) {
                $this->assertSame(403, $this->filter($route)?->getStatusCode(), $role . ' ' . $route);
            }
        }
        $this->signInAs($ada);
        foreach ($routes as $route) {
            $this->assertNull($this->filter($route), 'admin ' . $route);
        }

        // Through the controller: the list, then one download, then an unknown id.
        $stored = $this->stored(new LocalStorage($this->database->dataDir()), 5);
        Services::injectMock('storedBackups', $stored);
        $m = $stored->store(StoredBackups::KIND_PRE_RESTORE, 'ada');
        $list = json_decode((string)$this->controller()->index()->getBody(), true);
        $this->assertSame(5, $list['keep']);
        $this->assertSame([$m['id']], array_column($list['backups'], 'id'));
        $this->assertSame('/api/v1/backups/' . $m['id'], $list['backups'][0]['download']);
        $dl = $this->controller()->show($m['id']);
        $this->assertSame(200, $dl->getStatusCode());
        $this->assertSame('application/zip', $dl->getHeaderLine('Content-Type'));
        $this->assertSame((string)$m['size'], $dl->getHeaderLine('Content-Length'));
        $this->assertSame(404, $this->controller()->show('20200101-000000-000000')->getStatusCode());
        $this->assertSame(404, $this->controller()->show('../x')->getStatusCode());
    }

    /** The roles that may not download or list kept backups. */
    private const ROLES_WITHOUT_BACKUP = ['editor', 'viewer'];

    private function controller(): BackupsController
    {
        $c = new BackupsController();
        $c->initController(Services::request(null, false), Services::response(null, false), Services::logger());

        return $c;
    }

    private function filter(string $route): ?\CodeIgniter\HTTP\ResponseInterface
    {
        $config = clone config(\Config\App::class);
        $request = (new \CodeIgniter\HTTP\IncomingRequest($config, new \CodeIgniter\HTTP\SiteURI($config, $route), null, new \CodeIgniter\HTTP\UserAgent()))->withMethod('GET');

        return (new \Apotheke\Auth\Filters\PermissionFilter())->before($request);
    }

    private function seed(string $name): void
    {
        $this->database?->db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES (?, 1, '', 'x', '2026-01-01 00:00:00', '2026-01-01 00:00:00')", [$name]);
    }

    /** @return array{0:Storage,1:?MemoryR2} */
    private function store(string $where): array
    {
        if ($where === 'r2') {
            $r2 = new MemoryR2();

            return [$r2->storage(), $r2];
        }

        return [new LocalStorage((string)$this->database?->dataDir()), null];
    }

    private function stored(Storage $store, int $keep, int $partBytes = StoredBackups::PART_BYTES): StoredBackups
    {
        return new StoredBackups($store, $this->backups(), new Clock(), $keep, $partBytes);
    }

    private function backups(): Backups
    {
        $d = $this->database ?? throw new \RuntimeException('no database');

        return new Backups($d->db, new Clock(), $this->settings(), $d->dataDir(), $d->config->originalsDir());
    }

    private function restorer(?StoredBackups $stored): Restorer
    {
        $d = $this->database ?? throw new \RuntimeException('no database');
        $undo = new UndoTokens($d->db, new Clock(), 600, static function (string $f): void {
        }, static function (string $f): void {
        });
        $known = static fn (): array => array_keys((new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available());

        return new Restorer($d->db, $this->backups(), $this->settings(), $undo, $known, new LocalStorage($d->dataDir()),
            new LocalStorage($d->config->originalsDir(), 0640), 256, new RequestCleanup(), null, 0, $stored);
    }

    private function wire(): void
    {
        Services::injectMock('settings', $this->settings());
        Services::injectMock('currentUser', new CurrentUser(fn (): SettingsService => $this->settings(), new UserRepository($this->database->db), fn (): array => $this->requestSession));
        Services::injectMock('roles', new RoleRepository($this->database->db));
    }

    private function settings(): SettingsService
    {
        $d = $this->database ?? throw new \RuntimeException('no database');

        return new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($d->dataDir())), new Clock(),
            $d->config->values(), static fn (string $m): null => null, new CommandLine());
    }

    private function account(string $name, string $role): int
    {
        $db = $this->database?->db ?? throw new \RuntimeException('no database');
        $db->table('users')->insert(['username' => $name, 'username_key' => $name, 'password_hash' => password_hash('long-enough', PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);

        return (int)($db->table('users')->select('id')->where('username_key', $name)->get()->getRowArray()['id'] ?? 0);
    }

    private function signInAs(int $id): void
    {
        $user = (new UserRepository($this->database?->db ?? throw new \RuntimeException('no database')))->find($id);
        $this->assertNotNull($user);
        $this->requestSession = ['uid' => $id, 'auth_epoch' => (new SessionEpoch())->of($user)];
    }
}
