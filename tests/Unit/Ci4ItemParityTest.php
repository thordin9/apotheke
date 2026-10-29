<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/legacy-bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/items.php';

/**
 * Golden (PLAN step 11a, Catalog): the item page, the add and edit forms,
 * saving (with a photo upload), deleting, Undo, the activity pages and the
 * "recently viewed" recording on CI4 against legacy. The same requests go to
 * a legacy and a CI4 server with the same data and a frozen clock. Answers
 * are byte-identical except the asset cache busters, the per-session CSRF
 * token and the random names (photos, undo tokens, the device cookie), which
 * are numbered in order of appearance; the tables end up the same.
 */
final class Ci4ItemParityTest extends TestCase
{
    public function testTheSameRequestsGetTheSameAnswers(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('items-' . $app);
            $server = new Server($dir, ['APOTHEKE_CLOCK' => '2026-06-15 21:30:00'], $app);
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
        $this->assertGreaterThan(80, count($answers['legacy']));
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        // The cases are what they claim to be.
        $l = $answers['legacy'];
        $this->assertSame([200, 'no-store'], [$l['GET /item/1']['status'], $l['GET /item/1']['cache-control']]);
        $this->assertStringContainsString('Hammer &amp; &lt;b&gt;', (string)$l['GET /item/1']['body']);
        $this->assertStringContainsString('<strong>claw</strong>', (string)$l['GET /item/1']['body'], 'notes are Markdown');
        $this->assertStringContainsString('name="MAX_FILE_SIZE"', (string)$l['GET /new']['body']);
        $this->assertStringContainsString('name="MAX_FILE_SIZE"', (string)$l['GET /item/1/edit']['body']);
        $this->assertSame(404, $l['GET /item/99']['status']);
        $this->assertSame([303, '/?saved=5'], [$l['save new']['status'], $l['save new']['location']]);
        $this->assertSame([303, '/new?loc=2&cat=1&saved=6'], [$l['save new, another']['status'], $l['save new, another']['location']]);
        $this->assertMatchesRegularExpression('#^/item/1\?undo=H\d+$#', (string)$l['edit Hammer']['location']);
        $this->assertSame('/item/2', $l['save Apple unchanged']['location']);
        $this->assertSame(422, $l['save no name']['status']);
        $this->assertSame(['ok' => false, 'error' => 'Value is ambiguous – write it like 1234.50.'], $l['save ambiguous price fetch']['json']);
        $this->assertSame(403, $l['mover-less editor moves']['status']);
        $this->assertSame(303, $l['mover-less editor edits in place']['status']);
        $this->assertStringContainsString('class="undo-bar"', (string)$l['GET /item/1 with the undo bar']['body']);
        $this->assertMatchesRegularExpression('#^/\?deleted=1&undo=H\d+$#', (string)$l['delete Saw']['location']);
        $this->assertSame([303, '/item/3?undone=1'], [$l['undo the delete']['status'], $l['undo the delete']['location']]);
        $this->assertSame(410, $l['undo again']['status']);
        $this->assertSame([303, '/item/1?undone=1'], [$l['undo the edit']['status'], $l['undo the edit']['location']]);
        $this->assertStringContainsString('apo_dev=H', (string)$l['GET /item/2 new device']['set-cookie']);
        $this->assertNull($l['GET /item/2 fetch new device']['set-cookie']);
        $this->assertSame(['apo_dev', 'apothekesid'], $l['GET /item/1 first visit']['cookies']);
        $this->assertSame(403, $l['viewer GET /new']['status']);
        $this->assertSame(419, $l['save without csrf']['status']);
        $this->assertStringContainsString('id="act-older"', (string)$l['GET /activity']['body']);
        $this->assertSame(404, $l['GET /activity?item=99']['status']);
        $this->assertStringContainsString('History: Saw', (string)$l['GET /activity?item=3 after delete']['body']);
        foreach (['fail save new', 'fail edit', 'fail delete', 'fail undo', 'fail record view'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $pdo->prepare('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?, ?, ?, 0, ?)')
            ->execute(['tidier', 'Tidier', json_encode(['view', 'create', 'edit']), '2026-01-01 00:00:00']);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ada', 'admin'], ['vera', 'viewer'], ['tim', 'tidier']] as [$name, $role]) {
            $user->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
        $loc = $pdo->prepare('INSERT INTO locations (name, name_key, sort_key, parent_id, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['Garage', null, 0], ['Shelf 2', 1, 1], ['Box 10', 2, 0], ['Attic', null, 0]] as [$name, $parent, $default]) {
            $loc->execute([$name, mb_strtolower($name), natkey($name), $parent, $default, '2026-01-01 00:00:00']);
        }
        $cat = $pdo->prepare('INSERT INTO categories (name, name_key, sort_key, is_default, created_at) VALUES (?, ?, ?, 0, ?)');
        foreach (['Tools', 'Food', 'Paper'] as $name) {
            $cat->execute([$name, mb_strtolower($name), natkey($name), '2026-01-01 00:00:00']);
        }
        $a = str_repeat('a', 32) . '.jpg';
        $b = str_repeat('b', 32) . '.jpg';
        $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, quantity, min_qty, expires_on, value_cents, lent_to, lent_at, lent_due, favourite, notes, serial, barcode, image, purchase_date, search, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $items = [
            ['Hammer & <b>', 3, 1, 1, null, null, 1999, null, null, null, 1, "**claw**\n\n- one\n- two", '', null, $a, '2026-01-10', '2026-01-02 08:00:00', '2026-01-03 09:00:00'],
            ['Apple', null, 2, 3, 5, '2026-06-10', null, null, null, null, 0, '', '', null, null, null, '2026-02-01 23:30:00', '2026-02-01 23:30:00'],
            ['Saw', 1, 1, 2, null, '2026-06-20', 4550, 'Bob', '2026-05-01', '2026-06-01', 0, 'sharp', 'SN-1', '4006381333931', null, null, '2026-03-01 12:00:00', '2026-03-01 12:00:00'],
            ['Glue', 4, 3, 0, 1, null, null, 'Ann', '2026-06-10', null, 0, '', '', null, null, null, '2026-04-15 00:30:00', '2026-04-15 00:30:00'],
        ];
        foreach ($items as $r) {
            [$created, $updated] = array_splice($r, -2);
            $item->execute([...$r, mb_strtolower($r[0] . "\n" . $r[11]), $created, $updated]);
        }
        $pdo->exec("INSERT INTO item_photos (item_id, file, position, created_at) VALUES (1, '$a', 0, '2026-01-02 08:00:00'), (1, '$b', 1, '2026-01-02 08:00:00')");
        $pdo->exec("INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (1, '" . str_repeat('c', 32) . ".pdf', 'Manual <1>.pdf', 'application/pdf', 123456, '2026-01-02 08:00:00')");
        $tag = $pdo->prepare('INSERT INTO tags (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?)');
        foreach (['winter', 'red'] as $name) {
            $tag->execute([$name, $name, natkey($name), '2026-01-01 00:00:00']);
        }
        foreach ([[1, 2], [1, 1], [3, 1]] as [$i, $t]) {
            $pdo->exec("INSERT INTO item_tags (item_id, tag_id) VALUES ($i, $t)");
        }
        // Every kind of history entry, and more than a page of them (the pager, "Full history").
        $act = $pdo->prepare('INSERT INTO activity (item_id, item_name, action, details, actor, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $kinds = [
            ['created', ['location' => 'Garage', 'quantity' => 2, 'min_qty' => 1, 'photo' => true, 'tags' => ['red', '<x>'], 'copied_from' => 'Old']],
            ['edited', ['changes' => ['name' => ['Old', 'New'], 'value' => ['', '12.50'], 'notes' => ['a', ''], 'odd' => [1, 2]], 'reason' => 'undo']],
            ['moved', ['from' => '', 'to' => 'Attic', 'reason' => 'bulk']],
            ['quantity', ['from' => 1, 'to' => 3]],
            ['photo', ['change' => 'edited', 'rotate' => 90, 'size' => '800×600', 'count' => 2]],
            ['photo', ['change' => 'bogus']],
            ['lent', ['to' => 'Bob', 'at' => '2026-05-01', 'due' => '2026-06-01', 'before' => ['x']]],
            ['returned', ['from' => 'Bob', 'days' => 1]],
            ['attachment', ['change' => 'removed', 'file' => 'a.pdf']],
            ['merged', ['from' => 'Dup', 'quantity' => [1, 3], 'photos' => 1, 'attachments' => 2, 'filled' => ['notes'], 'lending' => 'copied']],
            ['restored', []],
            ['deleted', ['location' => 'Shelf']],
            ['strange', []],
        ];
        foreach ($kinds as $i => [$action, $details]) {
            $act->execute([3, 'Saw', $action, json_encode($details), $i % 2 ? 'ada' : null, sprintf('2026-06-%02d 10:00:00', $i + 1)]);
        }
        for ($i = 0; $i < 45; $i++) {
            $act->execute([2, 'Apple', 'quantity', json_encode(['from' => $i, 'to' => $i + 1]), null, sprintf('2026-05-01 %02d:%02d:00', intdiv($i, 60), $i % 60)]);
        }
        $act->execute([null, 'Gone thing', 'deleted', '{}', null, '2026-06-14 10:00:00']);
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
            $ids[$u['username']] = (int)$u['id'];
        }
        $device = str_repeat('ab', 16);
        $signIn = function (?string $account, bool $withDevice = true) use ($server, $pdo, $ids, $dir, $device): array {
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
            if ($withDevice) {
                $client->cookies['apo_dev'] = $device;
            }

            return [$client, $token];
        };
        // Random names (photos, undo tokens, a new device id) numbered in order of appearance.
        $names = [];
        $hide = static function (string $s) use (&$names, $device): string {
            return (string)preg_replace_callback('/(?<![0-9a-f])[0-9a-f]{32}(?![0-9a-f])/', static function (array $m) use (&$names, $device): string {
                if ($m[0] === $device || preg_match('/^(.)\1+$/', $m[0]) === 1) {
                    return $m[0];
                }

                return 'H' . ($names[$m[0]] ??= count($names) + 1);
            }, $s);
        };
        $out = [];
        $last = [];
        $record = function (string $case, array $r, string $token) use (&$out, &$last, $hide): void {
            $last = $r;
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $cache = $r['headers']['cache-control'] ?? '';
            $cache = $r['status'] === 200 || $r['json'] !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache);
            $json = $r['json'];
            if (is_array($json)) {
                array_walk_recursive($json, static function (&$v) use ($token, $hide): void {
                    $v = is_string($v) ? $hide(str_replace($token, 'SESSION-TOKEN', $v)) : $v;
                });
            }
            $cookie = null;
            $names = [];
            foreach ($r['raw'] as $line) {
                if (preg_match('/^set-cookie:\s*(([^=]+)=.*)$/i', $line, $m) === 1) {
                    $names[] = $m[2];
                    if ($m[2] === 'apo_dev') {
                        $cookie = $hide(cookie_times_settled($m[1]));
                    }
                }
            }
            sort($names);
            $location = $r['headers']['location'] ?? null;
            $out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $location === null ? null : $hide($location),
                'cache-control' => $cache, 'set-cookie' => $cookie, 'cookies' => $names,
                'json' => $json, 'body' => $json === null ? $hide(str_replace($token, 'SESSION-TOKEN', (string)$body)) : null];
        };
        $fetch = ['X-Requested-With: fetch'];
        $this->setting($pdo, 'auth_enabled', true);
        $this->setting($pdo, 'auth_guest_disabled', true);
        $this->setting($pdo, 'timezone', 'Europe/Athens');
        $this->setting($pdo, 'currency', '€');
        $this->setting($pdo, 'lend_due_days', 14);
        $as = function (string $case, string $method, string $path, array $fields = [], array $headers = [], ?string $who = 'ada', bool $csrf = true, array $files = [], bool $device = true) use ($signIn, $record): array {
            [$client, $token] = $signIn($who, $device);
            if ($method === 'POST' && $csrf) {
                $fields = ['csrf' => $token] + $fields;
            }
            $r = $client->req($method, $path, $fields, $files, $headers);
            $record($case, $r, $token);

            return $r;
        };
        $form = ['name' => 'Drill', 'location_id' => '2', 'category_id' => '1', 'quantity' => '2', 'min_qty' => '', 'notes' => "Bosch\r\n*fast*", 'tags' => 'red, Winter, new one',
            'value' => '€ 1,234.50', 'purchase_date' => '2026-06-01', 'expires_on' => '2028-01-01', 'serial' => 'X1', 'barcode' => ' 123 456 ', 'after' => 'view'];
        // Pages.
        foreach (['/item/1', '/item/1?p=1', '/item/1?p=9', '/item/1?p[]=1', '/item/2', '/item/3', '/item/4', '/item/1?undone=1', '/item/1?edited=1', '/item/1?reordered=1',
            '/item/1?merged=' . rawurlencode("Dup <b>\n"), '/item/1?undo=' . str_repeat('9', 32), '/item/1?undo[]=x', '/item/99', '/item/1/edit', '/item/2/edit', '/item/3/edit?copied=1', '/item/99/edit',
            '/new', '/new?loc=3&cat=2&barcode=%20978%2D3', '/new?loc=99&cat=x', '/new?saved=1', '/new?saved=99', '/new?cat=1', '/item/1/', '/item/01', '/new/',
            '/activity', '/activity?page=2', '/activity?page=99', '/activity?page[]=1', '/activity?item=3', '/activity?item=2&page=2', '/activity?item=99', '/activity?item=x', '/activity?item[]=1'] as $path) {
            $as('GET ' . $path, 'GET', $path);
        }
        $as('GET /item/99 fetch', 'GET', '/item/99', [], $fetch);
        $as('GET /activity?item=99 fetch', 'GET', '/activity?item=99', [], $fetch);
        $as('HEAD /item/1', 'HEAD', '/item/1');
        $this->setting($pdo, 'autofill_enabled', true);
        $this->setting($pdo, 'autofill_url', 'http://127.0.0.1:9/');
        $this->setting($pdo, 'autofill_auto', true);
        $as('GET /new autofill', 'GET', '/new');
        $as('GET /item/1/edit autofill', 'GET', '/item/1/edit');
        $as('tidier GET /new autofill', 'GET', '/new', [], [], 'tim');
        $this->setting($pdo, 'autofill_enabled', false);
        $this->setting($pdo, 'undo_seconds', 0);
        $as('GET /item/1 undo off', 'GET', '/item/1');
        $this->setting($pdo, 'undo_seconds', 30);
        $this->setting($pdo, 'attach_types', 'pdf');
        $this->setting($pdo, 'date_format', 'd/m/Y');
        $this->setting($pdo, 'time_format', 'g:i a');
        $as('GET /item/3 formats', 'GET', '/item/3');
        $this->setting($pdo, 'default_quantity', 3);
        $this->setting($pdo, 'default_min_qty', 1);
        $this->setting($pdo, 'default_tags', 'new');
        $this->setting($pdo, 'default_notes', 'From the shop');
        $this->setting($pdo, 'default_purchase_today', true);
        $this->setting($pdo, 'default_expiry_months', 6);
        $as('GET /new defaults', 'GET', '/new');
        foreach (['attach_types' => 'all', 'date_format' => 'j M Y', 'time_format' => 'H:i', 'default_quantity' => 1, 'default_tags' => '', 'default_notes' => '', 'default_purchase_today' => false] as $k => $v) {
            $this->setting($pdo, $k, $v);
        }
        $pdo->exec("DELETE FROM settings WHERE name IN ('default_min_qty', 'default_expiry_months')");
        // Recently viewed: a device's first view sets its cookie; a fetch records nothing.
        $as('GET /item/2 new device', 'GET', '/item/2', [], [], 'ada', true, [], false);
        $as('GET /item/2 fetch new device', 'GET', '/item/2', [], $fetch, 'ada', true, [], false);
        $as('GET /item/4 known device', 'GET', '/item/4');
        // A first visit (sign-in off, no session yet) gets the session cookie and the device cookie.
        $this->setting($pdo, 'auth_enabled', false);
        $first = $server->client()->get('/item/1');
        preg_match('/<meta name="csrf" content="([0-9a-f]{64})">/', $first['body'], $m);
        $record('GET /item/1 first visit', $first, $m[1] ?? 'no-token');
        // Legacy's session start adds PHP's "no-cache, must-revalidate" to its no-store; CI4 sends no-store (on every page).
        $out['GET /item/1 first visit']['cache-control'] = str_contains($out['GET /item/1 first visit']['cache-control'], 'no-store') ? 'no-store …' : 'missing';
        $this->setting($pdo, 'auth_enabled', true);
        // Saving.
        $as('save new', 'POST', '/save', $form);
        $as('save new, another', 'POST', '/save', ['name' => 'Tape', 'location_id' => '2', 'category_id' => '1', 'after' => 'another']);
        $as('GET /new?loc=2&cat=1&saved=9', 'GET', '/new?loc=2&cat=1&saved=6');
        $as('save new fetch', 'POST', '/save', ['name' => 'Nails', 'quantity' => '0', 'new_location' => 'Cellar', 'category_id' => '__new', 'new_category_ns' => 'Hardware'], $fetch);
        $png = $this->photo();
        $as('save new with photo', 'POST', '/save', ['name' => 'Pictured', 'location_id' => ''], [], 'ada', true, ['photo2' => ['name' => 'p.png', 'type' => 'image/png', 'data' => $png]]);
        $as('save bad photo', 'POST', '/save', ['name' => 'Broken'], [], 'ada', true, ['photo' => ['name' => 'p.png', 'type' => 'image/png', 'data' => 'not an image']]);
        foreach ([
            'save no name' => ['name' => '  '],
            'save bad quantity' => ['name' => 'X', 'quantity' => '-1'],
            'save array quantity' => ['name' => 'X', 'quantity' => ['1']],
            'save bad price' => ['name' => 'X', 'value' => '12.5.5'],
            'save huge price' => ['name' => 'X', 'value' => '9999999999'],
            'save future purchase' => ['name' => 'X', 'purchase_date' => '2026-06-18'],
            'save bad purchase' => ['name' => 'X', 'purchase_date' => '2026-02-30'],
            'save bad expiry' => ['name' => 'X', 'expires_on' => '3000-01-01'],
            'save bad min' => ['name' => 'X', 'min_qty' => 'many'],
            'save empty new location' => ['name' => 'X', 'location_id' => '__new'],
            'save gone category' => ['name' => 'X', 'category_id' => '99'],
            'save array name' => ['name' => ['x']],
        ] as $case => $fields) {
            $as($case, 'POST', '/save', $fields);
        }
        $as('save ambiguous price fetch', 'POST', '/save', ['name' => 'X', 'value' => '1,234'], $fetch);
        $as('save missing item', 'POST', '/save', ['id' => '99', 'name' => 'X']);
        $as('save missing item fetch', 'POST', '/save', ['id' => '99', 'name' => 'X'], $fetch);
        $as('edit Hammer', 'POST', '/save', ['id' => '1', 'name' => 'Hammer & <b>', 'location_id' => '1', 'category_id' => '1', 'quantity' => '4', 'notes' => '**claw**', 'tags' => 'red', 'value' => '19.99',
            'purchase_date' => '2026-01-10', 'remove_image' => '1']);
        $undoEdit = $this->token($last);
        $as('GET /item/1 with the undo bar', 'GET', '/item/1?undo=' . $undoEdit);
        $as('GET /item/1 after the edit', 'GET', '/item/1');
        $apple = ['id' => '2', 'name' => 'Apple', 'category_id' => '2', 'quantity' => '3', 'min_qty' => '5', 'expires_on' => '2026-06-10'];
        $as('save Apple', 'POST', '/save', $apple);
        $as('save Apple unchanged', 'POST', '/save', $apple);
        $as('edit Apple invalid', 'POST', '/save', ['id' => '2', 'name' => 'Apple', 'expires_on' => 'soon']);
        $as('mover-less editor moves', 'POST', '/save', ['id' => '2', 'name' => 'Apple', 'location_id' => '1'], [], 'tim');
        $as('mover-less editor adds a location', 'POST', '/save', ['id' => '2', 'name' => 'Apple', 'new_location' => 'Fridge'], [], 'tim');
        $as('mover-less editor edits in place', 'POST', '/save', ['id' => '2', 'name' => 'Apples', 'quantity' => '4'], [], 'tim');
        $as('mover-less editor creates', 'POST', '/save', ['name' => 'Pear', 'location_id' => '1'], [], 'tim');
        $as('viewer GET /new', 'GET', '/new', [], [], 'vera');
        $as('viewer GET /item/1/edit', 'GET', '/item/1/edit', [], [], 'vera');
        $as('viewer GET /item/1', 'GET', '/item/1', [], [], 'vera');
        $as('viewer saves', 'POST', '/save', ['name' => 'X'], [], 'vera');
        $as('viewer edits fetch', 'POST', '/save', ['id' => '1', 'name' => 'X'], $fetch, 'vera');
        $as('viewer deletes', 'POST', '/item/1/delete', [], [], 'vera');
        $as('viewer undoes', 'POST', '/undo', ['token' => $undoEdit], [], 'vera');
        $as('signed-out GET /item/1', 'GET', '/item/1', [], [], null);
        $as('signed-out save fetch', 'POST', '/save', ['name' => 'X'], $fetch, null);
        $as('save without csrf', 'POST', '/save', ['name' => 'X'], [], 'ada', false);
        $as('undo the edit', 'POST', '/undo', ['token' => $undoEdit]);
        $as('GET /item/1 after undo', 'GET', '/item/1?undone=1');
        // Deleting and Undo.
        $as('delete Saw', 'POST', '/item/3/delete');
        $undoDelete = $this->token($last);
        $as('GET / with the undo bar', 'GET', '/?deleted=1&undo=' . $undoDelete);
        $as('GET /item/3 deleted', 'GET', '/item/3');
        $as('GET /activity?item=3 after delete', 'GET', '/activity?item=3');
        $as('undo the delete', 'POST', '/undo', ['token' => $undoDelete]);
        $as('undo again', 'POST', '/undo', ['token' => $undoDelete]);
        $as('undo again fetch', 'POST', '/undo', ['token' => $undoDelete], $fetch);
        $as('undo array token', 'POST', '/undo', ['token' => ['x']]);
        $as('GET /item/3 restored', 'GET', '/item/3');
        $as('delete missing', 'POST', '/item/99/delete');
        $as('delete Glue fetch', 'POST', '/item/4/delete', [], $fetch);
        $undoGlue = $this->token($last);
        $as('undo Glue fetch', 'POST', '/undo', ['token' => $undoGlue], $fetch);
        $this->setting($pdo, 'undo_seconds', 0);
        $as('delete Tape, undo off', 'POST', '/item/6/delete');
        $this->setting($pdo, 'undo_seconds', 30);
        $as('GET /activity after all', 'GET', '/activity');
        $as('GET /item/4 after all', 'GET', '/item/4');

