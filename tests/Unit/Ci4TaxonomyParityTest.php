<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/src/items.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Libraries/Text.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Taxonomy/LocationTree.php';

use Apotheke\Core\Libraries\Text;
use Apotheke\Taxonomy\LocationTree;

/**
 * Golden (PLAN step 8, Taxonomy): /locations and /categories on CI4 against
 * legacy. The same requests go to a legacy and a CI4 server with the same
 * data: the Manage pages, add (with a parent), rename, move, default,
 * delete and its confirmation page, the 404s and 422s, fetch answers, the
 * permission and CSRF refusals. Answers are byte-identical except the asset
 * cache busters and the per-session CSRF token, and the tables (with the
 * item history) end up the same.
 */
final class Ci4TaxonomyParityTest extends TestCase
{
    /** natkey(), location_tree_order() and tax_option_label() on the same input. */
    public function testThePureHelpersMatchLegacy(): void
    {
        foreach (['Box 2', 'box 10', ' Ünïcode 007 ', '', 'a1b22c333', str_repeat('9', 30), str_repeat('x 1 ', 200)] as $s) {
            $this->assertSame(natkey($s), Text::natkey($s), $s);
        }
        $row = static fn (int $id, string $name, ?int $parent, int $n = 0, int $default = 0): array => ['id' => $id, 'name' => $name, 'is_default' => $default, 'parent_id' => $parent, 'n' => $n];
        $sets = [
            [],
            [$row(1, 'A', null, 2), $row(2, 'B', 1, 3, 1), $row(3, 'C', 2, 1), $row(4, 'D', null)],
            // a missing parent, a self-parent and a cycle
            [$row(1, 'A', 99, 1), $row(2, 'B', 2), $row(3, 'C', 4, 5), $row(4, 'D', 3, 7), $row(5, 'E', 3)],
        ];
        foreach ($sets as $i => $rows) {
            $legacy = location_tree_order($rows);
            $sorted = static fn (array $list): array => array_map(static function (array $r): array {
                ksort($r);

                return $r;
            }, $list);
            $this->assertSame($sorted($legacy), $sorted(LocationTree::order($rows)), 'set ' . $i);
            foreach ($legacy as $l) {
                $this->assertSame(tax_option_label($l), LocationTree::optionLabel($l));
            }
        }
    }

