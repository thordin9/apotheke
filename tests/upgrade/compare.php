<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: checks snapshots (tests/upgrade/snapshot.php).
 *   php tests/upgrade/compare.php LABEL expected.json actual.json
 *     exit 1 when the schema, any table's rows or any file's sha256 differ, or when actual breaks the originals
 *     or files/ modes (below); any other mode difference is a warning.
 *   php tests/upgrade/compare.php --guard LABEL seeded.json
 *     exit 1 when the seed is too thin to prove anything: no items, no kept original, no thumbnail, no attachment
 *     file, or an empty carried table.
 * Originals modes (PLAN §3.5, legacy originals_chmod_dir()/originals_chmod_file()): every directory 0750, every
 * file 0640, in every snapshot. files/ itself has legacy's mode, 0770 under the umask (0750 under 022; #142).
 */

if (PHP_SAPI !== 'cli') exit(2);
$json = static fn (string $f): array => json_decode((string)file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
$fmt = static fn (mixed $v): string => json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if (($argv[1] ?? '') === '--guard' && $argc === 4) {
    [, , $label, $file] = $argv;
    $s = $json($file);
    $bad = [];
    foreach ($s['carried'] as $t) {
        if (!isset($s['tables'][$t])) $bad[] = "carried table $t is missing";
        elseif ($s['tables'][$t] === []) $bad[] = "carried table $t is empty";
    }
    foreach (['originals/' => 'a kept original', 'thumbs/' => 'a thumbnail', 'files/' => 'an attachment file'] as $prefix => $what) {
        $n = count(array_filter(array_keys($s['files']), static fn (string $p): bool => str_starts_with($p, $prefix)
            && !str_starts_with($p, 'originals/map/') && $p !== 'originals/.htaccess'));
        if ($n === 0) $bad[] = "no $what";
    }
    foreach ($bad as $b) echo "FAIL $label: $b\n";
    if ($bad === []) printf("ok %s: %d carried tables, none empty; %d files\n", $label, count($s['carried']), count($s['files']));
    exit($bad ? 1 : 0);
}

if ($argc !== 4) { fwrite(STDERR, "usage: compare.php LABEL expected.json actual.json | --guard LABEL seeded.json\n"); exit(2); }
[, $label, $a, $b] = $argv;
$x = $json($a);
$y = $json($b);
$diffs = 0;
foreach (['schema', 'tables', 'files'] as $part) {
    foreach (array_unique([...array_keys($x[$part]), ...array_keys($y[$part])]) as $k) {
        $l = $x[$part][$k] ?? null;
        $r = $y[$part][$k] ?? null;
        if ($l === $r) continue;
        $diffs++;
        if (is_array($l) || is_array($r)) {
            echo "DIFF $label $part $k\n  only before: " . $fmt(array_values(array_diff((array)$l, (array)$r)))
                . "\n  only after:  " . $fmt(array_values(array_diff((array)$r, (array)$l))) . "\n";
        } else {
            echo "DIFF $label $part $k: " . var_export($l, true) . ' -> ' . var_export($r, true) . "\n";
        }
    }
}
foreach ($y['modes'] as $k => $m) {
    if ($k === 'originals' || str_starts_with($k, 'originals/')) {
        $want = str_ends_with($m, '/') || $k === 'originals' ? ['0750', '0750/'] : ['0640'];
        if (!in_array($m, $want, true)) {
            $diffs++;
            echo "DIFF $label mode $k: $m, want " . rtrim($want[0], '/') . "\n";
        }
    } elseif ($k === 'files' && $m !== ($want = sprintf('%04o', 0o770 & ~umask()))) {
        $diffs++;
        echo "DIFF $label mode $k: $m, want $want\n";
    } elseif (isset($x['modes'][$k]) && $x['modes'][$k] !== $m) {
        echo "WARN $label mode $k: {$x['modes'][$k]} -> $m\n";
    }
}
printf("%s %s: %d tables, %d files%s\n", $diffs ? 'FAIL' : 'ok', $label, count($y['tables']), count($y['files']), $diffs ? ", $diffs difference(s)" : '');
exit($diffs ? 1 : 0);
