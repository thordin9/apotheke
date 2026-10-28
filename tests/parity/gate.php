<?php
declare(strict_types=1);

/**
 * PLAN step 16, part 1: the parity gate. One scenario walks every ported area (all but backup and restore) on a
 * legacy and a CI4 server, both on this PHP (8.4 in CI), with the same seeded data and a frozen clock. After each
 * step it keeps the answer (status, type, Location, Cache-Control, Pragma, Expires, every Set-Cookie in full, the
 * security headers, the body or JSON; parity_gate_headers()), the stub's last autofill upload, every table and the data dir (path, mode, sha256). parity_gate() returns the differences per
 * area; there must be none. The allowlist (four quirks) never hides one.
 *
 * Normalised on both sides alike: the per-session CSRF token, the asset cache busters, random 32- and 64-hex names
 * (photos, originals, undo tokens, attachments) numbered in order of appearance, the salt and hash of a password
 * hash (algorithm and cost stay), session epochs, the session ID by shape, cookie expiry relative to the answer's
 * Date, the wall-clock `elapsed_ms` of a stub call and the `at` time in the autofill cache, and the one documented
 * Cache-Control pair (PARITY_GATE_CACHE_PAIR). The users the app created sign in with the passwords it stored.
 * The autofill cache is compared under
 * legacy's file names (PARITY_GATE_LAYOUT). A step marked `pending` names the parts of the answer that belong to a
 * page not ported yet; those are reported apart and the rest of the step must still match.
 *
 * Performance: unless $rounds is 0, parity_gate() also times each area's read requests, alternating between the
 * two servers, and returns the samples (see parity_p95()). PARITY_DEBUG="area: case" prints that step's raw headers.
 */

require_once __DIR__ . '/recorder.php';
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/src/items.php';

const PARITY_GATE_PW = 'long-enough';

/**
 * @return array{steps:int, diffs:array<string,array<string,mixed>>, pending:array<string,string>, legacy:array<string,array<string,mixed>>, samples:array<string,array{legacy:list<float>,ci4:list<float>}>, cases:array<string,list<string>>}
 */
function parity_gate(int $rounds = 15): array
{
    if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
        throw new RuntimeException('skip');
    }
    $stub = new StubServer();
    // APOTHEKE_TEST_PGSQL_PROBE=0: the harness's pgsql probe (a CREATE and an INSERT on every CI4 request) is not timed.
    $env = ['APOTHEKE_CLOCK' => '2026-06-15 09:00:00', 'PUBLIC_URL' => 'http://catalog.example/', 'APOTHEKE_TEST_PGSQL_PROBE' => '0'];
    $runs = [];
    try {
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('parity-gate-' . $app);
            $runs[$app] = ['dir' => $dir, 'server' => new Server($dir, $env, $app, null, ['opcache.enable_cli' => '1', 'pcov.enabled' => '0'])];
            if ($runs[$app]['server']->client()->get('/healthz')['status'] !== 200) {
                throw new RuntimeException($app . ' did not start: ' . (string)@file_get_contents($runs[$app]['server']->log));
            }
            parity_gate_seed(testdb($dir), $dir, $stub->base);
        }
        $records = [];
        $reads = [];
        foreach ($runs as $app => $run) {
            $stub->clear();
            [$records[$app], $reads[$app]] = parity_gate_run($run['server'], $run['dir'], $stub);
        }
        $diffs = [];
        $cases = [];
        $pending = [];
        foreach ($records['legacy'] as $key => $legacy) {
            [$area] = explode(': ', $key, 2);
            $cases[$area][] = $key;
            $diff = parity_diff($legacy, $records['ci4'][$key] ?? null);
            // A step marked pending names the parts of the answer that belong to a page not ported yet: those
            // differences are listed apart, with the reason, and the rest of the step must still match.
            foreach ($legacy['pending']['paths'] ?? [] as $want) {
                foreach (array_keys($diff) as $at) {
                    if ($at === $want || str_starts_with($at, $want . '.')) {
                        $pending[$key] = $legacy['pending']['why'];
                        unset($diff[$at]);
                    }
                }
            }
            if ($diff !== []) $diffs[$area][$key] = $diff;
        }
        foreach (array_diff_key($records['ci4'], $records['legacy']) as $key => $_) {
            $diffs['scenario'][$key] = 'only on ci4';
        }
        return ['steps' => count($records['legacy']), 'diffs' => $diffs, 'cases' => $cases, 'pending' => $pending, 'legacy' => $records['legacy'],
            'samples' => $rounds > 0 ? parity_gate_time($runs, $reads['legacy'], $rounds) : []];
    } finally {
        $stub->stop();
        foreach ($runs as $run) {
            $run['server']->stop();
            testdb_drop($run['dir']);
            rrmdir($run['dir']);
            @unlink($run['dir'] . '.server.log');
        }
    }
}

/** The $q quantile (nearest rank) of samples in ms. @param list<float> $ms */
function parity_quantile(array $ms, float $q): float
{
    if ($ms === []) return 0.0;
    sort($ms);
    return $ms[max(0, (int)ceil($q * count($ms)) - 1)];
}

/** p95 (nearest rank) of samples in ms. @param list<float> $ms */
function parity_p95(array $ms): float
{
    return parity_quantile($ms, 0.95);
}

/** Median (nearest rank, the lower middle) of samples in ms. @param list<float> $ms */
function parity_median(array $ms): float
{
    return parity_quantile($ms, 0.5);
}

/**
 * Which statistic the absolute budget (PARITY_P95_MAX_ABS_MS) compares: `p95` (unset or empty) or `median`, the
 * PM's fallback when runner noise fails the p95 gap. The +N % bound always compares p95.
 */
function parity_budget_stat(string|false $raw): string
{
    $v = $raw === false ? '' : strtolower(trim($raw));
    if ($v === '') return 'p95';
    if ($v !== 'p95' && $v !== 'median') throw new InvalidArgumentException("PARITY_BUDGET_STAT must be p95 or median, got '$raw'");
    return $v;
}

/**
 * A p95 limit from the environment (PARITY_P95_MAX_PCT, PARITY_P95_MAX_ABS_MS): unset or empty is no limit;
 * anything but a number >= 0 is an error, so a typo cannot switch the guard off.
 */
function parity_p95_limit(string|false $raw, string $name): ?float
{
    if ($raw === false || trim($raw) === '') return null;
    if (!is_numeric(trim($raw)) || (float)$raw < 0) throw new InvalidArgumentException("$name must be a number >= 0, got '$raw'");
    return (float)$raw;
}

