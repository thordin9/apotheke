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
        $lines = iterator_to_array(new CsvExport($this->database->db)->lines('EUR'), false);
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
        $pdo->exec("INSERT INTO items (name, quantity, search, sort_key, created_at, updated_at) VALUES ('a', 1, '', 'a', '2026-01-01', '2026-01-01')");
        $export = new CsvExport($db);

        $all = $export->lines('EUR');
        $this->assertStringStartsWith("\xEF\xBB\xBFid,name,", (string)$all->current());
        if ($driver === 'pgsql') {
            // A cursor, not one pg_query() result that libpq holds whole.
            $this->assertSame([['name' => 'apotheke_export']], $db->query('SELECT name FROM pg_cursors')->getResultArray());
        }
        $pdo->exec("INSERT INTO items (name, quantity, search, sort_key, created_at, updated_at) VALUES ('b', 1, '', 'b', '2026-01-01', '2026-01-01')");
        $rest = iterator_to_array((static function (\Generator $g): \Generator {
            for ($g->next(); $g->valid(); $g->next()) {
                yield $g->current();
            }
        })($all), false);
        if ($driver === 'pgsql') {
            $this->assertCount(1, $rest, 'the snapshot was taken when the export started');
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
