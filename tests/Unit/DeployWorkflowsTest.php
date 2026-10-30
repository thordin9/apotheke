<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Cloudflare deploy workflows (deploy-frontend.yml, deploy-edge.yml) and scripts/cf-deploy.sh: text checks on
 * the YAML, like Ci4BranchCiTest. What runs (the Worker's headers, the drift check, the guard and cf-deploy.sh) is
 * tested by scripts/deploy-scripts.test.mjs, which these workflows run.
 */
final class DeployWorkflowsTest extends TestCase
{
    private const CHECKOUT = 'actions/checkout@11d5960a326750d5838078e36cf38b85af677262';

    private static function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    private function workflow(string $name): string
    {
        return self::file('.github/workflows/' . $name);
    }

    /** A job's block (its indented lines). */
    private function job(string $yml, string $job): string
    {
        $this->assertSame(1, preg_match('/^  ' . preg_quote($job, '/') . ":\n((?:(?:    .*)?\n)+)/m", $yml, $m), "job $job exists");

        return $m[1];
    }

    /** The steps of a job, as one text block per step, in order (comments dropped). */
    private function steps(string $yml, string $job): array
    {
        $this->assertSame(1, preg_match("/^    steps:\n((?:(?:      .*)?\n)+)/m", $this->job($yml, $job), $s), "job $job has steps");
        $body = (string)preg_replace('/^\s*#.*\n/m', '', $s[1]);

        return array_values(array_filter(preg_split('/^      - /m', $body), static fn (string $x): bool => trim($x) !== ''));
    }

    /** @return iterable<string,array{string,string,string,string,string}> file, environment, Worker, config dir, check job */
    public static function deploys(): iterable
    {
        yield 'frontend' => ['deploy-frontend.yml', 'deploy-site', 'getapotheke-app', 'edge/app', 'build'];
        yield 'edge' => ['deploy-edge.yml', 'deploy-edge', 'apotheke-api', 'edge', 'check'];
    }

    public function testDeployEdgeFailsBeforeAnyWranglerCallUnlessStorageIsReady(): void
    {
        $yml = $this->workflow('deploy-edge.yml');
        $steps = $this->steps($yml, 'deploy');

        $token = $gate = $firstWrangler = null;
        foreach ($steps as $i => $step) {
            if ($token === null && str_contains($step, 'secrets.CLOUDFLARE_API_TOKEN') && str_contains($step, 'id: token')) {
                $token = $i;
            }
            if ($gate === null && str_contains($step, 'EDGE_STORAGE_READY')) {
                $gate = $i;
            }
            if ($firstWrangler === null && preg_match('/wrangler|cf-deploy\.sh|npm ci|actions\/checkout/', $step)) {
                $firstWrangler = $i;
            }
        }
        $this->assertSame(1, $token, 'the token check comes right after the tag check');
        $this->assertSame(2, $gate, 'the storage gate comes right after the token check');
        $this->assertNotNull($firstWrangler);
        $this->assertGreaterThan($gate, $firstWrangler, 'the gate runs before checkout and any wrangler call');

        $g = $steps[$gate];
        $this->assertStringContainsString("if: steps.token.outputs.set == 'true'\n", $g);
        $this->assertStringNotContainsString('github.event_name', $g);
        $this->assertStringContainsString('EDGE_STORAGE_READY: ${{ vars.EDGE_STORAGE_READY }}', $g);
        $this->assertStringContainsString('if [ "$EDGE_STORAGE_READY" != "true" ]; then', $g);
        $this->assertStringContainsString('apotheke-api is stateless until P4 storage (D1/R2) lands', $g);
        $this->assertStringContainsString('exit 1', $g);
        $this->assertStringNotContainsString('EDGE_STORAGE_READY', $this->workflow('deploy-frontend.yml'));
    }

    #[DataProvider('deploys')]
    public function testDeployRunsOnlyForFullReleasesOrManualRunsOnMainOrTags(string $file, string $env, string $worker, string $dir, string $check): void
    {
        $job = $this->job($this->workflow($file), 'deploy');
        $this->assertSame(1, preg_match("/^    if: >-\n((?:      .*\n)+)/m", $job, $m));
        $cond = (string)preg_replace('/\s+/', ' ', trim($m[1]));
        $want = "(github.event_name == 'release' && !github.event.release.prerelease && startsWith(github.ref, 'refs/tags/v')) || "
            . "(github.event_name == 'workflow_dispatch' && (github.ref == 'refs/heads/main' || startsWith(github.ref, 'refs/tags/v')))";
        $this->assertSame($file === 'deploy-frontend.yml' ? "needs.build.outputs.has_build == 'true' && ($want)" : $want, $cond);
        $this->assertSame(1, substr_count($job, '!github.event.release.prerelease'), 'pre-releases never deploy (L-1432)');
        $this->assertSame(1, substr_count($job, "github.event_name == 'release'"));
        $this->assertStringNotContainsString('pull_request', $job);
        $this->assertStringContainsString("    environment: $env\n", $job);

        // The first step requires main or a tag vX.Y.Z (N-1438), before the token is even looked at.
        $first = $this->steps($this->workflow($file), 'deploy')[0];
        $this->assertStringStartsWith('name: Tag is vX.Y.Z', $first);
        $this->assertStringContainsString('REF: ${{ github.ref }}', $first);
        $this->assertStringContainsString('refs/heads/main) exit 0 ;;', $first);
        $this->assertStringContainsString('=~ ^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then', $first);
        $this->assertStringNotContainsString('if:', $first);
        foreach (['v1.2.3' => true, 'v10.0.0' => true, 'v1.2' => false, 'v1.2.3-rc.1' => false, 'v01.2.3' => false, 'v1.2.3x' => false, 'vX.Y.Z' => false] as $tag => $ok) {
            $this->assertSame($ok, preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $tag) === 1, $tag);
        }
    }

    #[DataProvider('deploys')]
    public function testPermissionsAndCheckoutsAreLeastPrivilege(string $file): void
    {
        $yml = $this->workflow($file);
        $this->assertStringContainsString("\npermissions:\n  contents: read\n\n", $yml);
        $this->assertSame(1, substr_count($yml, 'permissions:'), 'no job widens the permissions');
        $this->assertDoesNotMatchRegularExpression('/id-token|contents:\s*write|: write\b|write-all/', $yml);
        $this->assertStringNotContainsString('pull_request_target', $yml);

        $checkouts = substr_count($yml, 'uses: ' . self::CHECKOUT);
        $this->assertSame(2, $checkouts, 'one checkout per job, the pinned one');
        $this->assertSame(0, substr_count($yml, 'actions/checkout@') - $checkouts);
        $this->assertSame($checkouts, preg_match_all("/uses: actions\/checkout@\S+ # v\S+\n(?:        if: .*\n)?        with:\n          persist-credentials: false\n/", $yml));
        $this->assertSame(0, preg_match_all('/persist-credentials: true/', $yml));
        if ($file === 'deploy-frontend.yml') {
            // N-1411: the build reads the latest release tag and its date.
            $this->assertSame(2, preg_match_all("/persist-credentials: false\n(?:          #.*\n)*          fetch-depth: 0\n/", $yml));
        }
    }

    #[DataProvider('deploys')]
    public function testTheTokenGatesEveryLaterStepAndNeverReachesNpmCi(string $file, string $env, string $worker, string $dir): void
    {
        $steps = $this->steps($this->workflow($file), 'deploy');
        $t = $steps[1];
        $this->assertStringContainsString('id: token', $t);
        $this->assertStringContainsString('CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}', $t);
        $this->assertStringContainsString('if [ -n "$CLOUDFLARE_ACCOUNT_ID" ]; then echo "::add-mask::$CLOUDFLARE_ACCOUNT_ID"; fi', $t);
        $this->assertMatchesRegularExpression('/if \[ -z "\$CLOUDFLARE_API_TOKEN" \]; then\n\s+echo "set=false" >> "\$GITHUB_OUTPUT"\n.*\n\s+else\n\s+echo "set=true" >> "\$GITHUB_OUTPUT"\n\s+fi/', $t);
        $this->assertStringNotContainsString('if:', $t);
        $this->assertStringNotContainsString('exit 1', $t, 'a missing token skips, green');

        foreach (array_slice($steps, 2) as $step) {
            $this->assertStringContainsString("if: steps.token.outputs.set == 'true'\n", $step);
        }
        $last = end($steps);
        $this->assertStringContainsString("run: bash scripts/cf-deploy.sh $worker $dir\n", $last);
        $this->assertStringContainsString('CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}', $last);

        $secretSteps = array_filter($steps, static fn (string $s): bool => str_contains($s, 'secrets.'));
        $this->assertSame([1, count($steps) - 1], array_keys($secretSteps), 'only the token check and the deploy step see the token');
        foreach ($this->steps($this->workflow($file), 'deploy') as $step) {
            if (str_contains($step, 'npm ci')) {
                $this->assertStringNotContainsString('secrets.', $step);
                $this->assertStringContainsString('npm ci --ignore-scripts --no-audit --no-fund', $step, 'N-1436');
            }
        }
    }

    #[DataProvider('deploys')]
    public function testConcurrencyIsOneGroupPerEnvironmentAndNeverCancelsADeploy(string $file, string $env): void
    {
        $yml = $this->workflow($file);
        $this->assertStringContainsString("    concurrency:\n      group: $env\n      cancel-in-progress: false\n", $this->job($yml, 'deploy'));
        $this->assertStringContainsString("cancel-in-progress: \${{ github.event_name == 'pull_request' }}\n", $yml);
        $this->assertSame(2, substr_count($yml, 'cancel-in-progress:'));
        $other = $env === 'deploy-site' ? 'deploy-edge' : 'deploy-site';
        $this->assertStringNotContainsString("group: $other", $yml);
    }

    #[DataProvider('deploys')]
    public function testCheckJobRunsTheGuardTheToolingTestsAndAnExplicitConfig(string $file, string $env, string $worker, string $dir, string $check): void
    {
        $yml = $this->workflow($file);
        $job = $this->job($yml, $check);
        $this->assertStringContainsString("run: node scripts/cf-worker-guard.mjs $worker $dir/wrangler.jsonc -- --dry-run\n", $job);
        $this->assertStringContainsString("run: node --test --experimental-strip-types scripts/deploy-scripts.test.mjs\n", $job);
        $this->assertStringContainsString('wrangler deploy --dry-run --config wrangler.jsonc', $job);
        $this->assertStringContainsString('wrangler types --check --config wrangler.jsonc', $job);
        $this->assertStringNotContainsString('secrets.', $job);
        $this->assertDoesNotMatchRegularExpression('/npx --no-install wrangler (deploy|dev|types)(?! [^\n]*--config wrangler\.jsonc)/', $yml, 'every wrangler call names its config (L-1431)');
        $this->assertDoesNotMatchRegularExpression('/--name\b/', $yml);
        if ($file === 'deploy-frontend.yml') {
            $this->assertStringContainsString("STUB_DIST: \${{ steps.detect.outputs.has_build == 'true' && '0' || '1' }}", $job);
            $this->assertStringContainsString('node scripts/app-worker-check.mjs http://127.0.0.1:8788 frontend/dist', $job);
        }
    }

    public function testCfDeployRunsTheGuardThenWranglerWithAnExplicitConfig(): void
    {
        $sh = self::file('scripts/cf-deploy.sh');
        $guard = strpos($sh, "node \"\$root/scripts/cf-worker-guard.mjs\" \"\$worker\" \"\$dir/wrangler.jsonc\" -- \"\$@\"\n");
        $deploy = strpos($sh, "npx --no-install wrangler deploy --config wrangler.jsonc \"\$@\" > \"\$log\" 2>&1 || code=\$?\n");
        $this->assertIsInt($guard);
        $this->assertIsInt($deploy);
        $this->assertLessThan($deploy, $guard);
        $this->assertSame(1, substr_count($sh, 'npx '), 'one wrangler call');
        $this->assertStringContainsString("set -euo pipefail\n", $sh);
        $this->assertStringNotContainsString('set -x', $sh);
        $this->assertDoesNotMatchRegularExpression('/echo[^\n]*\$CLOUDFLARE_(API_TOKEN|ACCOUNT_ID)/', $sh);

        $mjs = self::file('scripts/cf-worker-guard.mjs');
        $this->assertStringContainsString('const ALLOWLIST = new Set(["getapotheke-app", "apotheke-api"]);', $mjs);
        $this->assertStringContainsString('for (const other of ["wrangler.json", "wrangler.toml"])', $mjs);
        $this->assertStringContainsString('join(dir, ".wrangler", "deploy", "config.json")', $mjs);
        foreach (['edge/wrangler.jsonc', 'edge/app/wrangler.jsonc'] as $cfg) {
            $this->assertStringContainsString('scripts/cf-worker-guard.mjs', self::file($cfg));
            $this->assertStringNotContainsString('cf-worker-guard.sh', self::file($cfg));
        }
    }

    public function testWranglerIsPinnedExactly(): void
    {
        $pkg = json_decode(self::file('edge/package.json'), true, 512, JSON_THROW_ON_ERROR);
        $lock = json_decode(self::file('edge/package-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $pin = $pkg['devDependencies']['wrangler'] ?? '';
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $pin, 'an exact version, no range');
        $this->assertSame($pin, $lock['packages']['']['devDependencies']['wrangler'] ?? null);
        $this->assertSame($pin, $lock['packages']['node_modules/wrangler']['version'] ?? null);
        foreach (['deploy-frontend.yml', 'deploy-edge.yml', 'edge-local.yml'] as $f) {
            $yml = $this->workflow($f);
            $this->assertDoesNotMatchRegularExpression('/npx (?!--no-install )/', $yml, "$f only runs the locked wrangler");
            $this->assertStringNotContainsString('wrangler@', $yml);
        }
    }
}
