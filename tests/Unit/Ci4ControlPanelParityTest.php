<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/**
 * Golden: the Control Panel on CI4 against legacy. The same requests go to a
 * legacy and a CI4 server with the same data: GET /settings and every ported
 * section (General, Appearance with the logo, Currency, List, Thresholds, QR
 * labels, Photos, AI autofill, System) for an admin, a non-admin with the
 * Settings permission, an editor, a viewer, a guest and a signed-out visitor;
 * saves, every kind of validation failure, resets, CSRF refusals, the logo
 * upload (PNG, JPEG, SVG accepted; too small, too large, not an image, an SVG
 * with a DOCTYPE, no file refused) and its reset, AI autofill's access token
 * and Test buttons against the stub, plain and fetch. After each request the
 * settings table and the brand files are compared too. Answers are
 * byte-identical except the asset and logo cache busters, the session id, the
 * per-session CSRF token, the data directory and the stub's elapsed time. The
 * access token never appears in any answer.
 */
final class Ci4ControlPanelParityTest extends TestCase
{
    private const PW = 'long-enough';

    public function testTheControlPanelMatchesLegacy(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $stub = new StubServer();
        $answers = [];
        try {
            foreach (['legacy', 'ci4'] as $app) {
                $dir = tmpdir('cpanel-' . $app);
                $server = new Server($dir, [], $app);
                try {
                    $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                    $answers[$app] = $this->visit($server, $dir, $stub);
                } finally {
                    $server->stop();
                    testdb_drop($dir);
                    rrmdir($dir);
                    @unlink($dir . '.server.log');
                }
            }
        } finally {
            $stub->stop();
        }
        $this->assertGreaterThan(150, count($answers['legacy']));
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        // The access token is write-only: no answer of either app carries it (the stub's address names it under /t/).
        foreach ($answers as $app => $cases) {
            foreach ($cases as $case => $a) {
                $seen = str_replace('/t/' . self::token(), '/t/…', (string)json_encode([$a['body'], $a['json'], $a['location']], JSON_UNESCAPED_SLASHES));
                $this->assertStringNotContainsString(self::token(), $seen, "$app: $case");
            }
        }
        // The cases are what they claim to be.
        $l = $answers['legacy'];
        $this->assertSame(200, $l['ada GET /settings']['status']);
        $this->assertStringContainsString('id="setlist"', (string)$l['ada GET /settings']['body']);
        $this->assertSame([200, 403, 403, 303, 303], [$l['dee GET /settings/general']['status'], $l['eda GET /settings/general']['status'],
            $l['vera GET /settings/general']['status'], $l['guest GET /settings/general']['status'], $l['signed-out GET /settings/general']['status']]);
        $this->assertSame([419, 403], [$l['ada general without csrf']['status'], $l['eda general without csrf']['status']]);
        $this->assertSame([303, '/settings/general?done=saved'], [$l['general save']['status'], $l['general save']['location']]);
        foreach (['general: empty name', 'general: long name', 'general: bad address', 'general: name array', 'appearance: bad accent', 'appearance: bad theme',
            'labels: width too small', 'labels: width not a number', 'autofill: bad address', 'autofill: bad typed token'] as $case) {
            $this->assertSame(422, $l[$case]['status'], $case);
            $this->assertStringContainsString('id="set-errors"', (string)$l[$case]['body'], $case);
        }
        $this->assertSame(['ok' => true, 'redirect' => '/settings/general?done=saved'], $l['general save fetch']['json']);
        foreach (['logo png', 'logo jpeg', 'logo svg'] as $case) {
            $this->assertSame([303, '/settings/appearance?done=logo'], [$l[$case]['status'], $l[$case]['location']], $case);
        }
        foreach (['logo too small', 'logo not an image', 'logo svg doctype', 'logo too large', 'logo missing'] as $case) {
            $this->assertSame(422, $l[$case]['status'], $case);
        }
        $this->assertStringContainsString('The logo must be at least 64×64 pixels.', (string)$l['logo too small']['body']);
        $this->assertStringNotContainsString('<script', (string)$l['logo svg']['brand']);
        $this->assertNotSame('[]', $l['logo png']['brand']);
        $this->assertSame('[]', $l['logo reset']['brand']);
        $this->assertStringContainsString('A token is <b>set</b>.', (string)$l['autofill page with a token']['body']);
        $this->assertSame([303, '/settings/autofill?done=token-saved'], [$l['token save']['status'], $l['token save']['location']]);
        $this->assertSame(422, $l['token with a space']['status']);
        $this->assertStringContainsString('id="af-res"', (string)$l['test, plain form']['body']);
        $this->assertSame('ok', $l['test fetch']['json']['code'] ?? null);
        $this->assertStringContainsString('af-res', (string)($l['test fetch']['json']['html'] ?? ''));
        $this->assertSame('/settings/autofill?done=reset-token-cleared', $l['autofill reset']['location']);
        $this->assertStringContainsString('id="systable"', (string)$l['ada GET /settings/system']['body']);
        $this->assertSame((string)json_encode([['Shelf <2>'], ['Food & drink']]), $l['items save']['defaults']);
        $this->assertSame((string)json_encode([[], ['Food & drink']]), $l['items: none and unknown ids']['defaults']);
        $this->assertStringContainsString('id="s-default_location"', (string)$l['ada GET /settings/items']['body']);
        $this->assertSame(422, $l['items: invalid keeps the defaults']['status']);
        $this->assertSame([200, 'application/json; charset=utf-8'], [$l['settings export']['status'], $l['settings export']['type']]);
        $this->assertStringContainsString('attachment; filename="apotheke-settings-', (string)$l['settings export']['disposition']);
        $this->assertStringNotContainsString('password_hash', (string)$l['settings export']['body']);
        $this->assertSame([403, 303], [$l['eda GET /settings/export.json']['status'], $l['guest GET /settings/export.json']['status']]);
        $this->assertSame([419, 403], [$l['ada import without csrf']['status'], $l['vera import']['status']]);
        $this->assertStringContainsString('Imported ', (string)$l['import as a file']['body']);
        $this->assertStringContainsString('Ignored unknown or protected keys', (string)$l['import as a file']['body']);
        $this->assertStringContainsString('auth_guest_disabled', (string)$l['desk imports a sign-in setting']['body']);
        foreach (['import: not a settings file', 'import: nothing', 'import: too large'] as $case) {
            $this->assertSame(422, $l[$case]['status'], $case);
        }
        $this->assertSame([404, 404, 404], [$l['GET /settings/nope']['status'], $l['GET /settings/general/']['status'], $l['PUT /settings/general']['status']]);
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir, StubServer $stub): array
    {
        $pdo = testdb($dir);
        $pdo->prepare('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?, ?, ?, 0, ?)')
            ->execute(['desk', 'Settings desk', json_encode(['view', 'settings']), '2026-01-01 00:00:00']);
        $add = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ada', 'admin'], ['dee', 'desk'], ['eda', 'editor'], ['vera', 'viewer']] as [$name, $role]) {
            $add->execute([$name, $name, password_hash(self::PW, PASSWORD_BCRYPT, ['cost' => 4]), $role, 'active', '2026-01-01 00:00:00']);
        }
        $loc = $pdo->prepare('INSERT INTO locations (name, name_key, sort_key, parent_id, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['Garage', null, 1], ['Shelf <2>', 1, 0], ['Attic', null, 0]] as [$name, $parent, $default]) {
            $loc->execute([$name, mb_strtolower($name), natkey($name), $parent, $default, '2026-01-01 00:00:00']);
        }
        $cat = $pdo->prepare('INSERT INTO categories (name, name_key, sort_key, is_default, created_at) VALUES (?, ?, ?, 0, ?)');
        foreach (['Tools', 'Food & drink'] as $name) {
            $cat->execute([$name, mb_strtolower($name), natkey($name), '2026-01-01 00:00:00']);
        }
        $this->setting($pdo, 'auth_enabled', 'true');
        $this->setting($pdo, 'auth_guest_disabled', 'false');
        $hidden = [$dir => 'DATA', $server->base => 'http://SERVER'];
        foreach (test_db_env($dir) as $k => $v) {
            if (in_array($k, ['DB_SCHEMA'], true)) {
                $hidden[$v] = 'SCHEMA';
            }
        }
        $browser = function (?string $account) use ($pdo, $server, $dir): array {
            $client = $server->client();
            $token = bin2hex(random_bytes(32));
            $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . (time() - 100) . ';';
            if ($account !== null) {
                $row = $pdo->query("SELECT id, password_hash, session_epoch FROM users WHERE username_key = '$account'")->fetch();
                $epoch = (string)$row['session_epoch'] !== '' ? (string)$row['session_epoch'] : substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $session = 'uid|i:' . $row['id'] . ';auth_epoch|s:' . strlen($epoch) . ':"' . $epoch . '";' . $session;
            }
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, $session);
            $client->cookies['apothekesid'] = $sid;

