<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Auth\Accounts;
use Apotheke\Auth\AdminGuard;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\UserAdmin;
use Apotheke\Auth\UserRepository;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Items\UndoApply;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\D1\Connection;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Search\SearchIndex;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use Tests\Support\TestDatabase;

/**
 * P4-5 2-ii: the hosted API's write paths on the D1 driver against the real Installation handler: the driver's
 * unit rules, item save and delete, accounts, undo (once, under a real race), the search index, and the features
 * refused until they are converted. Skipped without node:sqlite and edge/node_modules.
 */
final class D1HostedWritesTest extends CIUnitTestCase
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
        putenv('APOTHEKE_CLOCK');
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

    /** @return list<array<string,mixed>> */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db(), $sql, $binds);
    }

    /** @return array<string,mixed> */
    private static function input(string $name, array $over = []): array
    {
        return $over + ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'min_qty' => null, 'notes' => '',
            'tags' => [], 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => ''];
    }

    public static function undoApply(TestDatabase $t): UndoApply
    {
        $db = $t->db;
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $units = UnitsOfWork::forSite($db, null);
        $items = $t->items($db, $units, $history);

        return new UndoApply($db, self::tokens($t), $items, new TaxonomyRepository($db, $clock, $history, $units), new TagRepository($db, $clock, $units), $history);
    }

    private static function tokens(TestDatabase $t): UndoTokens
    {
        return new UndoTokens($t->db, new Clock(), 600, static function (string $f): void {
        }, static function (string $f): void {
        });
    }

    private function items(): ItemRepository
    {
        return $this->t->items($this->db(), UnitsOfWork::forSite($this->db(), null));
    }

    // --- the driver's rules ------------------------------------------------------------------------------------

    public function testAUnitCannotOpenInsideATransactionAndNothingIsSent(): void
    {
        $db = $this->db();
        $units = UnitsOfWork::forSite($db, null);
        try {
            Writes::transaction($db, function () use ($db, $units): void {
                $db->query("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('a', 'a', 'a', '2026-01-01')");
                $units->run('tags', static fn (UnitOfWork $u) => $u->write("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('b', 'b', 'b', '2026-01-01')", [], 'b'));
            });
            $this->fail('a unit inside a D1 transaction must be refused');
        } catch (LogicException $e) {
            $this->assertSame('D1 cannot run a unit of work inside a transaction; run the unit on its own.', $e->getMessage());
        }
        $this->assertSame([], $this->rows('SELECT name FROM tags'), 'neither the queued write nor the unit reached the database');
    }

    public function testAWriteBesideTheOpenUnitAndASecondUnitAreRefused(): void
    {
        $db = $this->db();
        $units = UnitsOfWork::forSite($db, null);
        $other = UnitsOfWork::forSite($db, null);   // another registry on the same connection: it would not join
        foreach ([
            'D1 writes while a unit of work is open go through the unit.' => static fn () => $db->query("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('x', 'x', 'x', '2026-01-01')"),
            'D1 cannot open a second unit of work while one is open; join the open unit.' => static fn () => $other->run('tags', static fn (UnitOfWork $u) => null),
        ] as $message => $beside) {
            try {
                $units->run('tags', function (UnitOfWork $u) use ($beside): void {
                    $u->write("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('y', 'y', 'y', '2026-01-01')", [], 'y');
                    $beside();
                });
                $this->fail($message);
            } catch (\Apotheke\Core\Database\D1\UnitRuleViolation $e) {
                $this->assertSame($message, $e->getMessage(), 'thrown with DBDebug off too');
            }
        }
        $this->assertSame([], $this->rows('SELECT name FROM tags'), 'the unit failed whole: nothing was sent');
        $units->run('tags', static fn (UnitOfWork $u) => $u->write("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('z', 'z', 'z', '2026-01-01')", [], 'z'));
        $this->assertSame([['name' => 'z']], $this->rows('SELECT name FROM tags'), 'the counts are reset after a failed unit');
    }

    public function testAnAtomicStepRunsOneUnitAndRefusesASecondOrAStrayWrite(): void
    {
        $db = $this->db();
        $units = UnitsOfWork::forSite($db, null);
        $tag = static fn (string $n): \Closure => static fn (UnitOfWork $u) => $u->write('INSERT INTO tags (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?)', [$n, $n, $n, '2026-01-01'], $n);
        $this->assertSame('one', Writes::atomic($db, static function () use ($units, $tag): string {
            $units->run('tags', $tag('a'));

            return 'one';
        }));
        try {
            Writes::atomic($db, static function () use ($units, $tag): void {
                $units->run('tags', $tag('b'));
                $units->run('tags', $tag('c'));
            });
            $this->fail('a second unit in one atomic step must be refused');
        } catch (LogicException $e) {
            $this->assertSame('D1 runs one unit of work per atomic step; this step would commit in two batches.', $e->getMessage());
        }
        $this->assertSame(['a', 'b'], array_column($this->rows('SELECT name FROM tags ORDER BY name'), 'name'), 'c was never sent');
        try {
            Writes::atomic($db, static fn () => $db->query("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('d', 'd', 'd', '2026-01-01')"));
            $this->fail('a stray write in an atomic step must throw, DBDebug off too');
        } catch (\Apotheke\Core\Database\D1\UnitRuleViolation $e) {
            $this->assertSame('D1 writes in an atomic step go through its unit of work.', $e->getMessage());
        }
        $this->assertFalse((bool)$db->DBDebug);
        $this->assertSame(['a', 'b'], array_column($this->rows('SELECT name FROM tags ORDER BY name'), 'name'), 'd was never sent');
        $this->expectException(LogicException::class);
        Writes::atomic($db, static fn () => Writes::atomic($db, static fn () => null));
    }

    public function testAD1ConnectionNeverGetsAConnectionExecutor(): void
    {
        $this->expectExceptionMessage('The D1 driver runs units of work on its batch(), not on a connection executor.');
        new ConnectionExecutor($this->db());
    }

    public function testUnitsOfWorkOnAnotherD1ConnectionAreAWiringError(): void
    {
        $this->expectExceptionMessage('A write site and its units of work must use the same connection.');
        UnitsOfWork::forSite($this->db(), UnitsOfWork::on($this->t->connectAgain()->batch()));
    }

    // --- item CRUD, accounts -----------------------------------------------------------------------------------

    public function testItemsAreCreatedEditedAndDeletedAsOneBatchEach(): void
    {
        $repo = $this->items();
        $id = $repo->atomically(fn (): int => $repo->save(null, self::input('Hammer', ['tags' => ['tools']]), null, false));
        $this->assertSame([['name' => 'Hammer', 'quantity' => 1]], $this->rows('SELECT name, quantity FROM items WHERE id = ?', [$id]));
        $repo->save($repo->get($id), self::input('Claw hammer', ['quantity' => 3, 'tags' => ['tools', 'garage']]), null, false);
        $this->assertSame([['name' => 'Claw hammer', 'quantity' => 3]], $this->rows('SELECT name, quantity FROM items WHERE id = ?', [$id]));
        $this->assertSame(['garage', 'tools'], array_column($this->rows('SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY t.name'), 'name'));
        $this->assertTrue($repo->delete($repo->get($id)));
        $this->assertFalse($repo->delete(['id' => $id, 'name' => 'Claw hammer', 'quantity' => 3]), 'deleted already');
        $this->assertSame([], $this->rows('SELECT id FROM items'));
        $actions = array_column($this->rows('SELECT action FROM activity ORDER BY id'), 'action');
        $this->assertSame(['created', 'deleted'], [$actions[0], $actions[count($actions) - 1]]);
        $this->assertContains('edited', $actions);
        $this->assertSame([], $this->rows('SELECT id FROM tags'), 'unused tags dropped in the same batch');
        $this->assertTrue($repo->hosted());
    }

    public function testTwoSavesInOneAtomicStepAreRefusedBeforeTheSecond(): void
    {
        $repo = $this->items();
        try {
            $repo->atomically(function () use ($repo): void {
                $repo->save(null, self::input('One'), null, false);
                $repo->save(null, self::input('Two'), null, false);
            });
            $this->fail('two saves are two batches');
        } catch (LogicException $e) {
            $this->assertSame('D1 runs one unit of work per atomic step; this step would commit in two batches.', $e->getMessage());
        }
        $this->assertSame(['One'], array_column($this->rows('SELECT name FROM items'), 'name'));
    }

    public function testAnAccountIsCreatedInOneAtomicStep(): void
    {
        $db = $this->db();
        $clock = new Clock();
        $units = UnitsOfWork::forSite($db, null);
        $users = new UserRepository($db, new \Apotheke\Auth\SessionEpoch(), $units);
        $roles = new RoleRepository($db, $units);
        $admin = new UserAdmin($users, $roles, new Accounts($users, $roles, $clock), new AdminGuard(new CommandLine()), $clock);
        $password = 'pw-' . bin2hex(random_bytes(8));
        $uid = Writes::atomic($db, fn (): int => $admin->create('ada', $password, 'admin', $password));
        $this->assertSame([['username' => 'ada', 'role' => 'admin', 'status' => 'active']], $this->rows('SELECT username, role, status FROM users WHERE id = ?', [$uid]));
        $this->assertSame($uid, $users->atomically(fn (): int => $users->create('bob', 'x', 'viewer', 'active', '2026-01-01 00:00:00')) - 1);
    }

    /** The item form's new location or category would be a second unit: refused on D1 before anything is written. */
    public function testTheFormRefusesAnInlineNewLocationBeforeWriting(): void
    {
        $db = $this->db();
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $units = UnitsOfWork::forSite($db, null);
        \Config\Services::injectMock('itemRepository', $this->t->items($db, $units, $history));
        \Config\Services::injectMock('taxonomy', new TaxonomyRepository($db, $clock, $history, $units));
        \Config\Services::injectMock('undoTokens', self::tokens($this->t));
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        try {
            $request = \Config\Services::request();
            $request->setGlobal('post', ['name' => 'Kayak', 'quantity' => '1', 'location_id' => '__new', 'new_location' => 'Boathouse']);
            $request->setGlobal('server', $_SERVER);
            $c = new \Apotheke\Catalog\Controllers\ItemController();
            $c->initController($request, \Config\Services::response(), \Config\Services::logger());
            $r = $c->save();
            $this->assertSame(422, $r->getStatusCode());
            $this->assertSame(['ok' => false, 'error' => 'On the hosted catalog, add the new location first, then choose it for the item.'],
                json_decode((string)$r->getBody(), true));
            $this->assertSame([], $this->rows('SELECT id FROM locations'));
            $this->assertSame([], $this->rows('SELECT id FROM items'));
        } finally {
            unset($_SERVER['HTTP_ACCEPT']);
            \Config\Services::reset(true);
        }
    }

    public function testADuplicateWithPhotosIsRefusedAndWithoutCopiesTheItem(): void
    {
        $items = $this->items();
        $id = $items->save(null, self::input('Lamp'), null, false);
        $this->db()->query("INSERT INTO item_photos (item_id, file, position, created_at) VALUES (?, 'p.jpg', 0, '2026-01-01')", [$id]);
        $dup = new \Apotheke\BulkMerge\Duplicate($items, $this->t->photos($this->db(), UnitsOfWork::forSite($this->db(), null)),
            (new \ReflectionClass(\Apotheke\Media\ImageStore::class))->newInstanceWithoutConstructor());
        try {
            $dup->copy($id, true);
            $this->fail('the copy and its photos would be several units');
        } catch (UserError $e) {
            $this->assertSame([501, 'On the hosted catalog, duplicate the item without its photos.'], [$e->getCode(), $e->getMessage()]);
        }
        $this->assertSame(['Lamp'], array_column($this->rows('SELECT name FROM items'), 'name'));
        $copy = $dup->copy($id, false);
        $this->assertSame([['name' => 'Lamp (copy)']], $this->rows('SELECT name FROM items WHERE id = ?', [$copy]));
    }

    // --- undo -----------------------------------------------------------------------------------------------

    private function deletedWithToken(): array
    {
        $repo = $this->items();
        $id = $repo->save(null, self::input('Saw', ['tags' => ['wood']]), null, false);
        $tokens = self::tokens($this->t);
        $snap = $tokens->snapshot([$id]);
        $this->assertTrue($repo->delete($repo->get($id)));
        $token = $tokens->create('delete', 'Saw', $snap);
        $this->assertIsString($token);

        return [$id, $token];
    }

    public function testUndoOfADeletePutsTheItemBackOnce(): void
    {
        [$id, $token] = $this->deletedWithToken();
        $r = self::undoApply($this->t)->apply($token);
        $this->assertSame([$id], $r['ids']);
        $this->assertSame([['name' => 'Saw']], $this->rows('SELECT name FROM items WHERE id = ?', [$id]));
        $this->assertSame(['wood'], array_column($this->rows('SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = ?', [$id]), 'name'));
        $this->assertSame([], $this->rows('SELECT token FROM undo_tokens'));
        $this->assertSame(1, count($this->rows("SELECT id FROM activity WHERE action = 'restored'")));
        try {
            self::undoApply($this->t)->apply($token);
            $this->fail('a used token');
        } catch (UserError $e) {
            $this->assertSame([410, 'This can no longer be undone.'], [$e->getCode(), $e->getMessage()]);
        }
    }

    public function testUndoOfAnEditPutsTheOldValuesBack(): void
    {
        $repo = $this->items();
        $id = $repo->save(null, self::input('Drill', ['quantity' => 2]), null, false);
        $tokens = self::tokens($this->t);
        $snap = $tokens->snapshot([$id]);
        $repo->save($repo->get($id), self::input('Drill XL', ['quantity' => 9]), null, false);
        $token = (string)$tokens->create('edit', 'Drill', $snap);
        self::undoApply($this->t)->apply($token);
        $this->assertSame([['name' => 'Drill', 'quantity' => 2]], $this->rows('SELECT name, quantity FROM items WHERE id = ?', [$id]));
        $this->assertSame([], $this->rows('SELECT token FROM undo_tokens'));
    }

    /** Six requests undo the same delete at once: one puts the item back, the others answer 410 and change nothing. */
    public function testConcurrentUndosOfOneTokenRestoreOnce(): void
    {
        [$id, $token] = $this->deletedWithToken();
        $env = json_encode($this->t->clientEnv(), JSON_THROW_ON_ERROR);
        $racers = [];
        for ($i = 0; $i < 6; $i++) {
            $p = proc_open([PHP_BINARY, __DIR__ . '/_d1-hosted-worker.php', $env, 'undo', $token], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $this->assertSame("ready\n", fgets($pipes[1]), 'every racer is up before any applies');
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
        $this->assertCount(1, array_filter($out, static fn (?array $r): bool => ($r['ok'] ?? false) === true), json_encode($out));
        foreach ($out as $r) {
            $this->assertTrue(($r['ok'] ?? false) === true || ($r['code'] ?? 0) === 410, json_encode($r));
        }
        $this->assertSame([['n' => 1]], $this->rows('SELECT COUNT(*) AS n FROM items WHERE id = ?', [$id]));
        $this->assertSame([['n' => 1]], $this->rows("SELECT COUNT(*) AS n FROM activity WHERE action = 'restored'"));
        $this->assertSame([['n' => 1]], $this->rows('SELECT COUNT(*) AS n FROM item_tags WHERE item_id = ?', [$id]));
    }

    public function testPruneDeletesExpiredTokensThenReleasesTheirFiles(): void
    {
        $released = [];
        $tokens = new UndoTokens($this->db(), new Clock(), 600, static function (string $f) use (&$released): void {
            $released[] = $f;
        }, static function (string $f): void {
        });
        $id = $this->items()->save(null, self::input('Tent'), null, false);
        $snap = $tokens->snapshot([$id]);
        putenv('APOTHEKE_CLOCK=2000-01-01 00:00:00');
        $old = (string)(new UndoTokens($this->db(), new Clock(), 600, static function (string $f): void {
        }, static function (string $f): void {
        }))->create('delete', 'old', $snap, [], [['img', 'gone.jpg'], ['img', 'kept.jpg']]);
        putenv('APOTHEKE_CLOCK');
        $this->db()->query("UPDATE items SET image = 'kept.jpg' WHERE id = ?", [$id]);
        $this->assertSame([], $released);
        $live = (string)$tokens->create('delete', 'live', $snap);   // create() prunes first
        $this->assertSame([['token' => $live]], $this->rows('SELECT token FROM undo_tokens'));
        $this->assertSame(['gone.jpg'], $released, 'only the file nothing uses any more, after the delete committed');
        $this->assertSame(0, $tokens->prune());
        $this->assertSame(1, $tokens->prune(true));
        $this->assertSame([], $this->rows('SELECT token FROM undo_tokens'));
        $this->assertNotSame('', $old);
        $this->expectExceptionMessage('A guarded undo prune (a restore) is not available on D1.');
        $tokens->prune(true, static function (): void {
        });
    }

    // --- search index -----------------------------------------------------------------------------------------

    public function testTheSearchIndexRebuildsAsOneBatch(): void
    {
        $repo = $this->items();
        $repo->save(null, self::input('Screwdriver set', ['notes' => 'red handle']), null, false);
        $clock = new Clock();
        $history = new ActivityLogger($this->db(), $clock, static fn (): ?string => null);
        $index = new SearchIndex($this->db(), new TaxonomyRepository($this->db(), $clock, $history, UnitsOfWork::forSite($this->db(), null)), false);
        $index->ensure();
        $this->assertSame([['other' => 'Notes: red handle']], $this->rows('SELECT other FROM search_docs'));
        $fp = $this->rows("SELECT v FROM search_meta WHERE k = 'fingerprint'");
        $this->assertSame([['v' => $index->fingerprint()]], $fp);
        $repo->save(null, self::input('Wrench'), null, false);
        $this->db()->query("UPDATE items SET updated_at = '2030-01-01 00:00:00'");
        $index->ensure();
        $this->assertSame(2, count($this->rows('SELECT item_id FROM search_docs')));
        $this->assertNotSame($fp, $this->rows("SELECT v FROM search_meta WHERE k = 'fingerprint'"));
    }

    // --- refused until converted ---------------------------------------------------------------------------------

    /** @param class-string $class */
    private function bare(string $class): object
    {
        $obj = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $db = $this->db();
        (function () use ($db): void {
            $this->db = $db;
        })->call($obj);

        return $obj;
    }

    public function testFeaturesNotConvertedYetAreRefusedBeforeAnyWrite(): void
    {
        $cases = [
            'Bulk editing' => fn () => $this->bare(\Apotheke\BulkMerge\BulkService::class)->run([1], 'move'),
            'CSV import' => fn () => $this->bare(\Apotheke\ImportExport\Imports::class)->upload(null),
            'CSV import ' => fn () => $this->bare(\Apotheke\ImportExport\Imports::class)->run([], true),
            'CSV import  ' => fn () => $this->bare(\Apotheke\ImportExport\Imports::class)->commitRows([], (new \ReflectionClass(\Apotheke\Core\Jobs\JobContext::class))->newInstanceWithoutConstructor()),
            'Restoring a backup' => fn () => $this->bare(\Apotheke\Backup\Restorer::class)->restore('/nonexistent.zip', false),
            'Restoring a backup ' => fn () => \Apotheke\Backup\RestoreFence::claim($this->db(), 'token'),
            'Restoring a backup  ' => fn () => \Apotheke\Backup\RestoreMutation::replace($this->db(), 'token',
                (new \ReflectionClass(\Apotheke\Core\Storage\R2Storage::class))->newInstanceWithoutConstructor(), 'k', 'b'),
        ];
        foreach ($cases as $what => $call) {
            try {
                $call();
                $this->fail(trim($what) . ' must be refused');
            } catch (UserError $e) {
                $this->assertSame([501, trim($what) . ' is not available on the hosted database yet.'], [$e->getCode(), $e->getMessage()]);
            }
        }
        $this->assertSame([], $this->rows("SELECT run FROM restore_marker"));
    }

    public function testTheRefusalIsANoOpOffTheHostedDatabase(): void
    {
        $sqlite = TestDatabase::create('sqlite');
        try {
            Writes::refuseOnD1($sqlite->db, 'Anything');
            $this->assertFalse($sqlite->items()->hosted());
        } finally {
            $sqlite->drop();
        }
    }
}
