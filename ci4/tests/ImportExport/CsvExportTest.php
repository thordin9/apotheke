<?php

declare(strict_types=1);

namespace Tests\ImportExport;

use Apotheke\ImportExport\CsvExport;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The CSV export's lines (L-370, N-375). Byte parity with legacy, batches
 * included, is tests/Unit/Ci4ImportExportParityTest.php and ExportMemoryTest.
 */
final class CsvExportTest extends CIUnitTestCase
{
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
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /** N-375: a cell starting with TAB or CR (after any apostrophes) gets one more apostrophe. */
    #[DataProvider('drivers')]
    public function testCellsStartingWithTabOrCarriageReturnAreGuarded(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $item = $this->database->pdo()->prepare("INSERT INTO items (name, quantity, notes, serial, barcode, search, sort_key, created_at, updated_at) VALUES (?, 1, ?, ?, ?, '', ?, '2026-01-01', '2026-01-01')");
        $item->execute(["\tTab", "\rCR", "'\tq", "x\t", 'a']);
        $item->execute(["''\rr", 'plain', "'r", "\t", 'b']);
        $lines = iterator_to_array((new CsvExport($this->database->db))->lines('EUR'), false);
        $this->assertCount(3, $lines);
        $this->assertSame([
            self::csv(['1', "'\tTab", '', '', '1', "'\rCR", '', '2026-01-01', '2026-01-01', '', '', '', '', "''\tq", '', '', '', '', '', "x\t"]),
            self::csv(['2', "'''\rr", '', '', '1', 'plain', '', '2026-01-01', '2026-01-01', '', '', '', '', "'r", '', '', '', '', '', "'\t"]),
        ], array_slice($lines, 1));
    }

    /**
     * The items query runs when the generator starts, so a failing read
     * throws in the controller; on PostgreSQL the snapshot ends with the
     * last line and also when the export stops early.
     */
    #[DataProvider('drivers')]
    public function testTheReadStartsWithTheGeneratorAndTheSnapshotEnds(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $pdo = $this->database->pdo();
        $item = $pdo->prepare("INSERT INTO items (name, quantity, search, sort_key, created_at, updated_at) VALUES (?, 1, '', ?, '2026-01-01', '2026-01-01')");
        for ($i = 1; $i <= CsvExport::BATCH + 1; $i++) {
            $item->execute(['a' . $i, sprintf('a%04d', $i)]);
        }
        $pdo->exec("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('t', 't', 't', '2026-01-01')");
        $export = new CsvExport($db);

        $all = $export->lines('EUR');
        $this->assertStringStartsWith("\xEF\xBB\xBFid,name,", (string)$all->current());
        if ($driver === 'pgsql') {
            // A cursor, not one pg_query() result that libpq holds whole.
            $this->assertSame([['name' => 'apotheke_export']], $db->query('SELECT name FROM pg_cursors')->getResultArray());
        }
        // Another client adds an item and tags one in the second batch, whose tags are read later.
        $pdo->exec("INSERT INTO items (name, quantity, search, sort_key, created_at, updated_at) VALUES ('b', 1, '', 'b', '2026-01-01', '2026-01-01')");
        $pdo->exec('INSERT INTO item_tags (item_id, tag_id) SELECT ' . (CsvExport::BATCH + 1) . ', id FROM tags');
        $rest = iterator_to_array((static function (\Generator $g): \Generator {
            for ($g->next(); $g->valid(); $g->next()) {
                yield $g->current();
            }
        })($all), false);
        if ($driver === 'pgsql') {
            $this->assertCount(CsvExport::BATCH + 1, $rest, 'the snapshot was taken when the export started');
            $this->assertStringNotContainsString(',t,', implode('', $rest), 'tags read later come from the same snapshot');
        }
        $this->assertSame(0, (int)$db->transDepth);

        $early = $export->lines('EUR');
        $early->current();
        $early->next();
        unset($early);
        $this->assertSame(0, (int)$db->transDepth, 'stopping early ends the snapshot');

        $pdo->exec('ALTER TABLE items RENAME TO items_gone');
        $failed = $export->lines('EUR');
        try {
            $failed->current();
            $this->fail('the items query did not run');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('did not run', $e->getMessage());
        }
        $pdo->exec('ALTER TABLE items_gone RENAME TO items');
    }

