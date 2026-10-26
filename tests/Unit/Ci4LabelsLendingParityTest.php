<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Golden (PLAN step 13, first part): the QR labels pages, the barcode lookup
 * and lending on CI4 against legacy, on the configured driver. Besides the
 * answers (status, type, Location, JSON, security headers and the page with
 * its QR codes), the items and activity tables are compared after every
 * step. A failing statement (a trigger on activity) is a 500 and changes no
 * table on both.
 */
final class Ci4LabelsLendingParityTest extends TestCase
{
    public function testLabelsBarcodeAndLendingMatchLegacy(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('labels-' . $app);
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
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        $l = $answers['legacy'];
        // The cases are what they claim to be.
        $this->assertSame(3, substr_count((string)$l['labels all locations']['body'], '<figure class="label"'));
        $this->assertStringContainsString('<svg class="qr"', (string)$l['labels all locations']['body']);
        // The SVG is inline in the page, which carries the security headers and legacy's strict CSP.
        foreach (['labels all locations', 'item label', 'barcode form', 'lent list'] as $case) {
            $this->assertSame(['nosniff', 'text/html; charset=UTF-8'], [$l[$case]['nosniff'], $l[$case]['type']], $case);
            $this->assertStringContainsString("default-src 'self'", (string)$l[$case]['csp'], $case);
            $this->assertStringContainsString("script-src 'self'", (string)$l[$case]['csp'], $case);
            $this->assertStringContainsString("object-src 'none'", (string)$l[$case]['csp'], $case);
        }
        $this->assertStringContainsString('data-url="http://labels.example/?loc=1"', (string)$l['labels all locations']['body']);
        $this->assertStringContainsString('Location + sub-locations', (string)$l['labels one location']['body']);
        $this->assertSame(2, substr_count((string)$l['labels location and sub-locations']['body'], '<figure class="label"'));
        $this->assertSame(3, substr_count((string)$l['labels per item']['body'], '<figure class="label"'));
        $this->assertStringContainsString('2 items selected', (string)$l['labels selected ids']['body']);
        $this->assertStringContainsString('Nothing to print yet.', (string)$l['labels unknown ids']['body']);
        $this->assertSame(404, $l['labels missing location']['status']);
        $this->assertSame(422, $l['labels too many ids']['status']);
        $this->assertSame(403, $l['clerk labels']['status']);
        $this->assertSame(404, $l['labels trailing slash']['status']);
        $this->assertStringContainsString('aria-label="QR code for Lamp &lt;b&gt;&quot;&amp;&#039;"', (string)$l['item label']['body']);
        $this->assertStringNotContainsString('<b>"&', (string)$l['item label']['body']);
        $this->assertStringContainsString('data-url="http://shelf.localhost:9091/item/1"', (string)$l['labels without public_url']['body']);
        $this->assertStringContainsString('Settings → General', (string)$l['labels without public_url']['body']);
        $this->assertSame([303, '/barcode/4006381333931'], [$l['barcode form code']['status'], $l['barcode form code']['location']]);
        $this->assertSame('/item/2', $l['barcode one item']['location']);
        $this->assertSame('/?q=123', $l['barcode two items']['location']);
        $this->assertSame('/new?barcode=nothing', $l['barcode no item']['location']);
        $this->assertSame('/item/3', $l['barcode with a slash']['location']);
        $this->assertSame('/item/4', $l['barcode with a plus']['location']);
        $this->assertSame('/barcode', $l['barcode empty code']['location']);
        $this->assertSame(['ok' => true, 'redirect' => '/item/2'], $l['barcode fetch']['json']);
        $this->assertSame([303, '/item/1#lending'], [$l['lend']['status'], $l['lend']['location']]);
        $this->assertSame(422, $l['lend without a name']['status']);
        $this->assertSame(422, $l['lend bad date']['status']);
        $this->assertSame(422, $l['lend in the future']['status']);
        $this->assertSame(422, $l['lend due before lent']['status']);
        $this->assertSame(404, $l['lend missing item']['status']);
        $this->assertSame(403, $l['viewer lend']['status']);
        $this->assertSame(303, $l['lender lend']['status']);
        foreach (['return without CSRF', 'lend with no CSRF token'] as $case) {
            $this->assertSame(419, $l[$case]['status'], $case);
            $this->assertSame($l['GET lend']['tables'], $l[$case]['tables'], $case . ': nothing changed');
        }
        $this->assertSame('/lent', $l['return from the list']['location']);
        $this->assertStringContainsString('2 overdue', (string)$l['lent list']['body']);
        // Every value on /lent is escaped; only a valid photo name becomes a thumbnail.
        $lent = (string)$l['lent list']['body'];
        $this->assertStringContainsString('<b>Lamp &lt;b&gt;&quot;&amp;&#039;</b>', $lent);
        $this->assertStringContainsString('<span>Eleni &lt;b&gt;&amp;&quot;&#039; · since', $lent);
        $this->assertStringContainsString('aria-label="Mark Lamp &lt;b&gt;&quot;&amp;&#039; as returned"', $lent);
        $this->assertStringContainsString('<img src="/media/thumb/abcdef0123456789abcdef0123456789.jpg" alt="" loading="lazy">', $lent);
        $this->assertStringNotContainsString('x.jpg', $lent);
        $this->assertStringContainsString('data-url="http://labels.example/a&amp;b&quot;c/item/1"', (string)$l['labels with markup in public_url']['body']);
        $this->assertStringContainsString('Nothing is lent out right now.', (string)$l['lent list empty']['body']);
        foreach (['fail lend', 'fail return'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        // A clerk may look but not print labels; a lender may only look and lend.
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('clerk', 'Clerk', '[\"view\"]', 0, '2026-01-01 00:00:00'), ('lender', 'Lender', '[\"view\",\"lend\"]', 0, '2026-01-01 00:00:00')");
        foreach ([['ada', 'admin'], ['vera', 'viewer'], ['cleo', 'clerk'], ['leo', 'lender']] as [$name, $role]) {
            $user->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage <i>', 'garage <i>', 'garage <i>', '2026-01-01 00:00:00'), ('Attic', 'attic', 'attic', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, parent_id, created_at) VALUES ('Shelf & Box', 'shelf & box', 'shelf & box', 1, '2026-01-01 00:00:00')");
        $item = $pdo->prepare('INSERT INTO items (name, location_id, quantity, notes, barcode, lent_to, lent_at, lent_due, search, sort_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Lamp <b>"&\'', 1, 2, '', null, null, null, null],
            ['Drill', 3, 1, '', '4006381333931', 'Nikos', '2026-05-01', '2026-06-01'],
            ['Saw', 3, 1, '', 'a/b', null, null, null],
            ['Tea', null, 1, '', 'x+y', 'Maria', '2026-06-10', null],
            ['Tea (spare)', 2, 1, '', '123', null, null, null],
            ['Coffee', 2, 1, '', '123', 'Ana', '2026-06-12', '2026-07-01'],
        ] as $r) {
            $item->execute([...$r, mb_strtolower($r[0]), mb_strtolower($r[0]), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);
        }
        // A valid photo on a lent item (its thumbnail on /lent) and a name that is not one (no image).
        $pdo->exec("UPDATE items SET image = 'abcdef0123456789abcdef0123456789.jpg' WHERE id = 2");
        $pdo->exec("UPDATE items SET image = '../x.jpg' WHERE id = 6");
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
            $ids[$u['username']] = (int)$u['id'];
        }
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
        $tables = static function () use ($pdo): string {
            $o = [];
            foreach (['SELECT * FROM items ORDER BY id', 'SELECT item_id, item_name, action, details, actor, created_at FROM activity ORDER BY id'] as $sql) {
                $o[] = $pdo->query($sql)->fetchAll();
            }

            return json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        };
        $out = [];
        $record = function (string $case, array $r, string $token) use (&$out, $tables): void {
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $json = $r['json'];
            if (is_array($json)) {
                array_walk_recursive($json, static function (&$v) use ($token): void {
                    $v = is_string($v) ? str_replace($token, 'SESSION-TOKEN', $v) : $v;
                });
            }
            $cache = $r['headers']['cache-control'] ?? '';
            $out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'cache-control' => $r['status'] === 200 || $json !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache),
                'nosniff' => $r['headers']['x-content-type-options'] ?? null, 'csp' => $r['headers']['content-security-policy'] ?? null,
                'json' => $json, 'body' => $json === null ? str_replace($token, 'SESSION-TOKEN', (string)$body) : null, 'tables' => $tables()];
        };
        $fetch = ['X-Requested-With: fetch'];
        foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens', 'public_url' => 'http://labels.example', 'lend_due_days' => 14] as $k => $v) {
            $this->setting($pdo, $k, $v);
        }
        $as = function (string $case, string $method, string $path, array $fields = [], array $headers = [], ?string $who = 'ada') use ($signIn, $record): array {
            [$client, $token] = $signIn($who);
            if ($method === 'POST') {
                $fields += ['csrf' => $token];   // a case's own csrf wins; null sends none
            }
            $r = $client->req($method, $path, $fields, [], $headers);
            $record($case, $r, $token);

            return $r;
        };

        // QR labels.
        $as('labels all locations', 'GET', '/labels');
        $as('labels HEAD', 'HEAD', '/labels');
        $as('labels one location', 'GET', '/labels?loc=1');
        $as('labels location and sub-locations', 'GET', '/labels?loc=1&sub=1');
        $as('labels per item', 'GET', '/labels?loc=1&items=1');
        $as('labels leaf location', 'GET', '/labels?loc=3');
        $as('labels location none', 'GET', '/labels?loc=none');
        $as('labels location array', 'GET', '/labels?loc[]=1');
        $as('labels missing location', 'GET', '/labels?loc=99');
        $as('labels selected ids', 'GET', '/labels?ids=1,2,2,0,-3,99,x');
        $as('labels unknown ids', 'GET', '/labels?ids=98,99');
        $as('labels ids array', 'GET', '/labels?ids[]=1');
        $as('labels too many ids', 'GET', '/labels?ids=' . implode(',', range(1, 501)));
        $as('labels as many ids as allowed', 'GET', '/labels?ids=' . implode(',', range(1, 500)));
        $as('item label', 'GET', '/item/1/label');
        $as('item label without location', 'GET', '/item/4/label');
        $as('item label missing item', 'GET', '/item/99/label');
        $as('viewer labels', 'GET', '/labels', [], [], 'vera');
        $as('clerk labels', 'GET', '/labels', [], [], 'cleo');
        $as('clerk item label', 'GET', '/item/1/label', [], [], 'cleo');
        $as('signed out labels', 'GET', '/labels', [], [], null);
        $as('labels trailing slash', 'GET', '/labels/');
        $as('POST labels', 'POST', '/labels');
        $as('labels fetch', 'GET', '/labels', [], $fetch);
        foreach (['label_show_sub' => false, 'label_show_url' => false, 'label_border' => false, 'label_columns' => 2, 'label_width_mm' => 50] as $k => $v) {
            $this->setting($pdo, $k, $v);
        }
        $as('labels with other settings', 'GET', '/labels?loc=1&items=1');
        $this->setting($pdo, 'public_url', '');
        $as('labels without public_url', 'GET', '/labels?ids=1', [], ['Host: shelf.localhost:9091']);
        $as('labels for a refused host', 'GET', '/labels?ids=1', [], ['Host: evil.example']);
        $this->setting($pdo, 'public_url', 'http://labels.example/a&b"c');
        $as('labels with markup in public_url', 'GET', '/labels?ids=1');
        $this->setting($pdo, 'public_url', 'http://labels.example');

        // Barcode lookup.
        $as('barcode form', 'GET', '/barcode');
        $as('barcode form blank code', 'GET', '/barcode?code=%20%09');
        $as('barcode form code', 'GET', '/barcode?code=%204006%20381333931%20');
        $as('barcode form code with a slash', 'GET', '/barcode?code=a/b');
        $as('barcode form code array', 'GET', '/barcode?code[]=123');
        $as('barcode one item', 'GET', '/barcode/4006381333931');
        $as('barcode two items', 'GET', '/barcode/123');
        $as('barcode no item', 'GET', '/barcode/nothing');
        $as('barcode with a slash', 'GET', '/barcode/a%2Fb');
        $as('barcode with a plain slash', 'GET', '/barcode/a/b');
        $as('barcode with a plus', 'GET', '/barcode/x%2By');
        $as('barcode with a raw plus', 'GET', '/barcode/x+y');
        $as('barcode empty code', 'GET', '/barcode/');
        $as('barcode blank code', 'GET', '/barcode/%20');
        $as('barcode trailing slash', 'GET', '/barcode/123/');
        $as('barcode dot segments', 'GET', '/barcode/a/../b');
        $as('barcode markup', 'GET', '/barcode/%3Cb%3E%22');
        $as('barcode long code', 'GET', '/barcode/' . str_repeat('9', 80));
        $as('barcode HEAD', 'HEAD', '/barcode/123');
        $as('barcode fetch', 'GET', '/barcode/4006381333931', [], $fetch);
        $as('viewer barcode', 'GET', '/barcode/123', [], [], 'vera');
        $as('signed out barcode', 'GET', '/barcode/123', [], [], null);
        $as('POST barcode', 'POST', '/barcode/123');
        $as('barcode form trailing slash', 'GET', '/barcode/?code=1');

        // Lending.
        $as('lend', 'POST', '/item/1/lend', ['lent_to' => "  Nikos \t <i>x</i>  ", 'lent_at' => '2026-06-01', 'lent_due' => '2026-06-10']);
        $as('lend change', 'POST', '/item/1/lend', ['lent_to' => 'Eleni <b>&"\'', 'lent_at' => '', 'lent_due' => '']);
        $as('lend fetch', 'POST', '/item/3/lend', ['lent_to' => 'Kostas', 'lent_at' => '2026-06-16', 'back' => '/lent'], $fetch);
        $as('lend without a name', 'POST', '/item/5/lend', ['lent_to' => '   ']);
        $as('lend without a name fetch', 'POST', '/item/5/lend', ['lent_to' => ''], $fetch);
        $as('lend bad date', 'POST', '/item/5/lend', ['lent_to' => 'A', 'lent_at' => '2026-02-30']);
        $as('lend bad due date', 'POST', '/item/5/lend', ['lent_to' => 'A', 'lent_due' => '1899-12-31']);
        $as('lend in the future', 'POST', '/item/5/lend', ['lent_to' => 'A', 'lent_at' => '2026-06-17']);
        $as('lend due before lent', 'POST', '/item/5/lend', ['lent_to' => 'A', 'lent_at' => '2026-06-10', 'lent_due' => '2026-06-09']);
        $as('lend array name', 'POST', '/item/5/lend', ['lent_to' => ['A']]);
        $as('lend long name', 'POST', '/item/5/lend', ['lent_to' => str_repeat('Ω', 120)]);
        $as('lend missing item', 'POST', '/item/99/lend', ['lent_to' => 'A']);
        $as('return missing item', 'POST', '/item/99/return');
        $as('viewer lend', 'POST', '/item/5/lend', ['lent_to' => 'A'], [], 'vera');
        $as('viewer return', 'POST', '/item/2/return', [], [], 'vera');
        $as('lender lend', 'POST', '/item/6/lend', ['lent_to' => 'Ana', 'lent_at' => '2026-06-12', 'lent_due' => '2026-06-13'], [], 'leo');
        $as('GET lend', 'GET', '/item/1/lend');
        $as('return without CSRF', 'POST', '/item/1/return', ['csrf' => 'nope']);
        $as('lend with no CSRF token', 'POST', '/item/5/lend', ['csrf' => null, 'lent_to' => 'A']);
        $as('item page lent', 'GET', '/item/1');
        $as('item page overdue', 'GET', '/item/2');
        $as('item page not lent', 'GET', '/item/5');
        $as('lent list', 'GET', '/lent');
        $as('lent list HEAD', 'HEAD', '/lent');
        $as('viewer lent list', 'GET', '/lent', [], [], 'vera');
        $as('POST lent', 'POST', '/lent');
        $as('return', 'POST', '/item/1/return');
        $as('return again', 'POST', '/item/1/return');
        $as('return from the list', 'POST', '/item/2/return', ['back' => '/lent']);
        $as('return fetch', 'POST', '/item/3/return', ['back' => '/elsewhere'], $fetch);
        $as('return array back', 'POST', '/item/4/return', ['back' => ['/lent']]);
        $as('return the rest', 'POST', '/item/6/return');
        $as('return the long name', 'POST', '/item/5/return');
        $as('lent list empty', 'GET', '/lent');
        $as('lend for the failure', 'POST', '/item/5/lend', ['lent_to' => 'Zoe']);

        // A failing statement (a trigger on activity) is a 500 and changes no table.
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        foreach ([['fail lend', '/item/1/lend', ['lent_to' => 'Max']], ['fail return', '/item/5/return', []]] as [$case, $path, $fields]) {
            $pdo->exec($pg ? 'CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()'
                : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = $tables();
            $as($case, 'POST', $path, $fields);
            $pdo->exec($pg ? 'DROP TRIGGER apotheke_test_fail ON activity' : 'DROP TRIGGER apotheke_test_fail');
            $out[$case]['unchanged'] = $before === $out[$case]['tables'];
            unset($out[$case]['body']);
        }

        return $out;
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
