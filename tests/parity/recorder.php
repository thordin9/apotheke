<?php
declare(strict_types=1);

/**
 * Differential recorder for Feature 12. PR 3 records install, /healthz and a settings save.
 * Later PRs add steps. The allowlist names quirks that must stay identical; it never hides a diff.
 */

function parity_allowlist_load(?string $path = null): array
{
    $path ??= __DIR__ . '/allowlist.yml';
    if (!is_file($path)) throw new RuntimeException('missing allowlist ' . $path);
    $required = ['N-2', 'QA L-2', 'N-4', 'I-3'];
    $entries = [];
    $current = null;
    $version = null;
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new RuntimeException('unreadable allowlist ' . $path);
    foreach ($lines as $lineNo => $line) {
        if ($line === '' || str_starts_with(ltrim($line), '#')) continue;
        if (preg_match('/^version:\s*(\d+)\s*$/', $line, $m)) {
            $version = (int)$m[1];
            continue;
        }
        if ($line === 'entries:') continue;
        if (preg_match('/^  - id:\s*(.+)$/', $line, $m)) {
            if ($current !== null) $entries[] = parity_allowlist_entry($current);
            $current = ['id' => trim($m[1])];
            continue;
        }
        if ($current !== null && preg_match('/^    (behaviour|expect):\s*(.*)$/', $line, $m)) {
            $current[$m[1]] = $m[2];
            continue;
        }
        throw new RuntimeException('allowlist ' . $path . ':' . ($lineNo + 1) . ': unreadable line');
    }
    if ($current !== null) $entries[] = parity_allowlist_entry($current);
    if ($version !== 1) throw new RuntimeException('allowlist version must be 1');
    $ids = array_column($entries, 'id');
    foreach ($required as $id) {
        if (!in_array($id, $ids, true)) throw new RuntimeException('allowlist is missing ' . $id);
    }
    if ($ids !== $required) throw new RuntimeException('allowlist has entries beyond the approved quirks');
    return $entries;
}

/** @param array<string,string> $entry */
function parity_allowlist_entry(array $entry): array
{
    foreach (['id', 'behaviour', 'expect'] as $key) {
        if (!isset($entry[$key]) || $entry[$key] === '') throw new RuntimeException('allowlist entry missing ' . $key);
    }
    return $entry;
}

function parity_normalize_html(string $html): string
{
    $html = preg_replace('/(name="csrf" content=")[a-f0-9]+(")/', '$1CSRF$2', $html) ?? $html;
    return preg_replace('/(name="csrf" value=")[a-f0-9]+(")/', '$1CSRF$2', $html) ?? $html;
}

/** @param array<string,string> $headers */
function parity_headers(array $headers): array
{
    unset($headers['date'], $headers['host'], $headers['set-cookie'], $headers['content-length']);
    ksort($headers);
    return $headers;
}

function parity_diff(mixed $left, mixed $right, string $path = ''): array
{
    if ($left === $right) return [];
    if (is_array($left) && is_array($right)) {
        $out = [];
        foreach (array_keys($left + $right) as $key) {
            $child = ($path === '' ? '' : $path . '.') . (string)$key;
            $out += parity_diff(
                array_key_exists($key, $left) ? $left[$key] : null,
                array_key_exists($key, $right) ? $right[$key] : null,
                $child
            );
        }
        return $out;
    }
    $show = static function (mixed $value): mixed {
        if (!is_string($value) || strlen($value) <= 180) return $value;
        return substr($value, 0, 180) . '…';
    };
    return [$path => ['left' => $show($left), 'right' => $show($right)]];
}

/**
 * Run the PR 3 steps against two fresh legacy installs on this PHP and return the diff.
 * On the legacy@8.4 CI cell that is legacy@8.4 versus legacy@8.4.
 * @return array<string,array{left:mixed,right:mixed}>
 */
