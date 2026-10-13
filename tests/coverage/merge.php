<?php
declare(strict_types=1);

/* Usage: php tests/coverage/merge.php <dir with *.raw> <out.cov> — merges the HTTP request dumps into one .cov. */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
[$_, $in, $out] = $argv + [null, null, null];
if (!$in || !$out) { fwrite(STDERR, "usage: merge.php <raw-dir> <out.cov>\n"); exit(2); }
$filter = new Filter();
foreach (['src', 'migrations', 'public'] as $d) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$d", FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->getExtension() === 'php') $filter->includeFile($f->getPathname());
    }
}
$cc = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
$sum = [];
$n = 0;
foreach (glob("$in/*.raw") ?: [] as $f) {
    foreach (unserialize((string)file_get_contents($f)) as $file => $lines) {
        foreach ($lines as $line => $hit) $sum[$file][$line] = max($sum[$file][$line] ?? -1, $hit > 0 ? 1 : -1);
    }
    $n++;
}
$cc->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($sum), 'http-integration');
(new PhpReport())->process($cc, $out);
fwrite(STDOUT, "merged $n request dumps into $out\n");