        // A statement that fails (a trigger stands in for a broken disk or constraint) is a 500 and changes nothing.
        $snapshot = static function () use ($pdo): string {
            $out = [];
            foreach (['items', 'item_photos', 'item_tags', 'tags', 'activity', 'undo_tokens', 'recent_views', 'locations'] as $table) {
                $out[] = $pdo->query("SELECT * FROM $table ORDER BY 1, 2")->fetchAll();
            }

            return json_encode($out);
        };
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        $as('delete Pear', 'POST', '/item/9/delete');
        $undoPear = $this->token($last);
        foreach ([['fail save new', 'activity', 'INSERT', 'POST', '/save', ['name' => 'F', 'location_id' => '1', 'tags' => 'brandnew']],
            ['fail edit', 'activity', 'INSERT', 'POST', '/save', ['id' => '2', 'name' => 'Changed', 'tags' => 'brandnew']],
            ['fail delete', 'activity', 'INSERT', 'POST', '/item/2/delete', []],
            ['fail undo', 'activity', 'INSERT', 'POST', '/undo', ['token' => $undoPear]],
            ['fail record view', 'recent_views', 'INSERT', 'GET', '/item/2', []]] as [$case, $table, $op, $method, $path, $fields]) {
            $pdo->exec($pg ? "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()"
                : "CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = $snapshot();
            $as($case, $method, $path, $fields);
            $out[$case]['unchanged'] = $before === $snapshot();
            $pdo->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
        }

        $tables = [];
        foreach (['SELECT id, name, location_id, category_id, quantity, min_qty, expires_on, notes, image IS NULL AS no_image, value_cents, purchase_date, serial, barcode, favourite, search, sort_key, created_at, updated_at FROM items ORDER BY id',
            'SELECT item_id, position FROM item_photos ORDER BY item_id, position', 'SELECT item_id, name FROM item_attachments ORDER BY id',
            'SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY it.item_id, t.name', 'SELECT id, name, name_key, sort_key FROM tags ORDER BY id',
            'SELECT id, name, parent_id FROM locations ORDER BY id', 'SELECT id, name FROM categories ORDER BY id',
            'SELECT item_id, item_name, action, details, actor, created_at FROM activity ORDER BY id', 'SELECT action, label, created_at, expires_at FROM undo_tokens ORDER BY created_at, label',
            'SELECT scope, item_id, seq, viewed_at FROM recent_views ORDER BY seq, item_id'] as $sql) {
            $rows = $pdo->query($sql)->fetchAll();
            foreach ($rows as &$row) {
                $row = array_map(static fn ($v) => $v === null ? null : $hide((string)$v), $row);
            }
            unset($row);
            $tables[] = $rows;
        }
        $out['tables'] = ['body' => json_encode($tables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];

        return $out;
    }

    /** @param array<string,mixed> $r the undo token a redirect carries */
    private function token(array $r): string
    {
        $where = (string)($r['headers']['location'] ?? ($r['json']['redirect'] ?? ''));
        $this->assertSame(1, preg_match('/undo=([0-9a-f]{32})/', $where, $m), 'an undo token in ' . $where);

        return $m[1];
    }

    private function photo(): string
    {
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, (int)imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagepng($im);

        return (string)ob_get_clean();
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
