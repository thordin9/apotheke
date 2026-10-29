<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/legacy-bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/items.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Libraries/Text.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Search/SearchIndex.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Catalog/ListFilters.php';

use Apotheke\Catalog\ListFilters;
use Apotheke\Search\SearchIndex;

/**
 * Golden (PLAN step 9, Catalog): the list, search, the overview, saved
 * filters, the star and "recently viewed" on CI4 against legacy. The same
 * requests go to a legacy and a CI4 server with the same data and a frozen
 * clock: pages, fetch answers, filters, sorts, paging, typo search, toasts,
 * the 404s and 422s, permission and CSRF refusals and forced write failures.
 * Answers are byte-identical except the asset cache busters and the
 * per-session CSRF token, and the tables end up the same.
 */
final class Ci4CatalogParityTest extends TestCase
{
    /** search_tokens(), search_parts(), search_lev(), search_highlight(), filters_from() and filters_active() on the same input. */
    public function testThePureHelpersMatchLegacy(): void
    {
        $queries = ['', '  Hammer  saw ', '"exact phrase" -minus', 'Ünïcode straße ÆØÅ', 'a5 4006381333931', 'x-ray/v2 (b)', str_repeat('long ', 60), "tab\tnew\nline", '*', 'ß', 'İstanbul'];
        foreach ($queries as $q) {
            $this->assertSame(search_tokens($q), SearchIndex::tokens($q), $q);
            foreach (search_tokens($q) as $tok) {
                $this->assertSame(search_parts($tok), SearchIndex::parts($tok), $tok);
            }
        }
        foreach ([['', ''], ['kitten', 'sitting'], ['screwdrivr', 'screwdriver'], ['straße', 'strasse'], ['äbc', 'abc']] as [$a, $b]) {
            $this->assertSame(search_lev($a, $b), SearchIndex::lev($a, $b), "$a/$b");
        }
        foreach ([['Hammer & <b> claw', ['ham', 'claw']], ['Straße Ünï', ['strasse', 'uni']], ['no match', ['zz']], ['aaa', ['a']], ['', ['x']]] as [$text, $terms]) {
            $this->assertSame(search_highlight($text, $terms), SearchIndex::highlight($text, $terms), $text);
        }
        $sources = [[], ['loc' => '1', 'cat' => 'none', 'tag' => '2', 'stock' => 'low', 'exp' => 'soon', 'fav' => '1', 'lend' => 'overdue'], ['loc' => ['1'], 'cat' => 'x', 'tag' => '-1', 'exp' => 'bogus'],
            ['low' => '1', 'expired' => '1', 'lent' => '1', 'fav' => 'yes'], ['loc' => ' 3 ', 'cat' => '0', 'tag' => '01']];
        foreach ($sources as $i => $src) {
            $this->assertSame(filters_from($src), ListFilters::from($src), 'source ' . $i);
            $this->assertSame(filters_active(filters_from($src)), ListFilters::active(ListFilters::from($src)), 'source ' . $i);
        }
    }