function parity_legacy_pair(): array
{
    require_once dirname(__DIR__) . '/lib.php';
    $env = ['APOTHEKE_CLOCK' => '2026-06-15 09:00:00', 'PUBLIC_URL' => 'http://catalog.example/'];
    $dirs = [tmpdir('parity-a'), tmpdir('parity-b')];
    $servers = [];
    try {
        foreach ($dirs as $i => $dir) $servers[$i] = new Server($dir, $env, 'legacy');
        return parity_diff(
            parity_capture($servers[0]->client(), $dirs[0]),
            parity_capture($servers[1]->client(), $dirs[1])
        );
    } finally {
        foreach ($servers as $server) $server->stop();
        foreach ($dirs as $dir) {
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}

/** @return array<string,mixed> */
function parity_capture(Client $client, string $dataDir): array
{
    $home = $client->get('/');
    $healthz = $client->get('/healthz');
    $health = $client->get('/health');
    $exportBefore = $client->get('/settings/export.json');
    if ($home['status'] !== 200) throw new RuntimeException('install status ' . $home['status']);
    if ($healthz['status'] !== 200 || $healthz['body'] !== 'ok') {
        throw new RuntimeException('healthz ' . $healthz['status'] . ' ' . $healthz['body']);
    }
    $installed = [
        'status' => $home['status'],
        'headers' => parity_headers($home['headers']),
        'html' => parity_normalize_html($home['body']),
        'files' => parity_files($dataDir),
        'db' => parity_db_dump($dataDir),
    ];
    $save = $client->post('/settings/general', [
        'csrf' => $client->csrf(),
        'app_name' => 'Parity Store',
        'app_tagline' => 'the storehouse for your stuff',
        'public_url' => 'http://catalog.example/',
    ]);
    if ($save['status'] !== 303) {
        throw new RuntimeException('settings save status ' . $save['status'] . ' ' . substr($save['body'], 0, 180));
    }
    $exportAfter = $client->get('/settings/export.json');
    if (($exportAfter['json']['settings']['app_name'] ?? '') !== 'Parity Store') {
        throw new RuntimeException('settings were not saved');
    }
    $page = $client->get('/settings/general?done=saved');
    return [
        'install' => $installed,
        'healthz' => [
            'status' => $healthz['status'],
            'body' => $healthz['body'],
            'version' => $healthz['headers']['x-apotheke-version'] ?? '',
            'headers' => parity_headers($healthz['headers']),
            'health_status' => $health['status'],
            'health_json' => $health['json'],
            'health_headers' => parity_headers($health['headers']),
        ],
        'settings' => [
            'export_before' => parity_export($exportBefore),
            'save_status' => $save['status'],
            'save_location' => (string)($save['headers']['location'] ?? ''),
            'export_after' => parity_export($exportAfter),
            'page_status' => $page['status'],
            'page_html' => parity_normalize_html($page['body']),
            'page_headers' => parity_headers($page['headers']),
            'files' => parity_files($dataDir),
            'db' => parity_db_dump($dataDir),
        ],
    ];
}

/** @return array{status:int,headers:array<string,string>,json:mixed} */
function parity_export(array $response): array
{
    return [
        'status' => $response['status'],
        'headers' => parity_headers($response['headers']),
        'json' => $response['json'],
    ];
}

/** Relative paths and modes, without session files or the SQLite database. */
function parity_files(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) return $out;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($dir) + 1);
        if (str_starts_with($rel, 'sessions/')) continue;
        if (str_starts_with(basename($rel), 'catalog.sqlite')) continue;
        $out[$rel] = substr(sprintf('%o', $file->getPerms()), -4);
    }
    ksort($out);
    return $out;
}

/**
 * Legacy versus CI4 on the health bytes, the three originals URLs and the originals file modes.
 * Skips when the CI4 tree is not installed or PHP is older than 8.4.
 * @return array<string,array{left:mixed,right:mixed}>
 */
function parity_core_pair(): array
{
    if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
        throw new RuntimeException('skip');
    }
    require_once dirname(__DIR__) . '/lib.php';
    $env = ['APOTHEKE_CLOCK' => '2026-06-15 09:00:00', 'PUBLIC_URL' => 'http://catalog.example/'];
    $dirs = [tmpdir('parity-leg'), tmpdir('parity-ci4')];
    $servers = [];
    try {
        $servers[0] = new Server($dirs[0], $env, 'legacy');
        $servers[1] = new Server($dirs[1], $env, 'ci4');
        return parity_diff(parity_core_capture($servers[0]), parity_core_capture($servers[1]));
    } finally {
        foreach ($servers as $server) $server->stop();
        foreach ($dirs as $dir) {
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}

/** @return array<string,mixed> */
function parity_core_capture(Server $server): array
{
    $client = $server->client();
    $healthz = $client->get('/healthz');
    $health = $client->get('/health');
    $keep = ['content-type', 'x-apotheke-version', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'content-security-policy'];
    $pick = static function (array $headers) use ($keep): array {
        $out = [];
        foreach ($keep as $name) $out[$name] = $headers[$name] ?? null;
        return $out;
    };
    $originals = [];
    foreach (['/originals/guess.jpg', '/Originals/guess.jpg', '/ORIGINALS/guess.jpg'] as $path) {
        $response = $client->get($path);
        $originals[$path] = [
            'status' => $response['status'],
            'body' => $response['body'],
            'type' => $response['headers']['content-type'] ?? '',
        ];
    }
    $dir = $server->dataDir . '/originals';
    return [
        'healthz' => [
            'status' => $healthz['status'],
            'body' => $healthz['body'],
            'headers' => $pick($healthz['headers']),
        ],
        'health' => [
            'status' => $health['status'],
            'body' => $health['body'],
            'type' => $health['headers']['content-type'] ?? '',
            'cache' => $health['headers']['cache-control'] ?? '',
        ],
        'originals' => $originals,
        'modes' => [
            'dir' => is_dir($dir) ? (fileperms($dir) & 0777) : null,
            'htaccess' => is_file($dir . '/.htaccess') ? (fileperms($dir . '/.htaccess') & 0777) : null,
        ],
    ];
}

/** Row contents of every application table, sorted so two fresh installs can be compared. */
function parity_db_dump(string $dataDir): array
{
    $pdo = testdb($dataDir);
    if (test_driver() === 'pgsql') {
        $tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
    $out = [];
    foreach ($tables as $table) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$table)) continue;
        $rows = $pdo->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) ksort($row);
        unset($row);
        usort($rows, static fn(array $a, array $b): int => strcmp((string)json_encode($a), (string)json_encode($b)));
        $out[$table] = $rows;
    }
    return $out;
}
