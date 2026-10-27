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
