<?php
declare(strict_types=1);

/**
 * PLAN step 16: the parity gate, legacy against CI4 on this PHP and database (APOTHEKE_TEST_DB).
 *   php scripts/parity-gate.php [--rounds=N] [--json=FILE] [--markdown]
 * Prints the differences per area (exit 1 if any) and the p95 per area. With PARITY_P95_MAX_PCT set (for example 20),
 * a CI4 p95 more than that many percent above legacy's also exits 1; with PARITY_P95_MAX_ABS_MS set (for example 5),
 * so does a CI4 p95 more than that many ms above legacy's (the temporary budget, see docs/testing.md).
 */

require dirname(__DIR__) . '/tests/phpunit-bootstrap.php';
require dirname(__DIR__) . '/tests/parity/gate.php';

$opts = getopt('', ['rounds:', 'json:', 'markdown']);
try {
    $maxPct = parity_p95_limit(getenv('PARITY_P95_MAX_PCT'), 'PARITY_P95_MAX_PCT');
    $maxAbs = parity_p95_limit(getenv('PARITY_P95_MAX_ABS_MS'), 'PARITY_P95_MAX_ABS_MS');
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, 'parity gate: ' . $e->getMessage() . "\n");
    exit(2);
}
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
$over = [];
foreach ($gate['samples'] as $area => $by) {
    $l = parity_p95($by['legacy']);
    $c = parity_p95($by['ci4']);
    $pct = $l > 0 ? ($c / $l - 1) * 100 : 0.0;
    $rows[] = [$area, count($by['legacy']), $l, $c, $pct, $c - $l];
    if ($o = parity_p95_over($l, $c, $maxPct, $maxAbs)) $over[$area] = implode(', ', $o);
}
$budget = implode(', ', array_filter([$maxPct !== null ? "+{$maxPct} %" : null, $maxAbs !== null ? "+{$maxAbs} ms" : null]));
foreach ($over as $area => $why) echo "P95 OVER BUDGET $area: $why\n";
if ($over !== []) $code = 1;
echo $md ? "\n| Area | Requests | legacy p95 (ms) | CI4 p95 (ms) | CI4 vs legacy | Δ ms |\n|---|---:|---:|---:|---:|---:|\n" : "\np95 per area (ms), {$rounds} rounds\n";
foreach ($rows as [$area, $n, $l, $c, $pct, $d]) {
    printf($md ? "| %s | %d | %.2f | %.2f | %+.0f%% | %+.2f |\n" : "  %-10s n=%-4d legacy %7.2f  ci4 %7.2f  %+5.0f%%  %+6.2f ms\n", $area, $n, $l, $c, $pct, $d);
}
echo $md ? "\n" : '';
printf("p95 budget: %s; %s\n", $budget === '' ? 'none (report only)' : "CI4 within legacy $budget per area",
    $budget === '' ? 'not checked' : ($over === [] ? 'met' : count($over) . ' area(s) over'));
if (isset($opts['json'])) {
    file_put_contents($opts['json'], json_encode(['diffs' => $gate['diffs'], 'p95' => $rows, 'budget' => ['max_pct' => $maxPct, 'max_abs_ms' => $maxAbs], 'over' => $over], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
exit($code);
