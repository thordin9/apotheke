<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Golden (PLAN step 14, first part): the CSV export and import on CI4
 * against legacy, on the configured driver. Besides the answers (status,
 * type, Location, JSON, security headers, the pages and the CSV), the item,
 * tag, location, category, activity and imports tables and the waiting
 * upload files are compared after every step. A failing statement (a
 * trigger) is a 500 and changes no table on both.
 */
final class Ci4ImportExportParityTest extends TestCase
{
    /** Methods besides GET, HEAD and POST that legacy answers on the import pages (N-372, N-420): non-safe ones need CI4's token. */
    private const UNSAFE = ['PUT', 'PATCH', 'DELETE', 'CONNECT'];

    private const SAFE = ['OPTIONS', 'TRACE'];

    public function testImportAndExportMatchLegacy(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('import-' . $app);
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
        // N-372: legacy shows the home page for PUT, PATCH, DELETE and CONNECT without a token; CI4
        // wants the token on every non-safe method (SEC-N10) and shows the same page once it is sent.
        $untokened = [];
        foreach ($answers as $app => $cases) {
            foreach (self::UNSAFE as $verb) {
                $untokened[$app][$verb] = $cases[$verb . ' home without CSRF']['status'];
                unset($answers[$app][$verb . ' home without CSRF']);
            }
        }
        $this->assertSame(['legacy' => ['PUT' => 200, 'PATCH' => 200, 'DELETE' => 200, 'CONNECT' => 200], 'ci4' => ['PUT' => 419, 'PATCH' => 419, 'DELETE' => 419, 'CONNECT' => 419]], $untokened);
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        $l = $answers['legacy'];
        // The cases are what they claim to be.
        foreach (['home', 'map', 'preview with errors'] as $case) {
            $this->assertSame(['nosniff', 'text/html; charset=UTF-8'], [$l[$case]['nosniff'], $l[$case]['type']], $case);
            $this->assertStringContainsString("default-src 'self'", (string)$l[$case]['csp'], $case);
        }
        $this->assertSame('text/csv; charset=utf-8', $l['export']['type']);
        $this->assertStringStartsWith("\xEF\xBB\xBFid,name,location,", (string)$l['export']['body']);
        $this->assertStringContainsString("'=SUM(A1)", (string)$l['export']['body']);
        $this->assertSame(422, $l['upload nothing']['status']);
        $this->assertStringContainsString('Choose a CSV file to import.', (string)$l['upload nothing']['body']);
        $this->assertStringContainsString('That does not look like a CSV file.', (string)$l['upload binary']['body']);
        $this->assertStringContainsString('more than 5,000 rows', (string)$l['upload too many rows']['body']);
        $this->assertStringContainsString('That file is larger than 5 MB.', (string)$l['upload too large']['body']);
        $this->assertStringContainsString('needs a header row', (string)$l['upload header only']['body']);
        $this->assertSame([303, '/import/1'], [$l['upload']['status'], $l['upload']['location']]);
        foreach (['upload without CSRF', 'preview without CSRF'] as $case) {
            $this->assertSame(419, $l[$case]['status'], $case);
        }
        $this->assertSame(403, $l['viewer home']['status']);
        $this->assertSame(200, $l['viewer export']['status']);
        $this->assertSame(403, $l['looker export']['status']);
        foreach ([...self::UNSAFE, ...self::SAFE] as $verb) {
            $this->assertSame(200, $l[$verb . ' home']['status'], $verb . ' home (N-372, N-420)');
            $this->assertStringContainsString('name="csv"', (string)$l[$verb . ' home']['body'], $verb . ' home is the upload page');
            $this->assertSame(404, $l[$verb . ' map']['status'], $verb . ' map');
            $this->assertSame(404, $l[$verb . ' preview']['status'], $verb . ' preview');
            $this->assertSame([303, '/import?done=2'], [$l[$verb . ' map after commit']['status'], $l[$verb . ' map after commit']['location']], $verb);
            $this->assertSame(303, $l[$verb . ' preview after commit']['status'], $verb);
        }
        $this->assertSame(403, $l['viewer PUT home']['status']);
        $this->assertSame(403, $l['viewer OPTIONS home']['status']);
        $this->assertSame(404, $l['GET preview']['status']);
        $this->assertSame(404, $l['POST map']['status']);
        $this->assertSame(404, $l['missing import']['status']);
        $this->assertStringContainsString('is chosen for two columns', (string)$l['preview two names']['body']);
        $this->assertStringContainsString('Choose which column holds the item name.', (string)$l['preview without a name']['body']);
        $this->assertStringContainsString('Unknown location “I”', (string)$l['preview without creating']['body']);
        $this->assertStringContainsString('Quantity “&lt;b&gt;two”', (string)$l['preview with errors']['body']);
        $this->assertStringContainsString('Locations can be nested at most', (string)$l['preview with errors']['body']);
        $this->assertSame(422, $l['commit with errors']['status']);
        $this->assertSame([303, '/import?done=2'], [$l['commit other file']['status'], $l['commit other file']['location']]);
        $this->assertSame([303, '/import?done=2'], [$l['commit again']['status'], $l['commit again']['location']]);
        $this->assertSame(['ok' => true, 'redirect' => '/import?done=1'], $l['commit']['json']);
        $this->assertStringContainsString('Imported · ', (string)$l['home after commit']['body']);
        $this->assertSame([303, '/import?done=2'], [$l['map after commit']['status'], $l['map after commit']['location']]);
        $this->assertSame(410, $l['map without its file']['status']);
        $this->assertStringContainsString('unchanged', (string)$l['round trip preview']['body']);
        $this->assertStringContainsString('<b>0</b> new</span><span class="imp-n imp-upd"><b>0</b> updated</span><span class="imp-n imp-same"><b>7</b> unchanged</span><span class="imp-n imp-bad"><b>0</b> errors', (string)$l['round trip preview']['body']);
        $this->assertSame([303, '/import?done=4'], [$l['changed commit']['status'], $l['changed commit']['location']]);
        $this->assertStringContainsString('1,"Drill XL",', (string)$l['export after import']['body']);
        $this->assertStringContainsString(',Carol,2026-06-16,', (string)$l['export after import']['body']);
        $this->assertStringContainsString('ID must be a number.', (string)$l['bad ids preview']['body']);
        $this->assertStringContainsString('Quantity “-1”', (string)$l['bad ids preview']['body']);
        foreach (['fail commit', 'fail preview'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        // A looker may only look: no export.
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('looker', 'Looker', '[\"view\"]', 0, '2026-01-01 00:00:00')");
        foreach ([['ada', 'admin'], ['vera', 'viewer'], ['lou', 'looker']] as [$name, $role]) {
            $user->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
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
        $tables = static function () use ($pdo, $dir): string {
            $o = [];
            foreach (['SELECT * FROM items ORDER BY id', 'SELECT * FROM item_tags ORDER BY item_id, tag_id', 'SELECT * FROM tags ORDER BY id', 'SELECT * FROM locations ORDER BY id',
                'SELECT * FROM categories ORDER BY id', 'SELECT item_id, item_name, action, details, actor, created_at FROM activity ORDER BY id', 'SELECT * FROM imports ORDER BY id'] as $sql) {
                $o[] = $pdo->query($sql)->fetchAll();
            }
            $files = [];
            foreach (glob($dir . '/tmp/import-*') ?: [] as $f) {
                $files[basename($f)] = hash_file('sha256', $f);
            }
            $o[] = $files;

            return json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        };
        $out = [];
        $record = function (string $case, array $r, string $token) use (&$out, $tables): void {
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $json = str_starts_with((string)($r['headers']['content-type'] ?? ''), 'application/json') ? $r['json'] : null;
            if (is_array($json)) {
                array_walk_recursive($json, static function (&$v) use ($token): void {
                    $v = is_string($v) ? str_replace($token, 'SESSION-TOKEN', $v) : $v;
                });
            }
            $cache = $r['headers']['cache-control'] ?? '';
            $out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'disposition' => $r['headers']['content-disposition'] ?? null,
                'cache-control' => $r['status'] === 200 || $json !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache),
                'nosniff' => $r['headers']['x-content-type-options'] ?? null, 'csp' => $r['headers']['content-security-policy'] ?? null,
                'json' => $json, 'body' => $json === null ? str_replace($token, 'SESSION-TOKEN', (string)$body) : null, 'tables' => $tables()];
        };
        foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens', 'currency' => 'EUR'] as $k => $v) {
            $this->setting($pdo, $k, $v);
        }
        $as = function (string $case, string $method, string $path, array $fields = [], array $files = [], array $headers = [], ?string $who = 'ada') use ($signIn, $record): array {
            [$client, $token] = $signIn($who);
            if ($method === 'POST') {
                $fields += ['csrf' => $token];   // a case's own csrf wins; null sends none
                $fields = array_filter($fields, static fn ($v): bool => $v !== null);
            } elseif (in_array($method, self::UNSAFE, true) && !array_key_exists('csrf', $fields)) {
                $headers[] = 'X-CSRF-Token: ' . $token;   // ['csrf' => null] sends none
            }
            $r = $client->req($method, $path, $fields, $files, $headers);
            $record($case, $r, $token);

            return $r;
        };
        $csv = static fn (string $data, string $name = 'items.csv'): array => ['csv' => ['name' => $name, 'type' => 'text/csv', 'data' => $data]];
        $fetch = ['X-Requested-With: fetch'];

        // The export.
        $as('export empty', 'GET', '/export.csv');
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage & <i', 'garage & <i', 'garage & <i', '2026-01-01 00:00:00'), ('Attic', 'attic', 'attic', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO categories (name, name_key, sort_key, created_at) VALUES ('Tools', 'tools', 'tools', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('red', 'red', 'red', '2026-01-01 00:00:00'), ('Big', 'big', 'big', '2026-01-01 00:00:00')");
        $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, quantity, notes, value_cents, serial, barcode, min_qty, lent_to, lent_at, lent_due, expires_on, purchase_date, image, search, sort_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Drill', 1, 1, 2, "Line one\nline \"two\", three", 4990, 'SN-1', '4006381333931', 1, null, null, null, null, '2025-02-03', 'abcdef0123456789abcdef0123456789.jpg'],
            ['=SUM(A1)', 2, null, 1, "'@x", null, '+49', null, null, 'Bob', '2026-06-01', '2026-07-01', '2027-01-01', null, null],
            ['Lamp <b>"&\'', null, null, 0, '-1', 12, '', null, null, null, null, null, null, null, null],
            ['Ελιά', 1, null, 3, '', null, '', null, null, null, null, null, null, null, null],
        ] as $r) {
            $item->execute([...$r, mb_strtolower($r[0]), mb_strtolower($r[0]), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);
        }
        $pdo->exec('INSERT INTO item_tags (item_id, tag_id) VALUES (1, 1), (1, 2), (2, 1)');
        $as('export', 'GET', '/export.csv');
        $as('export HEAD', 'HEAD', '/export.csv');
        $as('viewer export', 'GET', '/export.csv', [], [], [], 'vera');
        $as('looker export', 'GET', '/export.csv', [], [], [], 'lou');
        $as('signed out export', 'GET', '/export.csv', [], [], [], null);
        $as('POST export', 'POST', '/export.csv');

        // Upload.
        $as('home', 'GET', '/import');
        $as('home HEAD', 'HEAD', '/import');
        $as('viewer home', 'GET', '/import', [], [], [], 'vera');
        $as('home trailing slash', 'GET', '/import/');
        $as('upload nothing', 'POST', '/import');
        $as('upload an array', 'POST', '/import', [], ['csv[]' => ['name' => 'a.csv', 'type' => 'text/csv', 'data' => "name\nx\n"]]);
        $as('upload binary', 'POST', '/import', [], $csv("name\n\0x\n"));
        $as('upload header only', 'POST', '/import', [], $csv("name;qty\n\n"));
        $as('upload too many rows', 'POST', '/import', [], $csv("name\n" . str_repeat("x\n", 5001)));
        $as('upload too large', 'POST', '/import', [], $csv("name\n" . str_repeat(str_repeat('y', 99) . "\n", 52429)));
        $as('upload without CSRF', 'POST', '/import', ['csrf' => 'nope'], $csv("name\nx\n"));
        $as('viewer upload', 'POST', '/import', [], $csv("name\nx\n"), [], 'vera');
        $long = str_repeat('a/b', 50);
        $file = "\xEF\xBB\xBFItem;Where;Qty;Colour <i>;Tags;Price;Bought;Category;Borrower;Due\n"
            . "Ladder;Shed › Wall;1;grey;red, New;12,50;2026-01-01;Tools;;\n"
            . "Rope;Garage shelf;<b>two;red;;;;;;\n"
            . "Bucket;Shed;3;'=blue;;€ 3;2027-01-01;Paint;;\n"
            . "Deep;A/B/C/D/E/F/G/H/I;1;;;;;;;\n"
            . "\"Sack \"\"<b>\"\"\";Attic;;;Big;1.234,50;;tools;Ann;2026-05-01\n"
            . "Box;Attic;1;;;;;;Ann;2026-07-01\n"
            . ";Attic;1;;;;;;;\n";
        $as('upload', 'POST', '/import', [], $csv($file, 'my <file>.csv'));
        $as('upload fetch', 'POST', '/import', [], $csv(mb_convert_encoding("Name,Notes\nCafé,ok\n", 'Windows-1252', 'UTF-8'), $long . '.csv'), $fetch);
        $as('upload fetch error', 'POST', '/import', [], $csv("x"), $fetch);

        // Mapping and preview.
        $as('map', 'GET', '/import/1');
        $as('map HEAD', 'HEAD', '/import/1');
        $as('viewer map', 'GET', '/import/1', [], [], [], 'vera');
        $as('missing import', 'GET', '/import/99');
        $as('missing import POST', 'POST', '/import/99/preview');
        $as('GET preview', 'GET', '/import/1/preview');
        $as('POST map', 'POST', '/import/1');
        $as('unknown act', 'GET', '/import/1/other');
        $cols = ['name', 'location', 'quantity', 'notes', 'tags', 'value', 'purchase_date', 'category', 'lent_to', 'lent_due'];
        $as('preview without CSRF', 'POST', '/import/1/preview', ['csrf' => null, 'col' => $cols]);
        $as('preview two names', 'POST', '/import/1/preview', ['col' => ['name', 'name']]);
        $as('preview without a name', 'POST', '/import/1/preview', ['col' => ['', 'location', 'bogus']]);
        $as('preview col string', 'POST', '/import/1/preview', ['col' => 'name']);
        $as('preview without creating', 'POST', '/import/1/preview', ['col' => $cols]);
        $as('preview with errors', 'POST', '/import/1/preview', ['col' => $cols, 'create' => '1']);
        $as('preview fetch', 'POST', '/import/1/preview', ['col' => $cols, 'create' => '1'], [], $fetch);
        $as('viewer preview', 'POST', '/import/1/preview', ['col' => $cols, 'create' => '1'], [], [], 'vera');
        $as('commit with errors', 'POST', '/import/1/commit', ['col' => $cols, 'create' => '1']);
        $as('preview skipping', 'POST', '/import/1/preview', ['col' => $cols, 'create' => '1', 'skip_errors' => '1']);
        $as('map after preview', 'GET', '/import/1');
        // N-372, N-420: legacy shows the home page for every method but POST; an import's page is GET only.
        foreach ([...self::UNSAFE, ...self::SAFE] as $verb) {
            $as($verb . ' home', $verb, '/import');
            if (in_array($verb, self::UNSAFE, true)) {
                $as($verb . ' home without CSRF', $verb, '/import', ['csrf' => null]);
            }
            $as($verb . ' map', $verb, '/import/1');
            $as($verb . ' preview', $verb, '/import/1/commit');
        }
        $as('viewer PUT home', 'PUT', '/import', [], [], [], 'vera');
        $as('viewer OPTIONS home', 'OPTIONS', '/import', [], [], [], 'vera');

        // A failing statement (a trigger) is a 500 and changes no table.
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        foreach ([['fail preview', 'item_tags', '/import/1/preview'], ['fail commit', 'activity', '/import/1/commit']] as [$case, $table, $path]) {
            $pdo->exec($pg ? "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()"
                : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = $tables();
            $as($case, 'POST', $path, ['col' => $cols, 'create' => '1', 'skip_errors' => '1']);
            $pdo->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
            $out[$case]['unchanged'] = $before === $out[$case]['tables'];
            unset($out[$case]['body']);
        }

        // Import.
        $as('commit other file', 'POST', '/import/2/commit', ['col' => ['name', 'notes']]);
        $as('home after commit', 'GET', '/import?done=2');
        $as('home done array', 'GET', '/import?done[]=2');
        $as('home done unknown', 'GET', '/import?done=1');
        $as('map after commit', 'GET', '/import/2');
        foreach ([...self::UNSAFE, ...self::SAFE] as $verb) {
            $as($verb . ' map after commit', $verb, '/import/2');
            $as($verb . ' preview after commit', $verb, '/import/2/preview');
        }
        $as('commit again', 'POST', '/import/2/commit', ['col' => ['name', 'notes']]);
        $as('commit', 'POST', '/import/1/commit', ['col' => $cols, 'create' => '1', 'skip_errors' => '1'], [], $fetch);
        $as('home with history', 'GET', '/import');

        // Round trip: the export imports unchanged; changed cells update.
        [$client] = $signIn('ada');
        $export = $client->get('/export.csv')['body'];
        $as('upload export', 'POST', '/import', [], $csv($export, 'export.csv'));
        $as('round trip map', 'GET', '/import/3');
        $as('round trip preview', 'POST', '/import/3/preview', ['col' => array_map(static fn (string $h): string => in_array($h, ['image', 'created_at', 'updated_at', 'currency', 'lent_at'], true) ? '' : $h, str_getcsv(strtok(substr($export, 3), "\n"), ',', '"', '\\'))]);
        $changed = str_replace(['Drill,', ',Bob,'], ['Drill XL,', ',Carol,'], $export);
        $as('upload changed', 'POST', '/import', [], $csv($changed, 'changed.csv'));
        $as('changed preview', 'POST', '/import/4/preview', ['col' => ['id', 'name', 'location', 'category', 'quantity', 'notes', '', '', '', 'tags', 'value', '', 'purchase_date', 'serial', 'min_qty', 'lent_to', '', 'lent_due', 'expires_on', 'barcode']]);
        $as('changed commit', 'POST', '/import/4/commit', ['col' => ['id', 'name', 'location', 'category', 'quantity', 'notes', '', '', '', 'tags', 'value', '', 'purchase_date', 'serial', 'min_qty', 'lent_to', '', 'lent_due', 'expires_on', 'barcode']]);
        $as('export after import', 'GET', '/export.csv');
        $as('upload bad ids', 'POST', '/import', [], $csv("id,name,qty\nx,A,1\n999,B,1\n3,C,-1\n"));
        $as('bad ids preview', 'POST', '/import/5/preview', ['col' => ['id', 'name', 'quantity']]);
        @unlink($dir . '/tmp/import-5.csv');
        $as('map without its file', 'GET', '/import/5');
        $as('preview without its file', 'POST', '/import/5/preview', ['col' => ['id', 'name', 'quantity']]);

        return $out;
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
