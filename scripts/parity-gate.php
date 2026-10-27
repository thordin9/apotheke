<?php
declare(strict_types=1);

/**
 * PLAN step 16: the parity gate, legacy against CI4 on this PHP and database (APOTHEKE_TEST_DB).
 *   php scripts/parity-gate.php [--rounds=N] [--json=FILE] [--markdown]
 * Prints the differences per area (exit 1 if any) and the p95 per area. With PARITY_P95_MAX_PCT set (for example 20),
 * a CI4 p95 more than that much above legacy's also exits 1.
 */

require dirname(__DIR__) . '/tests/phpunit-bootstrap.php';
require dirname(__DIR__) . '/tests/parity/gate.php';

$opts = getopt('', ['rounds:', 'json:', 'markdown']);
$rounds = max(0, (int)($opts['rounds'] ?? 15));
try {
    $gate = parity_gate($rounds);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'skip') {
        fwrite(STDERR, "parity gate: needs PHP 8.4 and ci4/vendor\n");
        exit(2);
    }
    throw $e;
}
$md = isset($opts['markdown']);
$max = getenv('PARITY_P95_MAX_PCT');
$code = 0;
printf("parity gate: %s, PHP %s, %d steps in %d areas\n", test_driver(), PHP_VERSION, $gate['steps'], count($gate['cases']));
foreach ($gate['cases'] as $area => $keys) {
    $n = count($gate['diffs'][$area] ?? []);
    printf("  %-10s %3d steps, %d with differences\n", $area, count($keys), $n);
}
foreach ($gate['diffs'] as $area => $cases) {
    $code = 1;
    foreach ($cases as $case => $diff) {
        echo "DIFF $case\n" . json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
foreach ($gate['pending'] as $case => $why) echo "PENDING $case: $why\n";
$rows = [];
foreach ($gate['samples'] as $area => $by) {
    $l = parity_p95($by['legacy']);
    $c = parity_p95($by['ci4']);
    $pct = $l > 0 ? ($c / $l - 1) * 100 : 0.0;
    $rows[] = [$area, count($by['legacy']), $l, $c, $pct];
    if ($max !== false && $max !== '' && $pct > (float)$max) $code = 1;
}
echo $md ? "\n| Area | Requests | legacy p95 (ms) | CI4 p95 (ms) | CI4 vs legacy |\n|---|---:|---:|---:|---:|\n" : "\np95 per area (ms), {$rounds} rounds\n";
foreach ($rows as [$area, $n, $l, $c, $pct]) {
    printf($md ? "| %s | %d | %.2f | %.2f | %+.0f%% |\n" : "  %-10s n=%-4d legacy %7.2f  ci4 %7.2f  %+5.0f%%\n", $area, $n, $l, $c, $pct);
}
if (isset($opts['json'])) {
    file_put_contents($opts['json'], json_encode(['diffs' => $gate['diffs'], 'p95' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
exit($code);
