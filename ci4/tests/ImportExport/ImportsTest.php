<?php

declare(strict_types=1);

namespace Tests\ImportExport;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\ImportExport\CsvCells;
use Apotheke\ImportExport\Imports;
use Apotheke\Lending\Loans;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Imports the golden test cannot interleave: a run while another client
 * holds a write, and the same file imported twice at once. The byte-level
 * parity with legacy is tests/Unit/Ci4ImportExportParityTest.php.
 */
final class ImportsTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    private const CSV = "name;location;tags;lent to\nLadder;Shed › Wall;red;\nRope;Garage;;Ann\n";

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

    /**
     * While another client's write is open, a preview and an import wait
     * for it and then go through: the run takes the write lock before its
     * first read (SQLite answered "database is locked").
     */
    #[DataProvider('drivers')]
    public function testARunWaitsForAnotherClientsWrite(string $driver): void
    {
        [$imports, $id] = $this->seed($driver);
        $imp = $imports->get($id);
        $this->assertNotNull($imp);
        $wait = $this->testDb()->holdInAnotherClient([["INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('blue', 'blue', 'blue', '2026-01-01')", []]], 300);
        $preview = $imports->run($imp, false);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame([2, 0, false], [$preview['created'], $preview['errors'], $preview['committed']]);
        $this->assertSame(0, $this->rowsIn('items'));

        $wait = $this->testDb()->holdInAnotherClient([["UPDATE imports SET actor = 'other' WHERE id = ?", [$id]], ["INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('red', 'red', 'red', '2026-01-01')", []]], 300);
        $summary = $imports->commit($imp);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'made' => ['location' => ['Shed', 'Wall', 'Garage'], 'category' => [], 'tag' => []]], $summary);
        $this->assertSame(2, $this->rowsIn('items'));
        $this->assertSame(['done', 'other'], array_values($this->db()->query('SELECT status, actor FROM imports WHERE id = ?', [$id])->getRowArray() ?? []));
        $this->assertSame('Ann', $this->db()->query("SELECT lent_to FROM items WHERE name = 'Rope'")->getRowArray()['lent_to'] ?? null);
        $this->assertFalse(is_file($this->testDb()->dataDir() . '/tmp/import-' . $id . '.csv'), 'the upload is gone');
    }

    /**
     * Two submits of one import at once: the second waits for the first,
     * finds it done and imports nothing (both answer with the done page).
     */
    #[DataProvider('drivers')]
    public function testTheSameFileImportsOnce(string $driver): void
    {
        [$imports, $id] = $this->seed($driver);
        $imp = $imports->get($id);
        $this->assertNotNull($imp);
        $wait = $this->testDb()->holdInAnotherClient([["UPDATE imports SET status = 'done', result = '{}' WHERE id = ?", [$id]]], 300);
        $this->assertNull($imports->commit($imp), 'the other client imported it');
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame(0, $this->rowsIn('items'));

        // Sequentially, with the page's stale copy: the first imports, the second finds it done.
        [$imports, $id] = $this->upload($imports);
        $stale = $imports->get($id);
        $this->assertNotNull($stale);
        $this->assertNotNull($imports->commit($stale));
        $this->assertNull($imports->commit($stale));
        $this->assertSame(2, $this->rowsIn('items'));
        $this->assertSame(1, (int)($this->db()->query("SELECT COUNT(*) AS n FROM activity WHERE item_name = 'Rope' AND action = 'lent'")->getRowArray()['n'] ?? 0));
    }

    /**
     * N-373: a preview that waited for a submit of the same import finds it
     * done, though the submit has taken the file, and previews nothing
     * (the page redirects to the done page) instead of answering 410.
     */
    #[DataProvider('drivers')]
    public function testAPreviewThatWaitedForASubmitFindsItDone(string $driver): void
    {
        [$imports, $id] = $this->seed($driver);
        $imp = $imports->get($id);
        $this->assertNotNull($imp);
        unlink($this->testDb()->dataDir() . '/tmp/import-' . $id . '.csv');   // the submit deletes it after its commit
        $wait = $this->testDb()->holdInAnotherClient([["UPDATE imports SET status = 'done', result = '{}' WHERE id = ?", [$id]]], 300);
        $preview = $imports->run($imp, false);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame([true, false, []], [$preview['done_before'], $preview['committed'], $preview['rows']]);
        $this->assertSame(0, (int)$this->db()->transDepth);
        $this->assertSame(0, $this->rowsIn('items'));
    }

    /** A failed statement in a run rolls back everything, the import stays waiting, and the next run works. */
    #[DataProvider('drivers')]
    public function testAFailedStatementRollsBackTheRun(string $driver): void
    {
        [$imports, $id] = $this->seed($driver);
        $imp = $imports->get($id);
        $this->assertNotNull($imp);
        $pdo = $this->testDb()->pdo();
        $pg = $driver === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        $pdo->exec($pg ? 'CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()'
            : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            $imports->commit($imp);
            $this->fail('the run went through');
        } catch (\Apotheke\Core\Exceptions\WriteFailed) {
        }
        $pdo->exec($pg ? 'DROP TRIGGER apotheke_test_fail ON activity' : 'DROP TRIGGER apotheke_test_fail');
        $this->assertSame([0, 0, 0], [$this->rowsIn('items'), $this->rowsIn('locations'), $this->rowsIn('activity')]);
        $this->assertSame('uploaded', $this->db()->query('SELECT status FROM imports WHERE id = ?', [$id])->getRowArray()['status'] ?? null);
        $this->assertSame(0, (int)$this->db()->transDepth);
        $this->assertNotNull($imports->commit($imp));
        $this->assertSame(2, $this->rowsIn('items'));
    }

    /**
     * N-374: the files of uploads left waiting for more than a day go, and
     * nothing else: not a newer upload, not a done import's leftover, not
     * another file in DATA_DIR/tmp. The rows stay.
     */
    #[DataProvider('drivers')]
    public function testStaleUploadsLoseTheirFiles(string $driver): void
    {
        [$imports, $old] = $this->seed($driver);
        [, $done] = $this->upload($imports);
        [, $fresh] = $this->upload($imports);
        $tmp = $this->testDb()->dataDir() . '/tmp';
        file_put_contents($tmp . '/other.csv', 'x');
        $now = (new Clock())->unix();
        $ago = static fn (int $s): string => gmdate('Y-m-d H:i:s', $now - $s);
        $this->db()->query('UPDATE imports SET created_at = ? WHERE id = ?', [$ago(Imports::STALE_SECONDS + 60), $old]);
        $this->db()->query("UPDATE imports SET created_at = ?, status = 'done' WHERE id = ?", [$ago(Imports::STALE_SECONDS * 3), $done]);
        $this->db()->query('UPDATE imports SET created_at = ? WHERE id = ?', [$ago(Imports::STALE_SECONDS - 60), $fresh]);

        $this->assertSame([$old], $imports->sweep());
        $this->assertFileDoesNotExist($tmp . '/import-' . $old . '.csv');
        foreach (['import-' . $done . '.csv', 'import-' . $fresh . '.csv', 'other.csv'] as $f) {
            $this->assertFileExists($tmp . '/' . $f);
        }
        $this->assertSame([], $imports->sweep(), 'a swept file is not swept again');
        $this->assertSame(3, $this->rowsIn('imports'));
    }

    public function testCellsSurviveTheRoundTrip(): void
    {
        foreach (['=1+2', "'=x", "''@y", '+49', '-', "\tz", "\rz", 'plain', "'plain", '', '@', "a=b"] as $v) {
            $this->assertSame($v, CsvCells::unprotect(CsvCells::protect($v)), json_encode($v));
        }
        $this->assertSame("'=1", CsvCells::protect('=1'));
        $this->assertSame('a=b', CsvCells::protect('a=b'));
        $this->assertSame("'plain", CsvCells::unprotect("'plain"));
        [$header, $rows, $delim] = CsvCells::parse("\xEF\xBB\xBFa\tb\n1\t'=2\n\n");
        $this->assertSame([['a', 'b'], [['1', '=2']], "\t"], [$header, $rows, $delim]);
        $this->assertSame('Café', CsvCells::parse("n\nCaf\xE9\n")[1][0][0]);
    }

    /** @return array{0:Imports,1:int} */
    private function seed(string $driver): array
    {
        $this->database = TestDatabase::create($driver);

        return $this->upload($this->imports());
    }

    /** @return array{0:Imports,1:int} */
    private function upload(Imports $imports): array
    {
        $file = $this->testDb()->dataDir() . '/upload-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($file, self::CSV);
        $id = $imports->upload(['error' => UPLOAD_ERR_OK, 'size' => strlen(self::CSV), 'tmp_name' => $file, 'name' => 'items.csv']);
        unlink($file);

        return [$imports, $id];
    }

    private function imports(): Imports
    {
        $db = $this->db();
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $items = $this->testDb()->items();
        $calendar = new Calendar($clock, 'UTC', 7);

        return new Imports($db, $clock, $calendar, new LocalStorage($this->testDb()->dataDir()), $items, new TaxonomyRepository($db, $clock, $history),
            new Loans($db, $clock, $calendar, $history, $items), static fn (): ?string => null, true);
    }

    private function rowsIn(string $table): int
    {
        return (int)($this->db()->query("SELECT COUNT(*) AS n FROM $table")->getRowArray()['n'] ?? 0);
    }

    private function testDb(): TestDatabase
    {
        $this->assertNotNull($this->database);

        return $this->database;
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        return $this->testDb()->db;
    }
}
