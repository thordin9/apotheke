<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** scripts/check-links.mjs scans AGENTS.md and every Markdown file under docs/. */
final class LinkCheckTest extends TestCase
{
    public function testCheckerScansAgentsAdrPlansQaAndSuperpowers(): void
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
        $this->assertStringContainsString('docs/qa/ci4-pr02-qa.md', $text);
        $this->assertStringContainsString('docs/superpowers/plans/2026-10-12-audit-hardening.md', $text);
        $this->assertStringContainsString('docs/superpowers/specs/2026-10-12-audit-hardening-design.md', $text);
        $this->assertStringContainsString('docs/user-guide.md', $text);
        $this->assertMatchesRegularExpression('/\d+ relative links checked in \d+ files, 0 broken/', $text);
    }

    public function testCheckerWalksDocsRecursivelyAndReportsABrokenLink(): void
    {
        $node = trim((string)shell_exec('command -v node'));
        if ($node === '') $this->markTestSkipped('node is not installed');
        $root = sys_get_temp_dir() . '/link-check-' . bin2hex(random_bytes(4));
        mkdir($root . '/docs/qa', 0775, true);
        mkdir($root . '/docs/superpowers/plans', 0775, true);
        file_put_contents($root . '/README.md', "[plan](docs/superpowers/plans/note.md)\n");
        file_put_contents($root . '/CONTRIBUTING.md', "# Contributing\n");
        file_put_contents($root . '/AGENTS.md', "# Agents\n");
        file_put_contents($root . '/docs/qa/note.md', "# Note\n\n[missing](missing.md)\n\nSuggestion: [Host names](…#host-names)\n\n[elided path](…/does-not-exist.md)\n\nSee ([#72](…)).\n");
        file_put_contents($root . '/docs/superpowers/plans/note.md', "# Plans\n\n[qa](../../qa/note.md)\n");
        $script = dirname(__DIR__, 2) . '/scripts/check-links.mjs';
        $out = [];
        $code = 0;
        exec('APOTHEKE_LINK_ROOT=' . escapeshellarg($root) . ' ' . escapeshellarg($node) . ' ' . escapeshellarg($script), $out, $code);
        $text = implode("\n", $out);
        $this->assertSame(1, $code, $text);
        $this->assertStringContainsString('docs/qa/note.md', $text);
        $this->assertStringContainsString('missing file missing.md', $text);
        $this->assertStringContainsString('missing file …/does-not-exist.md', $text, 'only (…) and (…#anchor) are skipped');
        $this->assertStringNotContainsString('…#host-names', $text, 'an elided (…#anchor) placeholder is not a link');
        $this->assertStringNotContainsString('missing file …' . "\n", $text . "\n", 'a fully elided (…) target is not a link');
        $this->assertStringContainsString('2 broken', $text);
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
        @rmdir($root);
    }
}