            return [$client, $token];
        };
        $clean = static function (string $s) use ($hidden): string {
            $s = strtr($s, $hidden);
            $s = (string)preg_replace('#\?v=[0-9a-f]{8}\b#', '?v=V', $s);
            $s = (string)preg_replace('/\b[0-9a-f]{64}\b/', 'SESSION-TOKEN', $s);

            return (string)preg_replace('#(<dd id="af-s-elapsed">)[^<]*#', '$1T', $s);
        };
        $out = [];
        $record = function (string $case, array $r, bool $state) use (&$out, $clean, $pdo, $dir): void {
            $cache = $r['headers']['cache-control'] ?? '';
            $cache = $r['status'] === 200 || $r['json'] !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache);
            $cookies = [];
            foreach ($r['raw'] as $line) {
                if (stripos($line, 'set-cookie:') === 0) {
                    $cookies[] = cookie_times_settled((string)preg_replace('/apothekesid=[^;]*/', 'apothekesid=SID', trim(substr($line, 11))));
                }
            }
            sort($cookies);
            $json = $r['json'];
            if (is_array($json)) {
                array_walk_recursive($json, static function (&$v, $k) use ($clean): void {
                    if ($k === 'elapsed_ms') {
                        $v = is_int($v) ? 'MS' : $v;
                    }
                    if ($k === 'exported_at') {
                        $v = is_string($v) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $v) === 1 ? 'T' : $v;
                    }
                    $v = is_string($v) ? $clean($v) : $v;
                });
            }
            $a = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'cache-control' => $cache, 'disposition' => $r['headers']['content-disposition'] ?? null, 'set-cookie' => $cookies, 'json' => $json, 'body' => $json === null ? $clean($r['body']) : null];
            if ($state) {
                $rows = $pdo->query("SELECT name, value FROM settings WHERE name <> 'password_hash' ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
                $a['settings'] = json_encode($rows);
                $brand = [];
                foreach (glob($dir . '/brand/*') ?: [] as $f) {
                    $brand[basename($f)] = str_ends_with($f, '.svg') ? (string)file_get_contents($f) : hash_file('sha256', $f);
                }
                $a['brand'] = json_encode($brand);
                $a['defaults'] = json_encode([$pdo->query('SELECT name FROM locations WHERE is_default = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN),
                    $pdo->query('SELECT name FROM categories WHERE is_default = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)]);
            }
            $out[$case] = $a;
        };
        $fetch = ['X-Requested-With: fetch'];
        $as = function (string $case, string $method, string $path, array $fields = [], array $o = []) use ($browser, $record): array {
            [$client, $token] = $browser(array_key_exists('who', $o) ? $o['who'] : 'ada');
            if ($method === 'POST' && ($o['csrf'] ?? true)) {
                $fields = ['csrf' => $token] + $fields;
            }
            $r = $client->req($method, $path, $fields, $o['files'] ?? [], $o['headers'] ?? []);
            $record($case, $r, $method === 'POST');

            return $r;
        };

        // Every visitor: the pages, and what is refused before CSRF.
        $pages = ['/settings', '/settings/general', '/settings/appearance', '/settings/locale', '/settings/list', '/settings/items', '/settings/limits',
            '/settings/labels', '/settings/uploads', '/settings/autofill', '/settings/data', '/settings/system', '/settings/export.json'];
        foreach (['ada' => 'ada', 'dee' => 'dee', 'eda' => 'eda', 'vera' => 'vera', 'guest' => null] as $visitor => $who) {
            foreach ($pages as $p) {
                $as("$visitor GET $p", 'GET', $p, [], ['who' => $who]);
            }
            $as("$visitor general without csrf", 'POST', '/settings/general', ['app_name' => 'X'], ['who' => $who, 'csrf' => false]);
            $as("$visitor general fetch without csrf", 'POST', '/settings/general', ['app_name' => 'X'], ['who' => $who, 'csrf' => false, 'headers' => $fetch]);
            $as("$visitor general save", 'POST', '/settings/general', ['app_name' => 'By ' . $visitor], ['who' => $who]);
            $as("$visitor logo reset", 'POST', '/settings/logo/reset', [], ['who' => $who]);
            $as("$visitor token clear", 'POST', '/settings/autofill/token', ['action' => 'clear'], ['who' => $who]);
            $as("$visitor import", 'POST', '/settings/import', ['json' => '{"format":"apotheke-settings","version":1,"settings":{"app_name":"Imported"}}'], ['who' => $who]);
            $as("$visitor import without csrf", 'POST', '/settings/import', ['json' => '{}'], ['who' => $who, 'csrf' => false]);
        }
        $this->setting($pdo, 'auth_guest_disabled', 'true');
        foreach (['/settings', '/settings/general', '/settings/system'] as $p) {
            $as("signed-out GET $p", 'GET', $p, [], ['who' => null]);
            $as("signed-out GET $p fetch", 'GET', $p, [], ['who' => null, 'headers' => $fetch]);
        }
        $as('signed-out general save', 'POST', '/settings/general', ['app_name' => 'Nobody'], ['who' => null]);
        $this->setting($pdo, 'auth_guest_disabled', 'false');

        // Routing: exactly the paths and methods legacy answers.
        foreach (['/settings/nope', '/settings/', '/settings/general/', '/settings/general/reset', '/settings/logo', '/settings/autofill/token', '/settings/system/x', '/settings.json'] as $p) {
            $as("GET $p", 'GET', $p);
        }
        $as('HEAD /settings', 'HEAD', '/settings');
        $as('HEAD /settings/labels', 'HEAD', '/settings/labels');
        $as('POST /settings', 'POST', '/settings');
        $as('POST /settings/nope', 'POST', '/settings/nope');
        $as('PUT /settings/general', 'PUT', '/settings/general');
        $as('DELETE /settings/general', 'DELETE', '/settings/general');
        foreach (['saved', 'reset', 'logo', 'logo-reset', 'token-saved', 'saved-token-cleared', 'user-added', 'nope'] as $done) {
            $as("GET general done=$done", 'GET', '/settings/general?done=' . $done);
        }
        $as('GET general done[]', 'GET', '/settings/general?done[]=saved');

        // General.
        $as('general save', 'POST', '/settings/general', ['app_name' => ' Our  <Store> ', 'app_tagline' => 'Tools & “things”', 'public_url' => 'http://shelf.example:9091/']);
        $as('general after save', 'GET', '/settings/general?done=saved');
        $as('general save fetch', 'POST', '/settings/general', ['app_name' => 'Fetched', 'app_tagline' => '', 'public_url' => ''], ['headers' => $fetch]);
        $as('general: empty name', 'POST', '/settings/general', ['app_name' => '  ', 'app_tagline' => 'x', 'public_url' => '']);
        $as('general: long name', 'POST', '/settings/general', ['app_name' => str_repeat('n', 41), 'app_tagline' => str_repeat('t', 81), 'public_url' => '']);
        $as('general: bad address', 'POST', '/settings/general', ['app_name' => 'A', 'public_url' => 'javascript:alert(1)']);
        $as('general: name array', 'POST', '/settings/general', ['app_name' => ['a', 'b'], 'public_url' => 'ftp://x']);
        $as('general: nothing posted', 'POST', '/settings/general');
        $as('general: unknown field ignored', 'POST', '/settings/general', ['app_name' => 'Kept', 'accent' => '#000000', 'password_hash' => 'x']);
        $as('general reset', 'POST', '/settings/general/reset');
        $as('general reset fetch', 'POST', '/settings/general/reset', [], ['headers' => $fetch]);

        // Appearance and the logo.
        $as('appearance save', 'POST', '/settings/appearance', ['accent' => '#C8502D', 'theme_default' => 'dark', 'icon_background' => '#112233']);
        $as('appearance with warnings', 'GET', '/settings/appearance?done=saved');
        $as('appearance short colour', 'POST', '/settings/appearance', ['accent' => 'abc', 'theme_default' => 'light', 'icon_background' => '#FBF1E3']);
        $as('appearance: bad accent', 'POST', '/settings/appearance', ['accent' => 'red', 'theme_default' => 'auto', 'icon_background' => '#FBF1E3']);
        $as('appearance: bad theme', 'POST', '/settings/appearance', ['accent' => '#4f46e5', 'theme_default' => 'neon', 'icon_background' => '#12']);
        $png = ['name' => 'logo.png', 'type' => 'image/png', 'data' => img_png(120, 80)];
        $as('logo png', 'POST', '/settings/logo', [], ['files' => ['logo' => $png]]);
        $as('appearance with a png logo', 'GET', '/settings/appearance?done=logo');
        $as('logo jpeg', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => 'l.jpg', 'type' => 'image/jpeg', 'data' => img_jpeg(64, 200)]]]);
        $svg = '<?xml version="1.0"?><!-- c --><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10" onload="x()">'
            . '<script>alert(1)</script><a href="https://evil.example"><rect width="10" height="10" fill="url(https://x.example/a)"/></a>'
            . '<circle cx="5" cy="5" r="4" fill="#C8502D" style="x"/><use xlink:href="#c"/><foreignObject/><![CDATA[t]]></svg>';
        $as('logo svg', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => 'l.svg', 'type' => 'image/svg+xml', 'data' => $svg]]]);
        $as('appearance with an svg logo', 'GET', '/settings/appearance');
        $as('logo too small', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => 's.png', 'type' => 'image/png', 'data' => img_png(10, 10)]]]);
        $as('logo not an image', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => 'l.png', 'type' => 'image/png', 'data' => "hello\n"]]]);
        $as('logo svg doctype', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => 'l.svg', 'type' => 'image/svg+xml', 'data' => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x "y">]><svg viewBox="0 0 1 1"/>']]]);
        $as('logo svg without a size', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => 'l.svg', 'type' => 'image/svg+xml', 'data' => '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>']]]);
        $as('logo too large', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => 'big.png', 'type' => 'image/png', 'data' => str_repeat('x', 2 * 1024 * 1024 + 1)]]]);
        $as('logo missing', 'POST', '/settings/logo', ['x' => '1']);
        $as('logo empty file', 'POST', '/settings/logo', [], ['files' => ['logo' => ['name' => '', 'type' => 'application/octet-stream', 'data' => '']]]);
        $as('logo png again', 'POST', '/settings/logo', [], ['files' => ['logo' => $png]]);
        $as('logo reset', 'POST', '/settings/logo/reset');
        $as('logo png for the reset', 'POST', '/settings/logo', [], ['files' => ['logo' => $png]]);
        $as('appearance reset', 'POST', '/settings/appearance/reset');
        $as('appearance after reset', 'GET', '/settings/appearance?done=reset');

        // QR labels and the other field-only sections.
        $as('labels save', 'POST', '/settings/labels', ['label_width_mm' => '50', 'label_height_mm' => '40', 'label_columns' => '4', 'label_gap_mm' => '0',
            'label_show_sub' => '0', 'label_show_url' => '1', 'label_border' => '0']);
        $as('labels after save', 'GET', '/settings/labels?done=saved');
        $as('labels: width too small', 'POST', '/settings/labels', ['label_width_mm' => '5', 'label_height_mm' => '40', 'label_columns' => '11', 'label_gap_mm' => '0']);
        $as('labels: width not a number', 'POST', '/settings/labels', ['label_width_mm' => '5mm', 'label_height_mm' => '', 'label_columns' => '2.5', 'label_gap_mm' => '-1', 'label_border' => 'yes']);
        $as('labels reset', 'POST', '/settings/labels/reset');
        $as('locale save', 'POST', '/settings/locale', ['currency' => 'CHF', 'money_format' => 'ch', 'date_format' => 'd.m.Y', 'time_format' => 'g:i A', 'timezone' => 'Europe/Zurich']);
        $as('locale bad zone', 'POST', '/settings/locale', ['currency' => '', 'money_format' => 'xx', 'date_format' => 'Y', 'time_format' => 'H:i', 'timezone' => 'Mars/Base']);
        $as('locale after save', 'GET', '/settings/locale');
        $as('list save', 'POST', '/settings/list', ['page_size' => '24', 'default_sort' => 'name']);
        $as('list bad', 'POST', '/settings/list', ['page_size' => '0', 'default_sort' => 'random']);
        $as('limits page after', 'GET', '/settings/limits');
        $as('limits bad', 'POST', '/settings/limits', ['expiry_soon_days' => '0', 'undo_seconds' => 'x']);
        $as('uploads bad', 'POST', '/settings/uploads', ['upload_max_mb' => '9999', 'photo_quality' => '1']);
        $as('uploads reset', 'POST', '/settings/uploads/reset');
        foreach (['locale', 'list', 'limits'] as $s) {
            $as("$s reset", 'POST', "/settings/$s/reset");
        }

        // New item defaults: the settings and the default location and category (stored on the lists).
        $as('items save', 'POST', '/settings/items', ['default_quantity' => '2', 'default_min_qty' => '', 'default_tags' => 'home, new', 'default_notes' => 'n',
            'default_purchase_today' => '1', 'default_expiry_months' => '24', 'default_location' => '2', 'default_category' => '2']);
        $as('items after save', 'GET', '/settings/items?done=saved');
        $as('items: none and unknown ids', 'POST', '/settings/items', ['default_quantity' => '1', 'default_location' => '', 'default_category' => '99']);
        $as('items: id array', 'POST', '/settings/items', ['default_quantity' => '1', 'default_location' => ['1'], 'default_category' => '1']);
        $as('items: invalid keeps the defaults', 'POST', '/settings/items', ['default_quantity' => '-1', 'default_expiry_months' => '0', 'default_location' => '3', 'default_category' => '']);
        $as('items after the invalid save', 'GET', '/settings/items');
        $as('items reset', 'POST', '/settings/items/reset');

        // Backup & data: its one setting, and the settings file.
        $as('data save', 'POST', '/settings/data', ['import_create_missing' => '0']);
        $as('settings export', 'GET', '/settings/export.json');
        $export = (string)json_encode(['format' => 'apotheke-settings', 'version' => 1, 'settings' => ['app_name' => 'From a file', 'page_size' => '0', 'password_hash' => 'x',
            'auth_enabled' => false, 'logo' => 'png', 'nope' => 1, 'autofill_url' => $stub->base . '/n/imported', 'currency' => ['a']]]);
        $as('import as a file', 'POST', '/settings/import', [], ['files' => ['file' => ['name' => 's.json', 'type' => 'application/json', 'data' => $export]]]);
        $as('import as a field', 'POST', '/settings/import', ['json' => '{"format":"apotheke-settings","version":1,"settings":{"app_tagline":"one"}}']);
        $as('import, the file wins over the field', 'POST', '/settings/import', ['json' => '{}'], ['files' => ['file' => ['name' => 's.json', 'type' => 'application/json', 'data' => $export]]]);
        $as('import: not a settings file', 'POST', '/settings/import', ['json' => '{"format":"other"}']);
        $as('import: nothing', 'POST', '/settings/import');
        $as('import: empty file falls back to the field', 'POST', '/settings/import', ['json' => '{"format":"apotheke-settings","settings":{}}'], ['files' => ['file' => ['name' => '', 'type' => 'application/octet-stream', 'data' => '']]]);
        $as('import: too large', 'POST', '/settings/import', ['json' => '{"format":"apotheke-settings","settings":{},"x":"' . str_repeat('x', 256 * 1024) . '"}']);
        $as('import fetch', 'POST', '/settings/import', ['json' => '{"format":"apotheke-settings","settings":{"app_tagline":"two"}}'], ['headers' => $fetch]);
        $as('desk imports a sign-in setting', 'POST', '/settings/import', ['json' => '{"format":"apotheke-settings","settings":{"auth_guest_disabled":true,"app_tagline":"desk"}}'], ['who' => 'dee']);
        $as('data reset', 'POST', '/settings/data/reset');

        // System (read-only).
        $as('system save (nothing to save)', 'POST', '/settings/system', ['x' => '1']);
        $as('system reset', 'POST', '/settings/system/reset');

        // AI autofill: the section, the access token and the Test buttons against the stub.
        $url = $stub->base . '/n/m-small,m-large*/z/512,1024*/t/' . self::token();
        $af = ['autofill_enabled' => '1', 'autofill_auto' => '0', 'autofill_url' => $url, 'autofill_timeout' => '10', 'autofill_model' => '', 'autofill_image_size' => '0',
            'autofill_max_image_bytes' => 'auto'];
        $as('autofill save', 'POST', '/settings/autofill', $af);
        $as('autofill page without a token', 'GET', '/settings/autofill?done=saved');
        $as('autofill: bad address', 'POST', '/settings/autofill', ['autofill_url' => 'file:///etc/passwd', 'autofill_timeout' => '1'] + $af);
        $as('autofill: bad typed token', 'POST', '/settings/autofill', ['autofill_token' => 'two words'] + $af);
        $as('token with a space', 'POST', '/settings/autofill/token', ['token' => 'a b']);
        $as('token empty', 'POST', '/settings/autofill/token', ['token' => '   ']);
        $as('token too long', 'POST', '/settings/autofill/token', ['token' => str_repeat('k', 513)]);
        $as('token save', 'POST', '/settings/autofill/token', ['token' => ' ' . self::token() . ' ', 'action' => 'set']);
        $as('autofill page with a token', 'GET', '/settings/autofill?done=token-saved');
        $as('autofill save keeps the token', 'POST', '/settings/autofill', ['autofill_model' => 'm-small', 'autofill_image_size' => '512'] + $af);
        $as('autofill page after a choice', 'GET', '/settings/autofill?done=saved');
        $as('test fetch', 'POST', '/settings/autofill/test', $af, ['headers' => $fetch]);
        $as('test, plain form', 'POST', '/settings/autofill/test', ['autofill_enabled' => ['x']] + $af);
        $as('test, typed address without the token', 'POST', '/settings/autofill/test', ['autofill_url' => $stub->base . '/n/a'] + $af, ['headers' => $fetch]);
        $as('test, bad address fetch', 'POST', '/settings/autofill/test', ['autofill_url' => 'gopher://x'] + $af, ['headers' => $fetch]);
        $as('test, bad address plain', 'POST', '/settings/autofill/test', ['autofill_url' => 'gopher://x'] + $af);
        $as('test photo fetch', 'POST', '/settings/autofill/test-photo', $af, ['headers' => $fetch]);
        $as('test photo plain', 'POST', '/settings/autofill/test-photo', ['autofill_model' => 'm-large', 'autofill_image_size' => '1024'] + $af);
        $as('token fetch', 'POST', '/settings/autofill/token', ['token' => self::token()], ['headers' => $fetch]);
        $as('autofill moves, token cleared', 'POST', '/settings/autofill', ['autofill_url' => $stub->base . '/n/other'] + $af);
        $as('autofill moves with a new token', 'POST', '/settings/autofill', ['autofill_url' => $url, 'autofill_token' => self::token()] + $af);
        $as('token clear', 'POST', '/settings/autofill/token', ['action' => 'clear']);
        $as('token set for the reset', 'POST', '/settings/autofill/token', ['token' => self::token()]);
        $as('autofill reset', 'POST', '/settings/autofill/reset');
        $as('autofill after reset', 'GET', '/settings/autofill?done=reset-token-cleared');

        return $out;
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, '2026-01-01']);
    }

    /** A made-up autofill token (the stub service accepts it only under /t/), built at run time so no literal is in the tree. */
    private static function token(): string
    {
        return implode('-', ['tok', 'Placeholder', '7Q']);
    }
}
