<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/coverage-readme.php';

/** scripts/coverage-readme.php: Clover parsing, Playwright counts and the marked README section. */
final class CoverageReadmeTest extends TestCase
{
    public function testCloverAndAreas(): void
    {
        $xml = '<?xml version="1.0"?><coverage><project><file name="/r/src/items.php"><metrics statements="10" coveredstatements="9"/></file>'
            . '<file name="/r/migrations/0001_x.php"><metrics statements="4" coveredstatements="1"/></file></project></coverage>';
        $f = cov_clover_files($xml, '/r');
        $this->assertSame(['src/items.php' => [10, 9], 'migrations/0001_x.php' => [4, 1]], $f);
        $this->assertSame([4, 1], cov_sum($f, ['src/db.php', 'migrations/']));
        $this->assertSame('90.0 %', cov_pct(cov_sum($f, ['src/items.php'])));
        $this->assertSame('–', cov_pct([0, 0]));
    }

    public function testPlaywrightCounts(): void
    {
        $t = fn(string $p) => ['projectName' => $p];
        $json = ['suites' => [
            ['file' => 'list.spec.ts', 'specs' => [['file' => 'list.spec.ts', 'tests' => [$t('a'), $t('b')]]], 'suites' => [['specs' => [['file' => 'list.spec.ts', 'tests' => [$t('a')]]]]]],
            ['file' => 'visual-x.spec.ts', 'specs' => [['file' => 'visual-x.spec.ts', 'tests' => [$t('a'), $t('a-dark')]]]],
        ]];
        $this->assertSame(['a' => [2, 1], 'a-dark' => [0, 1], 'b' => [1, 0]], cov_playwright_counts($json));
    }

    public function testReplaceSection(): void
    {
        $r = "# X\n\n<!-- coverage:start -->\nold\n<!-- coverage:end -->\n\nrest\n";
        $this->assertSame("# X\n\n<!-- coverage:start -->\nNEW\n<!-- coverage:end -->\n\nrest\n", cov_replace($r, 'NEW'));
        $table = cov_table(['src/items.php' => [10, 5]], ['src/items.php' => [10, 8]], ['src/items.php' => [10, 9]], ['p' => [3, 2]]);
        $this->assertStringContainsString('| Items, search, bulk, lending | 50.0 % | 80.0 % | 90.0 % |', $table);
        $this->assertStringContainsString('| **Total** | **3** | **2** |', $table);
        $this->expectException(RuntimeException::class);
        cov_replace('no markers', 'x');
    }

    public function testReadmeHasTheMarkers(): void
    {
        $readme = (string)file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $this->assertSame(1, substr_count($readme, COV_START));
        $this->assertSame(1, substr_count($readme, COV_END));
    }
}
