<?php

declare(strict_types=1);

namespace Tests\ImportExport;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\ImportExport\Imports;
use Apotheke\Lending\Loans;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The API's import preview (Imports::preview): the same answer as the HTML page's dry run (a run in a transaction
 * that is rolled back), but it validates without writing anything, so it needs no transaction or savepoint (D1).
 */
final class ImportPreviewTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** Rows that create, update, keep, fail, lend and make locations, categories and tags, one after another. */
    private const CSV = "id;name;location;category;tags;quantity;lent to;due back\n"
        . ";Ladder;Shed › Wall;Tools;red;2;;\n"
        . ";Rope;Shed › Wall;tools;red,blue;;Ann;\n"
        . "%A%;Hammer;Garage;Tools;;3;;\n"
        . "%A%;Hammer;Garage;Tools;;3;;\n"
        . "%B%;Saw;Garage;;;1;;\n"
        . ";;Garage;;;;;\n"
        . ";Box;Attic;Storage;;x;;\n"
        . ";Crate;Attic › Top;;green;;Bob;2001-01-01\n"
        . "abc;Drill;;;;;;\n"
        . ";Rope;Cellar;;;;Ann;\n"
        . ";Glue;Garage;Craft;;1;;\n"
        . ";Tape;Garage;craft;;1;;\n"
        . "%B%;Saw;Cellar › Back;;;1;;\n"
        . "%B%;Saw;Cellar › Back;;;1;;\n";

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function cases(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ', create missing' => [$driver, true];
            yield $driver . ', known only' => [$driver, false];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('cases')]
    public function testThePreviewAnswersAsTheDryRun(string $driver, bool $create): void
    {
        [$imports, $imp] = $this->seed($driver, $create);
        $dry = $imports->run($imp, false);
        $preview = $imports->preview($imp);
        $this->assertSame($dry, $preview);
        $this->assertGreaterThan(0, $preview['errors']);
        $this->assertContains('same', array_column($preview['rows'], 'state'));
        $this->assertContains('update', array_column($preview['rows'], 'state'));
        if ($create) {
            $this->assertSame(['update', 'same'], array_column(array_slice($preview['rows'], -2), 'state'), 'a place made by an earlier row is that place');
        }
    }

    #[DataProvider('cases')]
    public function testThePreviewWritesNothing(string $driver, bool $create): void
    {
        [$imports, $imp] = $this->seed($driver, $create);
        $before = $this->counts();
        $statements = [];
        $listener = static function (\CodeIgniter\Database\Query $q) use (&$statements): void {
            $statements[] = $q->getQuery();
        };
        Events::on('DBQuery', $listener);
        try {
            $preview = $imports->preview($imp);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertNotSame([], $preview['rows']);
        $this->assertNotSame([], $statements, 'the listener sees the reads');
        $writes = preg_grep('/^\s*(INSERT|UPDATE|DELETE|REPLACE|BEGIN|SAVEPOINT|RELEASE|ROLLBACK|COMMIT|CREATE|DROP|ALTER)\b/i', $statements);
        $this->assertSame([], array_values($writes ?: []), 'only reads');
        $this->assertSame(0, (int)$this->testDb()->db->transDepth);
        $this->assertSame($before, $this->counts(), 'every table has the rows it had');
    }

    public function testADoneImportIsNotPreviewed(): void
    {
        [$imports, $imp] = $this->seed('sqlite', true);
        $this->testDb()->db->query("UPDATE imports SET status = 'done' WHERE id = ?", [$imp['id']]);
        $preview = $imports->preview($imp);
        $this->assertSame([true, []], [$preview['done_before'], $preview['rows']]);
    }

    /** @return array{0:Imports,1:array<string,mixed>} */
    private function seed(string $driver, bool $create): array
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->testDb()->db;
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $items = $this->testDb()->items();
        $calendar = new Calendar($clock, 'UTC', 7);
        $taxonomy = new TaxonomyRepository($db, $clock, $history);
        $imports = new Imports($db, $clock, $calendar, new LocalStorage($this->testDb()->dataDir()), $items, $taxonomy,
            new Loans($db, $clock, $calendar, $history, $items), static fn (): ?string => null, $create);
        $garage = $taxonomy->create('location', 'Garage')['id'];
        $tools = $taxonomy->create('category', 'Tools')['id'];
        $db->query("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('red', 'red', 'red', '2026-01-01')");
        $base = ['notes' => '', 'tags' => [], 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => '', 'min_qty' => null];
        $a = $items->save(null, ['name' => 'Hammer', 'location_id' => $garage, 'category_id' => $tools, 'quantity' => 1] + $base, null, false);
        $b = $items->save(null, ['name' => 'Saw', 'location_id' => $garage, 'category_id' => null, 'quantity' => 1] + $base, null, false);
        $csv = strtr(self::CSV, ['%A%' => (string)$a, '%B%' => (string)$b]);
        $file = $this->testDb()->dataDir() . '/upload.csv';
        file_put_contents($file, $csv);
        $id = $imports->upload(['error' => UPLOAD_ERR_OK, 'size' => strlen($csv), 'tmp_name' => $file, 'name' => 'items.csv']);
        unlink($file);
        $imp = $imports->get($id);
        $this->assertNotNull($imp);
        $imp['options'] = ['create' => $create, 'skip_errors' => false];

        return [$imports, $imp];
    }

    /** @return array<string,int> every table's row count */
    private function counts(): array
    {
        $db = $this->testDb()->db;
        $out = [];
        foreach ($db->listTables() as $t) {
            $out[(string)$t] = (int)($db->query('SELECT COUNT(*) AS n FROM ' . $db->escapeIdentifiers((string)$t))->getRowArray()['n'] ?? 0);
        }
        ksort($out);

        return $out;
    }

    private function testDb(): TestDatabase
    {
        $this->assertNotNull($this->database);

        return $this->database;
    }
}
