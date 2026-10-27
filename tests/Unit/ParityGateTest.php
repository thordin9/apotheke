<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/parity/gate.php';

/**
 * PLAN step 16: the parity gate (tests/parity/gate.php). The full run, legacy against CI4 on this PHP and
 * database, is `php scripts/parity-gate.php` (its own CI job); PARITY_GATE=1 runs it here as well.
 */
final class ParityGateTest extends TestCase
{
    public function testP95IsTheNearestRank(): void
    {
        $this->assertSame(0.0, parity_p95([]));
        $this->assertSame(7.0, parity_p95([7.0]));
        $this->assertSame(95.0, parity_p95(array_map('floatval', range(1, 100))));
        $this->assertSame(19.0, parity_p95(array_map('floatval', range(20, 1, -1))), 'sorted first; rank ceil(0.95 × 20) = 19');
    }

    /** The temporary budget: CI4's p95 within legacy + N ms (8 on SQLite, 20 on PG in CI), next to the +N % bar. */
    public function testTheP95BudgetFailsOnlyWhenExceeded(): void
    {
        $this->assertSame([], parity_p95_over(2.08, 7.08, null, 5.0), 'exactly +5 ms is within budget');
        $this->assertSame(['+5.01 ms > +5 ms'], parity_p95_over(2.0, 7.01, null, 5.0));
        $this->assertSame(['+15.94 ms > +12 ms'], parity_p95_over(10.97, 26.91, null, 12.0));
        $this->assertSame([], parity_p95_over(10.97, 22.97, null, 12.0));
        $this->assertSame([], parity_p95_over(9.0, 3.0, 20.0, 5.0), 'CI4 faster is fine');
        $this->assertSame(['+100% > +20%'], parity_p95_over(2.0, 4.0, 20.0, 5.0), 'both bounds apply: +2 ms but +100 %');
        $this->assertSame(['+150% > +20%', '+6.00 ms > +5 ms'], parity_p95_over(4.0, 10.0, 20.0, 5.0));
        $this->assertSame([], parity_p95_over(10.0, 12.0, 20.0, null), 'exactly +20 % is within budget');
        $this->assertSame([], parity_p95_over(1.0, 50.0, null, null), 'no limit set: report only');
        $this->assertSame([], parity_p95_over(0.0, 3.0, 20.0, 5.0), 'no legacy samples: the % bound cannot apply');
    }

    /** The PM's fallback: the absolute budget may compare medians instead of p95. */
    public function testTheBudgetStatisticIsP95OrMedian(): void
    {
        $this->assertSame(3.0, parity_median([5.0, 1.0, 3.0]));
        $this->assertSame(2.0, parity_median([4.0, 1.0, 3.0, 2.0]), 'nearest rank: the lower middle');
        $this->assertSame(0.0, parity_median([]));
        $this->assertSame('p95', parity_budget_stat(false));
        $this->assertSame('p95', parity_budget_stat(''));
        $this->assertSame('p95', parity_budget_stat('p95'));
        $this->assertSame('median', parity_budget_stat(' Median '));
        $this->expectException(InvalidArgumentException::class);
        parity_budget_stat('p50');
    }

    public function testP95LimitsComeFromTheEnvironment(): void
    {
        $this->assertNull(parity_p95_limit(false, 'X'));
        $this->assertNull(parity_p95_limit('', 'X'));
        $this->assertNull(parity_p95_limit('  ', 'X'));
        $this->assertSame(5.0, parity_p95_limit('5', 'X'));
        $this->assertSame(12.5, parity_p95_limit(' 12.5 ', 'X'));
        $this->assertSame(0.0, parity_p95_limit('0', 'X'));
        foreach (['5ms', 'abc', '-1'] as $bad) {
            try {
                parity_p95_limit($bad, 'PARITY_P95_MAX_ABS_MS');
                $this->fail("'$bad' must not switch the guard off");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('PARITY_P95_MAX_ABS_MS', $e->getMessage());
            }
        }
    }

    public function testTheAllowlistStillHoldsOnlyTheFourQuirks(): void
    {
        $this->assertSame(['N-2', 'QA L-2', 'N-4', 'I-3'], array_column(parity_allowlist_load(), 'id'));
    }

    /** CI4's autofill cache is compared under legacy's names; nothing else is renamed. */
    public function testOnlyTheAutofillCacheIsComparedUnderLegacyNames(): void
    {
        $this->assertSame(['autofill/info.json' => 'autofill-info.json', 'autofill/tmp' => 'tmp'], PARITY_GATE_LAYOUT);
        $dir = tmpdir('parity-gate-files');
        try {
            mkdir($dir . '/autofill/tmp', 0700, true);
            file_put_contents($dir . '/autofill/info.json', '{"at":1,"url":"x"}');
            mkdir($dir . '/files', 0750);
            file_put_contents($dir . '/files/' . str_repeat('ab', 8) . 'cd' . str_repeat('ef', 7) . '.pdf', '%PDF');
            $names = [];
            $hide = static function (string $s) use (&$names): string {
                return (string)preg_replace_callback('/[0-9a-f]{32}/', static fn (array $m): string => 'H' . ($names[$m[0]] ??= count($names) + 1), $s);
            };
            $files = parity_gate_files($dir, $hide);
            $this->assertSame('0644 {"at":T,"url":"x"}', preg_replace('/^\d{4}/', '0644', $files['autofill-info.json']));
            $this->assertArrayHasKey('tmp', $files);
            $this->assertArrayNotHasKey('autofill', $files);
            $this->assertArrayHasKey('files/H1.pdf', $files);
        } finally {
            rrmdir($dir);
        }
    }

    public function testTheGateFindsNoDifferences(): void
    {
        if (getenv('PARITY_GATE') !== '1') {
            $this->markTestSkipped('PARITY_GATE=1 runs the full gate (CI: the parity-gate job runs scripts/parity-gate.php)');
        }
        try {
            $gate = parity_gate(0);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'skip') $this->markTestSkipped('needs PHP 8.4 and ci4/vendor');
            throw $e;
        }
        $this->assertGreaterThanOrEqual(190, $gate['steps']);
        $this->assertSame([], $gate['diffs'], json_encode($gate['diffs'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertSame(['autofill: settings test', 'autofill: settings test photo'], array_keys($gate['pending']));
    }
}
