<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/ci4-arch.php';

/** Feature 12 PR 2: CI branch wiring, PHP 8.4 legs, empty deptrac and DI configs. */
final class Ci4BranchCiTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function ci(): string
    {
        return (string)file_get_contents($this->root() . '/.github/workflows/ci.yml');
    }

    public function testPullRequestsAreUnfilteredAndPushesIncludeFeatureCi4(): void
    {
        $this->assertMatchesRegularExpression(
            "/^on:\\n  pull_request:\\n  push:\\n    branches: \\[main, feature\\/ci4\\]\\n/m",
            $this->ci()
        );
    }

    public function testMatrixIsLegacyAndCi4OnPhp84WithLegacy83Reference(): void
    {
        $yml = $this->ci();
        $this->assertStringContainsString("app: [legacy, ci4]", $yml);
        $this->assertStringContainsString("php: ['8.4']", $yml);
        $this->assertStringContainsString("php: '8.3'", $yml);
        $this->assertStringContainsString('db: [sqlite, pgsql]', $yml);
        $this->assertStringContainsString('APOTHEKE_APP: ${{ matrix.app }}', $yml);
        $this->assertStringContainsString("matrix.app == 'legacy' && matrix.php == '8.3' && matrix.db == 'sqlite'", $yml);
    }

    public function testDeptracAndDiJobsCallTheEmptyConfigScript(): void
    {
        $yml = $this->ci();
        $this->assertMatchesRegularExpression('/^  deptrac:\\n    name: deptrac\\n/m', $yml);
        $this->assertMatchesRegularExpression('/^  di-rule:\\n    name: DI rule\\n/m', $yml);
        $this->assertStringContainsString('php scripts/ci4-arch.php deptrac', $yml);
        $this->assertStringContainsString('php scripts/ci4-arch.php di', $yml);
        $this->assertSame(2, substr_count($yml, "php-version: '8.4'"));
    }

    public function testLintMobilePlaywrightAndDockerRunOnPhp83AndPhp84(): void
    {
        $yml = $this->ci();
        foreach (['lint:', 'mobile-checks:', 'e2e:', 'docker:'] as $job) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($job, '/') . "\\n(?:    .*\\n)*?    strategy:\\n      fail-fast: false\\n      matrix:\\n        php: \\['8\\.3', '8\\.4'\\]/",
                $yml,
                $job . ' keeps PHP 8.3 and adds PHP 8.4'
            );
        }
        $this->assertStringContainsString('name: Lint (PHP ${{ matrix.php }})', $yml);
        $this->assertStringContainsString('name: Headless mobile checks (PHP ${{ matrix.php }})', $yml);
        $this->assertStringContainsString('name: Visual E2E (Playwright, PHP ${{ matrix.php }}, shard ${{ matrix.shard }}/3)', $yml);
        $this->assertStringContainsString('name: Docker build + smoke test (PHP ${{ matrix.php }})', $yml);
        $this->assertStringContainsString('scripts/e2e-container-setup.sh "${{ matrix.php }}"', $yml);
        $this->assertStringContainsString('PHP_VERSION=${{ matrix.php }}', $yml);
        $this->assertStringContainsString("shard: [1, 2, 3]", $yml);
        $this->assertStringNotContainsString('stay on 8.3 until PR 3', $yml);

        $docker = (string)file_get_contents($this->root() . '/Dockerfile');
        $this->assertStringContainsString('ARG PHP_VERSION=8.3', $docker);
        $this->assertStringContainsString('FROM php:${PHP_VERSION}-fpm-alpine', $docker);
        $this->assertStringNotContainsString('FROM php:8.4', $docker);

        $setup = (string)file_get_contents($this->root() . '/scripts/e2e-container-setup.sh');
        $this->assertStringContainsString('version="${1:-8.3}"', $setup);
        $this->assertStringContainsString('ppa:ondrej/php', $setup);

        $out = [];
        $code = 0;
        exec('bash ' . escapeshellarg($this->root() . '/scripts/e2e-container-setup.sh') . ' 9.9 2>&1', $out, $code);
        $this->assertSame(2, $code, implode("\n", $out));
        $this->assertStringContainsString('is not supported', implode("\n", $out));
    }

    public function testN4WordingNamesLegacyPhp84PhpunitAndDockerSmoke(): void
    {
        foreach (['AGENTS.md', 'docs/adr/001-codeigniter4.md'] as $rel) {
            $text = (string)file_get_contents($this->root() . '/' . $rel);
            $this->assertStringContainsString('legacy@8.3 and legacy@8.4 PHPUnit also run', $text, $rel);
        }
        $plan = (string)file_get_contents($this->root() . '/docs/plans/feature12-ci4/PLAN.md');
        $this->assertStringContainsString('Docker build + smoke on a `php:8.4` test image', $plan);
        $this->assertStringContainsString(
            'PR 2 runs only the CI4 leg plus legacy@8.3, and the legacy@8.4 runs move to PR 3',
            $plan
        );
    }

    public function testReleaseWorkflowStaysOnMain(): void
    {
        $yml = (string)file_get_contents($this->root() . '/.github/workflows/release.yml');
        $this->assertStringNotContainsString('feature/ci4', $yml);
        $this->assertMatchesRegularExpression(
            "/workflow_run:\\n    workflows: \\[CI\\]\\n    types: \\[completed\\]\\n    branches: \\[main\\]\\n/",
            $yml
        );
        $this->assertMatchesRegularExpression('/ref: main\\n/', $yml);
    }

    public function testCi4LegSkipsWhenTheAppIsAbsent(): void
    {
        $root = $this->root();
        $out = [];
        $code = 0;
        exec('bash ' . escapeshellarg($root . '/scripts/ci4-leg.sh') . ' ci4 ' . escapeshellarg($root) . ' 2> ' . escapeshellarg($this->stderrFile()), $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame(['run=false'], $out);
        $err = (string)file_get_contents($this->stderrFile());
        $this->assertStringContainsString('ci4/public/index.php is not in this tree.', $err);
        $this->assertStringContainsString('The job stays green', $err);
    }

    public function testLegacyLegRuns(): void
    {
        $out = [];
        $code = 0;
        exec('bash ' . escapeshellarg($this->root() . '/scripts/ci4-leg.sh') . ' legacy ' . escapeshellarg($this->root()), $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame(['run=true'], $out);
    }

    public function testCi4LegRefusesATreeTheHarnessCannotTarget(): void
    {
        $dir = $this->fixture();
        mkdir($dir . '/ci4/public', 0775, true);
        mkdir($dir . '/tests', 0775, true);
        file_put_contents($dir . '/ci4/public/index.php', "<?php\n");
        file_put_contents($dir . '/tests/lib.php', "<?php\n");
        $out = [];
        $code = 0;
        exec('bash ' . escapeshellarg($this->root() . '/scripts/ci4-leg.sh') . ' ci4 ' . escapeshellarg($dir) . ' 2> ' . escapeshellarg($this->stderrFile()), $out, $code);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('does not switch servers on APOTHEKE_APP', (string)file_get_contents($this->stderrFile()));
    }

    public function testCi4LegRunsWhenTheHarnessSwitchesApps(): void
    {
        $dir = $this->fixture();
        mkdir($dir . '/ci4/public', 0775, true);
        mkdir($dir . '/tests', 0775, true);
        file_put_contents($dir . '/ci4/public/index.php', "<?php\n");
        file_put_contents($dir . '/tests/lib.php', "<?php\n// APOTHEKE_APP picks the server\n");
        $out = [];
        $code = 0;
        exec('bash ' . escapeshellarg($this->root() . '/scripts/ci4-leg.sh') . ' ci4 ' . escapeshellarg($dir), $out, $code);
        $this->assertSame(0, $code);
        $this->assertSame(['run=true'], $out);
    }

    public function testCommittedArchitectureConfigsAreEmptyPasses(): void
    {
        $root = $this->root();
        [$depCode, $depMsg] = ci4_arch_check('deptrac', $root . '/deptrac.yaml');
        [$diCode, $diMsg] = ci4_arch_check('di', $root . '/phpstan-di.neon');
        $this->assertSame(0, $depCode, $depMsg);
        $this->assertSame(0, $diCode, $diMsg);
        $this->assertStringContainsString('empty config', $depMsg);
        $this->assertStringContainsString('empty PHPStan config', $diMsg);
    }

    public function testFilledConfigFailsClosedWithoutTheTool(): void
    {
        $dep = ci4_arch_plan('deptrac', "deptrac:\n  paths:\n    - ci4\n  layers:\n    - name: Core\n  ruleset:\n    Core: []\n", false, 'deptrac.yaml');
        $this->assertSame('fail', $dep['action']);
        $this->assertSame(1, $dep['exit']);
        $this->assertStringContainsString('deptrac is not on PATH', $dep['message']);

        $di = ci4_arch_plan('di', "parameters:\n    level: 6\n    paths:\n        - ci4\n", false, 'phpstan-di.neon');
        $this->assertSame('fail', $di['action']);
        $this->assertSame(1, $di['exit']);
        $this->assertStringContainsString('phpstan is not on PATH', $di['message']);
    }

    public function testFilledConfigPlansTheToolCommand(): void
    {
        $yaml = "deptrac:\n  paths:\n    - ci4\n  layers:\n    - name: Core\n  ruleset:\n    Core: []\n";
        $dep = ci4_arch_plan('deptrac', $yaml, true, '/repo/deptrac.yaml');
        $this->assertSame('run', $dep['action']);
        $this->assertSame(['deptrac', 'analyse', '--config-file=/repo/deptrac.yaml'], $dep['command']);

        $neon = "parameters:\n    level: 6\n    paths:\n        - ci4\n";
        $di = ci4_arch_plan('di', $neon, true, '/repo/phpstan-di.neon');
        $this->assertSame(['phpstan', 'analyse', '-c', '/repo/phpstan-di.neon', '--no-progress'], $di['command']);
    }

    public function testCommentsDoNotCountAsConfig(): void
    {
        $yaml = "# note\n\ndeptrac:\n  paths: []\n  layers: []\n  ruleset: {}\n";
        $this->assertTrue(ci4_deptrac_is_empty($yaml));
        $neon = "# note\nparameters:\n    level: 6\n    paths: []\n";
        $this->assertTrue(ci4_di_is_empty($neon));
        $this->assertFalse(ci4_di_is_empty("parameters:\n    level: 6\n    paths:\n        - ci4\n"));
    }

    private string $stderr = '';
    private string $scratch = '';

    private function stderrFile(): string
    {
        if ($this->stderr === '') $this->stderr = tempnam(sys_get_temp_dir(), 'ci4leg');
        return $this->stderr;
    }

    private function fixture(): string
    {
        $this->scratch = sys_get_temp_dir() . '/ci4-leg-' . bin2hex(random_bytes(4));
        mkdir($this->scratch, 0775, true);
        return $this->scratch;
    }

    protected function tearDown(): void
    {
        if ($this->stderr !== '') @unlink($this->stderr);
        if ($this->scratch !== '' && is_dir($this->scratch)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
            @rmdir($this->scratch);
        }
    }
}
