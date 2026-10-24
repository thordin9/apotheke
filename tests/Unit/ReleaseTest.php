<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/release.php';

/** scripts/release.php: Conventional Commit parsing, SemVer bumps and CHANGELOG writing. */
final class ReleaseTest extends TestCase
{
    private const URL = 'https://github.com/o/r';

    public function testParse(): void
    {
        $this->assertSame(['type' => 'feat', 'scope' => 'items', 'breaking' => false, 'desc' => 'merge duplicates'], rel_parse('feat(items): merge duplicates'));
        $this->assertTrue(rel_parse('fix!: drop PHP 8.2')['breaking']);
        $this->assertTrue(rel_parse('refactor(db): new driver', "Long text.\n\nBREAKING CHANGE: config keys renamed")['breaking']);
        $this->assertNull(rel_parse('Merge pull request #3 from x/y'));
        $this->assertNull(rel_parse('feature: nope'));
        $this->assertTrue(rel_is_release('chore(release): v1.2.3 [skip ci]'));
        $this->assertFalse(rel_is_release('chore(deps): bump'));
    }

    public static function bumps(): array
    {
        return [
            'feat = minor' => [['fix: a', 'feat(x): b', 'docs: c'], 'minor'],
            'fix = patch' => [['fix: a', 'docs: c'], 'patch'],
            'perf = patch' => [['perf: faster'], 'patch'],
            'breaking = major' => [['feat: a', 'fix(api)!: b'], 'major'],
            'docs/ci/test only = none' => [['docs: a', 'ci: b', 'test(e2e): c', 'chore: d', 'build: e', 'style: f', 'refactor: g'], null],
            'release commits ignored' => [['chore(release): v1.1.0 [skip ci]'], null],
            'non-conventional ignored' => [['Merge branch main', 'wip'], null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bumps')]
    public function testBump(array $subjects, ?string $want): void
    {
        $this->assertSame($want, rel_bump(array_map(fn($s) => ['subject' => $s], $subjects)));
    }

    public function testNext(): void
    {
        $this->assertSame('2.0.0', rel_next('1.4.2', 'major'));
        $this->assertSame('1.5.0', rel_next('1.4.2', 'minor'));
        $this->assertSame('1.4.3', rel_next('1.4.2', 'patch'));
        $this->assertSame('0.2.0', rel_next('0.1.9', 'minor'));
        $this->expectException(InvalidArgumentException::class);
        rel_next('v1', 'patch');
    }

    public function testSectionGroupsAndLinks(): void
    {
        $s = rel_section('1.1.0', '2026-10-13', [
            ['subject' => 'feat(items): favourites', 'pr' => 14],
            ['subject' => 'fix(ui): readable undo bar', 'pr' => 14],
            ['subject' => 'feat(labels): bulk labels', 'pr' => 14],
            ['subject' => 'test(e2e): visual checks', 'pr' => 14],
            ['subject' => 'feat!: new API', 'pr' => 15],
            ['subject' => 'chore(release): v1.0.1 [skip ci]'],
            ['subject' => 'random'],
        ], self::URL);
        $this->assertSame("## [1.1.0] - 2026-10-13\n\n### Breaking changes\n\n- new API ([#15](https://github.com/o/r/pull/15))\n\n### Added\n\n"
            . "- **items:** favourites ([#14](https://github.com/o/r/pull/14))\n- **labels:** bulk labels ([#14](https://github.com/o/r/pull/14))\n\n"
            . "### Fixed\n\n- **ui:** readable undo bar ([#14](https://github.com/o/r/pull/14))\n\n### Maintenance\n\n- **e2e:** test: visual checks ([#14](https://github.com/o/r/pull/14))\n", $s);
    }

    public function testChangelogInsert(): void
    {
        $cl = "# Changelog\n\nIntro.\n\n## [Unreleased]\n\n## [1.0.0] - 2026-10-01\n\n### Added\n\n- first\n\n[Unreleased]: " . self::URL . "/compare/v1.0.0...HEAD\n[1.0.0]: " . self::URL . "/releases/tag/v1.0.0\n";
        $out = rel_changelog_insert($cl, "## [1.1.0] - 2026-10-13\n\n### Added\n\n- second\n", '1.1.0', 'v1.0.0', self::URL);
        $this->assertSame("# Changelog\n\nIntro.\n\n## [Unreleased]\n\n## [1.1.0] - 2026-10-13\n\n### Added\n\n- second\n\n## [1.0.0] - 2026-10-01\n\n### Added\n\n- first\n\n"
            . "[Unreleased]: https://github.com/o/r/compare/v1.1.0...HEAD\n[1.1.0]: https://github.com/o/r/compare/v1.0.0...v1.1.0\n[1.0.0]: https://github.com/o/r/releases/tag/v1.0.0\n", $out);
        $this->expectException(RuntimeException::class);
        rel_changelog_insert($out, "## [1.1.0] - x\n", '1.1.0', 'v1.0.0', self::URL);
    }

    public function testChangelogInsertMovesHandWrittenNotes(): void
    {
        $cl = "# Changelog\n\n## [Unreleased]\n\nRebuild first.\n\n### Fixed\n\n- kept\n\n### Changed\n\n- moved\n\n## [1.0.0] - 2026-10-01\n\n- old\n\n[Unreleased]: " . self::URL . "/compare/v1.0.0...HEAD\n";
        $out = rel_changelog_insert($cl, "## [1.1.0] - 2026-10-13\n\n### Fixed\n\n- generated\n", '1.1.0', 'v1.0.0', self::URL);
        $this->assertSame("# Changelog\n\n## [Unreleased]\n\n## [1.1.0] - 2026-10-13\n\nRebuild first.\n\n### Fixed\n\n- generated\n- kept\n\n### Changed\n\n- moved\n\n## [1.0.0] - 2026-10-01\n\n- old\n\n"
            . "[Unreleased]: https://github.com/o/r/compare/v1.1.0...HEAD\n[1.1.0]: https://github.com/o/r/compare/v1.0.0...v1.1.0\n", $out);
    }

    public function testReleaseNotesIncludeHandWrittenNotes(): void
    {
        $cl = "# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n- kept the handwritten line\n\n### Changed\n\n- moved aside\n\n## [1.0.0] - 2026-10-01\n\n- old\n\n[Unreleased]: " . self::URL . "/compare/v1.0.0...HEAD\n";
        $out = rel_changelog_insert($cl, "## [1.1.0] - 2026-10-13\n\n### Fixed\n\n- generated\n", '1.1.0', 'v1.0.0', self::URL);
        $notes = rel_release_notes($out, '1.1.0', self::URL . '/compare/v1.0.0...v1.1.0');
        $this->assertStringContainsString("- generated\n- kept the handwritten line", $notes);
        $this->assertStringContainsString('- moved aside', $notes);
        $this->assertStringContainsString('**Full changelog:** ' . self::URL . '/compare/v1.0.0...v1.1.0', $notes);
        $this->assertStringNotContainsString('## [1.0.0]', $notes);
        $this->assertStringNotContainsString('## [1.1.0]', $notes);
        $this->assertStringNotContainsString('[Unreleased]:', $notes);
    }

    public function testApplyWritesTheFoldedSectionIntoTheNotesFile(): void
    {
        $d = sys_get_temp_dir() . '/relapply-' . bin2hex(random_bytes(4));
        mkdir($d . '/scripts', 0775, true);
        mkdir($d . '/bin', 0775, true);
        copy(dirname(__DIR__, 2) . '/scripts/release.php', $d . '/scripts/release.php');
        file_put_contents($d . '/VERSION', "1.0.0\n");
        file_put_contents($d . '/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n- kept the handwritten line\n\n## [1.0.0] - 2026-01-01\n\n### Added\n\n- first\n\n[Unreleased]: https://github.com/o/r/compare/v1.0.0...HEAD\n[1.0.0]: https://github.com/o/r/releases/tag/v1.0.0\n");
        file_put_contents($d . '/bin/gh', "#!/bin/sh\nprintf '%s\n' \"\$@\" >> " . escapeshellarg($d . '/gh-args') . "\nexit 0\n");
        chmod($d . '/bin/gh', 0755);
        $run = function (string $c) use ($d): void {
            exec('cd ' . escapeshellarg($d) . " && $c 2>&1", $o, $rc);
            $this->assertSame(0, $rc, implode("\n", $o));
        };
        $run('git init -q -b main . && git config user.email t@t && git config user.name t && git config commit.gpgsign false');
        $run('git add VERSION CHANGELOG.md scripts/release.php && git commit -q -m "feat: base" && git tag v1.0.0');
        $run('git commit -q --allow-empty -m "fix(db): generated only"');
        $notes = $d . '/notes.md';
        $cmd = 'cd ' . escapeshellarg($d)
            . ' && PATH=' . escapeshellarg($d . '/bin:' . getenv('PATH'))
            . ' GITHUB_SERVER_URL=https://github.com GITHUB_REPOSITORY=o/r'
            . ' php scripts/release.php apply --notes=' . escapeshellarg($notes) . ' --date=2026-10-23';
        exec($cmd . ' 2>&1', $out, $rc);
        try {
            $this->assertSame(0, $rc, implode("\n", $out));
            $body = (string)file_get_contents($notes);
            $this->assertStringContainsString('- **db:** generated only', $body);
            $this->assertStringContainsString('- kept the handwritten line', $body);
            $this->assertStringContainsString('**Full changelog:** https://github.com/o/r/compare/v1.0.0...v1.0.1', $body);
            $this->assertStringNotContainsString('## [1.0.0]', $body);
        } finally {
            exec('rm -rf ' . escapeshellarg($d));
        }
    }

    public function testHandwrittenScopeReplacesTheGeneratedLine(): void
    {
        $cl = "# Changelog\n\n## [Unreleased]\n\n### Added\n\n- **autofill:** the long note about the image cap\n\n### Fixed\n\n- **autofill:** a later fix stays with its own heading\n\n## [1.0.0] - 2026-10-01\n\n- old\n\n[Unreleased]: " . self::URL . "/compare/v1.0.0...HEAD\n";
        $section = "## [1.1.0] - 2026-10-13\n\n### Added\n\n- **autofill:** add a configurable maximum image size\n- **items:** keep this generated line\n\n### Fixed\n\n- **db:** generated only\n";
        $out = rel_changelog_insert($cl, $section, '1.1.0', 'v1.0.0', self::URL);
        $this->assertStringNotContainsString('add a configurable maximum image size', $out);
        $this->assertStringContainsString('- **autofill:** the long note about the image cap', $out);
        $this->assertStringContainsString('- **items:** keep this generated line', $out);
        $this->assertStringContainsString('- **db:** generated only', $out);
        $this->assertStringContainsString('- **autofill:** a later fix stays with its own heading', $out);
        $added = substr($out, (int)strpos($out, '### Added'), (int)strpos($out, '### Fixed') - (int)strpos($out, '### Added'));
        $this->assertSame(1, substr_count($added, '**autofill:**'));
    }

    public function testChangelogFoldKeepsBlankLinesInsideNotes(): void
    {
        $cl = "# Changelog\n\n## [Unreleased]\n\nFirst paragraph.\n\nSecond paragraph.\n\n### Fixed\n\n- one\n\n- two\n\n```\nline\n\nline\n```\n\n## [1.0.0] - 2026-10-01\n\n- old\n\n[Unreleased]: " . self::URL . "/compare/v1.0.0...HEAD\n";
        $out = rel_changelog_insert($cl, "## [1.1.0] - 2026-10-13\n\n### Fixed\n\n- generated\n", '1.1.0', 'v1.0.0', self::URL);
        $this->assertStringContainsString("First paragraph.\n\nSecond paragraph.", $out);
        $this->assertStringContainsString("- generated\n- one\n\n- two", $out);
        $this->assertStringContainsString("```\nline\n\nline\n```", $out);
        $this->assertStringContainsString("## [Unreleased]\n\n## [1.1.0]", $out);
    }

    public function testReleasedSectionsDoNotRepeatDockerAndDbLines(): void
    {
        $cl = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        $this->assertStringNotContainsString('**docker:** pin the PHP 8.3.36 security release', $cl);
        $this->assertStringNotContainsString('**db:** keep the database user out of connect errors', $cl);
        $this->assertStringContainsString('**docker:** the Docker image now builds on PHP 8.3.36', $cl);
        $this->assertStringContainsString('**db:** seed, backup, restore and settings exit 1', $cl);
    }

    public function testUnreleasedHandwrittenBulletNeedsAPrLink(): void
    {
        $linked = '- **autofill:** the note ([#60](https://github.com/thordin9/apotheke/pull/60))';
        $bare = '- **db:** while the database is down, every route returns 500.';
        $missing = rel_unreleased_missing_pr_links("# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n$linked\n$bare\n\n## [1.0.0] - 2026-10-01\n\n$bare\n");
        $this->assertSame([$bare], $missing, 'a released section is not checked, and a linked Unreleased bullet is kept');
        $this->assertSame([], rel_unreleased_missing_pr_links("# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n$linked\n\n## [1.0.0] - 2026-10-01\n\n$bare\n"));
        $this->assertSame([], rel_unreleased_missing_pr_links("# Changelog\n\n## [Unreleased]\n\n## [1.0.0] - 2026-10-01\n\n- old\n"));
        $cl = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        $this->assertSame([], rel_unreleased_missing_pr_links($cl));
        $v261 = substr($cl, (int)strpos($cl, "## [2.6.1]"), (int)strpos($cl, "## [2.6.0]") - (int)strpos($cl, "## [2.6.1]"));
        $fixed261 = substr($v261, (int)strpos($v261, "### Fixed\n"), (int)strpos($v261, '### Maintenance') - (int)strpos($v261, "### Fixed\n"));
        $this->assertSame(5, substr_count($fixed261, '([#60](https://github.com/thordin9/apotheke/pull/60))'));
        $fixed260 = substr($cl, (int)strpos($cl, "### Fixed\n", (int)strpos($cl, '## [2.6.0]')), (int)strpos($cl, '### Maintenance', (int)strpos($cl, '## [2.6.0]')) - (int)strpos($cl, "### Fixed\n", (int)strpos($cl, '## [2.6.0]')));
        $this->assertStringContainsString('- **db:** while the database is down, every route (CSS, the manifest and /login included) returns 500. The log has only the sanitized connect line. The database user, the password and the DSN are not logged. ([#59](https://github.com/thordin9/apotheke/pull/59))', $fixed260);
    }

    public function testRealChangelogAcceptsTheNextRelease(): void
    {
        $cl = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        $out = rel_changelog_insert($cl, "## [9.9.9] - 2099-01-01\n\n### Fixed\n\n- x\n", '9.9.9', 'v1.0.0', self::URL);
        $this->assertLessThan(strpos($out, '## [1.0.0]'), strpos($out, '## [9.9.9]'));
        $this->assertStringContainsString('[9.9.9]: https://github.com/o/r/compare/v1.0.0...v9.9.9', $out);
    }

    public function testTextBeforeTheFirstHeadingOpensTheReleaseNotes(): void
    {
        $cl = "# Changelog\n\n## [Unreleased]\n\n**Upgrade note:** set X first.\n\n```\nX=nas.local\n```\n\n### Fixed\n\n- kept ([#7](" . self::URL . "/pull/7))\n\n## [1.0.0] - 2026-10-01\n\n- old\n\n[Unreleased]: " . self::URL . "/compare/v1.0.0...HEAD\n";
        $out = rel_changelog_insert($cl, "## [1.1.0] - 2026-10-13\n\n### Fixed\n\n- generated\n", '1.1.0', 'v1.0.0', self::URL);
        $notes = rel_release_notes($out, '1.1.0', self::URL . '/compare/v1.0.0...v1.1.0');
        $this->assertStringStartsWith("**Upgrade note:** set X first.\n\n```\nX=nas.local\n```\n\n### Fixed\n", $notes);
        $this->assertSame([], rel_unreleased_missing_pr_links($cl), 'a paragraph and a code block need no PR link');
    }

    public function testRealChangelogUpgradeNoteLeadsTheNextReleaseNotes(): void
    {
        $cl = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        if (!preg_match('/^## \[Unreleased\]\n\n\*\*Upgrade note:\*\*/m', $cl)) $this->markTestSkipped('no upgrade note under Unreleased');
        $out = rel_changelog_insert($cl, "## [9.9.9] - 2099-01-01\n\n### Fixed\n\n- x\n", '9.9.9', 'v1.0.0', self::URL);
        $notes = rel_release_notes($out, '9.9.9', self::URL . '/compare/v1.0.0...v9.9.9');
        $this->assertStringStartsWith('**Upgrade note:**', $notes);
        $this->assertStringContainsString("ALLOWED_HOSTS=nas.local,apotheke.example\n", $notes);
        $this->assertLessThan(strpos($notes, '### Fixed'), strpos($notes, 'ALLOWED_HOSTS='));
    }

    public function testCommitsFromAGitHistory(): void
    {
        $d = sys_get_temp_dir() . '/reltest-' . bin2hex(random_bytes(4));
        mkdir($d);
        $run = function (string $c) use ($d): void { exec('cd ' . escapeshellarg($d) . " && $c 2>&1", $o, $rc); $this->assertSame(0, $rc, implode("\n", $o)); };
        $run('git init -q -b main . && git config user.email t@t && git config user.name t && git config commit.gpgsign false');
        $run('git commit -q --allow-empty -m "feat: base" && git tag v1.0.0');
        $run('git checkout -q -b topic && git commit -q --allow-empty -m "fix(ui): one" && git commit -q --allow-empty -m "docs: two"');
        $run('git checkout -q main && git merge -q --no-ff topic -m "Merge pull request #7 from o/topic"');
        $run('git commit -q --allow-empty -m "perf: direct (#9)"');
        $cwd = getcwd();
        chdir($d);
        try {
            $this->assertSame('v1.0.0', rel_last_tag());
            $c = rel_commits('v1.0.0');
        } finally { chdir($cwd); exec('rm -rf ' . escapeshellarg($d)); }
        $this->assertSame([['fix(ui): one', 7], ['docs: two', 7], ['perf: direct', 9]], array_map(fn($x) => [$x['subject'], $x['pr']], $c));
        $this->assertSame('patch', rel_bump($c));
    }
}
