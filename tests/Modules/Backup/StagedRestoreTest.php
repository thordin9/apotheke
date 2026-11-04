<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\RestoreStage;
use Apotheke\Backup\Restorer;
use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * Restorer's staged path (P4-2b part 2): a backup restored through RestoreStage in transaction mode
 * (ConnectionExecutor) and batch mode (LocalBatchExecutor, the D1 stand-in) on SQLite and PostgreSQL, with the
 * pre-flight on the call path, a stop before and after the swap, the lock, and the sweep of leftover copies.
 */
final class StagedRestoreTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    private string $zip = '';

    /** @var array<string,list<array<string,string|null>>> the source's carried tables */
    private array $source = [];

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ' transaction' => [$driver, false];
            yield $driver . ' batch' => [$driver, true];
        }
    }

    protected function tearDown(): void
    {
        if ($this->zip !== '') {
            @unlink($this->zip);
        }
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /** The same rows, ids, sequences and parent_id as the source; no copy and no lock left; the run is marked. */
    #[DataProvider('modes')]
    public function testAStagedRestoreGivesTheSourcesRowsAndIds(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $out = $this->restorer($db, self::exec($db, $batch))->restore($this->zip, false, 'run-a');
        $this->assertSame(['locations' => 2, 'items' => 2, 'activity' => 1], array_intersect_key($out['tables'], ['locations' => 0, 'items' => 0, 'activity' => 0]));
        $this->assertSame($this->source, $this->dump($db));
        $this->assertSame([], $this->copies($db));
        $this->assertSame([['run' => 'run-a', 'sha256' => hash_file('sha256', $this->zip)]], $db->query('SELECT run, sha256 FROM restore_marker')->getResultArray(), 'marked, no lock row');
        $db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Next', 1, '', 'next', '2026-11-01 18:00:00', '2026-11-01 18:00:00')");
        $this->assertSame(41, (int)$db->query("SELECT id FROM items WHERE name = 'Next'")->getRowArray()['id'], 'past the restored ids and the history');
    }

    /** A stop before the swap changes nothing; one after it is applied. Either way the copies go and the lock is free. */
    #[DataProvider('modes')]
    public function testAStopBeforeOrAfterTheSwap(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $before = $this->dump($db);
        foreach (['before' => false, 'after' => true] as $when => $applied) {
            $exec = self::stopping(self::exec($db, $batch), $when);
            try {
                $this->restorer($db, $exec)->restore($this->zip, false, 'run-' . $when);
                $this->fail('the restore was not stopped');
            } catch (UserError $e) {
                $this->assertSame(Restorer::FAILED, $e->getMessage());
            }
            $this->assertSame($applied ? $this->source : $before, $this->dump($db), $when);
            $this->assertSame($applied, (new RestoreStage(self::exec($db, $batch)))->applied('run-' . $when), $when);
            $this->assertSame([], $this->copies($db), $when);
            $this->assertSame([], $db->query("SELECT run FROM restore_marker WHERE run = 'lock'")->getResultArray(), $when);
        }
    }

    /** N-1703: the limit is checked on the call path, on the staged size, before anything is staged. */
    #[DataProvider('modes')]
    public function testThePreflightRefusesBeforeAnythingIsStaged(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $before = $this->dump($db);
        $live = (new RestoreStage(self::exec($db, $batch)))->liveBytes();
        try {
            $this->restorer($db, self::exec($db, $batch), $live)->restore($this->zip, false);
            $this->fail('over the limit');
        } catch (UserError $e) {
            $this->assertSame([RestoreStage::TOO_LARGE, 413], [$e->getMessage(), $e->getCode()]);
        }
        $this->assertSame($before, $this->dump($db));
        $this->assertSame([], $this->copies($db));
        $this->assertSame([], $db->query('SELECT run FROM restore_marker')->getResultArray());
        $this->restorer($db, self::exec($db, $batch), $live + 4 * 1024 * 1024)->restore($this->zip, false);
        $this->assertSame($this->source, $this->dump($db), 'within the limit');
    }

    /** L-1700: while another staged restore holds the lock, a restore is refused (409) and changes nothing. */
    #[DataProvider('modes')]
    public function testARestoreIsRefusedWhileAnotherHoldsTheLock(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $before = $this->dump($db);
        $other = new RestoreStage(self::exec($db, $batch));
        $other->begin(['items']);
        try {
            $this->restorer($db, self::exec($db, $batch))->restore($this->zip, false);
            $this->fail('two staged restores at once');
        } catch (UserError $e) {
            $this->assertSame([Restorer::BUSY, 409], [$e->getMessage(), $e->getCode()]);
        }
        $this->assertSame($before, $this->dump($db));
        $this->assertSame([RestoreStage::copy('items')], $this->copies($db), 'the other restore keeps its copy');
    }

    /** N-1705: the upload sweep drops a stopped restore's copies once its lock expired, never a running one's. */
    #[DataProvider('modes')]
    public function testTheUploadSweepDropsLeftoverCopies(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        (new RestoreStage(self::exec($db, $batch)))->begin(Backups::tables());
        $at = time();
        $uploads = new RestoreUploads($this->database->dataDir(), 4, 100, static fn (): string => 'user:1', 8, null, null,
            static function () use ($db, $batch, &$at): RestoreStage {
                return new RestoreStage(self::exec($db, $batch), static fn (): int => $at);
            });
        $uploads->sweep();
        $this->assertCount(count(Backups::tables()), $this->copies($db), 'a running restore keeps its copies');
        $at += RestoreStage::LEASE + 1;
        $uploads->sweep();
        $this->assertSame([], $this->copies($db));
        $this->assertSame([], $db->query('SELECT run FROM restore_marker')->getResultArray(), 'the lock is free again');
    }

    /** A confirm found stopped on a staged backend: restored when its swap committed, else failed with nothing changed. */
    #[DataProvider('modes')]
    public function testAStoppedConfirmIsResolvedFromTheMarker(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $uploads = new RestoreUploads($this->database->dataDir(), 4, 100, static fn (): string => 'user:1', 8, null, null,
            static fn (): RestoreStage => new RestoreStage(self::exec($db, $batch)));
        foreach ([true, false] as $swapped) {
            [$id] = $uploads->create(3, null);   // one upload per account: the next one replaces this one
            $file = $this->database->dataDir() . '/uploads/restore/' . $id . '/manifest.json';
            file_put_contents($file, (string)json_encode(['status' => 'applying', 'fingerprint' => null] + (array)json_decode((string)file_get_contents($file), true)));
            if ($swapped) {
                $db->query('INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ?)', [$id, str_repeat('a', 64), '2026-11-01 18:00:00']);
            }
            $m = $uploads->get($id);
            $this->assertSame($swapped ? ['restored', [RestoreUploads::APPLIED_AFTER_STOP]] : ['failed', RestoreUploads::NOT_APPLIED],
                [$m['status'], $swapped ? ($m['result']['warnings'] ?? null) : ($m['result']['error'] ?? null)]);
        }
    }

    /** N-1704: a backup naming a column twice is refused by the staged path and the one-transaction path alike. */
    #[DataProvider('modes')]
    public function testAColumnNamedTwiceIsRefusedOnEveryPath(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $before = $this->dump($db);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($this->zip) === true);
        $b = json_decode((string)$zip->getFromName('backup.json'), true, 64, JSON_THROW_ON_ERROR);
        $b['tables']['items']['columns'][] = 'id';
        foreach ($b['tables']['items']['rows'] as $i => $row) {
            $b['tables']['items']['rows'][$i][] = $row[0] + 100;
        }
        $zip->addFromString('backup.json', (string)json_encode($b));
        $zip->close();
        foreach ([self::exec($db, $batch), null] as $exec) {
            try {
                $this->restorer($db, $exec)->restore($this->zip, false, 'run-twice');
                $this->fail('a column named twice must be refused');
            } catch (UserError $e) {
                $this->assertSame('Column items.id is named twice in backup.', $e->getMessage());
            }
            $this->assertSame($before, $this->dump($db));
            $this->assertSame([], $this->copies($db));
        }
    }

    /**
     * The target, with other rows, and $this->zip: a backup of a source where location 2 is a child of location 5
     * (a parent with a higher id) and the history names item 40, past the last item. The source is dropped first
     * (two open PostgreSQL test databases would share one link).
     *
     * @return BaseConnection<object|resource, object|resource>
     */
    private function target(string $driver): BaseConnection
    {
        $src = TestDatabase::create($driver);
        $at = "'2026-01-02 08:00:00'";
        foreach ([
            "INSERT INTO locations (id, name, name_key, is_default, created_at, parent_id) VALUES (5, 'Shed', 'shed', 0, $at, NULL)",
            "INSERT INTO locations (id, name, name_key, is_default, created_at, parent_id) VALUES (2, 'Shelf', 'shelf', 0, $at, 5)",
            "INSERT INTO items (id, name, location_id, quantity, notes, search, created_at, updated_at) VALUES (4, 'Saw ''n'' file', 2, 2, 'ü \"q\" \\ b', 'saw', $at, $at)",
            "INSERT INTO items (id, name, location_id, quantity, notes, search, created_at, updated_at) VALUES (9, 'Lamp', 5, 1, '', 'lamp', $at, $at)",
            "INSERT INTO activity (id, item_id, item_name, action, details, created_at) VALUES (11, 40, 'Gone', 'deleted', '{}', $at)",
        ] as $sql) {
            $src->db->query($sql);
        }
        $this->zip = sys_get_temp_dir() . '/apotheke-staged-' . bin2hex(random_bytes(4)) . '.zip';
        $this->backups($src)->create($this->zip);
        $this->source = $this->dump($src->db);
        $src->drop();
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $db->query("INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('Old', 'old', 1, '2025-01-01 00:00:00')");
        $db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Old', 1, '', 'old', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");

        return $db;
    }

    private function backups(TestDatabase $d): Backups
    {
        return new Backups($d->db, new Clock(), $this->settings($d), $d->dataDir(), $d->config->originalsDir());
    }

    private function settings(TestDatabase $d): SettingsService
    {
        return new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore($d->dataDir() . '/brand'), new Clock(), $d->config->values(), static fn (string $m): null => null, new CommandLine());
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    private function restorer(BaseConnection $db, ?SqlExecutor $exec, int $maxBytes = 0): Restorer
    {
        $d = $this->database ?? throw new RuntimeException('no target');
        $undo = new UndoTokens($db, new Clock(), 600, static function (string $f): void {
        }, static function (string $f): void {
        });
        $known = static fn (): array => array_keys((new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available());

        return new Restorer($db, $this->backups($d), $this->settings($d), $undo, $known, new LocalStorage($d->dataDir()), new LocalStorage($d->config->originalsDir(), 0640), 256, new RequestCleanup(), $exec, $maxBytes);
    }

    /** $inner, stopped by an exception just before ('before') or just after ('after') the swap batch runs. */
    private static function stopping(SqlExecutor $inner, string $when): SqlExecutor
    {
        return new class ($inner, $when) implements SqlExecutor {
            public function __construct(private readonly SqlExecutor $inner, private readonly string $when)
            {
            }

            public function run(array $statements): array
            {
                $swap = in_array('INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ?)', array_column($statements, 'sql'), true);
                if ($swap && $this->when === 'before') {
                    throw new RuntimeException('stopped before the swap');
                }
                $out = $this->inner->run($statements);
                if ($swap) {
                    throw new RuntimeException('stopped after the swap');
                }

                return $out;
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

    /** @param BaseConnection<object|resource, object|resource> $db */
    private static function exec(BaseConnection $db, bool $batch): SqlExecutor
    {
        return $batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db);
    }

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @return array<string,list<array<string,string|null>>> every carried table, ordered, values as text
     */
    private function dump(BaseConnection $db): array
    {
        $out = [];
        foreach (Backups::tables() as $t) {
            $cols = $db->query("SELECT * FROM $t WHERE 1 = 0")->getFieldNames();
            $order = in_array('id', $cols, true) ? 'id' : implode(', ', $cols);
            $out[$t] = array_map(static fn (array $r): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, $r), $db->query("SELECT * FROM $t ORDER BY $order")->getResultArray());
        }

        return $out;
    }

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @return list<string> the staging copies there are
     */
    private function copies(BaseConnection $db): array
    {
        $sql = $db->DBDriver === 'Postgre'
            ? 'SELECT table_name AS n FROM information_schema.tables WHERE table_schema = current_schema()'
            : "SELECT name AS n FROM sqlite_master WHERE type = 'table'";
        $names = array_map(static fn (array $r): string => strtolower((string)$r['n']), $db->query($sql)->getResultArray());

        return array_values(array_filter(array_map(RestoreStage::copy(...), Backups::tables()), static fn (string $n): bool => in_array($n, $names, true)));
    }
}