    /**
     * N-422: each batch reads the tags of its own items only, so memory does
     * not grow with the number of tagged items.
     */
    #[DataProvider('drivers')]
    public function testEachBatchReadsOnlyItsOwnItemsTags(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $pdo = $this->database->pdo();
        $pdo->exec("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('t', 't', 't', '2026-01-01')");
        $item = $pdo->prepare("INSERT INTO items (name, quantity, search, sort_key, created_at, updated_at) VALUES (?, 1, '', ?, '2026-01-01', '2026-01-01')");
        $n = 2 * CsvExport::BATCH + 1;
        for ($i = 1; $i <= $n; $i++) {
            $item->execute(['a' . $i, sprintf('a%04d', $i)]);
        }
        $pdo->exec('INSERT INTO item_tags (item_id, tag_id) SELECT i.id, t.id FROM items i, tags t');

        $tagQueries = [];
        $listener = static function (\CodeIgniter\Database\Query $q) use (&$tagQueries): void {
            if (str_contains($q->getQuery(), 'FROM item_tags')) {
                $tagQueries[] = $q->getQuery();
            }
        };
        \CodeIgniter\Events\Events::on('DBQuery', $listener);
        try {
            $lines = iterator_to_array((new CsvExport($db))->lines('EUR'), false);
        } finally {
            \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
        }
        $this->assertCount($n + 1, $lines);
        $ids = array_map(static fn (string $l): int => (int)strtok($l, ','), array_slice($lines, 1));
        $this->assertStringContainsString(',t,', $lines[$n], 'every item still has its tag');
        $batches = array_chunk($ids, CsvExport::BATCH);
        $this->assertCount(count($batches), $tagQueries, 'one tag query per batch');
        foreach ($tagQueries as $i => $sql) {
            $read = array_map(static fn (array $r): int => (int)$r['item_id'], $db->query($sql)->getResultArray());
            sort($read);
            $batch = $batches[$i];
            sort($batch);
            $this->assertSame($batch, $read, 'batch ' . $i . ' reads its own items\' tags');
        }
    }

    /**
     * N-421: on PostgreSQL the export's transaction bounds its statements and
     * the wait between two batches; the settings end with it.
     */
    public function testThePostgresSnapshotIsBounded(): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL is not configured');
        }
        $this->database = TestDatabase::create('pgsql');
        $db = $this->database->db;
        $this->database->pdo()->exec("INSERT INTO items (name, quantity, search, sort_key, created_at, updated_at) VALUES ('a', 1, '', 'a', '2026-01-01', '2026-01-01')");
        $show = static fn (string $name): string => (string)$db->query('SHOW ' . $name)->getRow(0, 'array')[$name];
        $before = [$show('statement_timeout'), $show('idle_in_transaction_session_timeout')];
        $this->assertNotSame(['1min', '5min'], $before, 'the server defaults differ from the bounds');

        $lines = (new CsvExport($db))->lines('EUR');
        $lines->current();
        $this->assertSame(['1min', '5min'], [$show('statement_timeout'), $show('idle_in_transaction_session_timeout')], 'set while the snapshot is open');
        $this->assertSame([CsvExport::PG_STATEMENT_TIMEOUT, CsvExport::PG_IDLE_TIMEOUT], ['60s', '5min']);
        $rows = 0;
        for ($lines->next(); $lines->valid(); $lines->next()) {
            $rows++;
        }
        $this->assertSame(1, $rows);
        $this->assertSame($before, [$show('statement_timeout'), $show('idle_in_transaction_session_timeout')], 'they end with the snapshot');
    }

    /** @param list<string> $cells */
    private static function csv(array $cells): string
    {
        $fh = fopen('php://memory', 'r+');
        self::assertNotFalse($fh);
        fputcsv($fh, $cells, ',', '"', '\\');
        rewind($fh);

        return (string)stream_get_contents($fh);
    }
}
