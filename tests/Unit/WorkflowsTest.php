<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The branch `old` (the 2.x line): CI runs on pull requests and on pushes to old, and 2.6.x releases are cut by hand
 * from old only, after CI passed on the exact commit, and never become the Latest GitHub Release (L-1060).
 */
final class WorkflowsTest extends TestCase
{
    public function testCiRunsOnPullRequestsAndPushesToOld(): void
    {
        $yml = $this->workflow('ci.yml');
        $this->assertMatchesRegularExpression("/^on:\\n  pull_request:\\n  push:\\n    branches: \\[old\\]\\n\\n/m", $yml);
        $this->assertSame(1, substr_count($yml, 'branches:'));
    }

    public function testReleaseIsDispatchOnlyAndOnlyFromOld(): void
    {
        $yml = $this->workflow('release.yml');
        $this->assertMatchesRegularExpression("/^on:\\n  workflow_dispatch:\\n\\n/m", $yml);
        $this->assertStringNotContainsString('workflow_run', $yml);
        $this->assertStringNotContainsString('RUN_ID', $yml);
        $this->assertStringNotContainsString('RUN_SHA', $yml);
        $this->assertStringNotContainsString('coverage-sqlite', $yml);
        $this->assertStringNotContainsString('coverage-readme', $yml);
        $this->assertStringNotContainsString('HEAD:main', $yml);
        $this->assertStringNotContainsString('ref: main', $yml);
        $this->assertStringContainsString("    if: github.ref == 'refs/heads/old'\n", $yml);
        $this->assertStringContainsString("          ref: old\n", $yml);
        $this->assertStringContainsString('git push --atomic origin HEAD:old "v$NEXT"', $yml);
    }

    public function testReleaseGuardsAndLatest(): void
    {
        $steps = $this->steps('release.yml');
        $ci = $this->step($steps, 'actions/workflows/ci.yml/runs?branch=old&event=push&status=success&head_sha=$head');
        $plan = $this->step($steps, 'php scripts/release.php plan');
        $version = $this->step($steps, '=~ ^2\.6\.[0-9]+$ ]]');
        $apply = $this->step($steps, 'php scripts/release.php apply');
        $push = $this->step($steps, 'git push --atomic');
        $release = $this->step($steps, 'gh release create');
        $this->assertSame([$ci, $plan, $version, $apply, $push, $release], [3, 4, 5, 6, 7, 8], 'order: checkout, php, CI guard, plan, version guard, apply, push, release');
        $this->assertStringContainsString('if [ "${runs:-0}" -lt 1 ]; then', $steps[$ci]);
        $this->assertStringContainsString("          exit 1\n", $steps[$ci]);
        $this->assertStringContainsString("            exit 1\n", $steps[$version]);
        $this->assertStringContainsString('if ! [[ "$NEXT" =~ ^2\.6\.[0-9]+$ ]]; then', $steps[$version]);
        $this->assertStringContainsString('--verify-tag --latest=false', $steps[$release]);
        $check = (int)strpos($steps[$release], 'latest=$(gh api "repos/$GITHUB_REPOSITORY/releases/latest" --jq \'.tag_name\')');
        $this->assertGreaterThan((int)strpos($steps[$release], 'gh release create'), $check);
        $this->assertStringContainsString('if [[ "$latest" != v3.* ]]; then', $steps[$release]);
        $this->assertStringContainsString("            exit 1\n", $steps[$release]);
        // The loop guard stays on the release commit.
        $this->assertStringContainsString('git commit -m "chore(release): v$NEXT [skip ci]"', $steps[$apply]);
    }

    public function testReleaseActionsAreShaPinnedAndTheTokenStaysNarrow(): void
    {
        $yml = $this->workflow('release.yml');
        preg_match_all('/^\s*(?:- )?uses: (\S+)(.*)$/m', $yml, $uses);
        $this->assertCount(2, $uses[1]);
        foreach ($uses[1] as $i => $ref) {
            $this->assertMatchesRegularExpression('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $ref);
            $this->assertMatchesRegularExpression('/^ # v?\d+\.\d+\.\d+$/', $uses[2][$i], $ref);
        }
        $this->assertStringContainsString("          persist-credentials: false\n", $yml);
        $this->assertStringContainsString("permissions:\n  contents: read\n", $yml);
        $this->assertStringContainsString("    permissions:\n      contents: write\n      actions: read\n", $yml);
        $this->assertSame(2, substr_count($yml, 'permissions:'));
        $steps = $this->steps('release.yml');
        $push = $this->step($steps, 'git push --atomic');
        $this->assertSame([$push], array_keys(array_filter($steps, static fn (string $s): bool => str_contains($s, 'PUSH_TOKEN'))));
        $this->assertStringContainsString("echo \"::add-mask::\$basic\"\n", $steps[$push]);
        $this->assertDoesNotMatchRegularExpression('/set\s+-[a-z]*x|set\s+-o\s+xtrace|bash\s+-[a-z]*x|GIT_TRACE|GIT_CURL_VERBOSE/', $yml);
    }

    private function workflow(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/' . $file);
    }

    /** @return list<string> the release job's steps, index 0 is the text before the first one */
    private function steps(string $file): array
    {
        $yml = $this->workflow($file);

        return preg_split('/\n      - /', substr($yml, (int)strpos($yml, "\n    steps:\n")));
    }

    /** @param list<string> $steps */
    private function step(array $steps, string $needle): int
    {
        $at = array_keys(array_filter($steps, static fn (string $s): bool => str_contains($s, $needle)));
        $this->assertCount(1, $at, $needle);

        return $at[0];
    }
}