    public function testTheSameRequestsGetTheSameAnswers(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('taxonomy-' . $app);
            $server = new Server($dir, [], $app);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $answers[$app] = $this->visit($server, $dir);
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
        $this->assertGreaterThan(60, count($answers['legacy']));
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        // The cases are what they claim to be.
        $l = $answers['legacy'];
        $this->assertSame(200, $l['GET /locations']['status']);
        $this->assertStringContainsString('<li class="loc is-default loc-d2" id="loc-3" data-depth="2">', (string)$l['GET /locations']['body']);
        $this->assertStringContainsString('move up one level into “Garage”', (string)$l['GET /locations/2/delete']['body']);
        $this->assertSame(['ok' => true, 'path' => 'Garage › Shelf 2'], $l['move Shelf 2 fetch']['json']);
        $this->assertSame('no-store', $l['GET /categories']['cache-control']);
        $this->assertSame([303, '/locations?done=added#loc-5'], [$l['add Box 2 in Garage']['status'], $l['add Box 2 in Garage']['location']]);
        $this->assertSame('/locations?done=exists#loc-1', $l['add garage again']['location']);
        $this->assertSame(422, $l['add empty']['status']);
        $this->assertStringContainsString('Location name cannot be empty.', (string)$l['add empty']['body']);
        $this->assertSame(['ok' => true, 'id' => 6, 'name' => 'Bin', 'created' => true], $l['add fetch']['json']);
        $this->assertSame(422, $l['move into own child']['status']);
        $this->assertSame(['ok' => false, 'error' => 'Locations can be nested at most 6 levels deep.'], $l['move too deep fetch']['json']);
        $this->assertSame(404, $l['category parent']['status']);
        $this->assertSame(404, $l['GET /locations/999/delete']['status']);
        $this->assertStringContainsString('Location not found.', (string)$l['GET /locations/999/delete']['body']);
        $this->assertStringContainsString('Page not found.', (string)$l['GET /locations/1']['body']);
        $this->assertSame('/locations/2/delete', $l['delete Shelf 2 without a choice']['location']);
        $this->assertSame(403, $l['viewer adds']['status']);
        $this->assertSame(419, $l['add without csrf']['status']);
        $this->assertSame([303, 200, 303], [$l['eda adds']['status'], $l['eda GET /locations']['status'], $l['eda deletes']['status']]);
        $this->assertStringContainsString('is-guest', (string)$l['guest GET /locations']['body']);
        $this->assertSame([200, 303, '/login'], [$l['guest GET /categories']['status'], $l['guest adds']['status'], $l['guest adds']['location']], 'the guest must sign in to change');
        $this->assertSame([303, '/login', 401], [$l['signed-out GET /locations']['status'], $l['signed-out GET /locations']['location'], $l['signed-out renames fetch']['status']]);
        foreach (['fail add', 'fail add fetch', 'fail rename', 'fail delete: item update', 'fail delete: history insert', 'fail delete: history insert fetch'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
        $this->assertStringContainsString('"actor": "ada"', (string)$l['tables']['body']);
        $this->assertStringContainsString('"item_name": "Nail",
            "action": "moved",
            "details": "{\\"from\\":\\"Box 10\\",\\"to\\":\\"\\",\\"reason\\":\\"location deleted\\"}",
            "actor": null', (string)$l['tables']['body']);
        $this->assertSame([303, '/locations?done=deleted'], [$l['delete Shelf 2 into an array']['status'], $l['delete Shelf 2 into an array']['location']], 'legacy (int) of an array is 1');
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['ada', 'ada', password_hash('long-enough', PASSWORD_DEFAULT), 'admin', 'active', '2026-01-01 00:00:00']);
        $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['vera', 'vera', password_hash('long-enough', PASSWORD_DEFAULT), 'viewer', 'active', '2026-01-01 00:00:00']);
        $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['eda', 'eda', password_hash('long-enough', PASSWORD_DEFAULT), 'editor', 'active', '2026-01-01 00:00:00']);
        $loc = $pdo->prepare('INSERT INTO locations (name, name_key, sort_key, parent_id, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        // 1 Garage, 2 Shelf 2 (in 1), 3 Box 10 (in 2), 4 Attic <x> & "y"
        foreach ([['Garage', null, 0], ['Shelf 2', 1, 0], ['Box 10', 2, 1], ['Attic <x> & "y"', null, 0]] as [$name, $parent, $default]) {
            $loc->execute([$name, mb_strtolower($name), mb_strtolower($name), $parent, $default, '2026-01-01 00:00:00']);
        }
        $cat = $pdo->prepare('INSERT INTO categories (name, name_key, sort_key, is_default, created_at) VALUES (?, ?, ?, ?, ?)');
        foreach ([['Tools', 1], ['Food', 0], ['Spare', 0]] as [$name, $default]) {
            $cat->execute([$name, mb_strtolower($name), mb_strtolower($name), $default, '2026-01-01 00:00:00']);
        }
        $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
        foreach ([['Hammer', 2, 1], ['Apple', null, 2], ['Saw', 1, 1], ['Nail', 3, null], ['Pin', 2, 3]] as [$name, $l, $c]) {
            $item->execute([$name, $l, $c, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
            $ids[$u['username']] = (int)$u['id'];
        }
        // A browser holding a live session (with a CSRF token), signed in as $account when given.
        $signIn = function (?string $account) use ($server, $pdo, $ids, $dir): array {
            $client = $server->client();
            $token = bin2hex(random_bytes(32));
            $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';';
            if ($account !== null) {
                $row = $pdo->query('SELECT id, password_hash FROM users WHERE id = ' . $ids[$account])->fetch();
                $epoch = substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $session = 'uid|i:' . $ids[$account] . ';auth_epoch|s:16:"' . $epoch . '";' . $session;
            }
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, $session);
            $client->cookies['apothekesid'] = $sid;

            return [$client, $token];
        };
        $out = [];
        $record = function (string $case, array $r, string $token) use (&$out): void {
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            // GET pages and JSON send exactly no-store; other answers keep each stack's own no-store variant.
            $cache = $r['headers']['cache-control'] ?? '';
            $cache = $r['status'] === 200 || $r['json'] !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache);
            $out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'cache-control' => $cache,
                'json' => $r['json'], 'body' => $r['json'] === null ? str_replace($token, 'SESSION-TOKEN', (string)$body) : null];
        };
        $fetch = ['X-Requested-With: fetch'];
        // Sign-in on, as the admin "ada", so the history names the actor.
        $this->setting($pdo, 'auth_enabled', 'true');
        $this->setting($pdo, 'auth_guest_disabled', 'true');
        $as = function (string $case, string $method, string $path, array $fields = [], array $headers = [], ?string $who = 'ada', bool $csrf = true) use ($signIn, $record): void {
            [$client, $token] = $signIn($who);
            if ($method === 'POST' && $csrf) {
                $fields = ['csrf' => $token] + $fields;
            }
            $record($case, $client->req($method, $path, $fields, [], $headers), $token);
        };
        $views = function (string $tag) use ($as): void {
            foreach (['/locations', '/categories', '/locations/1/delete', '/locations/2/delete', '/categories/1/delete'] as $path) {
                $as('GET ' . $path . $tag, 'GET', $path);
            }
        };
        $views('');
        foreach (['added', 'exists', 'renamed', 'default', 'nodefault', 'deleted', 'moved', 'bogus'] as $done) {
            $as('GET /locations?done=' . $done, 'GET', '/locations?done=' . $done);
            $as('GET /categories?done=' . $done, 'GET', '/categories?done=' . $done);
        }
        $as('GET /locations?done[]=x', 'GET', '/locations?done[]=x');
        // Add.
        $as('add Box 2 in Garage', 'POST', '/locations', ['name' => '  Box   2 ', 'parent' => '1']);
        $as('add garage again', 'POST', '/locations', ['name' => 'GARAGE', 'parent' => '4']);
        $as('add empty', 'POST', '/locations', ['name' => "  \t "]);
        $as('add fetch', 'POST', '/locations', ['name' => 'Bin'], $fetch);
        $as('add empty fetch', 'POST', '/categories', ['name' => ''], $fetch);
        $as('add in a missing parent', 'POST', '/locations', ['name' => 'Lost', 'parent' => '999']);
        $as('add with a bad parent', 'POST', '/locations', ['name' => 'Odd', 'parent' => '1x']);
        $as('add array name', 'POST', '/categories', ['name' => ['a', 'b']]);
        $as('add category with parent', 'POST', '/categories', ['name' => 'Toys', 'parent' => '1']);
        $as('add category 10', 'POST', '/categories', ['name' => 'Box 10']);
        // Move (locations only).
        $as('move Shelf 2 into Attic', 'POST', '/locations/2/parent', ['parent' => '4']);
        $as('move Shelf 2 fetch', 'POST', '/locations/2/parent', ['parent' => '1'], $fetch);
        $as('move to top', 'POST', '/locations/3/parent', ['parent' => '']);
        $as('move unchanged', 'POST', '/locations/3/parent', []);
        $as('move into own child', 'POST', '/locations/1/parent', ['parent' => '2']);
        $as('move into itself fetch', 'POST', '/locations/1/parent', ['parent' => '1'], $fetch);
        $as('move to a missing parent', 'POST', '/locations/1/parent', ['parent' => '999']);
        $as('move bad parent', 'POST', '/locations/1/parent', ['parent' => 'x']);
        $as('move array parent', 'POST', '/locations/1/parent', ['parent' => ['1']]);
        $as('category parent', 'POST', '/categories/1/parent', ['parent' => '2']);
        // A chain six deep under Box 10, then one more level.
        $id = static fn (string $name): string => (string)$pdo->query('SELECT id FROM locations WHERE name = ' . $pdo->quote($name))->fetchColumn();
        $parent = $id('Box 10');
        for ($i = 1; $i <= 6; $i++) {
            $as('chain ' . $i, 'POST', '/locations', ['name' => 'Level ' . $i, 'parent' => $parent]);
            $parent = $id('Level ' . $i);
        }
        $as('move too deep fetch', 'POST', '/locations/1/parent', ['parent' => $id('Level 5')], $fetch);
        $as('add too deep', 'POST', '/locations', ['name' => 'Level 7', 'parent' => $id('Level 6')]);
        $views(' after moves');
        // Rename.
        $as('rename Garage', 'POST', '/locations/1/rename', ['name' => 'Garage & <Yard>']);
        $as('rename to a taken name', 'POST', '/locations/1/rename', ['name' => 'bin']);
        $as('rename to a taken name fetch', 'POST', '/categories/1/rename', ['name' => 'FOOD'], $fetch);
        $as('rename same name new case', 'POST', '/categories/1/rename', ['name' => 'TOOLS']);
        $as('rename empty', 'POST', '/categories/2/rename', ['name' => '']);
        $as('rename fetch', 'POST', '/categories/2/rename', ['name' => 'Snacks'], $fetch);
        // Default.
        $as('default Food', 'POST', '/categories/2/default', ['on' => '1']);
        $as('default off', 'POST', '/categories/2/default', ['on' => '0']);
        $as('default no flag', 'POST', '/locations/4/default', []);
        $as('default array flag', 'POST', '/locations/4/default', ['on' => ['1']]);
        $as('default fetch', 'POST', '/locations/1/default', ['on' => '1'], $fetch);
        // Not found and wrong methods.
        foreach (['GET /locations/999/delete', 'GET /locations/1', 'GET /locations/1/rename', 'GET /categories/1/parent', 'GET /locations/999',
            'POST /locations/1', 'POST /locations/999/rename', 'POST /categories/999/delete', 'PUT /locations', 'DELETE /locations/1/delete'] as $case) {
            [$method, $path] = explode(' ', $case);
            $as($case, $method, $path);
        }
        // Delete.
        $as('delete Shelf 2 without a choice', 'POST', '/locations/2/delete', []);
        $as('delete Shelf 2 into itself', 'POST', '/locations/2/delete', ['action' => 'move', 'target' => '2']);
        $as('delete Shelf 2 into a missing one', 'POST', '/locations/2/delete', ['action' => 'move', 'target' => '999']);
        $as('delete Shelf 2 into an array', 'POST', '/locations/2/delete', ['action' => 'move', 'target' => ['4']]);
        $as('delete Shelf 2 again', 'POST', '/locations/2/delete', ['action' => 'move', 'target' => '4']);
        $as('delete Level 3 fetch', 'POST', '/locations/' . $id('Level 3') . '/delete', ['action' => 'unassign'], $fetch);
        $as('delete empty Bin', 'POST', '/locations/6/delete', []);
        $as('delete Tools unassign', 'POST', '/categories/1/delete', ['action' => 'unassign']);
        $as('delete Spare into Food', 'POST', '/categories/3/delete', ['action' => 'move', 'target' => '2']);
        $views(' after deletes');
        // A viewer may look but not change; CSRF is checked.
        $as('viewer sees', 'GET', '/locations', [], [], 'vera');
        $as('viewer adds', 'POST', '/locations', ['name' => 'Nope'], [], 'vera');
        $as('viewer renames fetch', 'POST', '/locations/1/rename', ['name' => 'Nope'], $fetch, 'vera');
        $as('add without csrf', 'POST', '/locations', ['name' => 'Nope'], [], 'ada', false);
        $as('rename without csrf fetch', 'POST', '/categories/2/rename', ['name' => 'Nope'], $fetch, 'ada', false);
        // The editor may change; the guest (guest access on) may only look; signed out goes to sign-in.
        foreach (['eda' => ['eda', 'true'], 'guest' => [null, 'false'], 'signed-out' => [null, 'true']] as $visitor => [$who, $guestOff]) {
            $this->setting($pdo, 'auth_guest_disabled', $guestOff);
            foreach (['/locations', '/categories', '/locations/1/delete', '/locations/999'] as $path) {
                $as($visitor . ' GET ' . $path, 'GET', $path, [], [], $who);
            }
            $as($visitor . ' adds', 'POST', '/locations', ['name' => 'Shed ' . $visitor], [], $who);
            $as($visitor . ' renames fetch', 'POST', '/categories/2/rename', ['name' => 'Treats ' . $visitor], $fetch, $who);
            $as($visitor . ' moves fetch', 'POST', '/locations/7/parent', ['parent' => '1'], $fetch, $who);
            $as($visitor . ' deletes', 'POST', '/categories/5/delete', ['action' => 'unassign'], [], $who);
        }
        $this->setting($pdo, 'auth_guest_disabled', 'true');
        // Sign-in off: no actor in the history.
        $this->setting($pdo, 'auth_enabled', 'false');
        $as('off delete Box 10', 'POST', '/locations/3/delete', ['action' => 'unassign']);
        $as('off GET /locations', 'GET', '/locations');

        // M-25: a statement that fails (a trigger stands in for a broken disk or constraint) is a 500 and changes nothing.
        $snapshot = static function () use ($pdo): string {
            $out = [];
            foreach (['locations', 'categories', 'items', 'activity'] as $table) {
                $out[] = $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll();
            }

            return json_encode($out);
        };
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        foreach ([['fail add', 'locations', 'INSERT', '/locations', ['name' => 'Shed']], ['fail add fetch', 'locations', 'INSERT', '/locations', ['name' => 'Shed']],
            ['fail rename', 'categories', 'UPDATE', '/categories/2/rename', ['name' => 'Treats']],
            ['fail delete: item update', 'items', 'UPDATE', '/locations/1/delete', ['action' => 'move', 'target' => '7']],
            ['fail delete: history insert', 'activity', 'INSERT', '/locations/1/delete', ['action' => 'move', 'target' => '7']],
            ['fail delete: history insert fetch', 'activity', 'INSERT', '/categories/2/delete', ['action' => 'unassign']]] as [$case, $table, $op, $path, $fields]) {
            $pdo->exec($pg ? "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()"
                : "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = $snapshot();
            $as($case, 'POST', $path, $fields, str_ends_with($case, 'fetch') ? $fetch : []);
            $out[$case]['unchanged'] = $before === $snapshot();
            $pdo->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
        }

        $tables = [];
        foreach (['SELECT id, name, name_key, sort_key, is_default, parent_id FROM locations ORDER BY id', 'SELECT id, name, name_key, sort_key, is_default FROM categories ORDER BY id',
            'SELECT id, name, location_id, category_id, updated_at FROM items ORDER BY id', 'SELECT item_id, item_name, action, details, actor FROM activity ORDER BY id'] as $sql) {
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$row) {
                if (isset($row['updated_at'])) {
                    $row['updated_at'] = $row['updated_at'] === '2026-01-01 00:00:00' ? 'seed' : 'changed';
                }
                $row = array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
            }
            $tables[] = $rows;
        }
        $out['tables'] = ['body' => json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];

        return $out;
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, '2026-01-01']);
    }
}
