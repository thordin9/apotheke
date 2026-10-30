<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/ci4-arch.php';

/** Feature 12: CI branch wiring, the PHP 8.4 legs of the one app, deptrac and DI configs. */
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

    /** After the branch switch, main is the CI4 branch: pull requests are unfiltered and pushes run on main only. */
    public function testPullRequestsAreUnfilteredAndPushesRunOnMain(): void
    {
        $this->assertMatchesRegularExpression(
            "/^on:\\n  pull_request:\\n  push:\\n    branches: \\[main\\]\\n/m",
            $this->ci()
        );
        foreach (['ci.yml', 'release.yml', 'snapshots.yml'] as $file) {
            $this->assertStringNotContainsString('feature/ci4', (string)file_get_contents($this->root() . '/.github/workflows/' . $file), $file);
        }
    }

    public function testMatrixIsTheOneAppOnPhp84(): void
    {
        $yml = $this->ci();
        $this->assertStringNotContainsString('matrix.app', $yml);
        $this->assertStringNotContainsString('legacy, ci4', $yml);
        $this->assertStringContainsString("php: ['8.4']", $yml);
        $this->assertStringContainsString('db: [sqlite, pgsql]', $yml);
        $this->assertStringContainsString('APOTHEKE_APP: ci4', $yml);
        $this->assertStringContainsString('APOTHEKE_TEST_DB: ${{ matrix.db }}', $yml);
        $this->assertStringContainsString('php scripts/ci4-pgsql-proof.php', $yml);
        $this->assertStringContainsString("if: matrix.db == 'pgsql'", $yml);
        $this->assertStringContainsString('bash scripts/coverage.sh --no-readme', $yml);
        $this->assertStringContainsString('name: coverage-ci4-sqlite', $yml);
        $proof = (string)file_get_contents($this->root() . '/scripts/ci4-pgsql-proof.php');
        $this->assertStringContainsString('public.apotheke_ci4_pgsql_probe', $proof);
        $this->assertStringContainsString('apotheke_ci4_pgsql_probe rows=', $proof);
        $this->assertStringContainsString("=== '42P01'", $proof);
        $this->assertStringNotContainsString('getMessage()', $proof);
        $wiring = (string)file_get_contents($this->root() . '/modules/Core/Boot/FrameworkWiring.php');
        $this->assertStringNotContainsString('apotheke_ci4_pgsql_probe', $wiring);
        $harness = (string)file_get_contents($this->root() . '/tests/ci4-router.php');
        $this->assertStringContainsString('public.apotheke_ci4_pgsql_probe', $harness);
        $this->assertStringContainsString("ROOT . '/tests/ci4-router.php'", (string)file_get_contents($this->root() . '/tests/lib.php'));
        $this->assertStringNotContainsString('parity-gate', $yml);
        $this->assertFileDoesNotExist($this->root() . '/scripts/ci4-leg.sh');
    }

    public function testPgsqlProofSeparatesMissingTableFromOtherFailures(): void
    {
        $password = 'proof-should-not-leak';
        [$code, $out, $err] = $this->runProof([
            'TEST_PG_HOST' => '127.0.0.1',
            'TEST_PG_PORT' => '1',
            'TEST_PG_DB' => 'apotheke',
            'TEST_PG_USER' => 'apotheke',
            'TEST_PG_PASSWORD' => $password,
        ]);
        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/connection or query failed \(SQLSTATE [^)]+\)/', $err);
        $this->assertStringNotContainsString('is missing', $err);
        $this->assertStringNotContainsString($password, $err . $out);
        $this->assertStringNotContainsString('pgsql:', $err . $out);

        $real = getenv('TEST_PG_PASSWORD');
        if ($real === false || $real === '') {
            $real = 'apotheke';
        }
        try {
            $pdo = new PDO(
                'pgsql:host=' . (getenv('TEST_PG_HOST') ?: '127.0.0.1') . ';port=' . (getenv('TEST_PG_PORT') ?: '5432') . ';dbname=' . (getenv('TEST_PG_DB') ?: 'apotheke') . ';connect_timeout=3',
                getenv('TEST_PG_USER') ?: 'apotheke',
                $real,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException) {
            $this->markTestIncomplete('PostgreSQL is not reachable, so the auth and missing-table cases were not run');
        }

        $bogus = 'proof-auth-should-not-leak';
        [$code, $out, $err] = $this->runProof([
            'TEST_PG_HOST' => getenv('TEST_PG_HOST') ?: '127.0.0.1',
            'TEST_PG_PORT' => getenv('TEST_PG_PORT') ?: '5432',
            'TEST_PG_DB' => getenv('TEST_PG_DB') ?: 'apotheke',
            'TEST_PG_USER' => getenv('TEST_PG_USER') ?: 'apotheke',
            'TEST_PG_PASSWORD' => $bogus,
        ]);
        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/connection or query failed \(SQLSTATE [^)]+\)/', $err);
        $this->assertStringNotContainsString('is missing', $err);
        $this->assertStringNotContainsString($bogus, $err . $out);
        $this->assertStringNotContainsString($real, $err . $out);
        $this->assertStringNotContainsString('pgsql:', $err . $out);

        $existed = $pdo->query("SELECT to_regclass('public.apotheke_ci4_pgsql_probe')")->fetchColumn();
        $rows = ($existed !== false && $existed !== null)
            ? (int)$pdo->query('SELECT count(*) FROM public.apotheke_ci4_pgsql_probe')->fetchColumn()
            : 0;
        $pdo->exec('DROP TABLE IF EXISTS public.apotheke_ci4_pgsql_probe');
        try {
            [$code, $out, $err] = $this->runProof([
                'TEST_PG_HOST' => getenv('TEST_PG_HOST') ?: '127.0.0.1',
                'TEST_PG_PORT' => getenv('TEST_PG_PORT') ?: '5432',
                'TEST_PG_DB' => getenv('TEST_PG_DB') ?: 'apotheke',
                'TEST_PG_USER' => getenv('TEST_PG_USER') ?: 'apotheke',
                'TEST_PG_PASSWORD' => $real,
            ]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('apotheke_ci4_pgsql_probe is missing (SQLSTATE 42P01)', $err);
            $this->assertStringNotContainsString('connection or query failed', $err);
            $this->assertStringNotContainsString($real, $err . $out);
            $this->assertStringNotContainsString('pgsql:', $err . $out);
        } finally {
            if ($existed !== false && $existed !== null) {
                $pdo->exec('CREATE TABLE public.apotheke_ci4_pgsql_probe (id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY)');
                for ($i = 0; $i < $rows; $i++) {
                    $pdo->exec('INSERT INTO public.apotheke_ci4_pgsql_probe DEFAULT VALUES');
                }
            }
        }
    }

    /** @param array<string, string> $env @return array{0: int, 1: string, 2: string} */
    private function runProof(array $env): array
    {
        $cmd = [PHP_BINARY, $this->root() . '/scripts/ci4-pgsql-proof.php'];
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root(),
            array_merge(getenv() ?: [], $env)
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }

    public function testDeptracAndDiJobsCallTheEmptyConfigScript(): void
    {
        $yml = $this->ci();
        $this->assertMatchesRegularExpression('/^  deptrac:\\n    name: deptrac\\n/m', $yml);
        $this->assertMatchesRegularExpression('/^  di-rule:\\n    name: DI rule\\n/m', $yml);
        $this->assertStringContainsString('php scripts/ci4-arch.php deptrac', $yml);
        $this->assertStringContainsString('php scripts/ci4-arch.php di', $yml);
        // deptrac, DI rule, and the second setup-php of upgrade-rollback (8.3 then 8.4 in one job).
        $this->assertSame(3, substr_count($yml, "php-version: '8.4'"));
    }

    /** PLAN step 16 part 2: one job sets up legacy's PHP 8.3 and CI4's 8.4, on both databases. */
    public function testUpgradeRollbackJobSetsUpPhp83AndPhp84(): void
    {
        $yml = $this->ci();
        $this->assertSame(1, preg_match('/^  upgrade-rollback:\n((?:(?:    .*)?\n)+)/m', $yml, $m), 'the upgrade-rollback job exists');
        $job = $m[1];
        $this->assertMatchesRegularExpression("/php-version: '8\\.3'\n(?:.*\n)*?.*php-version: '8\\.4'\n/", $job, '8.3 is set up, then 8.4');
        $this->assertStringContainsString('db: [sqlite, pgsql]', $job);
        $this->assertStringContainsString('PHP83: php8.3', $job);
        $this->assertStringContainsString('PHP84: php8.4', $job);
        $this->assertStringContainsString('run: bash scripts/upgrade-rollback.sh', $job);
    }

    public function testLintMobilePlaywrightAndDockerRunOnPhp84(): void
    {
        $yml = $this->ci();
        foreach (['lint:', 'mobile-checks:', 'e2e:'] as $job) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($job, '/') . "\\n(?:    .*\\n)*?    strategy:\\n      fail-fast: false\\n      matrix:\\n        php: \\['8\\.4'\\]\\n/",
                $yml,
                $job . ' runs on PHP 8.4'
            );
        }
        $this->assertStringContainsString('name: Lint (PHP ${{ matrix.php }})', $yml);
        $this->assertStringContainsString('name: Headless mobile checks (PHP ${{ matrix.php }})', $yml);
        $this->assertStringContainsString('name: Visual E2E (Playwright, PHP ${{ matrix.php }}, shard ${{ matrix.shard }}/3)', $yml);
        $this->assertStringContainsString('name: Docker build + smoke test (PHP ${{ matrix.php }})', $yml);
        $this->assertStringContainsString('scripts/e2e-container-setup.sh "${{ matrix.php }}"', $yml);
        // docker: the exact patch as the PHP_VERSION build-arg; it is the Dockerfile default (8.4 since #132).
        $this->assertMatchesRegularExpression(
            "/docker:\\n(?:    .*\\n)*?    strategy:\\n      fail-fast: false\\n      matrix:\\n(?:        #.*\\n)*        include:\\n"
            . "          - php: '8\\.4'\\n            php_patch: '8\\.4\\.\\d+'\\n    services:\\n/",
            $yml,
            'docker builds the shipped PHP 8.4 image, as an exact patch'
        );
        preg_match("/php: '8\\.4'\\n            php_patch: '(8\\.4\\.\\d+)'/", $yml, $patch);
        $this->assertStringContainsString('PHP_VERSION=${{ matrix.php_patch }}', $yml);
        $this->assertStringContainsString("shard: [1, 2, 3]", $yml);
        $this->assertStringNotContainsString('stay on 8.3 until PR 3', $yml);

        $docker = (string)file_get_contents($this->root() . '/Dockerfile');
        $this->assertStringContainsString('ARG PHP_VERSION=' . $patch[1] . "\n", $docker);
        $this->assertStringContainsString('FROM php:${PHP_VERSION}-fpm-alpine', $docker);
        $this->assertStringNotContainsString('FROM php:8.', $docker);

        $setup = (string)file_get_contents($this->root() . '/scripts/e2e-container-setup.sh');
        $this->assertStringContainsString('version="${1:-8.3}"', $setup);
        $this->assertStringContainsString('ppa:ondrej/php', $setup);

        $out = [];
        $code = 0;
        exec('bash ' . escapeshellarg($this->root() . '/scripts/e2e-container-setup.sh') . ' 9.9 2>&1', $out, $code);
        $this->assertSame(2, $code, implode("\n", $out));
        $this->assertStringContainsString('is not supported', implode("\n", $out));
    }

    /** 3.0.0 and later versions are cut from main: the release runs after CI on main and checks out main. */
    public function testReleaseWorkflowStaysOnMain(): void
    {
        $yml = (string)file_get_contents($this->root() . '/.github/workflows/release.yml');
        $this->assertStringNotContainsString('feature/ci4', $yml);
        // The whole trigger block, so a new trigger or branch (for example `old`) has to change this test (QA N-1061).
        $this->assertMatchesRegularExpression(
            "/^on:\\n  workflow_run:\\n    workflows: \\[CI\\]\\n    types: \\[completed\\]\\n    branches: \\[main\\]\\n  workflow_dispatch:\\n\\n/m",
            $yml
        );
        $this->assertSame(1, substr_count($yml, 'branches:'));
        $this->assertMatchesRegularExpression('/ref: main\\n/', $yml);
        $this->assertStringContainsString('git push --atomic origin HEAD:main "v$NEXT"', $yml);
        // L-1121: the App token's push would start CI again; the skip marker on the release commit prevents the loop.
        $this->assertStringContainsString('git commit -m "chore(release): v$NEXT [skip ci]"', $yml);
    }

    /** L-1060: the release pushes with the release GitHub App's token; GITHUB_TOKEN stays read-only. */
    public function testReleaseWorkflowPushesWithTheAppToken(): void
    {
        $yml = (string)file_get_contents($this->root() . '/.github/workflows/release.yml');
        $this->assertMatchesRegularExpression('/^    environment: release\n/m', $yml);
        $this->assertStringContainsString(
            "        id: app-token\n"
            . "        if: steps.plan.outputs.release == 'true'\n"
            . "        uses: actions/create-github-app-token@64ab729a6294000f5aa4a4bc0b73d1f32bc45510 # v3.3.0\n"
            . "        with:\n"
            . "          client-id: \${{ vars.RELEASE_APP_CLIENT_ID }}\n"
            . "          private-key: \${{ secrets.RELEASE_APP_PRIVATE_KEY }}\n"
            . "          permission-contents: write\n",
            $yml
        );
        $this->assertStringNotContainsString('app-id:', $yml);
        // M-1120: every action is pinned by a full commit SHA with its version as a comment.
        preg_match_all('/^\s*(?:- )?uses: (\S+)(.*)$/m', $yml, $uses);
        $this->assertCount(3, $uses[1]);
        foreach ($uses[1] as $i => $ref) {
            $this->assertMatchesRegularExpression('/^[\w.-]+\/[\w.-]+@[0-9a-f]{40}$/', $ref);
            $this->assertMatchesRegularExpression('/^ # v?\d+\.\d+\.\d+$/', $uses[2][$i], $ref);
        }
        // The checkout leaves no credentials behind.
        $this->assertStringContainsString("          persist-credentials: false\n", $yml);
        $this->assertStringNotContainsString('          token:', $yml);
        // The token is minted after the guard and the plan, only when there is a release, and reaches only
        // the push step and the gh release create step (step-level env).
        $steps = preg_split('/\n      - /', substr($yml, (int)strpos($yml, "\n    steps:\n")));
        $at = static fn (string $needle): array => array_keys(array_filter($steps, static fn (string $s): bool => str_contains($s, $needle)));
        [$token] = $at('id: app-token');
        $this->assertGreaterThan($at('id: guard')[0], $token);
        $this->assertGreaterThan($at('id: plan')[0], $token);
        $this->assertStringContainsString("        if: steps.plan.outputs.release == 'true'\n", $steps[$token]);
        $users = $at('steps.app-token.outputs.token');
        $this->assertSame([$at('git push --atomic')[0], $at('gh release create')[0]], $users);
        $this->assertSame(2, substr_count($yml, 'steps.app-token.outputs.token'));
        $this->assertStringContainsString("          APP_TOKEN: \${{ steps.app-token.outputs.token }}\n", $steps[$users[0]]);
        $this->assertStringContainsString("          GH_TOKEN: \${{ steps.app-token.outputs.token }}\n", $steps[$users[1]]);
        $this->assertDoesNotMatchRegularExpression('/(echo|set -x|::debug::).*TOKEN/', $yml);
        // N-1183: release.php apply, the commit and the tag run in an earlier step without the token; the token is
        // minted right before the push step, which does nothing but push.
        [$apply] = $at('php scripts/release.php apply');
        $this->assertLessThan($token, $apply);
        $this->assertStringNotContainsString('TOKEN', $steps[$apply]);
        $this->assertSame($token + 1, $users[0]);
        $this->assertStringNotContainsString('release.php', $steps[$users[0]]);
        $this->assertSame(1, substr_count($yml, 'release.php apply'));
        // L-1180: the base64 credential is masked before it is used; no tracing can print it.
        $push = $steps[$users[0]];
        $mask = (int)strpos($push, "          basic=$(printf 'x-access-token:%s' \"\$APP_TOKEN\" | base64 -w0)\n          echo \"::add-mask::\$basic\"\n");
        $this->assertGreaterThan(0, $mask);
        $this->assertGreaterThan($mask, strpos($push, 'GIT_CONFIG_VALUE_0="AUTHORIZATION: basic $basic"'));
        $this->assertGreaterThan($mask, strpos($push, 'git push --atomic'));
        $this->assertSame(1, substr_count($yml, 'base64 -w0'));
        $this->assertDoesNotMatchRegularExpression('/set\s+-[a-z]*x|set\s+-o\s+xtrace|bash\s+-[a-z]*x|shell:.*-[a-z]*x|GIT_TRACE|GIT_CURL_VERBOSE/', $yml);
        // GITHUB_TOKEN: read-only at workflow and job level; nothing asks it for write access.
        $this->assertStringContainsString("permissions:\n  contents: read\n", $yml);
        $this->assertStringContainsString("    permissions:\n      contents: read\n      actions: read\n", $yml);
        $this->assertSame(0, substr_count($yml, ': write') - substr_count($yml, 'permission-contents: write'));
        $this->assertSame(2, substr_count($yml, 'permissions:'));
    }

    /**
     * Dependabot keeps the SHA-pinned actions and the npm dev dependencies (Playwright at the root, tests/e2e) current: one weekly grouped PR
     * per ecosystem and directory, whose commits pass check-commits.
     */
    public function testDependabotUpdatesTheActionsWeeklyInOneGroup(): void
    {
        $this->assertSame(
            "version: 2\n"
            . "updates:\n"
            . "  - package-ecosystem: github-actions\n"
            . "    directory: /\n"
            . "    schedule:\n"
            . "      interval: weekly\n"
            . "    commit-message:\n"
            . "      prefix: ci\n"
            . "    groups:\n"
            . "      github-actions:\n"
            . "        patterns: ['*']\n"
            . "  - package-ecosystem: npm\n"
            . "    directory: /tests/e2e\n"
            . "    schedule:\n"
            . "      interval: weekly\n"
            . "    commit-message:\n"
            . "      prefix: ci\n"
            . "    groups:\n"
            . "      tests-e2e:\n"
            . "        patterns: ['*']\n"
            . "  - package-ecosystem: npm\n"
            . "    directory: /\n"
            . "    schedule:\n"
            . "      interval: weekly\n"
            . "    commit-message:\n"
            . "      prefix: ci\n"
            . "    groups:\n"
            . "      playwright:\n"
            . "        patterns: ['*']\n",
            (string)file_get_contents($this->root() . '/.github/dependabot.yml')
        );
        // A package.json added under frontend/, edge/ or site/ needs its own entry here.
        $found = [];
        foreach (['.', 'tests/e2e', 'frontend', 'edge', 'site'] as $dir) {
            if (is_file($this->root() . '/' . $dir . '/package.json')) {
                $found[] = $dir;
            }
        }
        $this->assertSame(['.', 'tests/e2e'], $found);
    }

    public function testServerEnvPassesApothekeTestDbExplicitly(): void
    {
        require_once dirname(__DIR__) . '/lib.php';
        $keys = ['APOTHEKE_TEST_DB', 'TEST_DB_DRIVER', 'DB_DRIVER', 'TEST_PG_HOST', 'TEST_PG_PORT', 'TEST_PG_DB', 'TEST_PG_USER', 'TEST_PG_PASSWORD'];
        $prev = [];
        foreach ($keys as $key) {
            $prev[$key] = getenv($key);
        }
        putenv('APOTHEKE_TEST_DB=pgsql');
        putenv('TEST_DB_DRIVER=sqlite');
        putenv('DB_DRIVER=sqlite');
        putenv('TEST_PG_HOST=10.1.2.3');
        putenv('TEST_PG_PORT=5432');
        putenv('TEST_PG_DB=apotheke');
        putenv('TEST_PG_USER=apotheke');
        putenv('TEST_PG_PASSWORD=apotheke\\test');
        try {
            $env = test_server_env('/tmp/apo-pgsql-leg');
            $this->assertSame('pgsql', $env['APOTHEKE_TEST_DB']);
            $this->assertSame('pgsql', $env['DB_DRIVER']);
            $this->assertSame('10.1.2.3', $env['DB_HOST']);
            $this->assertSame('5432', $env['DB_PORT']);
            $this->assertSame('apotheke', $env['DB_NAME']);
            $this->assertSame('apotheke', $env['DB_USER']);
            $this->assertSame('apotheke\\test', $env['DB_PASSWORD']);
            $this->assertNotSame('', $env['DB_SCHEMA']);
            $this->assertStringStartsWith('t_', $env['DB_SCHEMA']);

            putenv('APOTHEKE_TEST_DB=sqlite');
            putenv('DB_DRIVER=pgsql');
            $sqlite = test_server_env('/tmp/apo-sqlite-leg');
            $this->assertSame('sqlite', $sqlite['APOTHEKE_TEST_DB']);
            $this->assertSame('sqlite', $sqlite['DB_DRIVER']);
            $this->assertArrayNotHasKey('DB_SCHEMA', $sqlite);
            $this->assertArrayNotHasKey('DB_PASSWORD', $sqlite);
        } finally {
            foreach ($prev as $key => $value) {
                if ($value === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }

    public function testCommittedArchitectureConfigsRunTheTools(): void
    {
        $root = $this->root();
        $this->assertFalse(ci4_deptrac_is_empty((string)file_get_contents($root . '/deptrac.yaml')));
        $this->assertFalse(ci4_di_is_empty((string)file_get_contents($root . '/phpstan-di.neon')));
        if (PHP_VERSION_ID < 80400 || !is_file($root . '/vendor/autoload.php') || !is_file($root . '/vendor/bin/deptrac') || !is_file($root . '/vendor/bin/phpstan')) {
            $this->markTestSkipped('deptrac, phpstan and the CI4 vendor tree are installed on PHP 8.4');
        }
        [$depCode, $depMsg] = ci4_arch_check('deptrac', $root . '/deptrac.yaml');
        [$diCode, $diMsg] = ci4_arch_check('di', $root . '/phpstan-di.neon');
        $this->assertSame(0, $depCode, $depMsg);
        $this->assertSame(0, $diCode, $diMsg);
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
}