/**
 * The budget for one area (p95, or the median for the absolute bound with PARITY_BUDGET_STAT=median): CI4 may be at most $maxPct % above legacy and at most $maxAbsMs ms above it (each
 * null = not checked). Returns what was exceeded, empty when within budget.
 * @return list<string>
 */
function parity_p95_over(float $legacy, float $ci4, ?float $maxPct, ?float $maxAbsMs): array
{
    $over = [];
    $pct = $legacy > 0 ? ($ci4 / $legacy - 1) * 100 : 0.0;
    if ($maxPct !== null && $pct > $maxPct) $over[] = sprintf('%+.0f%% > +%s%%', $pct, $maxPct);
    if ($maxAbsMs !== null && $ci4 - $legacy > $maxAbsMs) $over[] = sprintf('%+.2f ms > +%s ms', $ci4 - $legacy, $maxAbsMs);
    return $over;
}

function parity_gate_setting(PDO $pdo, string $name, mixed $value): void
{
    $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
    $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')
        ->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01 00:00:00']);
}

/** The same rows in both databases (ids included). */
function parity_gate_seed(PDO $pdo, string $dir, string $stub): void
{
    foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens', 'currency' => '€', 'lend_due_days' => 14,
        'autofill_enabled' => true, 'autofill_url' => $stub, 'undo_seconds' => 30] as $k => $v) {
        parity_gate_setting($pdo, $k, $v);
    }
    $pdo->prepare('INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES (?, ?, ?, 0, ?)')
        ->execute(['tidier', 'Tidier', json_encode(['view', 'create', 'edit']), '2026-01-01 00:00:00']);
    $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([['ada', 'admin'], ['vera', 'viewer'], ['tim', 'tidier'], ['ed', 'editor']] as [$name, $role]) {
        $user->execute([$name, $name, password_hash(PARITY_GATE_PW, PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
    }
    $loc = $pdo->prepare('INSERT INTO locations (name, name_key, sort_key, parent_id, is_default, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([['Garage', null, 0], ['Shelf 2', 1, 1], ['Box 10', 2, 0], ['Attic', null, 0]] as [$name, $parent, $default]) {
        $loc->execute([$name, mb_strtolower($name), natkey($name), $parent, $default, '2026-01-01 00:00:00']);
    }
    $cat = $pdo->prepare('INSERT INTO categories (name, name_key, sort_key, is_default, created_at) VALUES (?, ?, ?, 0, ?)');
    foreach (['Tools', 'Food', 'Paper'] as $name) {
        $cat->execute([$name, mb_strtolower($name), natkey($name), '2026-01-01 00:00:00']);
    }
    $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, quantity, min_qty, expires_on, value_cents, lent_to, lent_at, lent_due, favourite, notes, serial, barcode, search, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $items = [
        ['Hammer & <b>', 3, 1, 1, null, null, 1999, null, null, null, 1, "**claw**\n\n- one\n- two", '', null, '2026-01-02 08:00:00', '2026-01-03 09:00:00'],
        ['Apple', null, 2, 3, 5, '2026-06-10', null, null, null, null, 0, '', '', null, '2026-02-01 23:30:00', '2026-02-01 23:30:00'],
        ['Saw', 1, 1, 2, null, '2026-06-20', 4550, 'Bob', '2026-05-01', '2026-06-01', 0, 'sharp', 'SN-1', '4006381333931', '2026-03-01 12:00:00', '2026-03-01 12:00:00'],
        ['Glue', 4, 3, 0, 1, null, null, null, null, null, 0, '', '', '123', '2026-04-15 00:30:00', '2026-04-15 00:30:00'],
        ['Tape', 1, 3, 4, null, null, 250, null, null, null, 0, 'grey', '', '123', '2026-04-16 00:30:00', '2026-04-16 00:30:00'],
    ];
    foreach ($items as $r) {
        [$created, $updated] = array_splice($r, -2);
        $item->execute([...$r, mb_strtolower($r[0] . "\n" . $r[11]), $created, $updated]);
    }
    $tag = $pdo->prepare('INSERT INTO tags (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?)');
    foreach (['winter', 'red'] as $name) $tag->execute([$name, $name, natkey($name), '2026-01-01 00:00:00']);
    foreach ([[1, 2], [1, 1], [3, 1]] as [$i, $t]) $pdo->exec("INSERT INTO item_tags (item_id, tag_id) VALUES ($i, $t)");
    @mkdir($dir . '/sessions', 0770, true);
}

/**
 * The scenario. Returns the records (area: case => snapshot) and the read requests worth timing (area => list of
 * [path, who, headers]).
 * @return array{0:array<string,array<string,mixed>>,1:array<string,list<array{0:string,1:?string,2:list<string>}>>}
 */
function parity_gate_run(Server $server, string $dir, StubServer $stub): array
{
    $pdo = testdb($dir);
    $ids = [];
    foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) $ids[$u['username']] = (int)$u['id'];
    $sessions = [];
    $signIn = static function (?string $who, bool $fresh = false) use ($server, $pdo, &$ids, $dir, &$sessions): array {
        $key = $who ?? '-';
        if (!$fresh && $who !== null && isset($sessions[$key])) return $sessions[$key];
        $client = $server->client();
        $token = bin2hex(random_bytes(32));
        $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';';
        if ($who !== null) {
            $row = $pdo->query('SELECT id, password_hash FROM users WHERE id = ' . (int)$ids[$who])->fetch();
            $epoch = substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
            $session = 'uid|i:' . $ids[$who] . ';auth_epoch|s:16:"' . $epoch . '";' . $session;
        }
        $sid = bin2hex(random_bytes(13));
        file_put_contents($dir . '/sessions/sess_' . $sid, $session);
        $client->cookies['apothekesid'] = $sid;
        $client->cookies['apo_dev'] = str_repeat('ab', 16);
        return $fresh || $who === null ? [$client, $token] : $sessions[$key] = [$client, $token];
    };
    $names = [];
    $hide = static function (string $s) use (&$names): string {
        return (string)preg_replace_callback('/(?<![0-9a-f])(?:[0-9a-f]{64}|[0-9a-f]{32})(?![0-9a-f])/', static function (array $m) use (&$names): string {
            if (preg_match('/^(.)\1+$/', $m[0]) === 1 || preg_match('/^(ab)+$/', $m[0]) === 1) return $m[0];
            return (strlen($m[0]) === 64 ? 'O' : 'H') . ($names[$m[0]] ??= count($names) + 1);
        }, $s);
    };
    $out = [];
    $reads = [];
    $last = [];
    $fetch = ['X-Requested-With: fetch'];
    $as = static function (string $area, string $case, string $method, string $path, array $fields = [], array $o = [])
        use (&$out, &$reads, &$last, $signIn, $hide, $pdo, $dir, $stub): array {
        $who = array_key_exists('who', $o) ? $o['who'] : 'ada';
        [$client, $token] = $signIn($who, !empty($o['fresh']));
        if ($method === 'POST' && ($o['csrf'] ?? true)) $fields = ['csrf' => $token] + $fields;
        $r = $client->req($method, $path, $fields, $o['files'] ?? [], $o['headers'] ?? []);
        $last = $r;
        if (getenv('PARITY_DEBUG') === $area . ': ' . $case) fwrite(STDERR, implode("\n", $r['raw']) . "\n\n");
        $body = parity_gate_cache_bust($r['body']);
        $json = $r['json'];
        if (is_array($json)) {
            array_walk_recursive($json, static function (&$v, $k) use ($token, $hide): void {
                // Wall-clock time of the call to the stub: only its presence and type can match.
                if ($k === 'elapsed_ms') $v = is_int($v) ? 'MS' : $v;
                $v = is_string($v) ? $hide(str_replace($token, 'SESSION-TOKEN', parity_gate_cache_bust($v))) : $v;
            });
        }
        $type = $r['headers']['content-type'] ?? null;
        $binary = $type !== null && !preg_match('#^(text/|application/(json|xml|javascript|manifest))|svg#', $type);
        $record = [
            'status' => $r['status'], 'type' => $type,
            'location' => isset($r['headers']['location']) ? $hide($r['headers']['location']) : null,
            ...parity_gate_headers($r['status'], $json !== null, $r['raw'], $hide),
            'disposition' => isset($r['headers']['content-disposition']) ? $hide($r['headers']['content-disposition']) : null,
            'json' => $json,
            'body' => $json !== null ? null : ($binary ? 'sha256:' . hash('sha256', $r['body']) : $hide(str_replace($token, 'SESSION-TOKEN', $body))),
        ];
        if (isset($o['pending'])) $record['pending'] = $o['pending'];
        if (!empty($o['stub'])) $record['stub'] = parity_gate_stub($stub->last());
        if ($method !== 'GET' && $method !== 'HEAD' || !empty($o['state'])) {
            $record['db'] = parity_gate_db($pdo, $hide);
            $record['files'] = parity_gate_files($dir, $hide);
        }
        $out[$area . ': ' . $case] = $record;
        if ($method === 'GET' && !empty($o['time'])) $reads[$area][] = [$path, $who, $o['headers'] ?? []];
        return $r;
    };
    $token = static function () use (&$last): string {
        $where = (string)($last['headers']['location'] ?? ($last['json']['redirect'] ?? ($last['json']['undo'] ?? '')));
        return preg_match('/undo=([0-9a-f]{32})/', $where, $m) === 1 ? $m[1] : (preg_match('/^[0-9a-f]{32}$/', $where) === 1 ? $where : '');
    };
    $jpg = jpg_file(img_jpeg(64, 48), 'photo.jpg');
    $png = ['name' => 'p.png', 'type' => 'image/png', 'data' => img_png(40, 30)];
    $heic = ['name' => 'tiny.heic', 'type' => 'image/heic', 'data' => (string)file_get_contents(dirname(__DIR__) . '/fixtures/tiny-gps.heic')];
    $brokenJpg = ['name' => 'broken.jpg', 'type' => 'image/jpeg', 'data' => substr(img_jpeg(64, 48), 0, 200)];
    $brokenHeic = ['name' => 'broken.heic', 'type' => 'image/heic', 'data' => "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1broken"];
    $pdf = ['name' => 'Manual <1>.pdf', 'type' => 'application/pdf', 'data' => "%PDF-1.4\n%parity\n"];

    // Core: health, the originals guard, static files.
    foreach (['/healthz', '/health', '/originals/guess.jpg', '/Originals/guess.jpg', '/ORIGINALS/guess.jpg', '/favicon.ico',
        '/manifest.webmanifest', '/theme.css', '/labels.css', '/no/such/page', '/stats/', '/./stats'] as $p) {
        $as('core', 'GET ' . $p, 'GET', $p, [], ['time' => in_array($p, ['/healthz', '/health', '/theme.css'], true), 'who' => null]);
    }
    // Layout: theme, error pages, JSON errors.
    $as('layout', 'theme dark', 'POST', '/theme', ['theme' => 'dark']);
    $as('layout', 'theme fetch', 'POST', '/theme', ['theme' => 'light'], ['headers' => $fetch]);
    $as('layout', 'theme bad value', 'POST', '/theme', ['theme' => '<x>']);
    $as('layout', 'POST without csrf', 'POST', '/theme', ['theme' => 'dark'], ['csrf' => false]);
    $as('layout', '404 fetch', 'GET', '/item/99', [], ['headers' => $fetch]);
    $as('layout', '404 page', 'GET', '/item/99', [], ['time' => true]);
    // Auth: sign-in pages and the real form.
    $as('auth', 'signed out home', 'GET', '/', [], ['who' => null]);
    $as('auth', 'login page', 'GET', '/login', [], ['who' => null, 'time' => true]);
    $as('auth', 'register page', 'GET', '/register', [], ['who' => null]);
    $as('auth', 'login wrong password', 'POST', '/login', ['username' => 'vera', 'password' => 'nope-nope'], ['who' => null]);
    $as('auth', 'login unknown user', 'POST', '/login', ['username' => 'nobody', 'password' => 'nope-nope'], ['who' => null]);
    $as('auth', 'login', 'POST', '/login', ['username' => ' Vera ', 'password' => PARITY_GATE_PW], ['who' => null]);
    $as('auth', 'account page', 'GET', '/account', [], ['time' => true]);
    $as('auth', 'account wrong current password', 'POST', '/account', ['current' => 'nope-nope', 'new' => 'another-one', 'confirm' => 'another-one'], ['who' => 'tim', 'fresh' => true]);
    $as('auth', 'logout', 'POST', '/logout', [], ['who' => 'ed', 'fresh' => true]);
    $as('auth', 'sign out everywhere', 'POST', '/account/signout', [], ['who' => 'ed', 'fresh' => true]);
    // Users and roles.
    $add = static fn (string $name, string $role): array => ['username' => $name, 'new' => PARITY_GATE_PW, 'confirm' => PARITY_GATE_PW, 'role' => $role];
    $as('users', 'security page', 'GET', '/settings/security', [], ['time' => true]);
    $as('users', 'viewer security page', 'GET', '/settings/security', [], ['who' => 'vera']);
    $as('users', 'add neo', 'POST', '/settings/users', $add(' neo ', 'editor'));
    $as('users', 'add taken name', 'POST', '/settings/users', $add('ADA', 'editor'));
    $as('users', 'add fetch', 'POST', '/settings/users/0', $add('nia', 'viewer'), ['headers' => $fetch]);
    // Each password the app stored must sign in (a wrong hash or the wrong password fails here, L-471).
    $as('users', 'nia signs in', 'POST', '/login', ['username' => 'nia', 'password' => PARITY_GATE_PW], ['who' => null]);
    $neo = (int)$pdo->query("SELECT id FROM users WHERE username_key = 'neo'")->fetchColumn();
    $as('users', 'neo becomes viewer', 'POST', "/settings/users/$neo/role", ['role' => 'viewer']);
    $as('users', 'rename neo', 'POST', "/settings/users/$neo/rename", ['username' => 'Neon']);
    $as('users', 'neo password', 'POST', "/settings/users/$neo/password", ['new' => 'neon-password', 'confirm' => 'neon-password']);
    $as('users', 'Neon signs in with the new password', 'POST', '/login', ['username' => 'Neon', 'password' => 'neon-password'], ['who' => null]);
    $as('users', 'add role', 'POST', '/settings/roles', ['name' => 'Counter <x>', 'perms' => ['view', 'stocktake']]);
    $as('users', 'save role', 'POST', '/settings/roles/counter-x', ['name' => 'Counter', 'perms' => ['view', 'stocktake', 'lend']]);
    $as('users', 'delete neo', 'POST', "/settings/users/$neo/delete");
    $as('users', 'delete role', 'POST', '/settings/roles/counter-x/delete');
    $as('users', 'save session settings', 'POST', '/settings/security', ['auth_guest_disabled' => '1', 'auth_register_disabled' => '1', 'auth_register_role' => 'viewer', 'session_days' => '20', 'session_idle_hours' => '12']);
    $as('users', 'security page after', 'GET', '/settings/security?done=saved');
    // Taxonomy.
    foreach (['/locations', '/categories', '/locations/2', '/locations/2/delete', '/categories/1/delete'] as $p) {
        $as('taxonomy', 'GET ' . $p, 'GET', $p, [], ['time' => in_array($p, ['/locations', '/categories'], true)]);
    }
    $as('taxonomy', 'add Box 2 in Garage', 'POST', '/locations', ['name' => '  Box   2 ', 'parent' => '1']);
    $as('taxonomy', 'add empty fetch', 'POST', '/categories', ['name' => ''], ['headers' => $fetch]);
    $as('taxonomy', 'add category', 'POST', '/categories', ['name' => 'Toys']);
    $as('taxonomy', 'rename Attic', 'POST', '/locations/4/rename', ['name' => 'Loft']);
    $as('taxonomy', 'move Shelf 2 into Loft', 'POST', '/locations/2/parent', ['parent' => '4']);
    $as('taxonomy', 'default Toys', 'POST', '/categories/4/default');
    $as('taxonomy', 'delete Toys', 'POST', '/categories/4/delete');
    $as('taxonomy', 'viewer adds', 'POST', '/locations', ['name' => 'X'], ['who' => 'vera']);
    // Catalog, search, stats, saved filters, favourites.
    foreach (['/', '/?q=saw', '/?q=hamer', '/?loc=1', '/?cat=1&sort=name', '/?stock=low', '/?exp=soon', '/?lend=overdue', '/?tag=1', '/?fav=1',
        '/?page=2', '/stats', '/filters', '/?select=1'] as $p) {
        $as('catalog', 'GET ' . $p, 'GET', $p, [], ['time' => true]);
    }
    $as('catalog', 'viewer list', 'GET', '/', [], ['who' => 'vera']);
    $as('catalog', 'save filter', 'POST', '/filters', ['name' => '  Tools   low ', 'cat' => '1', 'stock' => 'low', 'sort' => 'recent']);
    $as('catalog', 'save filter fetch', 'POST', '/filters', ['name' => 'Search', 'q' => 'saw'], ['headers' => $fetch]);
    $as('catalog', 'rename filter', 'POST', '/filters/1/rename', ['name' => 'Low tools']);
    $as('catalog', 'delete filter', 'POST', '/filters/2/delete');
    $as('catalog', 'star Apple', 'POST', '/item/2/fav', ['on' => '1']);
    $as('catalog', 'unstar fetch', 'POST', '/item/1/fav', ['on' => '0'], ['headers' => $fetch]);
    $as('catalog', 'clear recent', 'POST', '/recent/clear');
    // Items: pages, save, delete, undo, activity.
    foreach (['/item/1', '/item/3', '/item/1/edit', '/new', '/new?loc=3&cat=2', '/activity', '/activity?item=3'] as $p) {
        $as('items', 'GET ' . $p, 'GET', $p, [], ['time' => true]);
    }
    $form = ['name' => 'Drill', 'location_id' => '2', 'category_id' => '1', 'quantity' => '2', 'notes' => "Bosch\r\n*fast*", 'tags' => 'red, Winter, new one',
        'value' => '€ 1,234.50', 'purchase_date' => '2026-06-01', 'expires_on' => '2028-01-01', 'serial' => 'X1', 'barcode' => ' 123 456 '];
    $as('items', 'save new with a JPEG', 'POST', '/save', $form, ['files' => ['photo' => $jpg]]);
    $as('items', 'save new, another', 'POST', '/save', ['name' => 'Pliers', 'location_id' => '3', 'after' => 'another']);
    $as('items', 'save no name', 'POST', '/save', ['name' => '  ']);
    $as('items', 'save ambiguous price fetch', 'POST', '/save', ['name' => 'X', 'value' => '1,234'], ['headers' => $fetch]);
    $as('items', 'edit Hammer', 'POST', '/save', ['id' => '1', 'name' => 'Hammer', 'location_id' => '3', 'category_id' => '1', 'quantity' => '2', 'notes' => '**claw**', 'tags' => 'red']);
    $undo = $token();
    $as('items', 'item with the undo bar', 'GET', '/item/1?undo=' . $undo);
    $as('items', 'undo the edit', 'POST', '/undo', ['token' => $undo]);
    $as('items', 'undo again', 'POST', '/undo', ['token' => $undo]);
    $as('items', 'viewer saves', 'POST', '/save', ['name' => 'X'], ['who' => 'vera']);
    $as('items', 'tidier moves', 'POST', '/save', ['id' => '2', 'name' => 'Apple', 'location_id' => '1'], ['who' => 'tim']);
    $as('items', 'save without csrf', 'POST', '/save', ['name' => 'X'], ['csrf' => false]);
    $as('items', 'delete Glue', 'POST', '/item/4/delete');
    $undoGlue = $token();
    $as('items', 'list with the undo bar', 'GET', '/?deleted=1&undo=' . $undoGlue);
    $as('items', 'undo the delete fetch', 'POST', '/undo', ['token' => $undoGlue], ['headers' => $fetch]);
    $as('items', 'activity after', 'GET', '/activity', [], ['state' => true]);
    // Media and photos (QA L-2: broken JPEG refused, broken HEIC kept with a placeholder; N-4 and I-3 below).
    $drill = (int)$pdo->query("SELECT id FROM items WHERE name = 'Drill'")->fetchColumn();
    $as('photos', 'add a PNG', 'POST', "/item/$drill/photos", [], ['files' => ['photo' => $png]]);
    $as('photos', 'add a HEIC fetch', 'POST', "/item/$drill/photos", [], ['files' => ['photos[0]' => $heic], 'headers' => $fetch]);
    $as('photos', 'add a broken JPEG', 'POST', "/item/$drill/photos", [], ['files' => ['photo' => $brokenJpg]]);
    $as('photos', 'save new with a broken JPEG', 'POST', '/save', ['name' => 'Broken'], ['files' => ['photo' => $brokenJpg]]);
    $as('photos', 'save new with a broken HEIC', 'POST', '/save', ['name' => 'Placeholder'], ['files' => ['photo' => $brokenHeic]]);
    $as('photos', 'add nothing', 'POST', "/item/$drill/photos", ['x' => '1']);
    $photos = array_map('intval', $pdo->query("SELECT id FROM item_photos WHERE item_id = $drill ORDER BY position")->fetchAll(PDO::FETCH_COLUMN));
    $as('photos', 'item with photos', 'GET', "/item/$drill", [], ['time' => true]);
    $served = (string)$pdo->query("SELECT file FROM item_photos WHERE item_id = $drill ORDER BY position LIMIT 1")->fetchColumn();
    $as('photos', 'served copy', 'GET', '/media/full/' . $served, [], ['time' => true]);
    $as('photos', 'thumbnail', 'GET', '/media/thumb/' . $served, [], ['time' => true]);
    $as('photos', 'missing media', 'GET', '/media/full/' . str_repeat('0', 32) . '.jpg');
    if (count($photos) >= 2) {
        $as('photos', 'cover the second', 'POST', "/item/$drill/photos/{$photos[1]}/cover");
        $as('photos', 'second right', 'POST', "/item/$drill/photos/{$photos[1]}/right");
        $as('photos', 'order', 'POST', "/item/$drill/photos/order", ['ids' => implode(',', array_reverse($photos))]);
        $as('photos', 'edit page', 'GET', "/item/$drill/photos/{$photos[0]}/edit");
        $as('photos', 'rotate (I-3)', 'POST', "/item/$drill/photos/{$photos[0]}/edit", ['rotate' => '90']);
        $as('photos', 'delete one', 'POST', "/item/$drill/photos/{$photos[1]}/delete");
    }
    $as('photos', 'viewer adds', 'POST', "/item/$drill/photos", [], ['files' => ['photo' => $png], 'who' => 'vera']);
    // Autofill against the stub (I-3: the unedited original is analysed).
    $as('autofill', 'suggest', 'POST', '/autofill/suggest', ['photo_id' => (string)($photos[0] ?? 0)], ['headers' => $fetch, 'stub' => true]);
    $as('autofill', 'suggest an upload', 'POST', '/autofill/suggest', [], ['files' => ['image' => $jpg], 'headers' => $fetch, 'stub' => true]);
    $cp = ['why' => 'the Control Panel page (the html fragment of the result) is not ported yet', 'paths' => ['json.html']];
    $as('autofill', 'settings test', 'POST', '/settings/autofill/test', [], ['headers' => $fetch, 'pending' => $cp]);
    $as('autofill', 'settings test photo', 'POST', '/settings/autofill/test-photo', [], ['files' => ['photo' => $png], 'headers' => $fetch, 'stub' => true, 'pending' => $cp]);
    foreach (['error', 'malformed', 'busy', 'toolarge', 'unsupported'] as $mode) {
        parity_gate_setting($pdo, 'autofill_url', $stub->base . '/m/' . $mode);
        $stub->clear();
        $as('autofill', 'suggest, stub ' . $mode, 'POST', '/autofill/suggest', [], ['files' => ['image' => $jpg], 'headers' => $fetch, 'stub' => true]);
    }
    parity_gate_setting($pdo, 'autofill_url', $stub->base . '/t/s3cret');
    $as('autofill', 'token', 'POST', '/settings/autofill/token', ['token' => 's3cret']);
    $as('autofill', 'suggest with the token', 'POST', '/autofill/suggest', [], ['files' => ['image' => $jpg], 'headers' => $fetch, 'stub' => true]);
    $as('autofill', 'clear token', 'POST', '/settings/autofill/token', ['action' => 'clear']);
    $as('autofill', 'viewer suggests', 'POST', '/autofill/suggest', [], ['files' => ['image' => $jpg], 'headers' => $fetch, 'who' => 'vera']);
    // Bulk, merge, duplicate, quantity, attachments.
    $as('bulk', 'qty +1', 'POST', '/item/5/qty', ['delta' => '1']);
    $as('bulk', 'qty fetch -9', 'POST', '/item/5/qty', ['delta' => '-9'], ['headers' => $fetch]);
    $as('bulk', 'duplicate with photos', 'POST', "/item/$drill/duplicate", ['photos' => '1']);
    $as('bulk', 'attach a PDF', 'POST', '/item/2/attachments', [], ['files' => ['file' => $pdf]]);
    $att = (int)$pdo->query('SELECT id FROM item_attachments ORDER BY id DESC LIMIT 1')->fetchColumn();
    $as('bulk', 'download', 'GET', '/attachment/' . $att, [], ['time' => true]);
    $as('bulk', 'delete the attachment fetch', 'POST', "/item/2/attachments/$att/delete", [], ['headers' => $fetch]);
    $as('bulk', 'merge pick', 'GET', '/item/2/merge', [], ['time' => true]);
    $as('bulk', 'merge confirm page', 'GET', '/item/2/merge?into=5');
    $as('bulk', 'merge', 'POST', '/item/2/merge', ['into' => '5', 'confirm' => '1']);
    $as('bulk', 'bulk move', 'POST', '/bulk', ['ids' => [3, 5, 99], 'action' => 'move', 'location_id' => '2']);
    $as('bulk', 'undo the move', 'POST', '/undo', ['token' => $token()]);
    $as('bulk', 'bulk category', 'POST', '/bulk', ['ids' => ['3', '5'], 'action' => 'category', 'category_id' => '1']);
    $as('bulk', 'bulk tag_add', 'POST', '/bulk', ['ids' => [3, 5], 'action' => 'tag_add', 'tags' => 'Winter; Garage']);
    $as('bulk', 'bulk labels', 'POST', '/bulk', ['ids' => [3, 5], 'action' => 'labels']);
    $as('bulk', 'bulk delete asks', 'POST', '/bulk', ['ids' => [3, 5], 'action' => 'delete']);
    $copy = (int)$pdo->query("SELECT MAX(id) FROM items")->fetchColumn();
    $as('bulk', 'bulk delete the copy (N-4)', 'POST', '/bulk', ['ids' => [$copy], 'action' => 'delete', 'confirm' => '1']);
    $as('bulk', 'next undoable action prunes (N-4)', 'POST', '/item/5/qty', ['delta' => '1']);
    // Labels, barcode, lending.
    foreach (['/labels', '/labels?loc=1', '/labels?ids=1,3', '/item/1/label', '/barcode', '/barcode?code=123', '/barcode/4006381333931', '/barcode/123', '/lent'] as $p) {
        $as('labels', 'GET ' . $p, 'GET', $p, [], ['time' => true]);
    }
    $as('labels', 'lend', 'POST', '/item/1/lend', ['lent_to' => "  Nikos \t <i>x</i>  ", 'lent_at' => '2026-06-01', 'lent_due' => '2026-06-10']);
    $as('labels', 'lend fetch', 'POST', '/item/5/lend', ['lent_to' => 'Kostas', 'lent_at' => '2026-06-14', 'back' => '/lent'], ['headers' => $fetch]);
    $as('labels', 'lend bad date', 'POST', '/item/5/lend', ['lent_to' => 'A', 'lent_at' => '2026-02-30']);
    $as('labels', 'return', 'POST', '/item/3/return');
    $as('labels', 'lent after', 'GET', '/lent');
    // Stocktake.
    $as('stocktake', 'home', 'GET', '/stocktake', [], ['time' => true]);
    $as('stocktake', 'start', 'POST', '/stocktake', ['location_id' => '1']);
    $take = (int)$pdo->query('SELECT MAX(id) FROM stocktakes')->fetchColumn();
    $as('stocktake', 'take', 'GET', "/stocktake/$take", [], ['time' => true]);
    $as('stocktake', 'check', 'POST', "/stocktake/$take/check", ['item_id' => '5']);
    $as('stocktake', 'check by barcode fetch', 'POST', "/stocktake/$take/check", ['code' => '4006 381333931'], ['headers' => $fetch]);
    $as('stocktake', 'check misplaced fetch', 'POST', "/stocktake/$take/check", ['item_id' => '1'], ['headers' => $fetch]);
    $as('stocktake', 'report', 'GET', "/stocktake/$take/report");
    $as('stocktake', 'finish', 'POST', "/stocktake/$take/finish", ['move' => '1']);
    $as('stocktake', 'finish again', 'POST', "/stocktake/$take/finish");
    $as('stocktake', 'start another', 'POST', '/stocktake', ['location_id' => '2']);
    $take = (int)$pdo->query('SELECT MAX(id) FROM stocktakes')->fetchColumn();
    $as('stocktake', 'cancel', 'POST', "/stocktake/$take/cancel");
    // CSV export and import.
    $as('import', 'export', 'GET', '/export.csv', [], ['time' => true]);
    $as('import', 'import home', 'GET', '/import', [], ['time' => true]);
    $csv = "\xEF\xBB\xBFItem;Where;Qty;Tags;Price;Category\nLadder;Shed › Wall;1;red, New;12,50;Tools\nRope;Garage;<b>two;;;\nBucket;Shed;3;;€ 3;Paint\n";
    $as('import', 'upload', 'POST', '/import', [], ['files' => ['csv' => ['name' => 'my <file>.csv', 'type' => 'text/csv', 'data' => $csv]]]);
    $import = (int)$pdo->query('SELECT MAX(id) FROM imports')->fetchColumn();
    $as('import', 'map', 'GET', "/import/$import");
    $cols = ['name', 'location', 'quantity', 'tags', 'value', 'category'];
    $as('import', 'preview with errors', 'POST', "/import/$import/preview", ['col' => $cols, 'create' => '1']);
    $as('import', 'preview skipping', 'POST', "/import/$import/preview", ['col' => $cols, 'create' => '1', 'skip_errors' => '1']);
    $as('import', 'commit', 'POST', "/import/$import/commit", ['col' => $cols, 'create' => '1', 'skip_errors' => '1']);
    $as('import', 'done page', 'GET', "/import/$import");
    $as('import', 'export after', 'GET', '/export.csv');
    $as('import', 'viewer upload', 'POST', '/import', [], ['files' => ['csv' => ['name' => 'a.csv', 'type' => 'text/csv', 'data' => "name\nx\n"]], 'who' => 'vera']);
    // CLI.
    foreach ([['list'], ['get', 'app_name'], ['get', 'currency'], ['set', 'lend_due_days', '21'], ['get', 'lend_due_days'], ['set', 'nope', '1'],
        ['reset', 'lend_due_days'], ['export'], ['users'], ['roles'], ['user', 'signout', 'vera'], ['auth', 'status'], ['frobnicate']] as $argv) {
        $out['cli: settings ' . implode(' ', $argv)] = parity_gate_cli($server, $dir, $argv, $pdo, $hide);
    }
    return [$out, $reads];
}

/** One `bin/settings.php` run on the server's data dir, with the server's app. */
function parity_gate_cli(Server $server, string $dir, array $argv, PDO $pdo, callable $hide): array
{
    $env = array_merge(getenv(), test_server_env($dir), ['APOTHEKE_APP' => $server->app, 'APOTHEKE_CLOCK' => '2026-06-15 09:00:00']);
    $p = proc_open([PHP_BINARY, ROOT . '/bin/settings.php', ...$argv], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    $code = proc_close($p);
    return ['code' => $code, 'stdout' => $hide($stdout), 'stderr' => $hide($stderr), 'db' => parity_gate_db($pdo, $hide)];
}

/** What reached the autofill stub: never the original's bytes, always a plain JPEG within the limit. */
function parity_gate_stub(?array $last): ?array
{
    if ($last === null) return null;
    unset($last['headers']['host'], $last['headers']['content-length'], $last['headers']['user-agent']);
    foreach (['boundary', 'received_at', 'ms'] as $k) unset($last[$k]);
    return $last;
}

/** The security headers the gate compares exactly (absent = null). */
const PARITY_GATE_SECURITY_HEADERS = ['content-security-policy', 'cross-origin-opener-policy', 'cross-origin-resource-policy',
    'permissions-policy', 'referrer-policy', 'strict-transport-security', 'x-content-type-options', 'x-frame-options'];

/**
 * The one documented Cache-Control pair (docs/testing.md, "Parity gate"): on a non-JSON answer other than 200, PHP's
 * session cache limiter on legacy and CodeIgniter's noCache() on CI4. Any other value is compared exactly.
 */
const PARITY_GATE_CACHE_PAIR = ['no-store, no-cache, must-revalidate' => 'legacy', 'no-store, max-age=0, no-cache' => 'ci4'];

/** `?v=` asset cache-busters come from file times or contents, so HTML and JSON (the manifest icons) compare them as `?v=V` (N-490). */
function parity_gate_cache_bust(string $s): string
{
    return (string)preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $s);
}

/**
 * The headers of one answer as the gate compares them: Cache-Control, Pragma and Expires; every Set-Cookie in full
 * (parity_gate_cookie()); the security headers.
 * @param list<string> $raw the raw header lines
 * @return array{cache:string, pragma:?string, expires:?string, cookies:list<string>, security:array<string,?string>}
 */
function parity_gate_headers(int $status, bool $json, array $raw, callable $hide): array
{
    $h = [];
    $cookies = [];
    foreach ($raw as $line) {
        if (!preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) continue;
        $name = strtolower($m[1]);
        if ($name === 'set-cookie') $cookies[] = $m[2];
        else $h[$name] = trim($m[2]);
    }
    $date = isset($h['date']) ? (int)strtotime($h['date']) : time();
    $cache = $h['cache-control'] ?? '';
    if ($status !== 200 && !$json && isset(PARITY_GATE_CACHE_PAIR[$cache])) $cache = 'no-store, no-cache (session pair)';
    $cookies = array_map(static fn (string $c): string => parity_gate_cookie($c, $date, $hide), $cookies);
    sort($cookies);
    $security = [];
    foreach (PARITY_GATE_SECURITY_HEADERS as $name) $security[$name] = $h[$name] ?? null;
    return ['cache' => $cache, 'pragma' => $h['pragma'] ?? null, 'expires' => $h['expires'] ?? null, 'cookies' => $cookies, 'security' => $security];
}

/**
 * One Set-Cookie, normalised only where a value differs per run: the session ID and any CSRF cookie by shape
 * (length and alphabet), random hex names numbered ($hide); Expires as a bucket relative to the answer's Date (past,
 * minutes, or days). Name, other values and every attribute (Path, Domain, SameSite, Secure, HttpOnly, Max-Age) stay;
 * attribute names are lower-cased and sorted.
 */
function parity_gate_cookie(string $cookie, int $date, callable $hide): string
{
    $parts = array_map('trim', explode(';', $cookie));
    [$name, $value] = array_pad(explode('=', (string)array_shift($parts), 2), 2, '');
    if ($value !== '' && $value !== 'deleted' && ($name === 'apothekesid' || stripos($name, 'csrf') !== false)) {
        $value = sprintf('<%d %s>', strlen($value), preg_match('/^[0-9a-f]+$/', $value) ? 'hex' : (preg_match('/^[A-Za-z0-9,-]+$/', $value) ? 'sid' : 'other'));
    } else {
        $value = $hide(rawurldecode($value));
    }
    $attrs = [];
    foreach ($parts as $part) {
        if ($part === '') continue;
        [$k, $v] = array_pad(explode('=', $part, 2), 2, null);
        $k = strtolower(trim($k));
        if ($k === 'expires' && $v !== null) {
            $d = (int)strtotime($v) - $date;
            $v = $d <= 0 ? 'past' : ($d < 86400 ? '+' . (int)round($d / 60) . 'min' : '+' . (int)round($d / 86400) . 'd');
        }
        $attrs[] = $v === null ? $k : $k . '=' . $v;
    }
    sort($attrs);
    return implode('; ', [$name . '=' . $value, ...$attrs]);
}

/** The passwords the scenario stores; a stored hash is shown with the one it verifies (L-471). */
const PARITY_GATE_PASSWORDS = [PARITY_GATE_PW, 'neon-password', 'another-one'];

/**
 * A stored password hash as the gate compares it: algorithm and cost (parity_gate_hash()) and which scenario
 * password it verifies (`=1` for the first of PARITY_GATE_PASSWORDS, `=none`), so a hash of the wrong password or
 * at another cost is a difference. password_verify() runs once per distinct hash.
 */
function parity_gate_password(string $v): string
{
    static $seen = [];
    if (!isset($seen[$v])) {
        $which = 'none';
        foreach (PARITY_GATE_PASSWORDS as $i => $pw) {
            if (password_verify($pw, $v)) {
                $which = (string)($i + 1);
                break;
            }
        }
        $seen[$v] = parity_gate_hash($v) . ' =' . $which;
    }
    return $seen[$v];
}

/**
 * A password hash with its algorithm and cost kept and only the salt and hash masked: `$2y$12$…(53)`,
 * `$argon2id$v=19$m=65536,t=4,p=1$…`. Anything else is returned as it is.
 */
function parity_gate_hash(string $v): string
{
    if (preg_match('/^(\$2[aby]\$\d{2}\$)([.\/A-Za-z0-9]{53})$/', $v, $m) === 1) return $m[1] . '…(53)';
    if (preg_match('/^(\$argon2(?:id|i)\$v=\d+\$m=\d+,t=\d+,p=\d+\$)[^$]+\$[^$]+$/', $v, $m) === 1) return $m[1] . '…';
    return $v;
}

/** Every table, rows sorted, random names numbered, password hashes (algorithm and cost kept) and session stamps hidden. */
function parity_gate_db(PDO $pdo, callable $hide): array
{
    $tables = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
        ? $pdo->query('SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename')->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($tables as $table) {
        if (!preg_match('/^[a-z0-9_]+$/', (string)$table) || $table === 'apotheke_ci4_pgsql_probe') continue;
        $rows = $pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            ksort($row);
            foreach ($row as $k => &$v) {
                if ($v === null || is_int($v)) continue;
                $v = (string)$v;
                if (preg_match('/^\$(2[aby]|argon2)/', $v) === 1) $v = parity_gate_password($v);
                if ($k === 'session_epoch') $v = 'EPOCH';
            }
            unset($v);
        }
        unset($row);
        // Sort before numbering, on the row without its random names, so the numbers follow the content.
        $key = static fn (array $r): string => (string)preg_replace('/[0-9a-f]{32,64}/', '', (string)json_encode($r));
        usort($rows, static fn (array $a, array $b): int => [$key($a), json_encode($a)] <=> [$key($b), json_encode($b)]);
        $out[$table] = json_decode($hide((string)json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), true);
    }
    return $out;
}

