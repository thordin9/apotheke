<?php

declare(strict_types=1);

namespace Tests\ImportExport;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Jobs\Job;
use Apotheke\Core\Jobs\JobContext;
use Apotheke\Core\Jobs\JobHandler;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Jobs\JobRunner;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\ImportExport\Imports;
use Apotheke\ImportExport\Jobs\ImportCommitJob;
use Apotheke\Lending\Loans;
use Apotheke\Taxonomy\TaxonomyRepository;
use Closure;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * The API's import job (Imports::commitRows, the import.commit job): one transaction per row with its import_rows
 * marker, no savepoint, checkpoints, and a job stopped or crashed half-way that resumes to the state one run gives.
 */
final class ImportJobTest extends CIUnitTestCase
{
    /** @var list<TestDatabase> */
    private array $databases = [];

    private const CSV = "id;name;location;category;tags;quantity;lent to;due back\n"
        . ";Ladder;Shed › Wall;Tools;red;2;;\n"
        . ";Rope;Shed › Wall;tools;red,blue;;Ann;\n"
        . "%A%;Hammer;Garage;Tools;;3;;\n"
        . "%A%;Hammer;Garage;Tools;;3;;\n"
        . ";;Garage;;;;;\n"
        . ";Box;Attic;Storage;;x;;\n"
        . "%B%;Saw;Cellar › Back;;green;1;;\n"
        . ";Glue;Garage;Craft;;1;;\n"
        . ";Tape;Garage;craft;green;1;;\n"
        . "abc;Drill;;;;;;\n"
        . ";Nails;Attic;;;5;Bob;\n"
        . ";Kite;Garage;;pink;1;Bob;2001-01-01\n"   // fails after its tag: the loan is due in the past
        . ";Reel;Garage;;pink;1;;\n";

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->dropAll();
        parent::tearDown();
    }

    private function dropAll(): void
    {
        foreach ($this->databases as $d) {
            $d->drop();
        }
        $this->databases = [];
    }

    /**
     * The tables and the job's result after one uninterrupted job (skip_errors on), in a database dropped again.
     *
     * @return array{0:array<string,list<array<string,mixed>>>,1:array<string,mixed>|null}
     */
    private function oneRun(string $driver): array
    {
        [$env, $imp] = $this->seed($driver, true);
        $run = $this->runJob($env, $imp, 3);
        $this->assertSame('done', $run['status'] ?? null);
        $out = [$this->state($env), $this->queue($env)->find($run['id'])?->result];
        $this->dropAll();

        return $out;
    }

    #[DataProvider('drivers')]
    public function testTheJobImportsAsThePageDoes(string $driver): void
    {
        [$page, $pageImp] = $this->seed($driver, true);
        $html = $page['imports']->commit($pageImp);
        $pageState = $this->state($page);
        $this->dropAll();   // one PostgreSQL test schema at a time: pg_connect() shares a link with the same DSN
        [$api, $imp] = $this->seed($driver, true);
        $queue = $this->queue($api);
        $job = $queue->enqueue(ImportCommitJob::TYPE, ['import_id' => $imp['id']]);
        $this->assertSame('done', $this->runner($api, [ImportCommitJob::TYPE => new ImportCommitJob($api['imports'])])->runOnce()['status'] ?? null);
        $this->assertSame($pageState, $this->state($api), 'the same tables as the page\'s commit');
        $this->assertSame($html, $queue->find($job)?->result);
        $this->assertSame(['done' => 13, 'total' => 13], $queue->find($job)?->checkpoint);
        $this->assertSame([4, 4], [$html['skipped'] ?? null, count($api['db']->query("SELECT 1 FROM import_rows WHERE state = 'error'")->getResultArray())]);
    }

    #[DataProvider('drivers')]
    public function testARowIsOneTransactionWithoutSavepoints(string $driver): void
    {
        [$api, $imp] = $this->seed($driver, true);
        $seen = [];
        $listener = static function (Query $q) use (&$seen): void {
            $seen[] = [$q->getOriginalQuery(), substr_count($q->getOriginalQuery(), '?')];
        };
        Events::on('DBQuery', $listener);
        try {
            $this->runJob($api, $imp, 3);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertNotSame([], $seen);
        $this->assertSame([], preg_grep('/\b(SAVEPOINT|BEGIN|COMMIT|ROLLBACK)\b/i', array_column($seen, 0)) ?: []);
        $this->assertLessThanOrEqual(100, max(array_column($seen, 1)));
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function stops(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ', stopped' => [$driver, 'stop'];
            yield $driver . ', crashed in a row' => [$driver, 'crash'];
        }
    }

    #[DataProvider('stops')]
    public function testAJobStoppedHalfWayResumesToTheSameState(string $driver, string $how): void
    {
        [$onceState, $onceResult] = $this->oneRun($driver);
        [$api, $imp] = $this->seed($driver, true);
        $queue = $this->queue($api);
        $job = $queue->enqueue('import.test', ['import_id' => $imp['id']]);
        $inserts = 0;
        $crash = static function (Query $q) use (&$inserts): void {
            if (preg_match('/^INSERT INTO \W?items\W/', $q->getQuery()) === 1 && ++$inserts === 3) {
                throw new RuntimeException('killed');   // inside the third new item's transaction, half-way through its writes
            }
        };
        if ($how === 'crash') {
            Events::on('DBQuery', $crash);
        }
        try {
            $first = $this->runner($api, ['import.test' => $this->chunked($api, 3)], $how === 'stop' ? static fn (): bool => true : null)->runOnce();
        } finally {
            Events::removeListener('DBQuery', $crash);
        }
        $this->assertSame('queued', $first['status'] ?? null, 'released or retried');
        $half = $queue->find($job);
        $this->assertSame(['done' => $how === 'stop' ? 3 : 6, 'total' => 13], $half?->checkpoint);
        $this->assertSame('uploaded', $api['db']->query('SELECT status FROM imports WHERE id = ?', [$imp['id']])->getRowArray()['status'] ?? null);
        $this->assertGreaterThan(0, $this->rowCount($api, 'import_rows'));
        $api['db']->query("UPDATE jobs SET run_after = '2000-01-01 00:00:00'");   // the retry is due now
        $this->assertSame('done', $this->runner($api, ['import.test' => $this->chunked($api, 3)])->runOnce()['status'] ?? null);
        $this->assertSame($onceState, $this->state($api), 'no row twice, nothing missing');
        $this->assertSame($onceResult, $queue->find($job)?->result);
    }

    #[DataProvider('drivers')]
    public function testALineAnotherRunCommittedMeanwhileIsSkipped(string $driver): void
    {
        [$api, $imp] = $this->seed($driver, true);
        $db = $api['db'];
        $done = false;
        $other = static function (Query $q) use ($db, $imp, &$done): void {
            if (!$done && str_contains($q->getOriginalQuery(), 'SELECT name FROM tags')) {
                $done = true;   // once this run has started, another one commits line 2 (Ladder)
                $db->query("INSERT INTO import_rows (import_id, line, state) VALUES (?, 2, 'new')", [$imp['id']]);
            }
        };
        Events::on('DBQuery', $other);
        try {
            $this->assertSame('done', $this->runJob($api, $imp, 3)['status'] ?? null);
        } finally {
            Events::removeListener('DBQuery', $other);
        }
        $this->assertTrue($done);
        $this->assertSame([], $db->query("SELECT id FROM items WHERE name = 'Ladder'")->getResultArray(), 'not imported a second time');
        $this->assertSame(['new', '{}'], array_values($db->query('SELECT state, made FROM import_rows WHERE line = 2')->getRowArray() ?? []));
    }

    #[DataProvider('drivers')]
    public function testAnErrorLineIsTriedAgain(string $driver): void
    {
        [$api, $imp] = $this->seed($driver, false);
        $imp['options']['create'] = false;   // Attic is unknown: Box and Nails fail on it
        $api['db']->query('UPDATE imports SET options = ? WHERE id = ?', [json_encode($imp['options']), $imp['id']]);
        $this->assertSame('failed', $this->runJob($api, $imp, 3)['status'] ?? null);
        $this->assertSame([], $api['db']->query("SELECT id FROM items WHERE name = 'Nails'")->getResultArray());
        $api['taxonomy']->create('location', 'Attic');
        $imp['options']['skip_errors'] = true;
        $api['db']->query('UPDATE imports SET options = ? WHERE id = ?', [json_encode($imp['options']), $imp['id']]);
        $this->assertSame('done', $this->runJob($api, $imp, 3)['status'] ?? null);
        $this->assertCount(1, $api['db']->query("SELECT id FROM items WHERE name = 'Nails'")->getResultArray(), 'imported on the second run');
        $this->assertSame(['new', ''], array_values($api['db']->query("SELECT state, error FROM import_rows WHERE line = 12")->getRowArray() ?? []));
    }

    #[DataProvider('drivers')]
    public function testErrorsWithoutSkipFailTheJobAndANewCommitResumes(string $driver): void
    {
        [$onceState] = $this->oneRun($driver);
        [$api, $imp] = $this->seed($driver, false);
        $failed = $this->runJob($api, $imp, 3);
        $this->assertSame('failed', $failed['status'] ?? null);
        $this->assertSame('Some rows have errors. Fix the file, or commit again with skip_errors.', $this->queue($api)->find($failed['id'])?->error);
        $this->assertSame('uploaded', $api['db']->query('SELECT status FROM imports WHERE id = ?', [$imp['id']])->getRowArray()['status'] ?? null);
        $this->assertSame(8, $this->rowCount($api, 'items'), 'the rows without errors are in');
        $imp['options']['skip_errors'] = true;
        $api['db']->query('UPDATE imports SET options = ? WHERE id = ?', [json_encode($imp['options']), $imp['id']]);
        $this->assertSame('done', $this->runJob($api, $imp, 3)['status'] ?? null);
        $this->assertSame($onceState, $this->state($api));
    }

    /**
     * @param array{db:\CodeIgniter\Database\BaseConnection<object|resource, object|resource>,imports:Imports,database:TestDatabase} $env
     * @param array<string,mixed> $imp
     * @return array{id:int,type:string,status:string}|null
     */
    private function runJob(array $env, array $imp, int $chunk): ?array
    {
        $this->queue($env)->enqueue('import.test', ['import_id' => $imp['id']]);

        return $this->runner($env, ['import.test' => $this->chunked($env, $chunk)])->runOnce();
    }

    /** @param array{imports:Imports} $env */
    private function chunked(array $env, int $chunk): JobHandler
    {
        return new class ($env['imports'], $chunk) implements JobHandler {
            public function __construct(private readonly Imports $imports, private readonly int $chunk)
            {
            }

            public function run(Job $job, JobContext $context): array
            {
                $imp = $this->imports->get((int)$job->payload['import_id']);
                assert($imp !== null);

                return $this->imports->commitRows($imp, $context, $this->chunk);
            }
        };
    }

    /** @param array{db:\CodeIgniter\Database\BaseConnection<object|resource, object|resource>} $env */
    private function queue(array $env): JobQueue
    {
        return new JobQueue($env['db'], new Clock());
    }

    /**
     * @param array{db:\CodeIgniter\Database\BaseConnection<object|resource, object|resource>} $env
     * @param array<string,JobHandler> $handlers
     */
    private function runner(array $env, array $handlers, ?Closure $stop = null): JobRunner
    {
        return new JobRunner($this->queue($env), $handlers, 'test-worker', $stop, static function (string $line): void {
        });
    }

    /** @param array{db:\CodeIgniter\Database\BaseConnection<object|resource, object|resource>} $env */
    private function rowCount(array $env, string $table): int
    {
        return (int)($env['db']->query('SELECT COUNT(*) AS n FROM ' . $table)->getRowArray()['n'] ?? 0);
    }

    /**
     * Every table but jobs, without the times a run stamps.
     *
     * @param array{db:\CodeIgniter\Database\BaseConnection<object|resource, object|resource>} $env
     * @return array<string,list<array<string,mixed>>>
     */
    private function state(array $env): array
    {
        $out = [];
        foreach ($env['db']->listTables() as $t) {
            $t = (string)$t;
            if (in_array($t, ['jobs', 'import_rows'], true)) {
                continue;
            }
            $rows = $env['db']->query('SELECT * FROM ' . $env['db']->escapeIdentifiers($t))->getResultArray();
            foreach ($rows as &$r) {
                foreach (array_keys($r) as $k) {
                    if (preg_match('/(_at|^at|_key)$/', (string)$k) === 1) {
                        unset($r[$k]);
                    }
                }
                ksort($r);
            }
            unset($r);
            usort($rows, static fn (array $a, array $b): int => strcmp((string)json_encode($a), (string)json_encode($b)));
            $out[$t] = $rows;
        }
        ksort($out);

        return $out;
    }

    /** @return array{0:array{db:\CodeIgniter\Database\BaseConnection<object|resource, object|resource>,imports:Imports,taxonomy:TaxonomyRepository,database:TestDatabase},1:array<string,mixed>} */
    private function seed(string $driver, bool $skip): array
    {
        $database = TestDatabase::create($driver);
        $this->databases[] = $database;
        $db = $database->db;
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $items = $database->items();
        $calendar = new Calendar($clock, 'UTC', 7);
        $taxonomy = new TaxonomyRepository($db, $clock, $history);
        $imports = new Imports($db, $clock, $calendar, new LocalStorage($database->dataDir()), $items, $taxonomy,
            new Loans($db, $clock, $calendar, $history, $items), static fn (): ?string => null, true);
        $garage = $taxonomy->create('location', 'Garage')['id'];
        $tools = $taxonomy->create('category', 'Tools')['id'];
        $db->query("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('red', 'red', 'red', '2026-01-01')");
        $base = ['notes' => '', 'tags' => [], 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => '', 'min_qty' => null];
        $a = $items->save(null, ['name' => 'Hammer', 'location_id' => $garage, 'category_id' => $tools, 'quantity' => 1] + $base, null, false);
        $b = $items->save(null, ['name' => 'Saw', 'location_id' => $garage, 'category_id' => null, 'quantity' => 1] + $base, null, false);
        $csv = strtr(self::CSV, ['%A%' => (string)$a, '%B%' => (string)$b]);
        $file = $database->dataDir() . '/upload.csv';
        file_put_contents($file, $csv);
        $id = $imports->upload(['error' => UPLOAD_ERR_OK, 'size' => strlen($csv), 'tmp_name' => $file, 'name' => 'items.csv']);
        unlink($file);
        $options = ['create' => true, 'skip_errors' => $skip];
        $db->query('UPDATE imports SET options = ? WHERE id = ?', [json_encode($options), $id]);
        $imp = $imports->get($id);
        $this->assertNotNull($imp);

        return [['db' => $db, 'imports' => $imports, 'taxonomy' => $taxonomy, 'database' => $database], $imp];
    }
}
