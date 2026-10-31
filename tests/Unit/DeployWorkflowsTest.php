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

        // the job itself is skipped before the deploy-edge environment gate until the variable is true (no review
        // card), and the in-job gate below stays as a second check
        $job = $this->job($yml, 'deploy');
        $this->assertSame(1, preg_match("/^    if: >-\n((?:      .*\n)+)/m", $job, $m));
        $this->assertStringStartsWith("vars.EDGE_STORAGE_READY == 'true' && (", trim($m[1]));
        $this->assertLessThan(strpos($job, '    environment: deploy-edge'), strpos($job, "vars.EDGE_STORAGE_READY == 'true'"));

        $g = $steps[$gate];
        $this->assertStringContainsString("if: steps.token.outputs.set == 'true'\n", $g);
        $this->assertStringNotContainsString('github.event_name', $g);
        $this->assertStringContainsString('EDGE_STORAGE_READY: ${{ vars.EDGE_STORAGE_READY }}', $g);
        $this->assertStringContainsString('if [ "$EDGE_STORAGE_READY" != "true" ]; then', $g);
        $this->assertStringContainsString('apotheke-api is stateless until P4 storage (D1/R2) lands', $g);
        $this->assertStringContainsString('exit 1', $g);
        $this->assertStringNotContainsString('EDGE_STORAGE_READY', $this->workflow('deploy-frontend.yml'));
    }

    public function testDeployEdgeMergesTheStorageConfigOnlyInTheDeployJobThenSmokeChecks(): void
    {
        // P4 prep: the storage bindings (D1 DB, R2 STORAGE in eu, the Installation Durable Object) are merged only in
        // the deploy job, which runs only with EDGE_STORAGE_READY=true; the PR checks and wrangler dev keep the default.
        $yml = $this->workflow('deploy-edge.yml');
        $steps = $this->steps($yml, 'deploy');
        $n = count($steps);
        $merge = $steps[$n - 3];
        $this->assertStringStartsWith('name: Storage config', $merge);
        $this->assertStringContainsString('APOTHEKE_D1_DATABASE_ID: ${{ vars.APOTHEKE_D1_DATABASE_ID }}', $merge);
        $this->assertStringContainsString("run: node scripts/edge-storage-config.mjs edge/wrangler.jsonc edge/storage.jsonc\n", $merge);
        $this->assertStringNotContainsString('secrets.', $merge);
        $smoke = $steps[$n - 1];
        $this->assertStringStartsWith('name: Smoke check', $smoke);
        $this->assertStringContainsString('/healthz', $smoke);
        $this->assertStringContainsString("\n        shell: bash\n", $smoke);
        $this->assertStringContainsString('exit 1', $smoke);
        $this->assertDoesNotMatchRegularExpression('/secrets\.|CLOUDFLARE_/', $smoke);
        // no wrangler d1 migrations apply: the app's own runner applies migrations/ through the bridge
        $this->assertStringNotContainsString('migrations apply', implode('', $steps) . implode('', $this->steps($yml, 'check')));
        $this->assertSame(1, substr_count($yml, 'edge-storage-config.mjs edge/'), 'merged only in the deploy job');
        $this->assertStringNotContainsString('storage.jsonc', $this->job($yml, 'check'));
        $this->assertStringNotContainsString('storage.jsonc', $this->workflow('edge-local.yml'));
        $this->assertStringContainsString("      - 'scripts/edge-storage-config.mjs'\n", $yml);
        $this->assertStringNotContainsString('d1_databases', self::file('edge/wrangler.jsonc'));
        $this->assertStringNotContainsString('r2_buckets', self::file('edge/wrangler.jsonc'));
        $storage = self::file('edge/storage.jsonc');
        $this->assertStringContainsString('"database_id": "<APOTHEKE_D1_DATABASE_ID>"', $storage);
        $this->assertStringContainsString('"jurisdiction": "eu"', $storage);
        // ADR-002 decision 5 (#203): hosted mode keeps cookies host-only, so the flipped config drops COOKIE_DOMAIN
        // (the merge script applies drop_vars); the default config keeps it.
        $this->assertStringContainsString('"drop_vars": ["COOKIE_DOMAIN"]', $storage);
        // N-1543: CORS_ALLOWED_ORIGINS is not dropped (the SPA is served from getapotheke.com today)
        $this->assertSame(1, substr_count($storage, '"drop_vars"'));
        $this->assertDoesNotMatchRegularExpression('/"drop_vars": \[[^\]]*CORS_ALLOWED_ORIGINS/', $storage);
        $this->assertStringContainsString('for (const v of storage.drop_vars ?? []) delete vars[v];', self::file('scripts/edge-storage-config.mjs'));
        $this->assertStringContainsString('"COOKIE_DOMAIN": "getapotheke.com"', self::file('edge/wrangler.jsonc'));
    }

    #[DataProvider('deploys')]
    public function testDeployRunsOnlyForFullReleasesOrManualRunsOnMainOrTags(string $file, string $env, string $worker, string $dir, string $check): void
    {
        $job = $this->job($this->workflow($file), 'deploy');
        $this->assertSame(1, preg_match("/^    if: >-\n((?:      .*\n)+)/m", $job, $m));
        $cond = (string)preg_replace('/\s+/', ' ', trim($m[1]));
        $want = "(github.event_name == 'release' && !github.event.release.prerelease && startsWith(github.ref, 'refs/tags/v')) || "
            . "(github.event_name == 'workflow_dispatch' && (github.ref == 'refs/heads/main' || startsWith(github.ref, 'refs/tags/v')))";
        // deploy-edge: skipped before the environment gate (no review requested) until EDGE_STORAGE_READY is true
        $this->assertSame($file === 'deploy-frontend.yml' ? "needs.build.outputs.has_build == 'true' && ($want)" : "vars.EDGE_STORAGE_READY == 'true' && ( $want)", $cond);
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

        // L-1460: a tag also needs a published full release, read with the job's read-only token (tested by running
        // the step in scripts/deploy-scripts.test.mjs). Only GH_TOKEN, never a secret.
        $this->assertStringContainsString('GH_TOKEN: ${{ github.token }}', $first);
        $this->assertStringContainsString('gh api "repos/$GITHUB_REPOSITORY/releases/tags/$tag"', $first);
        $this->assertStringContainsString('if [ "$state" != "draft=false prerelease=false" ]; then', $first);
        $this->assertStringNotContainsString('secrets.', $first);
        // N-1464: the token is only in this step, once; N-1465: the step runs in the shell the test runs it in.
        $yml = $this->workflow($file);
        $this->assertSame(1, substr_count($yml, 'GH_TOKEN'));
        $this->assertSame(1, substr_count($yml, 'github.token'));
        $this->assertSame(1, substr_count($first, 'GH_TOKEN: ${{ github.token }}'));
        $this->assertStringContainsString("\n        shell: bash\n        run: |\n", $first);
        foreach (['v1.2.3' => true, 'v10.0.0' => true, 'v1.2' => false, 'v1.2.3-rc.1' => false, 'v01.2.3' => false, 'v1.2.3x' => false, 'vX.Y.Z' => false] as $tag => $ok) {
            $this->assertSame($ok, preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $tag) === 1, $tag);
        }
    }

    #[DataProvider('deploys')]
    public function testPermissionsAndCheckoutsAreLeastPrivilege(string $file, string $env, string $worker, string $dir, string $check): void
    {
        $yml = $this->workflow($file);
        $this->assertStringContainsString("\npermissions:\n  contents: read\n\n", $yml);
        // The deploy job states its own (it reads the tag's release, L-1460); every block is contents: read only.
        $this->assertStringContainsString("    permissions:\n      contents: read\n    steps:\n", $this->job($yml, 'deploy'));
        $this->assertSame(2, substr_count($yml, 'permissions:'));
        $this->assertSame(2, preg_match_all("/^( *)permissions:\n\\1  contents: read\n(?!\\1  \\S)/m", $yml), 'no block widens the permissions');
        $this->assertDoesNotMatchRegularExpression('/id-token|contents:\s*write|: write\b|write-all/', $yml);
        $this->assertStringNotContainsString('pull_request_target', $yml);

        // One checkout per job, pinned by a full commit SHA with its version as a comment (any version, so a
        // Dependabot bump passes), the same SHA everywhere, and no credentials left behind.
        $this->assertSame(2, preg_match_all('/^      - uses: actions\/checkout@([0-9a-f]{40}) # v\d\S*\n/m', $yml, $pins), 'one checkout per job, pinned by SHA');
        foreach ([$check, 'deploy'] as $job) {
            $this->assertSame(1, preg_match_all('/^      - uses: actions\/checkout@/m', $this->job($yml, $job)), "one checkout in $job");
        }
        $this->assertSame(1, count(array_unique($pins[1])), 'both checkouts use the same SHA');
        $this->assertSame(2, substr_count($yml, 'actions/checkout@'));
        $this->assertSame(2, preg_match_all("/uses: actions\/checkout@[0-9a-f]{40} # v\S+\n(?:        if: .*\n)?        with:\n          persist-credentials: false\n/", $yml));
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
        $deploys = array_keys(array_filter($steps, static fn (string $s): bool => str_contains($s, "run: bash scripts/cf-deploy.sh $worker $dir\n")));
        $this->assertCount(1, $deploys, 'one deploy step');
        $deploy = $deploys[0];
        $this->assertStringContainsString('CLOUDFLARE_API_TOKEN: ${{ secrets.CLOUDFLARE_API_TOKEN }}', $steps[$deploy]);
        // deploy-frontend: the deploy is the last step; deploy-edge: only the smoke check follows it
        $this->assertSame(count($steps) - ($file === 'deploy-edge.yml' ? 2 : 1), $deploy);

        $secretSteps = array_filter($steps, static fn (string $s): bool => str_contains($s, 'secrets.'));
        $this->assertSame([1, $deploy], array_keys($secretSteps), 'only the token check and the deploy step see the token');
        foreach ($this->steps($this->workflow($file), 'deploy') as $step) {
            if (str_contains($step, 'npm ci')) {
                $this->assertStringNotContainsString('secrets.', $step);
                $this->assertStringContainsString('npm ci --ignore-scripts --no-audit --no-fund', $step, 'N-1436');
            }
        }
    }

    #[DataProvider('deploys')]
    public function testNoStepMayFailOpen(string $file): void
    {
        // N-1461: continue-on-error on the tag step, the token check or the storage gate would let a refused deploy
        // carry on; nothing in these workflows may fail open.
        $yml = $this->workflow($file);
        $this->assertStringNotContainsString('continue-on-error', $yml);
        foreach ($this->steps($yml, 'deploy') as $step) {
            $this->assertStringNotContainsString('continue-on-error', $step);
        }
    }

    #[DataProvider('deploys')]
    public function testSetupNodeNeverCachesInDeployWorkflows(string $file, string $env, string $worker, string $dir, string $check): void
    {
        // N-1437: setup-node v5+ turns on its package-manager cache by default; the deploy workflows switch it off
        // on every setup-node step, so a Dependabot bump can't silently start saving or restoring a cache here.
        $yml = $this->workflow($file);
        $found = 0;
        foreach ([$check, 'deploy'] as $job) {
            foreach ($this->steps($yml, $job) as $step) {
                if (!str_starts_with($step, 'uses: actions/setup-node@')) {
                    continue;
                }
                $found++;
                $this->assertMatchesRegularExpression("/^        with:\n(?:          .*\n)*          package-manager-cache: false\n/m", $step, "setup-node in $job");
                $this->assertDoesNotMatchRegularExpression('/^          cache(-dependency-path)?:/m', $step, "setup-node in $job");
            }
        }
        $this->assertSame(2, $found, 'one setup-node per job');
        $this->assertSame(2, substr_count($yml, 'actions/setup-node@'));
        $this->assertSame(2, preg_match_all('/^ +package-manager-cache:/m', $yml), 'only on the two setup-node steps');
        $this->assertStringNotContainsString('actions/cache', $yml);
    }

    /** @return iterable<string,array{string,string}> file, job */
    public static function prWorkflows(): iterable
    {
        yield 'frontend' => ['frontend.yml', 'build'];
        yield 'edge-local' => ['edge-local.yml', 'wrangler-dev'];
    }

    #[DataProvider('prWorkflows')]
    public function testSetupNodeNeverCachesAndCheckoutKeepsNoCredentialsInPrWorkflows(string $file, string $job): void
    {
        // N-1486 and N-1487: the same as the deploy workflows, so a later package-manager field can't start a cache
        // and no step inherits the checkout token.
        $yml = $this->workflow($file);
        $steps = $this->steps($yml, $job);
        $nodes = array_values(array_filter($steps, static fn (string $s): bool => str_starts_with($s, 'uses: actions/setup-node@')));
        $this->assertCount(1, $nodes, 'one setup-node');
        $this->assertSame(1, substr_count($yml, 'actions/setup-node@'));
        $this->assertMatchesRegularExpression("/^        with:\n(?:          .*\n)*          package-manager-cache: false\n/m", $nodes[0]);
        $this->assertDoesNotMatchRegularExpression('/^          cache(-dependency-path)?:/m', $nodes[0]);
        $this->assertStringNotContainsString('actions/cache', $yml);

        $this->assertSame(1, substr_count($yml, 'actions/checkout@'), 'one checkout');
        $this->assertSame(1, preg_match("/^      - uses: actions\/checkout@[0-9a-f]{40} # v\S+\n        with:\n(?:          .*\n)*          persist-credentials: false\n/m", $yml));
        $this->assertStringNotContainsString('persist-credentials: true', $yml);
    }

    /** @return iterable<string,array{string}> */
    public static function npmWorkflows(): iterable
    {
        yield 'frontend' => ['deploy-frontend.yml'];
        yield 'edge' => ['deploy-edge.yml'];
        yield 'edge-local' => ['edge-local.yml'];
    }

    #[DataProvider('npmWorkflows')]
    public function testEveryNpmCiSkipsInstallScripts(string $file): void
    {
        // N-1436 (deploy) and N-1462 (PR and test jobs): the same install everywhere, so a dependency that needs an
        // install script fails on the pull request, not at release time.
        $yml = $this->workflow($file);
        $all = preg_match_all('/run: [^\n]*npm ci\b/', $yml);
        $this->assertGreaterThan(0, $all);
        $this->assertSame($all, substr_count($yml, 'npm ci --ignore-scripts --no-audit --no-fund'));
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
