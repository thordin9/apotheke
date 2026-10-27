<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: compares two snapshots (tests/upgrade/snapshot.php).
 *   php tests/upgrade/compare.php LABEL expected.json actual.json
 * Exit 1 when the schema, any table's rows or any file's sha256 differ; a file mode difference is only a warning.
 */

if (PHP_SAPI !== 'cli' || $argc !== 4) { fwrite(STDERR, "usage: compare.php LABEL expected.json actual.json\n"); exit(2); }
[, $label, $a, $b] = $argv;
$x = json_decode((string)file_get_contents($a), true, 512, JSON_THROW_ON_ERROR);
$y = json_decode((string)file_get_contents($b), true, 512, JSON_THROW_ON_ERROR);
$diffs = 0;
foreach (['schema', 'tables', 'files'] as $part) {
    foreach (array_unique([...array_keys($x[$part]), ...array_keys($y[$part])]) as $k) {
        $l = $x[$part][$k] ?? null;
        $r = $y[$part][$k] ?? null;
        if ($l === $r) continue;
        $diffs++;
        if (is_array($l) || is_array($r)) {
            $gone = array_values(array_diff((array)$l, (array)$r));
            $new = array_values(array_diff((array)$r, (array)$l));
            echo "DIFF $label $part $k\n  only before: " . json_encode($gone, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . "\n  only after:  " . json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        } else {
            echo "DIFF $label $part $k: " . var_export($l, true) . ' -> ' . var_export($r, true) . "\n";
        }
    }
}
foreach ($x['modes'] as $k => $m) {
    if (isset($y['modes'][$k]) && $y['modes'][$k] !== $m) echo "WARN $label mode $k: $m -> {$y['modes'][$k]}\n";
}
printf("%s %s: %d tables, %d files%s\n", $diffs ? 'FAIL' : 'ok', $label, count($y['tables']), count($y['files']), $diffs ? ", $diffs difference(s)" : '');
exit($diffs ? 1 : 0);
