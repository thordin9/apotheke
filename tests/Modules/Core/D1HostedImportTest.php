<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Database\D1\Connection;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\ImportExport\Controllers\Api\ImportsController;
use Apotheke\ImportExport\Controllers\ImportController;
use Apotheke\ImportExport\Imports;
use Apotheke\Lending\Loans;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * CSV import on the hosted database (ADR-002: per-row batches with the import_rows marker). The preview writes
 * nothing; a commit is one batch per line, its marker claimed in the same batch, so a commit that failed half-way
 * resumes without importing a line twice, and racing commits of one staged file import each line once.
 * (ImportJobTest runs the job's cases on d1 too with APOTHEKE_CI4_TEST_D1=1.)
 */
final class D1HostedImportTest extends CIUnitTestCase
{
    private ?TestDatabase $t = null;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 3);
        $ok = trim((string)shell_exec("node -e \"try{require('node:sqlite');console.log('y')}catch{}\" 2>/dev/null")) === 'y';
        if (!$ok || !is_dir($root . '/edge/node_modules')) {
            $this->markTestSkipped('needs node with node:sqlite and edge/node_modules');
        }
        $this->t = TestDatabase::create('d1');
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->t?->drop();
        $this->t = null;
        parent::tearDown();
    }

    private function db(): Connection
    {
        $db = $this->t?->db;
        $this->assertInstanceOf(Connection::class, $db);

        return $db;
    }

    /** The importer as Services builds it (one UnitsOfWork for its repositories), on $t's database and data dir. */
    public static function imports(TestDatabase $t): Imports
    {
        $db = $t->db;
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $units = UnitsOfWork::forSite($db, null);
        $items = $t->items($db, $units, $history);
        $calendar = new Calendar($clock, 'UTC', 7);

        return new Imports($db, $clock, $calendar, new LocalStorage($t->dataDir()), $items, new TaxonomyRepository($db, $clock, $history, $units),
            new Loans($db, $clock, $calendar, $history, $items, $units), static fn (): ?string => null, true);
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db(), $sql, $binds);
    }

    private function n(string $sql, array $binds = []): int
    {
        return (int)$this->rows($sql, $binds)[0]['n'];
    }

    /** Hammer (an item the file updates) and a staged upload: 4 lines, or $extra new ones after them. */
    private function staged(Imports $imports, bool $skip = false, string $extra = ''): int
    {
        $hammer = $this->t?->items($this->db(), UnitsOfWork::forSite($this->db(), null))->save(null, ['name' => 'Hammer', 'location_id' => null, 'category_id' => null,
            'quantity' => 1, 'min_qty' => null, 'notes' => '', 'tags' => [], 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => ''], null, false);
        $csv = "id;name;location;category;tags;quantity;lent to;due back\n"
            . ";Ladder;Shed › Wall;Tools;red;2;\n"
            . ";Rope;Shed › Wall;tools;red,blue;;Ann\n"
            . "$hammer;Hammer;Garage;;;3;\n"
            . ";Glue;Garage;Craft;;1;\n" . $extra;
        $file = $this->t?->dataDir() . '/upload.csv';
        file_put_contents($file, $csv);
        $id = $imports->upload(['error' => UPLOAD_ERR_OK, 'size' => strlen($csv), 'tmp_name' => $file, 'name' => 'items.csv']);
        unlink($file);
        $this->db()->query('UPDATE imports SET options = ? WHERE id = ?', [json_encode(['create' => true, 'skip_errors' => $skip]), $id]);

        return $id;
    }

    /** @return array<string,mixed> */
    private function imp(Imports $imports, int $id): array
    {
        $imp = $imports->get($id);
        $this->assertNotNull($imp);

        return $imp;
    }

    /** @return array<string,int> the row count of every table the import writes */
    private function counts(): array
    {
        $out = [];
        foreach (['items', 'locations', 'categories', 'tags', 'item_tags', 'activity', 'import_rows'] as $t) {
            $out[$t] = $this->n('SELECT COUNT(*) AS n FROM ' . $t);
        }

        return $out;
    }

    public function testACommitThatFailedOnALineResumesWithoutImportingALineTwice(): void
    {
        $imports = self::imports($this->t);
        $id = $this->staged($imports);
        $this->db()->query("CREATE TRIGGER stop_rope BEFORE INSERT ON items WHEN NEW.name = 'Rope' BEGIN SELECT RAISE(ABORT, 'stop'); END");
        try {
            $imports->commit($this->imp($imports, $id));
            $this->fail('the Rope line must fail');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(UserError::class, $e, 'a failed batch, not a row error: ' . $e->getMessage());
        }
        $this->assertSame([['line' => 2, 'state' => 'new']], $this->rows('SELECT line, state FROM import_rows ORDER BY line'), 'the line before is in, Rope has no marker');
        $this->assertSame([['name' => 'Hammer'], ['name' => 'Ladder']], $this->rows('SELECT name FROM items ORDER BY name'));
        $this->assertSame(0, $this->n("SELECT COUNT(*) AS n FROM activity WHERE action = 'lent'"), 'nothing of the failed line');
        $this->assertSame('uploaded', $this->imp($imports, $id)['status']);
        $this->db()->query('DROP TRIGGER stop_rope');

        $stale = $this->imp($imports, $id);
        $r = $imports->commit($stale);
        $this->assertNull($imports->commit($stale), 'a second submit that read the import before it was done finds it done');
        $this->assertSame([3, 1, 0], [$r['created'] ?? null, $r['updated'] ?? null, $r['skipped'] ?? null], json_encode($r));
        $this->assertSame(['location' => ['Shed', 'Wall', 'Garage'], 'category' => ['Tools', 'Craft'], 'tag' => ['red', 'blue']], $r['made'] ?? null, 'what each line made, once');
        $this->assertSame([['name' => 'Glue', 'n' => 1], ['name' => 'Hammer', 'n' => 1], ['name' => 'Ladder', 'n' => 1], ['name' => 'Rope', 'n' => 1]],
            array_map(static fn (array $x): array => ['name' => $x['name'], 'n' => (int)$x['n']], $this->rows('SELECT name, COUNT(*) AS n FROM items GROUP BY name ORDER BY name')));
        $this->assertSame([['lent_to' => 'Ann']], $this->rows("SELECT lent_to FROM items WHERE name = 'Rope'"));
        $this->assertSame([4, 1, 1], [$this->n("SELECT COUNT(*) AS n FROM activity WHERE action = 'created'"), $this->n("SELECT COUNT(*) AS n FROM activity WHERE action = 'lent'"),
            $this->n("SELECT COUNT(*) AS n FROM activity WHERE action = 'moved'")], 'created (Hammer + 3), lent and moved once each');
        $this->assertSame([['name' => 'Wall', 'parent' => 'Shed']], $this->rows("SELECT l.name, p.name AS parent FROM locations l JOIN locations p ON p.id = l.parent_id"));
        $this->assertSame([['n' => 2]], $this->rows("SELECT COUNT(*) AS n FROM item_tags JOIN tags ON tags.id = tag_id WHERE tags.name = 'red'"));
        $this->assertSame([4, 'done'], [$this->n('SELECT COUNT(*) AS n FROM import_rows'), $this->imp($imports, $id)['status']]);
        try {
            $imports->commit($this->imp($imports, $id));
            $this->fail('imported once');
        } catch (UserError $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    public function testThePagesPreviewWritesNothing(): void
    {
        $imports = self::imports($this->t);
        $id = $this->staged($imports, false, ";Box;Attic;;;x;\n");
        $before = $this->counts();
        $run = $imports->run($this->imp($imports, $id), false);
        $this->assertSame($before, $this->counts());
        $this->assertSame(['new', 'new', 'update', 'new', 'error'], array_column($run['rows'], 'state'));
        $this->assertSame([3, 1, 1, false], [$run['created'], $run['updated'], $run['errors'], $run['committed']]);
        $this->assertSame(['Shed', 'Wall', 'Garage'], $run['made']['location'], 'Box fails on its quantity before its location');
    }

    public function testErrorsWithoutSkipImportNothingAndWithSkipTheRestGoesIn(): void
    {
        $imports = self::imports($this->t);
        // Box fails on its quantity; Kite only on its loan, after its new location and tag (the dry run finds it first).
        $id = $this->staged($imports, false, ";Box;Cellar;;;x;;\n;Kite;Attic;;pink;1;Bob;2001-01-01\n;Reel;Garage;;pink;1;;\n");
        $before = $this->counts();
        try {
            $imports->commit($this->imp($imports, $id));
            $this->fail('a row has errors');
        } catch (UserError $e) {
            $this->assertSame([422, '2 rows have errors. Fix the file, or tick “Skip rows with errors”.'], [$e->getCode(), $e->getMessage()]);
        }
        $this->assertSame($before, $this->counts(), 'nothing written, no marker');
        $this->db()->query('UPDATE imports SET options = ? WHERE id = ?', [json_encode(['create' => true, 'skip_errors' => true]), $id]);
        $r = $imports->commit($this->imp($imports, $id));
        $this->assertSame([4, 1, 2], [$r['created'] ?? null, $r['updated'] ?? null, $r['skipped'] ?? null]);
        $this->assertSame([], $this->rows("SELECT id FROM items WHERE name IN ('Box', 'Kite')"));
        $this->assertSame([], $this->rows("SELECT id FROM locations WHERE name IN ('Attic', 'Cellar')"), 'the dry run caught the errors before their locations were made');
        $this->assertSame(['red', 'blue', 'pink'], $r['made']['tag'] ?? null, 'Reel made pink: the failed Kite did not');
        $this->assertSame([['state' => 'error', 'error' => 'Quantity “x” is not a whole number from 0 to 1,000,000.']], $this->rows('SELECT state, error FROM import_rows WHERE line = 6'));
    }

    /** A line another run imported is skipped before anything of it is made (its location, category or tag). */
    public function testALineAnotherRunImportedIsSkippedWithoutTouchingAnything(): void
    {
        $imports = self::imports($this->t);
        $id = $this->staged($imports, true);
        foreach ([2, 3] as $line) {   // Ladder and Rope: the only lines with Shed › Wall, Tools and red
            $this->db()->query("INSERT INTO import_rows (import_id, line, state) VALUES (?, ?, 'new')", [$id, $line]);
        }
        $r = $imports->commit($this->imp($imports, $id));
        $this->assertSame([['name' => 'Glue'], ['name' => 'Hammer']], $this->rows('SELECT name FROM items ORDER BY name'));
        $this->assertSame([['name' => 'Garage']], $this->rows('SELECT name FROM locations'));
        $this->assertSame([['name' => 'Craft']], $this->rows('SELECT name FROM categories'));
        $this->assertSame([], $this->rows('SELECT name FROM tags'));
        $this->assertSame([3, 1], [$r['created'] ?? null, $r['updated'] ?? null], 'counted from the markers');
    }

    /** A line that lends an item someone has: the loan is in the line's batch, its history says who had it. */
    public function testALineLendsInItsBatchAndLendInChecksItsInput(): void
    {
        $imports = self::imports($this->t);
        $db = $this->db();
        $items = $this->t?->items($db, UnitsOfWork::forSite($db, null));
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $loans = new Loans($db, $clock, new Calendar($clock, 'UTC', 7), $history, $items);
        $id = $this->staged($imports);
        $hammer = (int)$this->rows("SELECT id FROM items WHERE name = 'Hammer'")[0]['id'];
        $loans->lend($hammer, 'Bob', null, null);
        $this->db()->query('DELETE FROM import_rows');
        $csv = "id;name;quantity;lent to\n$hammer;Hammer;1;Ann\n";
        $file = $this->t?->dataDir() . '/again.csv';
        file_put_contents($file, $csv);
        $again = $imports->upload(['error' => UPLOAD_ERR_OK, 'size' => strlen($csv), 'tmp_name' => $file, 'name' => 'again.csv']);
        unlink($file);
        $this->assertSame(1, $imports->commit($this->imp($imports, $again))['updated'] ?? null);
        $lent = $this->rows("SELECT details FROM activity WHERE action = 'lent' ORDER BY id DESC LIMIT 1")[0]['details'];
        $this->assertSame(['to' => 'Ann', 'before' => 'Bob'], array_intersect_key(json_decode((string)$lent, true), ['to' => 1, 'before' => 1]));
        $this->assertSame([['lent_to' => 'Ann']], $this->rows('SELECT lent_to FROM items WHERE id = ?', [$hammer]));
        $this->assertGreaterThan(0, $id);
        foreach ([['', null, 'Enter who you lent it to.'], ['Cy', '2001-01-01', 'The due date cannot be before the lent date.']] as [$to, $due, $msg]) {
            try {
                $items->inUnit(static fn ($uow) => $loans->lendIn($uow, $hammer, 'Hammer', 'Ann', $to, $due));
                $this->fail($msg);
            } catch (UserError $e) {
                $this->assertSame($msg, $e->getMessage());
            }
        }
        $this->assertSame([['lent_to' => 'Ann']], $this->rows('SELECT lent_to FROM items WHERE id = ?', [$hammer]), 'nothing lent');
    }

    /** Racing commits of one staged file: each line is imported (and logged) once; nothing is lost. */
    public function testRacingCommitsOfOneImportImportEachLineOnce(): void
    {
        $imports = self::imports($this->t);
        $extra = '';
        for ($i = 1; $i <= 16; $i++) {
            $extra .= ";Item $i;Garage › Bin " . ($i % 4) . ";Kind " . ($i % 3) . ";t" . ($i % 5) . ";1;" . ($i % 4 === 0 ? 'Bob' : '') . "\n";
        }
        $id = $this->staged($imports, false, $extra);
        $env = json_encode($this->t?->clientEnv(), JSON_THROW_ON_ERROR);
        $racers = [];
        for ($i = 0; $i < 3; $i++) {
            $p = proc_open([PHP_BINARY, __DIR__ . '/_d1-hosted-worker.php', $env, 'import-commit', (string)$id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $this->assertSame("ready\n", fgets($pipes[1]), 'every racer is up before any commits');
            $racers[] = [$p, $pipes];
        }
        foreach ($racers as [, $pipes]) {
            fwrite($pipes[0], "go\n");
        }
        $out = [];
        foreach ($racers as [$p, $pipes]) {
            $out[] = json_decode((string)fgets($pipes[1]), true);
            proc_close($p);
        }
        foreach ($out as $r) {
            $this->assertTrue(($r['ok'] ?? false) === true || ($r['code'] ?? 0) === 409, json_encode($out));
        }
        $this->assertSame(20, $this->n('SELECT COUNT(*) AS n FROM items'), json_encode($out));
        $this->assertSame([], $this->rows('SELECT name FROM items GROUP BY name HAVING COUNT(*) > 1'), 'no line imported twice');
        $this->assertSame([20, 5, 1], [$this->n("SELECT COUNT(*) AS n FROM activity WHERE action = 'created'"), $this->n("SELECT COUNT(*) AS n FROM activity WHERE action = 'lent'"),
            $this->n("SELECT COUNT(*) AS n FROM activity WHERE action = 'quantity'")], 'history once per line');
        $this->assertSame([20, 'done'], [$this->n("SELECT COUNT(*) AS n FROM import_rows WHERE state <> 'error'"), $this->imp($imports, $id)['status']]);
        $this->assertSame([['n' => 4]], $this->rows("SELECT COUNT(*) AS n FROM locations WHERE name LIKE 'Bin %'"));
    }

    public function testTheImportPageAndApiNoLongerAnswer501(): void
    {
        Services::reset(true);
        Services::injectMock('apothekeDb', $this->db());
        Services::injectMock('apothekeConfig', $this->t?->config);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request = Services::request(null, false)->withMethod('POST');
        Services::injectMock('request', $request);
        foreach ([new ImportController(), new ImportsController()] as $c) {
            $c->initController($request, Services::response(null, false), Services::logger());
        }
        $api = (new ImportsController());
        $api->initController($request, Services::response(null, false), Services::logger());
        $this->assertSame(422, $api->create()->getStatusCode(), 'no file: the usual validation answer');
        $page = new ImportController();
        $page->initController($request, Services::response(null, false), Services::logger());
        $r = $page->index();
        $this->assertSame(422, $r->getStatusCode());
        $this->assertStringContainsString('Choose a CSV file to import.', (string)$r->getBody());
    }
}
