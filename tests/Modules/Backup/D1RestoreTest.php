<?php

declare(strict_types=1);

namespace Tests\Modules\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\RestoreFence;
use Apotheke\Backup\RestoreMutation;
use Apotheke\Backup\RestoreStage;
use Apotheke\Backup\RestoreUnit;
use Apotheke\Backup\Restorer;
use Apotheke\Backup\StoredBackups;
use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LeaseDenied;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\MemoryR2;
use Tests\Support\TestDatabase;

/**
 * Restore on the hosted database (the D1 driver over the Installation handler): the preview, the fence, the staged
 * load and the one-batch swap, a stop that leaves the catalog as it was, and backups carried between PDO and D1.
 */
final class D1RestoreTest extends CIUnitTestCase
{
    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    /** @var list<TestDatabase> */
    private array $databases = [];

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $ok = trim((string)shell_exec("node -e \"try{require('node:sqlite');console.log('y')}catch{}\" 2>/dev/null")) === 'y';
        if (!$ok || !is_dir(dirname(__DIR__, 3) . '/edge/node_modules')) {
            $this->markTestSkipped('needs node with node:sqlite and edge/node_modules');
        }
        putenv('APOTHEKE_CLOCK=2026-11-12 10:00:00');
    }

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        Services::reset(true);
        foreach ($this->files as $f) {
            @unlink($f);
        }
        foreach ($this->databases as $d) {
            $d->drop();
        }
        $this->databases = $this->files = [];
        parent::tearDown();
    }

    /** @return iterable<string,array{0:string}> the PDO drivers this run has */
    public static function pdoDrivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            if ($driver !== 'd1') {
                yield $driver => [$driver];
            }
        }
    }

    /** A new hosted installation starts with the built-in roles' new defaults, as a new PDO one does. */
    public function testAFreshHostedInstallStartsWithTheNewRoleDefaults(): void
    {
        $this->databases[] = $d = TestDatabase::create('d1');
        $roles = new \Apotheke\Auth\RoleRepository($d->db);
        $this->assertSame([true, true, true, false, false, false], [$roles->has('editor', 'backup'), $roles->has('editor', 'restore'),
            $roles->has('viewer', 'backup'), $roles->has('viewer', 'restore'), $roles->has('guest', 'backup'), $roles->has('guest', 'restore')]);
    }

    public function testThePreviewOfAHostedRestoreReadsTheBackupAndChangesNothing(): void
    {
        [$zip, $source] = $this->backupOf('sqlite');
        [$d, $r2] = $this->hosted();
        $before = self::dump($d->db);
        $opened = $this->restorer($d, $r2)->open($zip);
        $opened['zip']->close();
        $this->assertSame('sqlite', $opened['driver']);
        $this->assertSame(2, $opened['tables']['items']);
        $this->assertSame(1, $opened['photos']);
        $this->assertSame($before, self::dump($d->db));
        $this->assertNotSame($source, $before);
    }

    /** PDO to D1: the rows, the photo object and the sequences; no copy, no lock and no fence row left. */
    #[DataProvider('pdoDrivers')]
    public function testAPdoBackupRestoresOnTheHostedDatabase(string $driver): void
    {
        [$zip, $source] = $this->backupOf($driver);
        [$d, $r2] = $this->hosted();
        $out = $this->restorer($d, $r2)->restore($zip, false, 'run-pdo');
        $this->assertSame(2, $out['tables']['items']);
        $this->assertSame($source, self::dump($d->db));
        $this->assertArrayHasKey('images/' . self::PHOTO, $r2->objects);
        $this->assertSame([], self::copies($d->db));
        $this->assertSame([['run' => 'run-pdo']], $d->db->query('SELECT run FROM restore_marker')->getResultArray(), 'marked; no lock or fence row');
        $d->db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Next', 1, '', 'next', '2026-11-12 10:00:00', '2026-11-12 10:00:00')");
        $this->assertSame(41, (int)$d->db->query("SELECT id FROM items WHERE name = 'Next'")->getRowArray()['id'], 'past the restored ids and the history');
    }

    /** D1 to PDO and D1 to D1: a hosted backup is the same catalog wherever it is restored. */
    #[DataProvider('pdoDrivers')]
    public function testAHostedBackupRestoresOnPdoAndOnTheHostedDatabase(string $driver): void
    {
        [$zip, $source] = $this->backupOf('d1');
        $pdo = $this->databases[] = TestDatabase::create($driver);
        $this->restorer($pdo, null)->restore($zip, false);
        $this->assertSame($source, self::dump($pdo->db));
        $this->assertFileExists($pdo->dataDir() . '/images/' . self::PHOTO);

        [$d, $r2] = $this->hosted();
        $this->restorer($d, $r2)->restore($zip, true, 'run-d1');
        $this->assertSame($source, self::dump($d->db));
        $this->assertSame('Restored shed', $this->settings($d)->get('app_name'), 'settings, in the guarded settings unit');
    }

    /** On the hosted database the catalog before a restore is kept under backups/ in object storage, and restores. */
    public function testAHostedRestoreKeepsTheCatalogBeforeItInObjectStorage(): void
    {
        [$zip] = $this->backupOf('sqlite');
        [$d, $r2] = $this->hosted();
        $before = self::dump($d->db);
        $stored = new StoredBackups($r2->storage(), $this->backups($d, $r2), new Clock(), 5);
        $restorer = $this->restorer($d, $r2, null, $stored);
        $restorer->restore($zip, false, 'run-kept', null, 'ada');
        $kept = $restorer->backupBefore();
        $this->assertNotNull($kept);
        $this->assertContains('backups/' . $kept['id'] . '/manifest.json', $r2->keys('backups/'));
        $this->assertSame(1, (int)$d->db->query('SELECT COUNT(*) AS n FROM activity WHERE action = ? AND actor = ?', [Restorer::RESTORED_ACTION, 'ada'])->getRowArray()['n']);

        $file = $this->files[] = sys_get_temp_dir() . '/apotheke-d1-kept-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($file, implode('', iterator_to_array($stored->bytes($kept), false)));
        $pdo = $this->databases[] = TestDatabase::create('sqlite');
        $this->restorer($pdo, null)->restore($file, false);
        $this->assertSame($before, self::dump($pdo->db), 'the catalog as it was before the restore');
    }

    /**
     * The hosted object's SQLite refuses the page_count pragma: the size check measures the values instead, and only
     * when RESTORE_DB_MAX_MB is set.
     */
    public function testTheSizeCheckWorksOnTheHostedDatabase(): void
    {
        [$zip, $source] = $this->backupOf('sqlite');
        [$d, $r2] = $this->hosted();
        $before = self::dump($d->db);
        try {
            $this->restorer($d, $r2, null, null, 1)->restore($zip, false, 'run-small');
            $this->fail('over the limit');
        } catch (UserError $e) {
            $this->assertSame([RestoreStage::TOO_LARGE, 413], [$e->getMessage(), $e->getCode()]);
        }
        $this->assertSame($before, self::dump($d->db));
        $live = (new RestoreStage($this->exec($d)))->liveBytes();
        $this->assertGreaterThan(0, $live);
        $this->restorer($d, $r2, null, null, $live + 4 * 1024 * 1024)->restore($zip, false, 'run-fits');
        $this->assertSame($source, self::dump($d->db));
    }

    /** A failed restore gives the catalog lease up: restores/unit is left expired and the next restore runs. */
    public function testAFailedRestoreReleasesItsLeaseAndTheNextOneRuns(): void
    {
        [$zip, $source] = $this->backupOf('sqlite');
        [$d, $r2] = $this->hosted();
        try {
            $this->restorer($d, $r2, self::stopping($this->exec($d)))->restore($zip, false, 'run-stop');
            $this->fail('the restore was not stopped');
        } catch (UserError $e) {
            $this->assertSame(Restorer::FAILED, $e->getMessage());
        }
        $this->assertSame(['token' => '', 'expires' => 0], json_decode($r2->objects[RestoreUnit::KEY]['bytes'], true), 'released, not left held');
        $this->restorer($d, $r2)->restore($zip, false, 'run-next');
        $this->assertSame($source, self::dump($d->db));
        $this->assertSame(['token' => '', 'expires' => 0], json_decode($r2->objects[RestoreUnit::KEY]['bytes'], true));
    }

    /** A failed restore writes one server log line: the step and the class of each cause, no backup data. */
    public function testAFailedRestoreLogsItsStepAndCauses(): void
    {
        [$zip] = $this->backupOf('sqlite');
        [$d, $r2] = $this->hosted();
        $lines = [];
        try {
            $this->restorer($d, $r2, self::stopping($this->exec($d)), null, 0, static function (string $l) use (&$lines): void {
                $lines[] = $l;
            })->restore($zip, false, 'run-log');
            $this->fail('the restore was not stopped');
        } catch (UserError $e) {
            $this->assertSame(Restorer::FAILED, $e->getMessage());
        }
        $this->assertSame(['[apotheke] restore failed at swap: RuntimeException'], $lines);
    }

    /** A confirm that fails on the hosted database answers 422 on the hosted page: no command-line restore hint. */
    public function testAFailedConfirmShowsTheHostedPage(): void
    {
        [$zip] = $this->backupOf('sqlite');
        [$d, $r2] = $this->hosted();
        $token = bin2hex(random_bytes(16));
        $backups = $this->backups($d, $r2);
        @mkdir($backups->tmpDir(), 0770, true);
        copy($zip, $backups->tmpDir() . '/restore-' . $token . '.zip');
        Services::injectMock('settings', $this->settings($d));
        Services::injectMock('currentUser', new \Apotheke\Auth\CurrentUser(fn (): SettingsService => $this->settings($d), new \Apotheke\Auth\UserRepository($d->db), static fn (): array => []));
        Services::injectMock('roles', new \Apotheke\Auth\RoleRepository($d->db));
        Services::injectMock('backups', $backups);
        Services::injectMock('restorer', $this->restorer($d, $r2, self::stopping($this->exec($d))));
        $request = Services::request(null, false);
        $request->setGlobal('post', ['token' => $token, 'confirm' => '1']);
        $c = new \Apotheke\Backup\Controllers\BackupController();
        $c->initController($request, Services::response(null, false), Services::logger());
        $response = $c->confirm();
        $html = (string)$response->getBody();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(Restorer::FAILED, $html);
        $this->assertStringNotContainsString('php bin/restore.php', $html);
    }

    /** A stop before the swap leaves the rows and the objects as they were; the copies, lock and fence go. */
    public function testAStopBeforeTheSwapLeavesTheHostedCatalogIntact(): void
    {
        [$zip] = $this->backupOf('sqlite');
        [$d, $r2] = $this->hosted();
        $before = self::dump($d->db);
        $objects = $r2->keys('images/');
        try {
            $this->restorer($d, $r2, self::stopping($this->exec($d)))->restore($zip, false, 'run-stop');
            $this->fail('the restore was not stopped');
        } catch (UserError $e) {
            $this->assertSame(Restorer::FAILED, $e->getMessage());
        }
        $this->assertSame($before, self::dump($d->db));
        $this->assertSame($objects, $r2->keys('images/'), 'the photo it wrote is removed again');
        $this->assertSame([], $r2->keys('thumbs/'));
        $this->assertSame([], self::copies($d->db));
        $this->assertSame([], $d->db->query('SELECT run FROM restore_marker')->getResultArray());
    }

    /** claim() is one batch on D1; the newest claim owns the fence and an older token is refused. */
    public function testTheFenceIsClaimedAndCheckedInBatchesOnTheHostedDatabase(): void
    {
        [$d] = $this->hosted();
        RestoreFence::claim($d->db, 'one');
        RestoreFence::claim($d->db, 'two');
        $this->assertSame([['run' => RestoreFence::RUN, 'sha256' => 'two']], $d->db->query('SELECT run, sha256 FROM restore_marker')->getResultArray());
        RestoreFence::rejectUnless($d->db, 'two');
        $this->expectException(LeaseDenied::class);
        RestoreFence::rejectUnless($d->db, 'one');
    }

    /** replace() on D1: written while the token owns the fence; refused, the object kept, once another claimed it. */
    public function testAMapPointerIsReplacedOnlyWhileTheFenceIsOwned(): void
    {
        [$d, $r2] = $this->hosted();
        $store = $r2->storage()->forOriginals();
        RestoreFence::claim($d->db, 'mine');
        RestoreMutation::replace($d->db, 'mine', $store, 'map/a', 'first');
        RestoreMutation::replace($d->db, 'mine', $store, 'map/a', 'second');
        $this->assertSame('second', $store->read('map/a'));
        RestoreFence::claim($d->db, 'theirs');
        try {
            RestoreMutation::replace($d->db, 'mine', $store, 'map/a', 'stale');
            $this->fail('a stale token wrote');
        } catch (LeaseDenied) {
        }
        $this->assertSame('second', $store->read('map/a'));
    }

    /** The staged load is chunked to D1's value limit; the swap is one batch and refused whole when it would not fit. */
    public function testTheStagedLoadIsChunkedAndTheSwapIsOneBatch(): void
    {
        $this->assertLessThan(RestoreStage::MAX_VALUE_BYTES, RestoreStage::CHUNK_BYTES);
        [$d] = $this->hosted();
        $batches = [];
        $exec = self::recording($this->exec($d), $batches);
        $stage = new RestoreStage($exec);
        $stage->begin(['tags']);
        $rows = (static function (): \Generator {
            for ($i = 1; $i <= 2500; $i++) {
                yield [$i, 'tag ' . $i . ' ' . str_repeat('x', 300), 'tag ' . $i, '2026-01-01 00:00:00'];
            }
        })();
        $this->assertSame(2500, $stage->stage('tags', ['id', 'name', 'name_key', 'created_at'], $rows));
        $loads = array_values(array_filter($batches, static fn (array $b): bool => str_starts_with($b[1]['sql'], 'INSERT INTO ' . RestoreStage::copy('tags'))));
        $this->assertGreaterThan(2, count($loads), 'several chunks');
        foreach ($loads as $b) {
            $this->assertLessThanOrEqual(RestoreStage::CHUNK_BYTES + 2, strlen((string)$b[1]['params'][0]));
            $this->assertLessThanOrEqual(RestoreStage::CHUNK_ROWS, count(json_decode((string)$b[1]['params'][0], true)));
        }
        $stage->finish();

        RestoreStage::oneBatch(array_fill(0, RestoreStage::MAX_BATCH_STATEMENTS, ['sql' => 'SELECT 1', 'params' => []]));
        try {
            RestoreStage::oneBatch(array_fill(0, RestoreStage::MAX_BATCH_STATEMENTS + 1, ['sql' => 'SELECT 1', 'params' => []]));
            $this->fail('an oversized swap was not refused');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        RestoreStage::oneBatch([['sql' => 'SELECT ?', 'params' => [str_repeat('x', RestoreStage::MAX_BATCH_BYTES)]]]);
    }

    public function testARowLargerThanTheHostedValueLimitIsRefusedBeforeTheSwap(): void
    {
        [$d] = $this->hosted();
        $stage = new RestoreStage($this->exec($d));
        $stage->begin(['tags']);
        try {
            $stage->stage('tags', ['id', 'name', 'name_key', 'created_at'], [[1, str_repeat('x', RestoreStage::MAX_VALUE_BYTES), 'k', '2026-01-01 00:00:00']]);
            $this->fail('the row was staged');
        } catch (UserError $e) {
            $this->assertSame(RestoreStage::ROW_TOO_LARGE, $e->getMessage());
        } finally {
            $stage->finish();
        }
        $this->assertSame(0, (int)$d->db->query('SELECT COUNT(*) AS n FROM tags')->getRowArray()['n']);
    }

    /**
     * A backup of a source on $driver with a photo, a child location before its parent, history past the last
     * item and one setting; its zip and the source's carried tables.
     *
     * @return array{0:string,1:array<string,list<array<string,string|null>>>}
     */
    private function backupOf(string $driver): array
    {
        $src = $this->databases[] = TestDatabase::create($driver);
        $at = "'2026-01-02 08:00:00'";
        foreach ([
            "INSERT INTO locations (id, name, name_key, is_default, created_at, parent_id) VALUES (5, 'Shed', 'shed', 0, $at, NULL)",
            "INSERT INTO locations (id, name, name_key, is_default, created_at, parent_id) VALUES (2, 'Shelf', 'shelf', 0, $at, 5)",
            "INSERT INTO items (id, image, name, location_id, quantity, notes, search, created_at, updated_at) VALUES (4, '" . self::PHOTO . "', 'Saw ''n'' file', 2, 2, 'ü \"q\" \\ b', 'saw', $at, $at)",
            "INSERT INTO items (id, name, location_id, quantity, notes, search, created_at, updated_at) VALUES (9, 'Lamp', 5, 1, '', 'lamp', $at, $at)",
            "INSERT INTO item_photos (item_id, file, position, created_at) VALUES (4, '" . self::PHOTO . "', 0, $at)",
            "INSERT INTO activity (id, item_id, item_name, action, details, created_at) VALUES (11, 40, 'Gone', 'deleted', '{}', $at)",
        ] as $sql) {
            $this->assertNotFalse($src->db->query($sql), $sql);
        }
        $this->settings($src)->store(['app_name' => 'Restored shed']);
        $jpeg = self::jpeg();
        $storage = null;
        if ($driver === 'd1') {
            $storage = new MemoryR2(['images/' . self::PHOTO => $jpeg, 'thumbs/' . self::PHOTO => $jpeg]);
        } else {
            @mkdir($src->dataDir() . '/images', 0770, true);
            @mkdir($src->dataDir() . '/thumbs', 0770, true);
            file_put_contents($src->dataDir() . '/images/' . self::PHOTO, $jpeg);
            file_put_contents($src->dataDir() . '/thumbs/' . self::PHOTO, $jpeg);
        }
        $zip = $this->files[] = sys_get_temp_dir() . '/apotheke-d1-restore-' . bin2hex(random_bytes(4)) . '.zip';
        $this->assertSame([], $this->backups($src, $storage)->create($zip)['missing']);

        return [$zip, self::dump($src->db)];
    }

    /** @return array{0:TestDatabase,1:MemoryR2} a hosted target with other rows, and its object storage */
    private function hosted(): array
    {
        $d = $this->databases[] = TestDatabase::create('d1');
        $d->db->query("INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('Old', 'old', 1, '2025-01-01 00:00:00')");
        $d->db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Old', 1, '', 'old', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");

        return [$d, new MemoryR2(['images/old.jpg' => 'old'])];
    }

    private function exec(TestDatabase $d): SqlExecutor
    {
        return new D1HttpExecutor($d->clientEnv()['D1_TEST_URL']);
    }

    private function settings(TestDatabase $d): SettingsService
    {
        return new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($d->dataDir())), new Clock(),
            $d->config->values(), static fn (string $m): null => null, new CommandLine());
    }

    private function backups(TestDatabase $d, ?MemoryR2 $r2): Backups
    {
        return $r2 === null
            ? new Backups($d->db, new Clock(), $this->settings($d), $d->dataDir(), $d->config->originalsDir())
            : new Backups($d->db, new Clock(), $this->settings($d), $d->dataDir(), $d->config->originalsDir(), $r2->storage(), $r2->storage()->forOriginals());
    }

    /** A restorer for $d: on D1 with $r2 and the staged executor (or $exec), else on local disk. */
    private function restorer(TestDatabase $d, ?MemoryR2 $r2, ?SqlExecutor $exec = null, ?StoredBackups $stored = null, int $limit = 0, ?\Closure $log = null): Restorer
    {
        $undo = new UndoTokens($d->db, new Clock(), 600, static function (string $f): void {
        }, static function (string $f): void {
        });
        $known = static fn (): array => array_keys((new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available());
        /** @var array{0:Storage,1:Storage} $stores */
        $stores = $r2 === null ? [new LocalStorage($d->dataDir()), new LocalStorage($d->config->originalsDir(), 0640)] : [$r2->storage(), $r2->storage()->forOriginals()];

        return new Restorer($d->db, $this->backups($d, $r2), $this->settings($d), $undo, $known, $stores[0], $stores[1], 256, new RequestCleanup(),
            $d->driver === 'd1' ? ($exec ?? $this->exec($d)) : null, $limit, $stored, $log);
    }

    private static function jpeg(): string
    {
        $im = imagecreatetruecolor(8, 6);
        ob_start();
        imagejpeg($im);

        return (string)ob_get_clean();
    }

    /** $inner, stopped just before the swap batch runs. */
    private static function stopping(SqlExecutor $inner): SqlExecutor
    {
        $batches = [];

        return self::recording($inner, $batches, true);
    }

    /**
     * $inner, noting each batch in $batches; with $stop, the swap batch throws before it is sent.
     *
     * @param list<list<array{sql:string,params:list<scalar|null>}>> $batches
     */
    private static function recording(SqlExecutor $inner, array &$batches, bool $stop = false): SqlExecutor
    {
        return new class ($inner, $batches, $stop) implements SqlExecutor {
            /** @param list<list<array{sql:string,params:list<scalar|null>}>> $batches */
            public function __construct(private readonly SqlExecutor $inner, private array &$batches, private readonly bool $stop)
            {
            }

            public function run(array $statements): array
            {
                $this->batches[] = $statements;
                if ($this->stop && preg_grep('/^INSERT INTO restore_marker \(run, sha256, applied_at\) VALUES/', array_column($statements, 'sql')) !== []) {
                    throw new RuntimeException('stopped before the swap');
                }

                return $this->inner->run($statements);
            }

            public function query(string $sql, array $params = []): array
            {
                return $this->inner->query($sql, $params);
            }

            public function dialect(): Dialect
            {
                return $this->inner->dialect();
            }

            public function connection(): ?BaseConnection
            {
                return $this->inner->connection();
            }
        };
    }

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @return array<string,list<array<string,string|null>>> every carried table, ordered, values as text
     */
    private static function dump(BaseConnection $db): array
    {
        $out = [];
        foreach (Backups::tables() as $t) {
            $rows = $db->query("SELECT * FROM $t ORDER BY 1, 2")->getResultArray();
            if ($t === 'app_settings') {
                $rows = array_map(static function (array $r): array {
                    unset($r['updated_at']);

                    return $r;
                }, $rows);
            }
            $out[$t] = array_map(static fn (array $r): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, $r), $rows);
        }

        return $out;
    }

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @return list<string> the staging copies there are
     */
    private static function copies(BaseConnection $db): array
    {
        $names = array_map(static fn (array $r): string => strtolower((string)$r['n']), $db->query("SELECT name AS n FROM sqlite_master WHERE type = 'table'")->getResultArray());

        return array_values(array_filter(array_map(RestoreStage::copy(...), Backups::tables()), static fn (string $n): bool => in_array($n, $names, true)));
    }
}
