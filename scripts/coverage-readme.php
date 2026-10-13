<?php
declare(strict_types=1);

/*
 * Writes the coverage table into README.md between <!-- coverage:start --> and <!-- coverage:end -->.
 * Usage: php scripts/coverage-readme.php [coverage-dir] [--readme=README.md] [--check]
 *   coverage-dir (default coverage/) holds unit.xml, http.xml, combined.xml (Clover, from scripts/coverage.sh or the
 *   CI artifact) and playwright-list.json (`npx playwright test --list --reporter=json`).
 *   --print: write the table to stdout instead (CI job summary); the README is not read or changed.
 *   --check: exit 1 when the README section differs from what would be written (nothing is changed).
 */

const COV_START = '<!-- coverage:start -->';
const COV_END = '<!-- coverage:end -->';
const COV_AREAS = [
    'Front controller & routes' => ['public/'],
    'Items, search, bulk, lending' => ['src/items.php'],
    'Favourites, merge, undo' => ['src/favourites.php', 'src/merge.php', 'src/undo.php'],
    'Stocktake' => ['src/stocktake.php', 'src/stocktake_views.php'],
    'Users & roles' => ['src/users.php', 'src/roles.php'],
    'Full-text search' => ['src/search.php'],
    'CSV import' => ['src/import.php', 'src/import_views.php'],
    'Photos' => ['src/photo_edit.php', 'src/photo_views.php'],
    'Notes & attachments' => ['src/markdown.php', 'src/attachments.php'],
    'Views (HTML)' => ['src/views.php', 'src/settings_views.php'],
    'Control Panel settings' => ['src/settings.php'],
    'Backup & restore' => ['src/backup.php'],
    'Database, migrations, legacy' => ['src/db.php', 'src/legacy.php', 'migrations/'],
    'Bootstrap, config, QR, seed' => ['src/bootstrap.php', 'src/config.php', 'src/qr.php', 'src/seed.php'],
];

/** @return array<string,array{0:int,1:int}> relative file => [executable lines, covered lines] */
function cov_clover_files(string $xml, string $root): array
{
    $out = [];
    $doc = @simplexml_load_string($xml);
    if ($doc === false) throw new RuntimeException('not a Clover XML file');
    foreach ($doc->xpath('//file') ?: [] as $f) {
        $name = (string)$f['name'];
        $rel = str_starts_with($name, $root . '/') ? substr($name, strlen($root) + 1) : $name;
        $m = $f->metrics;
        $out[$rel] = [(int)$m['statements'], (int)$m['coveredstatements']];
    }
    return $out;
}

function cov_sum(array $files, ?array $prefixes = null): array
{
    $t = [0, 0];
    foreach ($files as $rel => [$n, $c]) {
        if ($prefixes !== null && !array_filter($prefixes, fn($p) => $rel === $p || (str_ends_with($p, '/') && str_starts_with($rel, $p)))) continue;
        $t[0] += $n; $t[1] += $c;
    }
    return $t;
}

function cov_pct(array $t): string
{
    return $t[0] ? number_format(100 * $t[1] / $t[0], 1) . ' %' : '–';
}

/** Playwright `--list --reporter=json` → [project => [functional, visual]]. */
function cov_playwright_counts(array $json): array
{
    $counts = [];
    $walk = function (array $suite, string $file) use (&$walk, &$counts): void {
        $file = (string)($suite['file'] ?? $file);
        foreach ($suite['specs'] ?? [] as $spec) {
            $kind = preg_match('/(^|\/)visual[\w-]*\.spec\.ts$/', (string)($spec['file'] ?? $file)) ? 1 : 0;
            foreach ($spec['tests'] ?? [] as $t) {
                $p = (string)$t['projectName'];
                $counts[$p] ??= [0, 0];
                $counts[$p][$kind]++;
            }
        }
        foreach ($suite['suites'] ?? [] as $s) $walk($s, $file);
    };
    foreach ($json['suites'] ?? [] as $s) $walk($s, '');
    ksort($counts);
    return $counts;
}