/** The data dir without sessions and the SQLite file: path => mode and sha256. */
/**
 * CI4 keeps the autofill cache behind the storage interface (docs/autofill.md, "Storage"): `autofill/info.json` and
 * `autofill/tmp/` instead of legacy's `autofill-info.json` and `tmp/`. That move is by design (#69) and neither
 * app reads the other's file, so the gate compares them under legacy's names. Their content is still compared.
 */
const PARITY_GATE_LAYOUT = ['autofill/info.json' => 'autofill-info.json', 'autofill/tmp' => 'tmp'];

function parity_gate_files(string $dir, callable $hide): array
{
    // Names already seen (in the tables) first, then the rest in an order that does not depend on them.
    // Map entries go first, so the originals they name are numbered in map order, not in storage order.
    $files = parity_files($dir);
    $key = static fn (string $rel): string => (string)preg_replace('/[0-9a-f]{32,64}/', '', $rel);
    $map = array_values(array_filter(array_keys($files), static fn (string $r): bool => str_contains($r, '/map/')));
    usort($map, static fn (string $a, string $b): int => [$key($a), $hide($a)] <=> [$key($b), $hide($b)]);
    $rest = array_values(array_diff(array_keys($files), $map));
    foreach ($map as $rel) $hide((string)@file_get_contents($dir . '/' . $rel));
    usort($rest, static fn (string $a, string $b): int => [$key($a), $hide($a)] <=> [$key($b), $hide($b)]);
    $out = [];
    foreach ([...$map, ...$rest] as $rel) {
        $path = $dir . '/' . $rel;
        $what = '';
        if (is_file($path)) {
            $data = (string)file_get_contents($path);
            // A small text file (an originals map entry, a cache) is shown with its names numbered.
            $what = strlen($data) <= 2048 && preg_match('//u', $data) === 1 && !str_contains($data, "\0")
                ? ' ' . $hide((string)preg_replace('/"at":\d+/', '"at":T', $data)) : ' ' . substr(hash('sha256', $data), 0, 16);
        }
        if ($rel === 'autofill') continue;
        $out[$hide(PARITY_GATE_LAYOUT[$rel] ?? $rel)] = $files[$rel] . $what;
    }
    ksort($out);
    return $out;
}

