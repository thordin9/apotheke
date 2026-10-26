<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Golden (PLAN step 13, second part): stocktakes on CI4 against legacy, on
 * the configured driver. Besides the answers (status, type, Location, JSON,
 * security headers and the pages), the items, activity, stocktakes and
 * stocktake_lines tables are compared after every step. A failing
 * statement (a trigger) is a 500 and changes no table on both.
 */
final class Ci4StocktakeParityTest extends TestCase
{
    public function testStocktakesMatchLegacy(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('stocktake-' . $app);
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
        foreach (['list', 'take', 'report open', 'report finished'] as $case) {
            $this->assertSame(['nosniff', 'text/html; charset=UTF-8', 'no-store'], [$l[$case]['nosniff'], $l[$case]['type'], $l[$case]['cache-control']], $case);
            $this->assertStringContainsString("default-src 'self'", (string)$l[$case]['csp'], $case);
        }
        $this->assertStringContainsString('No locations yet.', (string)$l['list without locations']['body']);
        $this->assertStringContainsString('<option value="3" selected>', (string)$l['list preselected']['body']);
        $this->assertSame(422, $l['start without a location']['status']);
        $this->assertSame(404, $l['start a missing location']['status']);
        foreach (['start without CSRF', 'start with no CSRF token'] as $case) {
            $this->assertSame(419, $l[$case]['status'], $case);
            $this->assertSame($l['start a missing location']['tables'], $l[$case]['tables'], $case . ': nothing started');
        }
        $this->assertSame([303, '/stocktake/1'], [$l['start']['status'], $l['start']['location']]);
        $this->assertSame('/stocktake/1', $l['start again']['location']);
        $this->assertSame(['ok' => true, 'redirect' => '/stocktake/2'], $l['start fetch']['json']);
        $this->assertStringContainsString('Garage &lt;i&gt; › Shelf &amp; Box', (string)$l['take']['body']);
        $this->assertStringNotContainsString('<i>', (string)$l['take']['body']);
        $this->assertSame(404, $l['take missing']['status']);
        $this->assertSame(404, $l['GET check']['status']);
        $this->assertSame(404, $l['POST take']['status']);
        $this->assertSame('found', $l['check fetch']['json']['state'] ?? null);
        $this->assertSame('misplaced', $l['check misplaced']['json']['state'] ?? null);
        $this->assertSame('none', $l['untick misplaced']['json']['state'] ?? null);
        $this->assertSame([409, 'This stocktake is finished.'], [$l['check finished']['status'], $l['check finished fetch']['json']['error'] ?? null]);
        $this->assertSame(409, $l['check two barcodes']['status']);
        $this->assertStringContainsString('No item has the barcode “&lt;b&gt;”.', (string)$l['check unknown barcode']['body']);
        $this->assertSame(403, $l['viewer check']['status']);
        $this->assertSame(403, $l['stocktaker finish with moves']['status']);
        $this->assertStringContainsString('id="stk-moves" checked', (string)$l['report open']['body']);
        $this->assertStringNotContainsString('id="stk-moves" checked', (string)$l['report open without moves']['body']);
        $this->assertSame('/stocktake/1/report?finished=2', $l['finish with moves']['location']);
        $this->assertStringContainsString('Stocktake finished · 1 item moved', (string)$l['report finished']['body']);
        $this->assertStringContainsString('<span>Saw</span>', (string)$l['report with a deleted item']['body']);
        $this->assertSame(409, $l['finish a deleted location with moves']['status']);
        $this->assertSame('/stocktake?done=cancelled', $l['cancel']['location']);
        $this->assertStringContainsString('Stocktake cancelled', (string)$l['list after cancel']['body']);
        foreach (['fail start', 'fail misplaced', 'fail finish'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        // A stocktaker may check locations but not move items.
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('stocktaker', 'Stocktaker', '[\"view\",\"stocktake\"]', 0, '2026-01-01 00:00:00')");
        foreach ([['ada', 'admin'], ['vera', 'viewer'], ['stan', 'stocktaker']] as [$name, $role]) {
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
        $tables = static function () use ($pdo): string {
            $o = [];
            foreach (['SELECT * FROM items ORDER BY id', 'SELECT item_id, item_name, action, details, actor, created_at FROM activity ORDER BY id', 'SELECT * FROM stocktakes ORDER BY id', 'SELECT * FROM stocktake_lines ORDER BY id'] as $sql) {
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
        foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens'] as $k => $v) {
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


        $as('list without locations', 'GET', '/stocktake');
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage <i>', 'garage <i>', 'garage <i>', '2026-01-01 00:00:00'), ('Attic', 'attic', 'attic', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, parent_id, created_at) VALUES ('Shelf & Box', 'shelf & box', 'shelf & box', 1, '2026-01-01 00:00:00'), ('Box', 'box', 'box', NULL, '2026-01-01 00:00:00')");
        $item = $pdo->prepare('INSERT INTO items (name, location_id, quantity, notes, barcode, image, search, sort_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Lamp <b>"&\'', 1, 2, '', null, 'abcdef0123456789abcdef0123456789.jpg'],
            ['Drill', 3, 1, '', '4006381333931', null],
            ['Saw', 3, 1, '', 'a/b', null],
            ['Tea', null, 1, '', 'x+y', null],
            ['Élan', 2, 1, '', '123', null],
            ['Coffee', 2, 1, '', '123', null],
            ['eraser', 1, 1, '', null, '../x.jpg'],
        ] as $r) {
            $item->execute([...$r, mb_strtolower($r[0]), mb_strtolower($r[0]), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);
        }
        $fetch = ['X-Requested-With: fetch'];

        // Start and the history.
        $as('list', 'GET', '/stocktake');
        $as('list HEAD', 'HEAD', '/stocktake');
        $as('list preselected', 'GET', '/stocktake?loc=3');
        $as('list preselected array', 'GET', '/stocktake?loc[]=3');
        $as('viewer list', 'GET', '/stocktake', [], [], 'vera');
        $as('signed out list', 'GET', '/stocktake', [], [], null);
        $as('list trailing slash', 'GET', '/stocktake/');
        $as('start without a location', 'POST', '/stocktake');
        $as('start with a word', 'POST', '/stocktake', ['location_id' => 'x']);
        $as('start with an array', 'POST', '/stocktake', ['location_id' => ['1']]);
        $as('start a missing location', 'POST', '/stocktake', ['location_id' => '99']);
        $as('start without CSRF', 'POST', '/stocktake', ['csrf' => 'nope', 'location_id' => '1']);
        $as('start with no CSRF token', 'POST', '/stocktake', ['csrf' => null, 'location_id' => '1']);
        $as('viewer start', 'POST', '/stocktake', ['location_id' => '1'], [], 'vera');
        $as('start', 'POST', '/stocktake', ['location_id' => '1']);
        $as('start again', 'POST', '/stocktake', ['location_id' => '1']);
        $as('start fetch', 'POST', '/stocktake', ['location_id' => '2'], $fetch, 'stan');
        $as('start fetch error', 'POST', '/stocktake', ['location_id' => '99'], $fetch);

        // The checking screen.
        $as('take', 'GET', '/stocktake/1');
        $as('take HEAD', 'HEAD', '/stocktake/1');
        $as('viewer take', 'GET', '/stocktake/1', [], [], 'vera');
        $as('take missing', 'GET', '/stocktake/99');
        $as('take missing report', 'POST', '/stocktake/99/report');
        $as('GET check', 'GET', '/stocktake/1/check');
        $as('GET finish', 'GET', '/stocktake/1/finish');
        $as('POST take', 'POST', '/stocktake/1');
        $as('POST report', 'POST', '/stocktake/1/report');
        $as('take trailing slash', 'GET', '/stocktake/1/');
        $as('take unknown act', 'GET', '/stocktake/1/other');
        $as('check', 'POST', '/stocktake/1/check', ['item_id' => '1']);
        $as('check fetch', 'POST', '/stocktake/1/check', ['item_id' => '2'], $fetch);
        $as('untick', 'POST', '/stocktake/1/check', ['item_id' => '2', 'found' => '0'], $fetch);
        $as('check found array', 'POST', '/stocktake/1/check', ['item_id' => '2', 'found' => ['0']], $fetch);
        $as('check by #id', 'POST', '/stocktake/1/check', ['code' => ' #3 '], $fetch);
        $as('check by QR link', 'POST', '/stocktake/1/check', ['code' => 'http://elsewhere.example/item/7?x=1'], $fetch);
        $as('check by barcode', 'POST', '/stocktake/1/check', ['code' => '4006 381333931']);
        $as('check misplaced', 'POST', '/stocktake/1/check', ['code' => 'x+y'], $fetch);
        $as('check misplaced again', 'POST', '/stocktake/1/check', ['item_id' => '4'], $fetch);
        $as('check another misplaced', 'POST', '/stocktake/1/check', ['item_id' => '5'], $fetch);
        $as('untick misplaced', 'POST', '/stocktake/1/check', ['item_id' => '4', 'found' => '0'], $fetch);
        $as('untick not listed', 'POST', '/stocktake/1/check', ['item_id' => '6', 'found' => '0'], $fetch);
        $as('check misplaced item again', 'POST', '/stocktake/1/check', ['item_id' => '4']);
        $as('check missing QR item', 'POST', '/stocktake/1/check', ['code' => '/item/99']);
        $as('check missing item', 'POST', '/stocktake/1/check', ['item_id' => '99'], $fetch);
        $as('check two barcodes', 'POST', '/stocktake/1/check', ['code' => '123']);
        $as('check unknown barcode', 'POST', '/stocktake/1/check', ['code' => '<b>']);
        $as('check empty code', 'POST', '/stocktake/1/check', ['code' => '  ']);
        $as('check empty code fetch', 'POST', '/stocktake/1/check', [], $fetch);
        $as('check code array', 'POST', '/stocktake/1/check', ['code' => ['1']]);
        $as('viewer check', 'POST', '/stocktake/1/check', ['item_id' => '1'], [], 'vera');
        $as('stocktaker check', 'POST', '/stocktake/1/check', ['item_id' => '7'], [], 'stan');
        $as('take with progress', 'GET', '/stocktake/1');
        $as('list with an open stocktake', 'GET', '/stocktake');

        // Finish.
        $as('report open', 'GET', '/stocktake/1/report');
        $this->setting($pdo, 'stocktake_apply_moves', false);
        $as('report open without moves', 'GET', '/stocktake/1/report');
        $as('stocktaker finish with moves', 'POST', '/stocktake/1/finish', ['moves' => '1'], [], 'stan');
        $as('viewer finish', 'POST', '/stocktake/1/finish', [], [], 'vera');
        $as('finish with moves', 'POST', '/stocktake/1/finish', ['moves' => '1']);
        $as('report finished', 'GET', '/stocktake/1/report?finished=1');
        $as('report finished nothing moved', 'GET', '/stocktake/1/report?finished=0');
        $as('report finished array', 'GET', '/stocktake/1/report?finished[]=2');
        $as('take finished', 'GET', '/stocktake/1');
        $as('check finished', 'POST', '/stocktake/1/check', ['item_id' => '1']);
        $as('check finished fetch', 'POST', '/stocktake/1/check', ['item_id' => '1'], $fetch);
        $as('finish again', 'POST', '/stocktake/1/finish');
        $as('cancel finished', 'POST', '/stocktake/1/cancel');
        $as('finish fetch', 'POST', '/stocktake/2/finish', ['moves' => '0'], $fetch, 'stan');

        // A deleted item and a deleted location.
        $as('start box', 'POST', '/stocktake', ['location_id' => '4']);
        $as('take empty', 'GET', '/stocktake/3');
        $as('check into box', 'POST', '/stocktake/3/check', ['item_id' => '3']);
        $as('check another into box', 'POST', '/stocktake/3/check', ['item_id' => '6']);
        $pdo->exec('DELETE FROM items WHERE id = 3');
        $as('report with a deleted item', 'GET', '/stocktake/3/report');
        $pdo->exec('DELETE FROM locations WHERE id = 4');
        $as('take a deleted location', 'GET', '/stocktake/3');
        $as('finish a deleted location with moves', 'POST', '/stocktake/3/finish', ['moves' => '1']);
        $as('finish a deleted location', 'POST', '/stocktake/3/finish');
        $as('start garage again', 'POST', '/stocktake', ['location_id' => '1']);
        $as('cancel', 'POST', '/stocktake/4/cancel');
        $as('list after cancel', 'GET', '/stocktake?done=cancelled');
        $as('report cancelled', 'GET', '/stocktake/4');
        $as('start attic', 'POST', '/stocktake', ['location_id' => '2']);
        $as('check into attic', 'POST', '/stocktake/5/check', ['item_id' => '1']);

        // A failing statement (a trigger) is a 500 and changes no table.
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        foreach ([['fail start', 'stocktake_lines', '/stocktake', ['location_id' => '1']], ['fail misplaced', 'stocktake_lines', '/stocktake/5/check', ['item_id' => '2']],
            ['fail finish', 'activity', '/stocktake/5/finish', ['moves' => '1']]] as [$case, $table, $path, $fields]) {
            $pdo->exec($pg ? "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()"
                : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = $tables();
            $as($case, 'POST', $path, $fields);
            $pdo->exec($pg ? "DROP TRIGGER apotheke_test_fail ON $table" : 'DROP TRIGGER apotheke_test_fail');
            $out[$case]['unchanged'] = $before === $out[$case]['tables'];
            unset($out[$case]['body']);
        }
        $as('finish after the failure', 'POST', '/stocktake/5/finish', ['moves' => '1']);

        return $out;
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
