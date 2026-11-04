<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\RestoreStage;
use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;
use ZipArchive;

/**
 * RestoreStage (restore on the edge, P4-2b): staged copies swapped in by one batch, in transaction mode
 * (ConnectionExecutor) and batch mode (LocalBatchExecutor, the D1 stand-in) on SQLite and PostgreSQL.
 */
final class RestoreStageTest extends CIUnitTestCase
{
    /** @var list<TestDatabase> */
    private array $databases = [];

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ' transaction' => [$driver, false];
            yield $driver . ' batch' => [$driver, true];
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->databases as $d) {
            $d->drop();
        }
        $this->databases = [];
        parent::tearDown();
    }

    /**
     * The same rows, ids and sequences as the source, with a child location staged before its parent (deferred
     * foreign keys) and history past the last item; the copies are gone after drop().
     */
    #[DataProvider('modes')]
    public function testASwapRestoresTheSameRowsIdsAndSequences(string $driver, bool $batch): void
    {
        $src = $this->source($driver);
        $dst = $this->target($driver);
        $stage = new RestoreStage(self::exec($dst, $batch));
        [$tables, $staged, $withId] = $this->stageAll($stage, $src);
        $stage->swap($tables, $staged, $withId, 'run-1', str_repeat('a', 64), '2026-11-01 18:00:00');
        $this->assertSame($src['dump'], $this->dump($dst));
        $this->assertTrue($stage->applied('run-1'));
        $this->assertFalse($stage->applied('run-0'));
        $this->assertSame(array_map(RestoreStage::copy(...), Backups::tables()), $this->copies($dst), 'the swap leaves the copies to drop()');
        if ($driver === 'sqlite') {
            $seq = $dst->query("SELECT name, seq, typeof(seq) AS t FROM sqlite_sequence WHERE name IN ('items', 'locations', 'activity') ORDER BY name")->getResultArray();
            $this->assertSame([['name' => 'activity', 'seq' => '11', 't' => 'text'], ['name' => 'items', 'seq' => '40', 't' => 'text'], ['name' => 'locations', 'seq' => '5', 't' => 'text']], $seq);
        }
        $dst->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Next', 1, '', 'next', '2026-11-01 18:00:00', '2026-11-01 18:00:00')");
        $dst->query("INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('Next', 'next', 0, '2026-11-01 18:00:00')");
        $this->assertSame([41, 6], [(int)$dst->query("SELECT id FROM items WHERE name = 'Next'")->getRowArray()['id'], (int)$dst->query("SELECT id FROM locations WHERE name = 'Next'")->getRowArray()['id']], 'new ids come after the restored ones and the history');
    }

    /** Stopped before the swap: nothing changed, the run is not applied, and its leftover copies are dropped. */
    #[DataProvider('modes')]
    public function testAStopBeforeTheSwapChangesNothing(string $driver, bool $batch): void
    {
        $src = $this->source($driver);
        $dst = $this->target($driver);
        $before = $this->dump($dst);
        $stage = new RestoreStage(self::exec($dst, $batch));
        [$tables, $staged, $withId] = $this->stageAll($stage, $src);
        $this->assertSame(array_map(RestoreStage::copy(...), Backups::tables()), $this->copies($dst), 'the copies are left over');
        $this->assertSame($before, $this->dump($dst));
        $this->assertFalse($stage->applied('run-1'));
        // staging stopped part way (a copy holds fewer rows than staged): the swap's guard refuses it
        $dst->query('DELETE FROM ' . RestoreStage::copy('items') . ' WHERE id = 9');
        try {
            $stage->swap($tables, $staged, $withId, 'run-1', str_repeat('a', 64), '2026-11-01 18:00:00');
            $this->fail('a short copy must not be swapped in');
        } catch (WriteFailed $e) {
            $this->assertSame(BatchExecutor::GUARD_FAILED, $e->getCode());
        }
        $this->assertSame($before, $this->dump($dst));
        $this->assertFalse($stage->applied('run-1'));
        $stage->drop();
        $this->assertSame([], $this->copies($dst));
    }

    /** Stopped after the swap (copies not dropped yet): the run is applied; the next begin() starts from empty copies. */
    #[DataProvider('modes')]
    public function testAStopAfterTheSwapIsApplied(string $driver, bool $batch): void
    {
        $src = $this->source($driver);
        $dst = $this->target($driver);
        $stage = new RestoreStage(self::exec($dst, $batch));
        [$tables, $staged, $withId] = $this->stageAll($stage, $src);
        $stage->swap($tables, $staged, $withId, 'run-1', str_repeat('a', 64), '2026-11-01 18:00:00');
        $this->assertTrue($stage->applied('run-1'));
        $this->assertCount(count(Backups::tables()), $this->copies($dst));
        $stage->begin(['items']);
        $this->assertSame([RestoreStage::copy('items')], $this->copies($dst));
        $this->assertSame([], $dst->query('SELECT * FROM ' . RestoreStage::copy('items'))->getResultArray());
        $stage->swap(['items'], ['items' => ['columns' => ['id', 'name', 'created_at', 'updated_at'], 'rows' => 0]], ['items'], 'run-2', str_repeat('b', 64), '2026-11-01 18:05:00');
        $this->assertSame([false, true], [$stage->applied('run-1'), $stage->applied('run-2')], 'only the last swap is marked');
        $stage->drop();
        $this->assertSame([], $this->copies($dst));
    }

    /** Refused before staging when the current data and the copies would pass the limit (0 is none). */
    #[DataProvider('drivers')]
    public function testThePreflightRefusesWhatDoesNotFit(string $driver): void
    {
        foreach ([[100, 50, 0, true], [100, 50, 150, true], [100, 50, 149, false], [50, 100, 200, true], [50, 100, 199, false]] as [$live, $staged, $limit, $fits]) {
            try {
                RestoreStage::preflight($live, $staged, $limit);
                $this->assertTrue($fits, "$live + $staged within $limit");
            } catch (UserError $e) {
                $this->assertFalse($fits, "$live + $staged within $limit");
                $this->assertSame([RestoreStage::TOO_LARGE, 413], [$e->getMessage(), $e->getCode()]);
            }
        }
        $db = $this->target($driver);
        $this->assertGreaterThan(0, RestoreStage::liveBytes($db));
    }

    /** The copies' prefix names no real table, and only carried tables and plain column names are staged. */
    #[DataProvider('drivers')]
    public function testOnlyCarriedTablesArePrefixedCopies(string $driver): void
    {
        $db = $this->target($driver);
        $names = self::tables($db);
        $this->assertContains('items', $names);
        $this->assertSame([], array_values(array_filter($names, static fn (string $n): bool => str_starts_with($n, RestoreStage::PREFIX))));
        $stage = new RestoreStage(new LocalBatchExecutor($db));
        foreach ([static fn () => $stage->begin(['restore_marker']), static fn () => RestoreStage::copy('jobs'), static fn () => $stage->stage('items', ['name; DROP'], [['x']]), static fn () => $stage->stage('items', ['name'], [['x', 'y']])] as $i => $f) {
            try {
                $f();
                $this->fail('case ' . $i . ' must be refused');
            } catch (LogicException) {
            }
        }
    }

    /** Leftover copies of a stopped restore are not carried: a backup made next to them neither names nor holds them. */
    #[DataProvider('drivers')]
    public function testABackupLeavesTheCopiesOut(string $driver): void
    {
        $d = TestDatabase::create($driver);
        $this->databases[] = $d;
        (new RestoreStage(new LocalBatchExecutor($d->db)))->begin(Backups::tables());
        $d->db->query('INSERT INTO ' . RestoreStage::copy('items') . " (id, name, quantity, notes, search, created_at, updated_at) VALUES (7, 'Staged', 1, '', 'staged', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
        $clock = new Clock();
        $settings = new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore($d->dataDir() . '/brand'), $clock, $d->config->values(), static fn (string $m): null => null, new CommandLine());
        $zipPath = $d->dataDir() . '/backup.zip';
        (new Backups($d->db, $clock, $settings, $d->dataDir(), $d->config->originalsDir()))->create($zipPath);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $json = (string)$zip->getFromName('backup.json');
        $zip->close();
        $this->assertStringContainsString('"items"', $json);
        $this->assertStringNotContainsString(RestoreStage::PREFIX, $json);
        $this->assertStringNotContainsString('Staged', $json);
        $this->assertStringNotContainsString('restore_marker', $json);
    }

    /**
     * The largest dataset (100k rows) on the D1 stand-in: staged in statements of one bind each, no larger than
     * CHUNK_BYTES, and swapped in by one batch.
     */
    #[DataProvider('drivers')]
    public function testAHundredThousandRowsAreStagedInChunksAndSwappedAtOnce(string $driver): void
    {
        $db = $this->target($driver);
        $exec = new class (new LocalBatchExecutor($db)) implements SqlExecutor {
            /** @var list<int> statements per run() */
            public array $runs = [];

            public int $largest = 0;

            public function __construct(private readonly SqlExecutor $inner)
            {
            }

            public function run(array $statements): array
            {
                $this->runs[] = count($statements);
                foreach ($statements as $s) {
                    $this->largest = max($this->largest, count($s['params']), ...array_map(static fn (mixed $p): int => is_string($p) ? strlen($p) : 0, $s['params'] ?: [0]));
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
                return null;
            }
        };
        $stage = new RestoreStage($exec);
        $cols = ['id', 'item_id', 'item_name', 'action', 'details', 'created_at'];
        $rows = (static function (): \Generator {
            for ($i = 1; $i <= 100_000; $i++) {
                yield [$i, $i % 500, 'Item ' . $i, 'edited', '{"field":"quantity","from":1,"to":2}', '2026-11-01 18:00:00'];
            }
        })();
        $stage->begin(['activity']);
        $this->assertSame(100_000, $stage->stage('activity', $cols, $rows));
        $chunks = count($exec->runs) - 2;
        $this->assertGreaterThanOrEqual(15, $chunks, 'staged in many statements');
        $this->assertLessThanOrEqual(RestoreStage::CHUNK_BYTES + 2, $exec->largest);
        $stage->swap(Backups::tables(), ['activity' => ['columns' => $cols, 'rows' => 100_000]], ['activity'], 'run-big', str_repeat('c', 64), '2026-11-01 18:00:00');
        $this->assertSame(['n' => 100_000, 'm' => 100_000], array_map('intval', $db->query('SELECT COUNT(*) AS n, MAX(id) AS m FROM activity')->getRowArray()));
        $stage->drop();
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function target(string $driver): BaseConnection
    {
        $d = TestDatabase::create($driver);
        $this->databases[] = $d;
        $d->db->query("INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('Old', 'old', 1, '2025-01-01 00:00:00')");
        $d->db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Old', 1, '', 'old', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");

        return $d->db;
    }

    /**
     * The rows to restore: location 2 is a child of location 5 (staged first, so its parent comes later), and the
     * history names item 40, past the last item. Read from a database that is dropped before the target is made
     * (two open PostgreSQL test databases would share one link): per carried table its columns and rows, and its
     * dump().
     *
     * @return array{tables:array<string,array{columns:list<string>,rows:list<list<scalar|null>>}>,dump:array<string,list<array<string,string|null>>>}
     */
    private function source(string $driver): array
    {
        $d = TestDatabase::create($driver);
        $at = "'2026-01-02 08:00:00'";
        foreach ([
            "INSERT INTO locations (id, name, name_key, is_default, created_at, parent_id) VALUES (5, 'Shed', 'shed', 0, $at, NULL)",
            "INSERT INTO locations (id, name, name_key, is_default, created_at, parent_id) VALUES (2, 'Shelf', 'shelf', 0, $at, 5)",
            "INSERT INTO categories (id, name, name_key, is_default, created_at) VALUES (3, 'Tools', 'tools', 1, $at)",
            "INSERT INTO items (id, name, location_id, quantity, notes, search, created_at, updated_at, category_id, value_cents) VALUES (4, 'Saw ''n'' file', 2, 2, 'ü \"quoted\" \\ back', 'saw', $at, $at, 3, 1999)",
            "INSERT INTO items (id, name, location_id, quantity, notes, search, created_at, updated_at, category_id, value_cents) VALUES (9, 'Lamp', 5, 1, '', 'lamp', $at, $at, NULL, NULL)",
            "INSERT INTO tags (id, name, name_key, sort_key, created_at) VALUES (6, 'red', 'red', 'red', $at)",
            'INSERT INTO item_tags (item_id, tag_id) VALUES (4, 6)',
            'INSERT INTO item_tags (item_id, tag_id) VALUES (9, 6)',
            "INSERT INTO activity (id, item_id, item_name, action, details, created_at) VALUES (11, 40, 'Gone', 'deleted', '{}', $at)",
        ] as $sql) {
            $d->db->query($sql);
        }
        $tables = [];
        foreach (Backups::tables() as $t) {
            $cols = self::columns($d->db, $t);
            $order = $t === 'locations' ? ' ORDER BY id DESC' : '';   // the child before its parent
            $tables[$t] = ['columns' => $cols, 'rows' => array_map(array_values(...), $d->db->query('SELECT ' . implode(', ', $cols) . " FROM $t$order")->getResultArray())];
        }
        $out = ['tables' => $tables, 'dump' => $this->dump($d->db)];
        $d->drop();

        return $out;
    }

    /**
     * @param array{tables:array<string,array{columns:list<string>,rows:list<list<scalar|null>>}>} $src
     * @return array{0:list<string>,1:array<string,array{columns:list<string>,rows:int}>,2:list<string>}
     */
    private function stageAll(RestoreStage $stage, array $src): array
    {
        $tables = Backups::tables();
        $stage->begin($tables);
        [$staged, $withId] = [[], []];
        foreach ($tables as $t) {
            $cols = $src['tables'][$t]['columns'];
            $staged[$t] = ['columns' => $cols, 'rows' => $stage->stage($t, $cols, $src['tables'][$t]['rows'])];
            if (in_array('id', $cols, true)) {
                $withId[] = $t;
            }
        }

        return [$tables, $staged, $withId];
    }

    /** @return array<string,list<array<string,string|null>>> every carried table, ordered, values as text */
    private function dump(BaseConnection $db): array
    {
        $out = [];
        foreach (Backups::tables() as $t) {
            $cols = self::columns($db, $t);
            $order = in_array('id', $cols, true) ? 'id' : implode(', ', $cols);
            $out[$t] = array_map(static fn (array $r): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, $r), $db->query("SELECT * FROM $t ORDER BY $order")->getResultArray());
        }

        return $out;
    }

    /** @return list<string> the staging copies there are, by name */
    private function copies(BaseConnection $db): array
    {
        $names = array_values(array_filter(self::tables($db), static fn (string $n): bool => str_starts_with($n, RestoreStage::PREFIX)));
        $order = array_map(RestoreStage::copy(...), Backups::tables());

        return array_values(array_filter($order, static fn (string $n): bool => in_array($n, $names, true)));
    }

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @return list<string> the tables of the test database (its own schema on PostgreSQL)
     */
    private static function tables(BaseConnection $db): array
    {
        $sql = $db->DBDriver === 'Postgre'
            ? 'SELECT table_name AS n FROM information_schema.tables WHERE table_schema = current_schema()'
            : "SELECT name AS n FROM sqlite_master WHERE type = 'table'";

        return array_map(static fn (array $r): string => strtolower((string)$r['n']), $db->query($sql)->getResultArray());
    }

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @return list<string>
     */
    private static function columns(BaseConnection $db, string $table): array
    {
        return $db->query("SELECT * FROM $table WHERE 1 = 0")->getFieldNames();
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    private static function exec(BaseConnection $db, bool $batch): SqlExecutor
    {
        return $batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db);
    }
}
