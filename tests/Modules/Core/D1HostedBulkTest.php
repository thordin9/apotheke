<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\BulkMerge\BulkService;
use Apotheke\BulkMerge\Controllers\BulkMergeController;
use Apotheke\Core\Database\D1\Connection;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Media\ImageStore;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * Bulk editing on the hosted database (ADR-002: all or nothing on the Installation object): the whole selection is
 * one unit of work, one batch. A batch that fails writes nothing, and the same request again resumes from the rows
 * as they are; racing bulk deletes of one selection delete and log each item once.
 */
final class D1HostedBulkTest extends CIUnitTestCase
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

    /** The service as Services builds it, on $t's database; photos are not touched (keep_files or none). */
    public static function bulk(TestDatabase $t): BulkService
    {
        $db = $t->db;
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $units = UnitsOfWork::forSite($db, null);

        return new BulkService($db, $t->items($db, $units, $history), new TaxonomyRepository($db, $clock, $history, $units), $history,
            (new \ReflectionClass(ImageStore::class))->newInstanceWithoutConstructor());
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db(), $sql, $binds);
    }

    /** @return list<int> three items, the first two tagged "old" */
    private function three(): array
    {
        $items = $this->t?->items($this->db(), UnitsOfWork::forSite($this->db(), null));
        $in = static fn (string $name, array $tags): array => ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1,
            'min_qty' => null, 'notes' => '', 'tags' => $tags, 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => ''];

        return [$items->save(null, $in('Lamp', ['old']), null, false), $items->save(null, $in('Rope', ['old']), null, false), $items->save(null, $in('Tent', []), null, false)];
    }

    private function location(string $name): int
    {
        $db = $this->db();
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);

        return (new TaxonomyRepository($db, $clock, $history, UnitsOfWork::forSite($db, null)))->create('location', $name)['id'];
    }

    private function actions(string $action): int
    {
        return (int)$this->rows('SELECT COUNT(*) AS n FROM activity WHERE action = ?', [$action])[0]['n'];
    }

    public function testEveryBulkActionRunsOnTheHostedDatabase(): void
    {
        $ids = $this->three();
        $bulk = self::bulk($this->t);
        $shed = $this->location('Shed');
        $this->assertSame(3, $bulk->run($ids, 'move', ['location_id' => $shed]));
        $this->assertSame([['n' => 3]], $this->rows('SELECT COUNT(*) AS n FROM items WHERE location_id = ?', [$shed]));
        $this->assertSame(3, $this->actions('moved'));
        $this->assertSame(3, $bulk->run($ids, 'tag_add', ['tags' => ['Winter']]));
        $this->assertSame([['n' => 3]], $this->rows("SELECT COUNT(*) AS n FROM item_tags JOIN tags ON tags.id = tag_id WHERE tags.name = 'Winter'"));
        $old = (int)$this->rows("SELECT id FROM tags WHERE name = 'old'")[0]['id'];
        $this->assertSame(3, $bulk->run($ids, 'tag_remove', ['tag_id' => $old]));
        $this->assertSame([], $this->rows("SELECT id FROM tags WHERE name = 'old'"), 'an unused tag is dropped in the same batch');
        $this->assertSame(2, $bulk->run([$ids[0], $ids[1]], 'tag_add', ['tags' => ['Gone']]));
        $this->assertSame(2, $bulk->run([$ids[0], $ids[1], 999], 'delete', ['keep_files' => true]), 'a missing item is skipped');
        $this->assertSame([], $this->rows("SELECT id FROM tags WHERE name = 'Gone'"), 'a tag only the deleted items had is dropped');
        $this->assertSame([['name' => 'Winter']], $this->rows('SELECT name FROM tags'), 'a tag still in use stays');
        $this->assertSame([['id' => $ids[2]]], $this->rows('SELECT id FROM items'));
        $this->assertSame(2, $this->actions('deleted'));
        $this->assertSame(['bulk' => true], array_intersect_key(json_decode((string)$this->rows("SELECT details FROM activity WHERE action = 'deleted'")[0]['details'], true), ['bulk' => 1]));
    }

    /** A step that fails in the batch (here a trigger) leaves every item as it was; the same request then finishes the job. */
    public function testAFailedBulkWritesNothingAndTheSameRequestResumes(): void
    {
        $ids = $this->three();
        $bulk = self::bulk($this->t);
        $shed = $this->location('Shed');
        $this->db()->query('CREATE TRIGGER stop_tent BEFORE UPDATE ON items WHEN OLD.id = ' . $ids[2] . " BEGIN SELECT RAISE(ABORT, 'stop'); END");
        $this->db()->query('CREATE TRIGGER keep_tent BEFORE DELETE ON items WHEN OLD.id = ' . $ids[2] . " BEGIN SELECT RAISE(ABORT, 'stop'); END");
        foreach ([['move', ['location_id' => $shed]], ['delete', ['keep_files' => true]]] as [$action, $opt]) {
            try {
                $bulk->run($ids, $action, $opt);
                $this->fail($action . ': the batch must fail');
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(\Error::class, $e, $e->getMessage());
            }
        }
        $this->assertSame([['n' => 0]], $this->rows('SELECT COUNT(*) AS n FROM items WHERE location_id IS NOT NULL'), 'no item moved');
        $this->assertSame([['n' => 3]], $this->rows('SELECT COUNT(*) AS n FROM items'), 'no item deleted');
        $this->assertSame([0, 0], [$this->actions('moved'), $this->actions('deleted')], 'and nothing logged');
        $this->db()->query('DROP TRIGGER stop_tent');
        $this->db()->query('DROP TRIGGER keep_tent');
        $this->assertSame(3, $bulk->run($ids, 'move', ['location_id' => $shed]));
        $this->assertSame(3, $bulk->run($ids, 'move', ['location_id' => $shed]), 'again: the same end state');
        $this->assertSame(3, $this->actions('moved'), 'a repeat moves nothing, so it logs nothing');
        $this->assertSame(3, $bulk->run($ids, 'delete', ['keep_files' => true]));
        $this->assertSame(0, $bulk->run($ids, 'delete', ['keep_files' => true]), 'a repeat finds nothing left');
        $this->assertSame(3, $this->actions('deleted'));
    }

    /** Racing deletes of one selection: each item goes, and is logged, exactly once; a loser wrote nothing. */
    public function testRacingBulkDeletesDeleteAndLogEachItemOnce(): void
    {
        $ids = $this->three();
        $env = json_encode($this->t?->clientEnv(), JSON_THROW_ON_ERROR);
        $racers = [];
        for ($i = 0; $i < 4; $i++) {
            $p = proc_open([PHP_BINARY, __DIR__ . '/_d1-hosted-worker.php', $env, 'bulk-delete', implode(',', $ids)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $this->assertSame("ready\n", fgets($pipes[1]), 'every racer is up before any deletes');
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
        $done = 0;
        foreach ($out as $r) {
            $this->assertTrue(($r['ok'] ?? false) === true || in_array($r['code'] ?? 0, [404, 409], true), json_encode($r));
            $done += ($r['ok'] ?? false) === true ? (int)$r['n'] : 0;
        }
        $this->assertSame(3, $done, 'the deletes that committed cover each item once: ' . json_encode($out));
        $this->assertSame([], $this->rows('SELECT id FROM items'));
        $this->assertSame(3, $this->actions('deleted'), 'no history for a delete that did not happen');
    }

    /** Through the page: a confirmed bulk delete on the hosted database gives an undo token that brings the items back. */
    public function testTheBulkPageDeletesAndItsUndoRestores(): void
    {
        $ids = $this->three();
        Services::reset(true);
        Services::injectMock('apothekeDb', $this->db());
        $request = Services::request(null, false)->withMethod('POST');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $request->setGlobal('post', ['ids' => array_map('strval', $ids), 'action' => 'delete', 'confirm' => '1', 'back' => '/?select=1']);
        Services::injectMock('request', $request);
        $c = new BulkMergeController();
        $c->initController($request, Services::response(null, false), Services::logger());
        $r = $c->bulk();
        parse_str((string)parse_url($r->getHeaderLine('Location'), PHP_URL_QUERY), $q);
        $this->assertSame(['3', 'delete'], [$q['bulk_done'] ?? null, $q['bulk_act'] ?? null], $r->getHeaderLine('Location'));
        $this->assertSame([], $this->rows('SELECT id FROM items'));
        $this->assertIsString($q['undo'] ?? null, 'an undo token');
        D1HostedWritesTest::undoApply($this->t)->apply((string)$q['undo']);
        $this->assertSame($ids, array_map('intval', array_column($this->rows('SELECT id FROM items ORDER BY id'), 'id')));
        try {
            D1HostedWritesTest::undoApply($this->t)->apply((string)$q['undo']);
            $this->fail('an undo is used once');
        } catch (UserError $e) {
            $this->assertSame(410, $e->getCode());
        }
    }
}