/** The fewest timed requests per area and app (see parity_gate_time()). */
const PARITY_GATE_MIN_SAMPLES = 200;

/**
 * Times the recorded reads: every round asks both apps the same request, legacy first in even rounds and CI4 first
 * in odd ones, so load and order hit both alike. An area gets more rounds when it has few reads.
 * @return array<string,array{legacy:list<float>,ci4:list<float>}>
 */
function parity_gate_time(array $runs, array $reads, int $rounds): array
{
    $clients = [];
    foreach ($runs as $app => $run) {
        $pdo = testdb($run['dir']);
        foreach (['ada', 'vera', null] as $who) {
            $client = $run['server']->client();
            if ($who !== null) {
                $row = $pdo->query("SELECT id, password_hash FROM users WHERE username_key = '$who'")->fetch();
                $epoch = substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $sid = bin2hex(random_bytes(13));
                file_put_contents($run['dir'] . '/sessions/sess_' . $sid, 'uid|i:' . $row['id'] . ';auth_epoch|s:16:"' . $epoch . '";csrf|s:64:"'
                    . str_repeat('0', 64) . '";last_activity|i:' . (time() + 3600) . ';signed_in_at|i:' . time() . ';');
                $client->cookies['apothekesid'] = $sid;
            }
            $clients[$app][$who ?? '-'] = $client;
        }
    }
    $samples = [];
    foreach ($reads as $area => $list) {
        foreach ($list as [$path, $who, $headers]) {
            foreach (['legacy', 'ci4'] as $app) $clients[$app][$who ?? '-']->req('GET', $path, [], [], $headers); // warm
        }
        // At least PARITY_GATE_MIN_SAMPLES per app, so the p95 is not one sample's outlier
        // (with 15 samples the nearest-rank p95 is the slowest one); the app asked first alternates.
        $areaRounds = max($rounds, (int)ceil(PARITY_GATE_MIN_SAMPLES / max(1, count($list))));
        for ($i = 0; $i < $areaRounds; $i++) {
            foreach ($list as [$path, $who, $headers]) {
                foreach ($i % 2 === 0 ? ['legacy', 'ci4'] : ['ci4', 'legacy'] as $app) {
                    $t = hrtime(true);
                    $clients[$app][$who ?? '-']->req('GET', $path, [], [], $headers);
                    $samples[$area][$app][] = (hrtime(true) - $t) / 1e6;
                }
            }
        }
    }
    return $samples;
}
