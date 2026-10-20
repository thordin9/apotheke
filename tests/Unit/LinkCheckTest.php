<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** scripts/check-links.mjs also scans AGENTS.md, docs/adr/ and docs/plans/. */
final class LinkCheckTest extends TestCase
{
    public function testCheckerScansAgentsAdrAndPlans(): void
    {
        $node = trim((string)shell_exec('command -v node'));
        if ($node === '') $this->markTestSkipped('node is not installed');
        $script = dirname(__DIR__, 2) . '/scripts/check-links.mjs';
        $out = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($script) . ' --list', $out, $code);
        $text = implode("\n", $out);
        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString("\nAGENTS.md\n", "\n$text\n");
        $this->assertStringContainsString('docs/adr/001-codeigniter4.md', $text);
        $this->assertStringContainsString('docs/plans/feature12-ci4/PLAN.md', $text);
        $this->assertStringContainsString('docs/plans/feature12-ci4/APPENDIX-A-inventory.md', $text);
        $this->assertStringContainsString('docs/plans/feature12-ci4/APPENDIX-B-schema.sql.md', $text);
        $this->assertMatchesRegularExpression('/\d+ relative links checked in \d+ files, 0 broken/', $text);
    }
}