function cov_table(array $unit, array $http, array $all, array $pw, array $phpunit = []): string
{
    $L = [];
    $L[] = '| Area | PHPUnit (unit) | HTTP integration | Combined |';
    $L[] = '|---|---:|---:|---:|';
    foreach (COV_AREAS as $area => $pref) {
        $L[] = "| $area | " . cov_pct(cov_sum($unit, $pref)) . ' | ' . cov_pct(cov_sum($http, $pref)) . ' | ' . cov_pct(cov_sum($all, $pref)) . ' |';
    }
    $tot = cov_sum($all);
    $L[] = '| **All PHP** (' . $tot[0] . ' lines) | **' . cov_pct(cov_sum($unit)) . '** | **' . cov_pct(cov_sum($http)) . '** | **' . cov_pct($tot) . '** |';
    $L[] = '';
    $L[] = '| Playwright project | Functional tests | Visual tests |';
    $L[] = '|---|---:|---:|';
    $sf = $sv = 0;
    foreach ($pw as $p => [$f, $v]) { $L[] = "| `$p` | $f | $v |"; $sf += $f; $sv += $v; }
    $L[] = "| **Total** | **$sf** | **$sv** |";
    $L[] = '';
    $L[] = '<sub>Line coverage from pcov: PHPUnit unit suite, the HTTP integration suite (every `php -S` request of `tests/integration.php`, '
        . 'collected by `tests/coverage/prepend.php`) and both merged with phpcov, SQLite driver. Regenerate with `npm run coverage:readme`; '
        . 'the release workflow refreshes it from the CI artifact in every release commit.</sub>';
    return implode("\n", $L);
}

function cov_replace(string $readme, string $table): string
{
    $a = strpos($readme, COV_START);
    $b = strpos($readme, COV_END);
    if ($a === false || $b === false || $b < $a) throw new RuntimeException('README has no ' . COV_START . ' … ' . COV_END . ' section');
    return substr($readme, 0, $a + strlen(COV_START)) . "\n" . $table . "\n" . substr($readme, $b);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $dir = 'coverage';
    $readmePath = "$root/README.md";
    $check = false;
    $print = false;
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--check') $check = true;
        elseif ($a === '--print') $print = true;
        elseif (str_starts_with($a, '--readme=')) $readmePath = substr($a, 9);
        else $dir = $a;
    }
    $read = function (string $f) use ($dir): string {
        $p = "$dir/$f";
        if (!is_file($p)) { fwrite(STDERR, "missing $p (run scripts/coverage.sh first)\n"); exit(2); }
        return (string)file_get_contents($p);
    };
    // Clover paths are absolute on the machine that produced them (CI runner): strip up to the repo dir name
    $strip = function (array $files): array {
        $o = [];
        foreach ($files as $k => $v) $o[preg_replace('#^.*?/((?:src|migrations|public)/)#', '$1', $k)] = $v;
        return $o;
    };
    $unit = $strip(cov_clover_files($read('unit.xml'), $root));
    $http = $strip(cov_clover_files($read('http.xml'), $root));
    $all = $strip(cov_clover_files($read('combined.xml'), $root));
    $pw = cov_playwright_counts(json_decode($read('playwright-list.json'), true) ?: []);
    if ($print) { echo "### Coverage\n\n" . cov_table($unit, $http, $all, $pw) . "\n"; exit(0); }
    $readme = (string)file_get_contents($readmePath);
    $new = cov_replace($readme, cov_table($unit, $http, $all, $pw));
    if ($check) {
        if ($new !== $readme) { fwrite(STDERR, "README coverage section is stale; run npm run coverage:readme\n"); exit(1); }
        echo "README coverage section is up to date\n";
        exit(0);
    }
    file_put_contents($readmePath, $new);
    echo 'README coverage: ' . cov_pct(cov_sum($all)) . ' combined (' . cov_pct(cov_sum($unit)) . ' unit, ' . cov_pct(cov_sum($http)) . " HTTP)\n";
}
