<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Cloudflare deploy workflows: the apotheke-api storage gate (EDGE_STORAGE_READY) and the
 * hard-coded Worker names. Text checks on the YAML, like Ci4BranchCiTest.
 */
final class DeployWorkflowsTest extends TestCase
{
    private function workflow(string $name): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/' . $name);
    }

    /** The steps of a job, as one text block per step, in order. */
    private function steps(string $yml, string $job): array
    {
        $this->assertSame(1, preg_match('/^  ' . preg_quote($job, '/') . ":\n((?:(?:    .*)?\n)+)/m", $yml, $m), "job $job exists");
        $this->assertSame(1, preg_match("/^    steps:\n((?:(?:      .*)?\n)+)/m", $m[1], $s), "job $job has steps");

        $body = (string)preg_replace('/^\s*#.*\n/m', '', $s[1]);   // comments belong to no step

        return array_values(array_filter(preg_split('/^      - /m', $body), static fn (string $x): bool => trim($x) !== ''));
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
        $this->assertSame(0, $token, 'the token check is the first step');
        $this->assertSame(1, $gate, 'the storage gate comes right after the token check');
        $this->assertNotNull($firstWrangler);
        $this->assertGreaterThan($gate, $firstWrangler, 'the gate runs before checkout and any wrangler call');

        $g = $steps[$gate];
        // Only skipped when the token is missing (green); never conditional on the trigger.
        $this->assertStringContainsString("if: steps.token.outputs.set == 'true'\n", $g);
        $this->assertStringNotContainsString('github.event_name', $g);
        $this->assertStringContainsString('EDGE_STORAGE_READY: ${{ vars.EDGE_STORAGE_READY }}', $g);
        $this->assertStringContainsString('if [ "$EDGE_STORAGE_READY" != "true" ]; then', $g);
        $this->assertStringContainsString('apotheke-api is stateless until P4 storage (D1/R2) lands', $g);
        $this->assertStringContainsString('exit 1', $g);

        // Every later step still requires the token, so a missing token skips them all.
        foreach (array_slice($steps, $gate + 1) as $step) {
            $this->assertStringContainsString("if: steps.token.outputs.set == 'true'", $step);
        }
        $this->assertStringContainsString('run: bash scripts/cf-deploy.sh apotheke-api edge', $yml);
    }

    public function testDeployFrontendHasNoStorageGate(): void
    {
        $yml = $this->workflow('deploy-frontend.yml');
        $this->assertStringNotContainsString('EDGE_STORAGE_READY', $yml);
        $this->assertStringContainsString('run: bash scripts/cf-deploy.sh getapotheke-app edge/app', $yml);
    }

    public function testDeployJobsRunOnlyForReleasesOrManualRunsOnMainOrTags(): void
    {
        foreach (['deploy-edge.yml' => 'deploy-edge', 'deploy-frontend.yml' => 'deploy-site'] as $file => $environment) {
            $yml = $this->workflow($file);
            $this->assertStringContainsString("    environment: $environment\n", $yml);
            $this->assertStringContainsString("(github.event_name == 'release' && startsWith(github.ref, 'refs/tags/v'))", $yml);
            $this->assertStringContainsString("github.ref == 'refs/heads/main' || startsWith(github.ref, 'refs/tags/v')", $yml);
            $this->assertStringNotContainsString('pull_request_target', $yml);
            $this->assertStringNotContainsString('--name', $yml);
        }
    }
}
