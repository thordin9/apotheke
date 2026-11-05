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
        $stage->swap($tables, $staged, $withId, 'run-1', str_repeat('a', 64));
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
            $stage->swap($tables, $staged, $withId, 'run-1', str_repeat('a', 64));
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
        $stage->swap($tables, $staged, $withId, 'run-1', str_repeat('a', 64));
        $this->assertTrue($stage->applied('run-1'));
        $this->assertCount(count(Backups::tables()), $this->copies($dst));
        $stage->begin(['items']);
        $this->assertSame([RestoreStage::copy('items')], $this->copies($dst));
        $this->assertSame([], $dst->query('SELECT * FROM ' . RestoreStage::copy('items'))->getResultArray());
        $stage->swap(['items'], ['items' => ['columns' => ['id', 'name', 'created_at', 'updated_at'], 'rows' => 0]], ['items'], 'run-2', str_repeat('b', 64));
        $this->assertSame([true, true, RestoreStage::REPLACED, RestoreStage::APPLIED], [$stage->applied('run-1'), $stage->applied('run-2'), $stage->outcome('run-1'), $stage->outcome('run-2')], 'L-1707: both kept, the first one replaced');
        $stage->drop();
        $this->assertSame([], $this->copies($dst));
    }

    /**
     * L-1700: two staged restores never swap each other's rows. While A holds the lock, B is refused (409); once A's
     * lock expired and B took it over, A can neither stage, swap nor drop, and B swaps in its own rows.
     */
    #[DataProvider('modes')]
    public function testTwoStagedRestoresCannotSwapEachOthersRows(string $driver, bool $batch): void
    {
        $src = $this->source($driver);
        $dst = $this->target($driver);
        $before = $this->dump($dst);
        $at = time();
        $a = new RestoreStage(self::exec($dst, $batch), static function () use (&$at): int {
            return $at;
        });
        $b = new RestoreStage(self::exec($dst, $batch), static function () use (&$at): int {
            return $at;
        });
        [$tables, $staged, $withId] = $this->stageAll($a, $src);
        try {
            $b->begin($tables);
            $this->fail('a second staged restore while the first holds the lock');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
        $at += RestoreStage::LEASE + 1;
        $b->begin(['items']);
        $mine = [[77, 'B only', 1, '', 'b only', '2026-11-01 18:00:00', '2026-11-01 18:00:00']];
        $this->assertSame(1, $b->stage('items', ['id', 'name', 'quantity', 'notes', 'search', 'created_at', 'updated_at'], $mine));
        foreach ([static fn () => $a->stage('items', ['id', 'name', 'quantity', 'notes', 'search', 'created_at', 'updated_at'], [[78, 'A late', 1, '', 'a', '2026-11-01 18:00:00', '2026-11-01 18:00:00']]),
            static fn () => $a->swap(['items'], ['items' => ['columns' => ['id'], 'rows' => 1]], ['items'], 'run-a', str_repeat('a', 64)),
            static fn () => $a->swap($tables, $staged, $withId, 'run-a', str_repeat('a', 64)),
            static fn () => $a->drop()] as $i => $f) {
            try {
                $f();
                $this->fail('step ' . $i . ' of the restore that lost the lock');
            } catch (WriteFailed $e) {
                $this->assertSame(BatchExecutor::GUARD_FAILED, $e->getCode());
            }
        }
        $this->assertSame($before, $this->dump($dst));
        $this->assertFalse($a->applied('run-a'));
        $a->release();   // not A's any more: B keeps it
        $b->swap(['items'], ['items' => ['columns' => ['id', 'name', 'quantity', 'notes', 'search', 'created_at', 'updated_at'], 'rows' => 1]], ['items'], 'run-b', str_repeat('b', 64));
        $this->assertSame([['id' => '77', 'name' => 'B only']], array_map(static fn (array $r): array => array_map('strval', $r), $dst->query('SELECT id, name FROM items')->getResultArray()));
        $b->finish();
        $this->assertSame([], $this->copies($dst));
        $this->assertSame([['run' => 'run-b']], $dst->query('SELECT run FROM restore_marker')->getResultArray());
    }

    /**
     * L-1708: each staged chunk renews the lock, so staging that runs well past LEASE keeps it; it is taken over
     * only once LEASE has passed since the last chunk, and then the stopped stage can no longer write.
     */
    #[DataProvider('modes')]
    public function testEachChunkRenewsTheLockUntilItIsReallyStale(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $at = time();
        $clock = static function () use (&$at): int {
            return $at;
        };
        $a = new RestoreStage(self::exec($db, $batch), $clock);
        $b = new RestoreStage(self::exec($db, $batch), $clock);
        $a->begin(['items']);
        $cols = ['id', 'name', 'quantity', 'notes', 'search', 'created_at', 'updated_at'];
        for ($i = 1; $i <= 4; $i++) {   // one chunk each, LEASE - 10 seconds apart: 4 x that is well past LEASE
            $at += RestoreStage::LEASE - 10;
            $this->assertRefusedBusy($b);
            $this->assertSame(1, $a->stage('items', $cols, [[$i, 'Row ' . $i, 1, '', 'row', '2026-01-02 08:00:00', '2026-01-02 08:00:00']]));
        }
        $at += RestoreStage::LEASE - 1;
        $this->assertRefusedBusy($b);
        $at += 2;
        $b->claim();
        try {
            $a->stage('items', $cols, [[9, 'Late', 1, '', 'late', '2026-01-02 08:00:00', '2026-01-02 08:00:00']]);
            $this->fail('a stage whose lock went stale and was taken over');
        } catch (WriteFailed $e) {
            $this->assertSame(BatchExecutor::GUARD_FAILED, $e->getCode());
        }
        $b->finish();
    }

    /**
     * N-1711: without a test clock the lease is on the database's clock; N-1710: the sweep only reads when there is
     * nothing to clear or the lock is held, and clears a stale lock with its copies.
     */
    #[DataProvider('modes')]
    public function testTheLeaseUsesTheDatabaseClockAndTheSweepReadsFirst(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $runs = 0;
        $exec = new class (self::exec($db, $batch), $runs) implements SqlExecutor {
            public function __construct(private readonly SqlExecutor $inner, private int &$runs)
            {
            }

            public function run(array $statements): array
            {
                $this->runs++;

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
        $this->assertTrue((new RestoreStage($exec))->sweep());
        $this->assertSame(0, $runs, 'nothing to clear: no write');
        $a = new RestoreStage(self::exec($db, $batch));
        $a->begin(['items']);
        $until = strtotime((string)$db->query("SELECT applied_at FROM restore_marker WHERE run = 'lock'")->getRowArray()['applied_at'] . ' UTC');
        $this->assertEqualsWithDelta(time() + RestoreStage::LEASE, $until, 60);
        $this->assertFalse((new RestoreStage($exec))->sweep());
        $this->assertSame(0, $runs, 'a held lock: no write');
        $db->query("UPDATE restore_marker SET applied_at = '2000-01-01 00:00:00' WHERE run = 'lock'");
        $this->assertTrue((new RestoreStage($exec))->sweep());
        $this->assertSame([], $this->copies($db));
        $this->assertSame([], $db->query('SELECT run FROM restore_marker')->getResultArray());
    }

    private function assertRefusedBusy(RestoreStage $stage): void
    {
        try {
            $stage->claim();
            $this->fail('the lock is still held');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    /**
     * L-1712: the lease is 120 seconds, on the test clock and on the database's: a lock is held 119 seconds after
     * its last write and free at 121, so a much shorter or longer lease fails here.
     */
    #[DataProvider('modes')]
    public function testTheLeaseIsTwoMinutes(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $at = time();
        $clock = static function () use (&$at): int {
            return $at;
        };
        $a = new RestoreStage(self::exec($db, $batch), $clock);
        $b = new RestoreStage(self::exec($db, $batch), $clock);
        $a->begin(['items']);
        $at += 119;
        $this->assertRefusedBusy($b);
        $at += 2;
        $b->claim();
        $b->finish();
        (new RestoreStage(self::exec($db, $batch)))->begin(['items']);
        $lock = $db->query("SELECT applied_at FROM restore_marker WHERE run = 'lock'")->getRowArray();
        $this->assertEqualsWithDelta(time() + 120, strtotime((string)$lock['applied_at'] . ' UTC'), 5, 'on the database clock');
    }

    /**
     * L-1713: runs are ordered by the database, not by applied_at (whole seconds): of two runs swapped in the same
     * second the later one is applied and the earlier replaced, and the pruning keeps the newest KEEP. applied_at is
     * the database's time.
     */
    #[DataProvider('modes')]
    public function testRunsInTheSameSecondKeepTheirOrder(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $at = strtotime('2026-11-01 18:00:00 UTC');
        $stage = new RestoreStage(self::exec($db, $batch), static fn (): int => $at);
        $swap = static function (RestoreStage $stage, string $run): void {
            $stage->begin(['items']);
            $stage->swap(['items'], ['items' => ['columns' => ['id', 'name', 'created_at', 'updated_at'], 'rows' => 0]], ['items'], $run, str_repeat('a', 64));
            $stage->finish();
        };
        $runs = array_map(static fn (int $i): string => sprintf('run-%02d', $i), range(1, RestoreStage::KEEP + 2));
        $swap($stage, $runs[0]);
        $swap($stage, $runs[1]);
        $this->assertSame([RestoreStage::REPLACED, RestoreStage::APPLIED], [$stage->outcome($runs[0]), $stage->outcome($runs[1])]);
        foreach (array_slice($runs, 2) as $run) {
            $swap($stage, $run);
        }
        $kept = array_column($db->query("SELECT run FROM restore_marker WHERE run <> 'lock' ORDER BY run")->getResultArray(), 'run');
        $this->assertSame(array_slice($runs, 2), $kept, 'the newest KEEP runs are kept');
        $this->assertSame([null, RestoreStage::REPLACED, RestoreStage::APPLIED], [$stage->outcome($runs[1]), $stage->outcome($runs[2]), $stage->outcome(end($runs))]);
        $this->assertSame(['2026-11-01 18:00:00'], array_values(array_unique(array_column($db->query('SELECT applied_at FROM restore_marker')->getResultArray(), 'applied_at'))));
        $swap(new RestoreStage(self::exec($db, $batch)), 'run-db');
        $marked = $db->query("SELECT applied_at FROM restore_marker WHERE run = 'run-db'")->getRowArray();
        $this->assertEqualsWithDelta(time(), strtotime((string)$marked['applied_at'] . ' UTC'), 5, 'on the database clock');
    }

    /** L-1701: the swap is one batch: every live table's delete and insert, the sequences and the marker in one run(). */
    #[DataProvider('modes')]
    public function testTheSwapIsOneBatch(string $driver, bool $batch): void
    {
        $src = $this->source($driver);
        $dst = $this->target($driver);
        $exec = self::recording(self::exec($dst, $batch));
        $stage = new RestoreStage($exec);
        [$tables, $staged, $withId] = $this->stageAll($stage, $src);
        $exec->runs = [];
        $stage->swap($tables, $staged, $withId, 'run-1', str_repeat('a', 64));
        $this->assertCount(1, $exec->runs, 'one batch');
        $sql = array_column($exec->runs[0], 'sql');
        foreach ($tables as $t) {
            $this->assertContains('DELETE FROM ' . $t, $sql);
        }
        $this->assertCount(count($tables), preg_grep('/^INSERT INTO (?!uow_guard|restore_marker|sqlite_sequence)\w+ \(.*\) SELECT .* FROM ' . RestoreStage::PREFIX . '/', $sql) ?: []);
        $this->assertCount(count($withId), preg_grep('/^(SELECT setval|INSERT INTO sqlite_sequence)/', $sql) ?: []);
        $this->assertStringStartsWith('INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ', (string)end($sql));
        $this->assertSame($src['dump'], $this->dump($dst));
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
        $this->assertGreaterThan(0, (new RestoreStage(new LocalBatchExecutor($db)))->liveBytes());
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
        foreach ([static fn () => $stage->begin(['restore_marker']), static fn () => RestoreStage::copy('jobs'), static fn () => $stage->stage('items', ['name; DROP'], [['x']]), static fn () => $stage->stage('items', ['Name'], [['x']]), static fn () => $stage->stage('items', ['name'], [['x', 'y']])] as $i => $f) {
            try {
                $f();
                $this->fail('case ' . $i . ' must be refused');
            } catch (LogicException) {
            }
        }
    }

    /**
     * N-1704: stage() takes only the live table's columns, each once (SQLite alone would take `id, id`), and values
     * as Restorer binds them: a boolean as 1 or 0 on both drivers, an array refused. Nothing is staged when refused.
     */
    #[DataProvider('modes')]
    public function testStagedColumnsAndValuesAreCheckedAsRestorerDoes(string $driver, bool $batch): void
    {
        $db = $this->target($driver);
        $stage = new RestoreStage(self::exec($db, $batch));
        $stage->begin(['items']);
        $cols = ['id', 'name', 'quantity', 'notes', 'search', 'created_at', 'updated_at'];
        $row = static fn (int $id, mixed $qty): array => [$id, 'Item ' . $id, $qty, '', 'item', '2026-01-02 08:00:00', '2026-01-02 08:00:00'];
        foreach ([[['id', 'id'], [[1, 2]]], [['id', 'nope'], [[1, 2]]], [[], [[]]]] as $i => [$c, $rows]) {
            try {
                $stage->stage('items', $c, $rows);
                $this->fail('columns ' . $i . ' must be refused');
            } catch (UserError $e) {
                $this->assertSame(RestoreStage::UNKNOWN_COLUMN, $e->getMessage());
            }
        }
        try {
            $stage->stage('items', $cols, [$row(7, ['x'])]);
            $this->fail('an array is not a plain value');
        } catch (UserError $e) {
            $this->assertSame(\Apotheke\Backup\Restorer::FAILED, $e->getMessage());
        }
        $copy = RestoreStage::copy('items');
        $this->assertSame(0, (int)$db->query("SELECT COUNT(*) AS n FROM $copy")->getRowArray()['n'], 'nothing staged');
        $this->assertSame(2, $stage->stage('items', $cols, [$row(7, true), $row(8, false)]));
        $got = $db->query("SELECT id, quantity FROM $copy ORDER BY id")->getResultArray();
        $this->assertSame([['7', '1'], ['8', '0']], array_map(static fn (array $r): array => [(string)$r['id'], (string)$r['quantity']], $got));
        if ($driver === 'sqlite') {
            $this->assertSame(['integer', 'integer'], array_column($db->query("SELECT typeof(quantity) AS t FROM $copy ORDER BY id")->getResultArray(), 't'));
        }
        $stage->drop();
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
     * CHUNK_BYTES and CHUNK_ROWS, and swapped in by one batch.
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
        $stage->swap(Backups::tables(), ['activity' => ['columns' => $cols, 'rows' => 100_000]], ['activity'], 'run-big', str_repeat('c', 64));
        $this->assertSame(['n' => 100_000, 'm' => 100_000], array_map('intval', $db->query('SELECT COUNT(*) AS n, MAX(id) AS m FROM activity')->getRowArray()));
        $stage->drop();
    }

    /**
     * N-1759: stage() also caps by CHUNK_ROWS, and items use a smaller byte budget so a
     * wide table cannot spend the whole D1 request on one statement.
     */
    #[DataProvider('drivers')]
    public function testStageCapsByRowCountAndItemsByteBudget(string $driver): void
    {
        $db = $this->target($driver);
        $exec = new class (new LocalBatchExecutor($db)) implements SqlExecutor {
            /** @var list<int> */
            public array $paramLens = [];

            public function __construct(private readonly SqlExecutor $inner)
            {
            }

            public function run(array $statements): array
            {
                foreach ($statements as $s) {
                    foreach ($s['params'] as $p) {
                        if (is_string($p) && str_starts_with($p, '[')) {
                            $this->paramLens[] = strlen($p);
                        }
                    }
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
        $cols = ['id', 'name', 'quantity', 'notes', 'search', 'created_at', 'updated_at'];
        $at = '2026-01-02 08:00:00';
        // 2501 tiny rows: CHUNK_ROWS=1000 → 3 inserts (1000+1000+501), well under CHUNK_BYTES.
        $rows = (static function () use ($at): \Generator {
            for ($i = 1; $i <= 2501; $i++) {
                yield [$i, 'n' . $i, 1, '', 's', $at, $at];
            }
        })();
        $stage->begin(['items']);
        $this->assertSame(2501, $stage->stage('items', $cols, $rows));
        $jsonChunks = count($exec->paramLens);
        $this->assertSame(3, $jsonChunks, 'CHUNK_ROWS must split 2501 rows into 3 statements');
        $itemsCap = RestoreStage::CHUNK_BYTES_FOR['items'];
        foreach ($exec->paramLens as $len) {
            $this->assertLessThanOrEqual($itemsCap + 2, $len, 'items chunks stay under the items byte cap');
        }
        $stage->finish();
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

    /** @return SqlExecutor&object{runs:list<list<array{sql:string,params:list<scalar|null>}>>} $inner, noting each run() */
    private static function recording(SqlExecutor $inner): SqlExecutor
    {
        return new class ($inner) implements SqlExecutor {
            /** @var list<list<array{sql:string,params:list<scalar|null>}>> */
            public array $runs = [];

            public function __construct(private readonly SqlExecutor $inner)
            {
            }

            public function run(array $statements): array
            {
                $this->runs[] = $statements;

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

    /** @param BaseConnection<object|resource, object|resource> $db */
    private static function exec(BaseConnection $db, bool $batch): SqlExecutor
    {
        return $batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db);
    }
}