    public function testTheSameRequestsGetTheSameAnswers(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('catalog-' . $app);
            $server = new Server($dir, ['APOTHEKE_CLOCK' => '2026-06-15 21:30:00', 'PAGE_SIZE' => '4'], $app);
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
        $this->assertSame([200, 'no-store'], [$l['GET /']['status'], $l['GET /']['cache-control']]);
        $this->assertStringContainsString('Hammer &amp; &lt;b&gt;', (string)$l['GET /?all=1']['body']);
        $this->assertStringContainsString('Recently viewed', (string)$l['GET /']['body']);
        $this->assertSame(4, substr_count((string)$l['GET /']['body'], 'class="card-title"'), 'page size 4');
        $this->assertTrue($l['GET / fetch page 2']['json']['ok'] ?? false);
        $this->assertSame(['Screwdriver set'], titles(['json' => $l['search screwdrivr fetch']['json']]), 'typo search');
        if (test_driver() !== 'pgsql') {
            $this->assertSame([200, ['Saw']], [$l['no fts GET /?q=saw fetch']['status'], titles(['json' => $l['no fts GET /?q=saw fetch']['json']])], 'LIKE search without FTS5');
        }
        $this->assertStringContainsString('Expired', (string)$l['GET /?exp=expired']['body']);
        $this->assertStringContainsString('Saved “Saw”', (string)$l['GET /?saved=3']['body']);
        $this->assertSame(200, $l['GET /stats']['status']);
        foreach (['GET /stats/', 'GET /locations/', 'GET /./stats', 'GET /%2e/stats', 'GET /a/../stats', 'POST /filters/', 'POST /item/1/fav/ fetch'] as $case) {
            $this->assertSame(404, $l[$case]['status'], $case);
        }
        $this->assertSame(200, $l['GET //stats']['status'], 'legacy reads //stats as host "stats", path /');
        $this->assertStringContainsString('<svg', (string)$l['GET /stats']['body']);
        $this->assertSame([303, '/?cat=1&stock=low&filter_saved=1'], [$l['save tools filter']['status'], $l['save tools filter']['location']]);
        $this->assertSame(422, $l['save nothing fetch']['status']);
        $this->assertSame(409, $l['rename to a taken name']['status']);
        $this->assertSame(404, $l['rename missing']['status']);
        $this->assertSame(['ok' => true, 'favourite' => true, 'count' => 2], $l['star Saw fetch']['json']);
        $this->assertSame([303, '/item/1'], [$l['unstar Hammer, bad back']['status'], $l['unstar Hammer, bad back']['location']]);
        $this->assertSame(404, $l['star missing']['status']);
        $this->assertSame([303, '/'], [$l['clear recent']['status'], $l['clear recent']['location']]);
        $this->assertSame(403, $l['viewer stars']['status']);
        $this->assertSame(303, $l['viewer clears recent']['status']);
        $this->assertSame(419, $l['star without csrf']['status']);
        foreach (['fail star', 'fail save filter', 'fail save filter fetch', 'fail rename filter', 'fail delete filter', 'fail clear recent'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ada', 'admin'], ['vera', 'viewer']] as [$name, $role]) {
            $user->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
        $loc = $pdo->prepare('INSERT INTO locations (name, name_key, sort_key, parent_id, is_default, created_at) VALUES (?, ?, ?, ?, 0, ?)');
        // 1 Garage, 2 Shelf 2 (in 1), 3 Box 10 (in 2), 4 Attic
        foreach ([['Garage', null], ['Shelf 2', 1], ['Box 10', 2], ['Attic', null]] as [$name, $parent]) {
            $loc->execute([$name, mb_strtolower($name), natkey($name), $parent, '2026-01-01 00:00:00']);
        }
        $cat = $pdo->prepare('INSERT INTO categories (name, name_key, sort_key, is_default, created_at) VALUES (?, ?, ?, 0, ?)');
        foreach (['Tools', 'Food', 'Paper'] as $name) {
            $cat->execute([$name, mb_strtolower($name), natkey($name), '2026-01-01 00:00:00']);
        }
        $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, quantity, min_qty, expires_on, value_cents, lent_to, lent_at, lent_due, favourite, notes, serial, barcode, image, search, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $items = [
            ['Hammer & <b>', 2, 1, 1, null, null, 1999, null, null, null, 1, 'claw', '', null, str_repeat('a', 32) . '.jpg', '2026-01-02 08:00:00'],
            ['Apple', null, 2, 3, 5, '2026-06-10', null, null, null, null, 0, '', '', null, null, '2026-02-01 23:30:00'],
            ['Saw', 1, 1, 2, null, null, 4550, 'Bob', '2026-05-01', '2026-06-01', 0, '', 'SN-1', '4006381333931', null, '2026-03-01 12:00:00'],
            ['Screwdriver set', 3, 1, 1, 1, null, 1250, 'Ann', '2026-06-01', '2026-07-01', 0, 'philips', '', null, null, '2026-04-15 00:30:00'],
            ['Milk', 4, 2, 0, 1, '2026-06-18', 129, null, null, null, 0, '', '', null, null, '2026-05-20 10:00:00'],
            ['Notebook', null, null, 7, null, '2027-01-01', null, null, null, null, 0, 'A5 dotted', '', null, null, '2026-06-14 22:30:00'],
            ['Glue', 3, 3, 1, null, null, 300, null, null, null, 0, '', '', null, null, '2026-06-15 20:00:00'],
        ];
        foreach ($items as $r) {
            $created = array_pop($r);
            $item->execute([...$r, mb_strtolower($r[0] . ' ' . $r[11]), $created, $created]);
        }
        $tag = $pdo->prepare('INSERT INTO tags (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?)');
        foreach (['winter', 'red'] as $name) {
            $tag->execute([$name, $name, natkey($name), '2026-01-01 00:00:00']);
        }
        foreach ([[1, 2], [3, 1], [4, 2], [5, 1]] as [$i, $t]) {
            $pdo->exec("INSERT INTO item_tags (item_id, tag_id) VALUES ($i, $t)");
        }
        $device = str_repeat('ab', 16);
        foreach ([[3, 1], [1, 2], [6, 3]] as [$i, $seq]) {
            $pdo->exec("INSERT INTO recent_views (scope, item_id, seq, viewed_at) VALUES ('d:$device', $i, $seq, '2026-06-01 00:00:00')");
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
            $ids[$u['username']] = (int)$u['id'];
        }
        // A browser holding a live session (with a CSRF token) and this device's cookie, signed in as $account.
        $signIn = function (?string $account) use ($server, $pdo, $ids, $dir, $device): array {
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
            $client->cookies['apo_dev'] = $device;

            return [$client, $token];
        };
        $out = [];
        $record = function (string $case, array $r, string $token) use (&$out): void {
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $cache = $r['headers']['cache-control'] ?? '';
            $cache = $r['status'] === 200 || $r['json'] !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache);
            $json = $r['json'];
            if (is_array($json)) {
                array_walk_recursive($json, static function (&$v) use ($token): void {
                    $v = is_string($v) ? str_replace($token, 'SESSION-TOKEN', $v) : $v;
                });
            }
            $out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'cache-control' => $cache, 'set-cookie' => $r['headers']['set-cookie'] ?? null,
                'json' => $json, 'body' => $json === null ? str_replace($token, 'SESSION-TOKEN', (string)$body) : null];
        };
        $fetch = ['X-Requested-With: fetch'];
        $this->setting($pdo, 'auth_enabled', true);
        $this->setting($pdo, 'auth_guest_disabled', true);
        $this->setting($pdo, 'timezone', 'Europe/Athens');
        $this->setting($pdo, 'currency', '€');
        $as = function (string $case, string $method, string $path, array $fields = [], array $headers = [], ?string $who = 'ada', bool $csrf = true) use ($signIn, $record): void {
            [$client, $token] = $signIn($who);
            if ($method === 'POST' && $csrf) {
                $fields = ['csrf' => $token] + $fields;
            }
            $record($case, $client->req($method, $path, $fields, [], $headers), $token);
        };
        $lists = ['/', '/?all=1', '/?page=2', '/?page=99', '/?page[]=2', '/?q=hammer', '/?q=screwdrivr', '/?q=%22saw%22', '/?q=a5', '/?q=garage', '/?q=4006381333931',
            '/?q=zzzz', '/?q[]=x', '/?q=' . str_repeat('x', 250), '/?loc=1', '/?loc=2', '/?loc=none', '/?loc=99', '/?cat=1', '/?cat=none', '/?cat=x', '/?tag=1', '/?tag=2',
            '/?low=1', '/?stock=low', '/?exp=expired', '/?exp=soon', '/?fav=1', '/?lend=out', '/?lend=overdue', '/?sort=name', '/?sort=location', '/?sort=category',
            '/?sort=qty', '/?sort=value', '/?sort=bogus', '/?sort[]=name', '/?cat=1&sort=value&tag=1&q=s', '/?select=1', '/?select=1&all=1',
            '/?saved=3', '/?saved=99', '/?deleted=1', '/?undone=1', '/?bulk_done=3&bulk_act=move', '/?bulk_done=1&bulk_act=delete', '/?bulk_done[]=1', '/?filter_saved=99'];
        foreach ($lists as $path) {
            $as('GET ' . $path, 'GET', $path);
            $as('GET ' . $path . ' fetch', 'GET', $path, [], $fetch);
        }
        $as('GET / fetch page 2', 'GET', '/?page=2', [], $fetch);
        $as('search screwdrivr fetch', 'GET', '/?q=screwdrivr', [], $fetch);
        $as('GET /stats', 'GET', '/stats');
        $as('HEAD /', 'HEAD', '/');
        foreach (['/index.php', '/index.php/stats', '/index.php?q=saw'] as $path) {
            $as('GET ' . $path, 'GET', $path);
        }
        // N-51: legacy routes rawurldecode(path) exactly, so a trailing slash or dot segment is no route.
        foreach (['/stats/', '/locations/', '/./stats', '/%2e/stats', '/%2E/stats', '/a/../stats', '/stats/.', '/stats/..', '/stats/%2e', '/x/..', '/./',
            '/%20stats', '/stats%20', '/stats%2F', '/locations//', '/filters/', '/healthz/', '/theme.css/', '/media/full/x.jpg/', '//stats', '//', '/%73tats', '/%2573tats', '/sta+ts', '/stats+', '/stats%2B', '/+stats', '/stats%20%2B'] as $path) {
            $as('GET ' . $path, 'GET', $path);
        }
        $as('GET /stats/ fetch', 'GET', '/stats/', [], $fetch);
        $as('signed-out GET /stats/', 'GET', '/stats/', [], [], null);
        $as('signed-out GET /./stats fetch', 'GET', '/./stats', [], $fetch, null);
        $as('POST /filters/', 'POST', '/filters/', ['name' => 'Slash', 'q' => 'x']);
        $as('POST /item/1/fav/ fetch', 'POST', '/item/1/fav/', ['on' => '1'], $fetch);
        $as('POST /./recent/clear', 'POST', '/./recent/clear');
        $as('GET /filters', 'GET', '/filters');
        // Saved filters.
        $as('save tools filter', 'POST', '/filters', ['name' => '  Tools   low ', 'cat' => '1', 'stock' => 'low', 'sort' => 'recent', 'page' => '2']);
        $as('save fetch', 'POST', '/filters', ['name' => 'Search', 'q' => 'saw', 'sort' => 'name', 'tag' => '1', 'loc' => 'none'], $fetch);
        $as('save same name', 'POST', '/filters', ['name' => 'TOOLS LOW', 'fav' => '1']);
        $as('save same name fetch', 'POST', '/filters', ['name' => 'tools low', 'fav' => '1'], $fetch);
        $as('save nothing', 'POST', '/filters', ['name' => 'Empty']);
        $as('save nothing fetch', 'POST', '/filters', ['name' => 'Empty', 'sort' => 'recent'], $fetch);
        $as('save empty name fetch', 'POST', '/filters', ['name' => ' ', 'q' => 'x'], $fetch);
        $as('save array name', 'POST', '/filters', ['name' => ['a'], 'q' => 'x']);
        $as('save expiry', 'POST', '/filters', ['name' => 'Soon & <b>', 'exp' => 'soon', 'lend' => 'overdue', 'loc' => '2', 'cat' => 'none']);
        foreach (['/filters', '/filters?done=renamed', '/filters?done=deleted', '/filters?done[]=x', '/?filter_saved=1', '/?cat=1&stock=low'] as $path) {
            $as('GET ' . $path . ' after saves', 'GET', $path);
        }
        $as('rename', 'POST', '/filters/2/rename', ['name' => 'Saws']);
        $as('rename to a taken name', 'POST', '/filters/2/rename', ['name' => 'tools LOW']);
        $as('rename empty fetch', 'POST', '/filters/2/rename', ['name' => ''], $fetch);
        $as('rename missing', 'POST', '/filters/99/rename', ['name' => 'X']);
        $as('delete', 'POST', '/filters/3/delete');
        $as('delete missing fetch', 'POST', '/filters/3/delete', [], $fetch);
        $as('GET /filters/1/rename', 'GET', '/filters/1/rename');
        $this->setting($pdo, 'saved_filters_max', 2);
        $as('save over the limit', 'POST', '/filters', ['name' => 'Third', 'q' => 'x']);
        $this->setting($pdo, 'saved_filters_max', 50);
        // The star and recently viewed.
        $as('star Saw fetch', 'POST', '/item/3/fav', ['on' => '1'], $fetch);
        $as('star Saw again', 'POST', '/item/3/fav', ['back' => '/?q=saw']);
        $as('unstar Hammer, bad back', 'POST', '/item/1/fav', ['back' => 'https://example.com/']);
        $as('toggle Apple, item back', 'POST', '/item/2/fav', ['back' => '/item/2']);
        $as('star array on', 'POST', '/item/2/fav', ['on' => ['1']]);
        $as('star missing', 'POST', '/item/99/fav', ['on' => '1']);
        $as('star missing fetch', 'POST', '/item/99/fav', [], $fetch);
        $as('GET /item/1/fav', 'GET', '/item/1/fav');
        $as('GET /?fav=1 after stars', 'GET', '/?fav=1');
        $as('viewer stars', 'POST', '/item/1/fav', ['on' => '1'], [], 'vera');
        $as('viewer sees the list', 'GET', '/', [], [], 'vera');
        $as('star without csrf', 'POST', '/item/1/fav', ['on' => '1'], [], 'ada', false);
        $as('save filter without csrf fetch', 'POST', '/filters', ['name' => 'X', 'q' => 'x'], $fetch, 'ada', false);
        $as('signed-out list', 'GET', '/', [], [], null);
        $as('signed-out stats fetch', 'GET', '/stats', [], $fetch, null);
        $this->setting($pdo, 'auth_guest_disabled', false);
        $as('guest list', 'GET', '/', [], [], null);
        $as('guest stars', 'POST', '/item/1/fav', ['on' => '1'], [], null);
        $this->setting($pdo, 'auth_guest_disabled', true);
        $this->setting($pdo, 'recent_scope', 'install');
        $as('GET / install scope', 'GET', '/');
        $this->setting($pdo, 'recent_scope', 'device');
        $this->setting($pdo, 'overview_open', false);
        $this->setting($pdo, 'list_view', 'compact');
        $this->setting($pdo, 'currency_position', 'after');
        $as('GET / settings changed', 'GET', '/');
        $as('GET /stats settings changed', 'GET', '/stats');
        $this->setting($pdo, 'auth_enabled', false);
        $as('off GET /', 'GET', '/');
        $this->setting($pdo, 'auth_enabled', true);

        // M-25: a statement that fails (a trigger stands in for a broken disk or constraint) is a 500 and changes nothing.
        $snapshot = static function () use ($pdo): string {
            $out = [];
            foreach (['items', 'saved_filters', 'recent_views'] as $table) {
                $out[] = $pdo->query("SELECT * FROM $table ORDER BY 1, 2")->fetchAll();
            }

            return json_encode($out);
        };
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        foreach ([['fail star', 'items', 'UPDATE', '/item/4/fav', ['on' => '1']], ['fail save filter', 'saved_filters', 'INSERT', '/filters', ['name' => 'F', 'q' => 'x']],
            ['fail save filter fetch', 'saved_filters', 'INSERT', '/filters', ['name' => 'F', 'q' => 'x']], ['fail rename filter', 'saved_filters', 'UPDATE', '/filters/1/rename', ['name' => 'G']],
            ['fail delete filter', 'saved_filters', 'DELETE', '/filters/1/delete', []], ['fail clear recent', 'recent_views', 'DELETE', '/recent/clear', []]] as [$case, $table, $op, $path, $fields]) {
            $pdo->exec($pg ? "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()"
                : "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = $snapshot();
            $as($case, 'POST', $path, $fields, str_ends_with($case, 'fetch') ? $fetch : []);
            $out[$case]['unchanged'] = $before === $snapshot();
            $pdo->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
        }
        $as('clear recent', 'POST', '/recent/clear');
        $as('viewer clears recent', 'POST', '/recent/clear', [], [], 'vera');
        $as('GET / after clear', 'GET', '/');

        // M-50: SQLite without FTS5 (migration 0019 skips the FTS tables) searches with LIKE.
        if (!$pg) {
            $pdo->exec('DROP TABLE search_fts_v');
            $pdo->exec('DROP TABLE search_fts');
            $pdo->exec('DELETE FROM search_meta');
            foreach (['/?q=saw', '/?q=screwdrivr', '/?q=garage', '/?q=a5&sort=name'] as $path) {
                $as('no fts GET ' . $path, 'GET', $path);
                $as('no fts GET ' . $path . ' fetch', 'GET', $path, [], $fetch);
            }
        }

        $tables = [];
        foreach (['SELECT id, name, favourite, updated_at FROM items ORDER BY id', 'SELECT id, name, name_key, query, sort_key FROM saved_filters ORDER BY id',
            'SELECT scope, item_id, seq FROM recent_views ORDER BY scope, seq'] as $sql) {
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$row) {
                $row = array_map(static fn ($v) => $v === null ? null : (string)$v, $row);
            }
            $tables[] = $rows;
        }
        $out['tables'] = ['body' => json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];

        return $out;
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
