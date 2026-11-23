<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * API P4: GET /api/v1/locations and GET /api/v1/categories (view permission), the read half of the catalog: what the
 * Manage pages and the list's filters show. Locations come in the page's tree order with parent, depth, path and
 * total; categories are flat; both count the items that have none. Refusals are the pages' refusals.
 */
final class Ci4ApiTaxonomyTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private const READS = ['/api/v1/locations', '/api/v1/categories'];

    private const LOCATION_KEYS = ['id', 'name', 'is_default', 'items', 'parent_id', 'depth', 'path', 'total'];

    private const CATEGORY_KEYS = ['id', 'name', 'is_default', 'items'];

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-taxonomy');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
        $pdo = testdb(self::$dir);
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $user->execute(['vera', 'vera', $hash, 'viewer', 'active', '2026-01-01 00:00:00']);
        $user->execute(['nora', 'nora', $hash, 'blind', 'active', '2026-01-02 00:00:00']);
        $pdo->prepare('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?, ?, ?, 0, ?)')
            ->execute(['blind', 'No permissions', '[]', '2026-01-01 00:00:00']);

        $loc = $pdo->prepare('INSERT INTO locations (name, name_key, is_default, parent_id, created_at, sort_key) VALUES (?, ?, ?, ?, ?, ?)');
        $loc->execute(['Garage', 'garage', 1, null, '2026-01-01 00:00:00', 'garage']);
        $loc->execute(['Basement', 'basement', 0, null, '2026-01-01 00:00:00', 'basement']);
        $loc->execute(['Shelf A', 'shelf a', 0, 1, '2026-01-01 00:00:00', 'shelf a']);
        $pdo->exec("INSERT INTO locations (name, name_key, is_default, parent_id, created_at, sort_key) VALUES ('Shelf B', 'shelf b', 0, (SELECT id FROM locations WHERE name = 'Shelf A'), '2026-01-01 00:00:00', 'shelf b')");

        $cat = $pdo->prepare('INSERT INTO categories (name, name_key, is_default, created_at, sort_key) VALUES (?, ?, ?, ?, ?)');
        $cat->execute(['Tools', 'tools', 1, '2026-01-01 00:00:00', 'tools']);
        $cat->execute(['Garden', 'garden', 0, '2026-01-01 00:00:00', 'garden']);

        $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, quantity, notes, search, created_at, updated_at, sort_key) VALUES (?, ?, ?, 1, \'\', ?, ?, ?, ?)');
        $at = '2026-01-01 00:00:00';
        $item->execute(['Drill', 1, 1, 'drill', $at, $at, 'drill']);          // Garage + Tools
        $item->execute(['Rake', 1, 2, 'rake', $at, $at, 'rake']);            // Garage + Garden
        $item->execute(['Saw', 4, null, 'saw', $at, $at, 'saw']);            // Shelf B, no category
        $item->execute(['Spare key', null, 1, 'spare key', $at, $at, 'spare key']);   // nothing, Tools
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    /** The tree, parents before their children, with the counts the page shows. */
    public function testLocationsAreThePagesTree(): void
    {
        $r = $this->signedIn('admin')->rest('GET', '/api/v1/locations');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/locations', $r);
        $this->assertSame(['data', 'items_without_location'], array_keys($r['json']));
        $this->assertSame(1, $r['json']['items_without_location']);
        $this->assertSame(['Basement', 'Garage', 'Shelf A', 'Shelf B'], array_column($r['json']['data'], 'name'));
        $rows = array_column($r['json']['data'], null, 'name');
        foreach ($r['json']['data'] as $l) {
            $this->assertSame(self::LOCATION_KEYS, array_keys($l), $l['name']);
        }
        $this->assertSame(['is_default' => true, 'items' => 2, 'parent_id' => null, 'depth' => 0, 'path' => 'Garage', 'total' => 3],
            array_intersect_key($rows['Garage'], array_flip(['is_default', 'items', 'parent_id', 'depth', 'path', 'total'])));
        $this->assertSame(['items' => 0, 'parent_id' => 1, 'depth' => 1, 'path' => 'Garage › Shelf A'], array_intersect_key($rows['Shelf A'], array_flip(['items', 'parent_id', 'depth', 'path'])));
        $this->assertSame(['items' => 1, 'parent_id' => 3, 'depth' => 2, 'path' => 'Garage › Shelf A › Shelf B'], array_intersect_key($rows['Shelf B'], array_flip(['items', 'parent_id', 'depth', 'path'])));
        $this->assertSame(['is_default' => false, 'items' => 0, 'total' => 0], array_intersect_key($rows['Basement'], array_flip(['is_default', 'items', 'total'])));

        // The page shows the same entries in the same order.
        $page = $this->signedIn('admin')->get('/locations');
        $this->assertSame(200, $page['status']);
        $at = array_map(fn (string $n): int => (int)strpos($page['body'], '>' . $n . '<'), array_column($r['json']['data'], 'name'));
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at, 'the page lists them in the same order');
        $this->assertSame('no-store', $r['headers']['cache-control']);
    }

    /** Categories are flat, in the page's order, with their own counts. */
    public function testCategoriesAreFlat(): void
    {
        $r = $this->signedIn('admin')->rest('GET', '/api/v1/categories');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/categories', $r);
        $this->assertSame(['data', 'items_without_category'], array_keys($r['json']));
        $this->assertSame(1, $r['json']['items_without_category']);
        $this->assertSame(['Garden', 'Tools'], array_column($r['json']['data'], 'name'));
        foreach ($r['json']['data'] as $c) {
            $this->assertSame(self::CATEGORY_KEYS, array_keys($c), $c['name']);
        }
        $this->assertSame([['name' => 'Garden', 'is_default' => false, 'items' => 1], ['name' => 'Tools', 'is_default' => true, 'items' => 2]],
            array_map(static fn (array $c): array => array_intersect_key($c, array_flip(['name', 'is_default', 'items'])), $r['json']['data']));
        $this->assertSame('no-store', $r['headers']['cache-control']);
    }

    /** The refusals are the pages': signed out is 401 here and the sign-in page there; no view is 403 both. */
    public function testRefusalsMatchThePages(): void
    {
        $anon = $this->client();
        foreach (self::READS as $path) {
            $r = $anon->rest('GET', $path);
            $this->assertSame([401, 'auth.required'], [$r['status'], $r['json']['code'] ?? null], $path);
            ApiContract::assertMatches('GET', $path, $r);
        }
        $this->assertSame(303, $anon->get('/locations')['status']);
        $this->assertSame(303, $anon->get('/categories')['status']);

        $nora = $this->signedIn('nora');
        foreach (self::READS as $path) {
            $r = $nora->rest('GET', $path);
            $this->assertSame([403, 'permission.denied'], [$r['status'], $r['json']['code'] ?? null], $path);
            ApiContract::assertMatches('GET', $path, $r);
        }
        $this->assertSame(403, $nora->get('/locations')['status']);
        $this->assertSame(403, $nora->get('/categories')['status']);

        $vera = $this->signedIn('vera');
        foreach (self::READS as $path) {
            $this->assertSame(200, $vera->rest('GET', $path)['status'], $path . ': view is enough');
        }
    }

    /** Reads only: a write is not routed in this release (405 with Allow), as for the other read-only endpoints. */
    public function testWritesAreNotRouted(): void
    {
        $admin = $this->signedIn('admin');
        $token = $admin->rest('GET', '/api/v1/session')['json']['csrf_token'];
        foreach (self::READS as $path) {
            foreach (['POST', 'PATCH', 'DELETE'] as $method) {
                $r = $admin->rest($method, $path, [], ['X-CSRF-Token: ' . $token]);
                $this->assertSame([405, 'GET, HEAD'], [$r['status'], $r['headers']['allow'] ?? null], $method . ' ' . $path);
            }
        }
    }

    private function client(): Client
    {
        $this->assertNotNull(self::$server);

        return self::$server->client();
    }

    private function signedIn(string $user): Client
    {
        $c = $this->client();
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $in = $c->rest('POST', '/api/v1/session', ['username' => $user, 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);

        return $c;
    }
}
