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

    public function testRealChangelogAcceptsTheNextRelease(): void
    {
        $cl = (string)file_get_contents(dirname(__DIR__, 2) . '/CHANGELOG.md');
        $out = rel_changelog_insert($cl, "## [9.9.9] - 2099-01-01\n\n### Fixed\n\n- x\n", '9.9.9', 'v1.0.0', self::URL);
        $this->assertLessThan(strpos($out, '## [1.0.0]'), strpos($out, '## [9.9.9]'));
        $this->assertStringContainsString('[9.9.9]: https://github.com/o/r/compare/v1.0.0...v9.9.9', $out);
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
