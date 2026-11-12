<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The image base is one full PHP patch, the ARG PHP_VERSION default in the Dockerfile.
 * Same rule as the docker job's pin assert: ARG and FROM match case-insensitively, there is
 * exactly one ARG PHP_VERSION=X.Y.Z line, and the one FROM php line uses ${PHP_VERSION}.
 * No trailing space, no CR, and no later floating php stage.
 */
final class DockerfileTest extends TestCase
{
    private const FROM = 'FROM php:${PHP_VERSION}-fpm-alpine';

    public function testArgDefaultPinsAFullPatchTag(): void
    {
        $docker = $this->dockerfile();
        $this->assertNotNull($this->pin($docker), $docker);
    }

    public function testPinRejectsTrailingSpaceCrlfAndAFloatingStage(): void
    {
        $pin = $this->pin($this->dockerfile());
        $this->assertNotNull($pin);
        $arg = "ARG PHP_VERSION={$pin}\n";
        $from = self::FROM . "\n";
        $good = $arg . $from;
        $this->assertSame($pin, $this->pin($good));
        $this->assertSame($pin, $this->pin(strtolower('ARG') . " PHP_VERSION={$pin}\n" . 'from php:${PHP_VERSION}-fpm-alpine' . "\n"));
        $this->assertNull($this->pin("ARG PHP_VERSION={$pin} \n" . $from));
        $this->assertNull($this->pin("ARG PHP_VERSION={$pin}\r\n" . $from));
        $this->assertNull($this->pin($arg . self::FROM . " \n"));
        $this->assertNull($this->pin($arg . self::FROM . "\r\n"));
        $this->assertNull($this->pin("ARG PHP_VERSION=8.3\n" . $from));
        $this->assertNull($this->pin("ARG PHP_VERSION\n" . $from));
        $this->assertNull($this->pin($from));
        $this->assertNull($this->pin($arg));
        $this->assertNull($this->pin($arg . "FROM php:{$pin}-fpm-alpine\n"));
        $this->assertNull($this->pin($good . "FROM php:8-fpm-alpine\n"));
        $this->assertNull($this->pin($good . "FROM php:fpm-alpine\n"));
        $this->assertNull($this->pin($good . "from php:8.3-fpm-alpine\n"));
        $this->assertNull($this->pin($good . $from));
        $this->assertNull($this->pin($good . $good));
    }

    /** GET /api/v1/openapi.json serves docs/api/openapi.yaml, so the image carries it at the same relative path. */
    public function testImageCarriesTheOpenApiDocument(): void
    {
        $this->assertStringContainsString("COPY --chown=root:root docs/api/openapi.yaml /var/www/app/docs/api/openapi.yaml\n", $this->dockerfile());
        $this->assertDoesNotMatchRegularExpression('#^docs|^\*\.ya?ml#m', (string)file_get_contents(dirname(__DIR__, 2) . '/.dockerignore'));
    }

    /**
     * L-1241: the official image has no pcntl, so the Dockerfile builds it and its build-time check needs it:
     * without it `php spark jobs:work` ends on SIGTERM (143) instead of handing the running job back.
     */
    public function testImageBuildsAndChecksPcntl(): void
    {
        $docker = $this->dockerfile();
        $this->assertMatchesRegularExpression('/^\s*docker-php-ext-install\b[^\n]*\spcntl\b/m', $docker);
        $this->assertMatchesRegularExpression('/^\s*php -m \| grep -qx pcntl;/m', $docker);
        $ci = (string)file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
        $this->assertStringContainsString('docker run --rm apotheke:ci php -m | grep -qx pcntl', $ci);
    }

    /** Hardening (SEC-M3): compose publishes the app on localhost only, and the image needs sign-in to start. */
    public function testEdgeImageChecksCurlForR2Storage(): void
    {
        // STORAGE_BACKEND=r2 needs ext-curl (StorageFactory); the edge image fails its build without it.
        $docker = (string)file_get_contents(dirname(__DIR__, 2) . '/Dockerfile.frankenphp');
        $this->assertMatchesRegularExpression('/^\s*php -m \| grep -qx curl;/m', $docker);
    }

    public function testComposeBindsLocalhostAndPassesTheSignInVariables(): void
    {
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml');
        $this->assertSame(1, preg_match('/^services:\n  apotheke:\n(?:    .*\n|\s*\n)*?    ports:\n((?:      .*\n)+)/m', $compose, $m), $compose);
        preg_match_all('/^      - "([^"]+)"$/m', $m[1], $ports);
        $this->assertSame(['127.0.0.1:9091:9091'], $ports[1], 'the only published port is bound to 127.0.0.1');
        $this->assertStringContainsString('      APP_PASSWORD: ${APP_PASSWORD:-}', $compose);
        $this->assertStringContainsString('      ALLOW_NO_LOGIN: ${ALLOW_NO_LOGIN:-}', $compose);
    }

    /** Both images run the background jobs worker as www-data, on unless APOTHEKE_JOBS_WORKER is 0/false/no. */
    public function testBothImagesRunTheJobsWorker(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['Dockerfile', 'Dockerfile.frankenphp'] as $f) {
            $docker = (string)file_get_contents("$root/$f");
            $this->assertStringContainsString("COPY docker/jobs-worker.sh /usr/local/bin/apotheke-jobs-worker\n", $docker, $f);
            $this->assertMatchesRegularExpression('/^RUN chmod [^\n]*\/usr\/local\/bin\/apotheke-jobs-worker$/m', $docker, $f);
            $this->assertMatchesRegularExpression('/apk add --no-cache [^\n]*\bsu-exec\b/', $docker, $f);
        }
        foreach (['docker/entrypoint.sh', 'docker/frankenphp/entrypoint.sh'] as $f) {
            $sh = (string)file_get_contents("$root/$f");
            $this->assertStringContainsString('case "${APOTHEKE_JOBS_WORKER:-1}" in', $sh, $f);
            $this->assertStringContainsString('  1|true|yes) jobs_worker=1 ;;', $sh, $f);
            $this->assertStringContainsString('  0|false|no) jobs_worker=0 ;;', $sh, $f);
            $this->assertMatchesRegularExpression('/su-exec www-data (apotheke-jobs-worker|"\$@")/', $sh, $f);
            $this->assertMatchesRegularExpression('/kill -TERM "\$jobs"/', $sh, $f);
            // L-1470: the trap is in place before anything starts, and the flag is checked again once the PIDs are set
            $trap = strpos($sh, 'trap stop TERM INT QUIT');
            $this->assertNotFalse($trap, $f);
            $first = strpos($sh, 'if [ "$stopping" = 0 ]; then ', (int)strpos($sh, 'PORT="${PORT:-9091}"'));
            $this->assertNotFalse($first, "$f: the flag is checked before the first child");
            $this->assertLessThan($first, $trap, "$f: trap before the first child");
            $this->assertStringContainsString('if [ "$stopping" = 1 ]; then stop; fi', $sh, $f);
            // every child of the supervisor starts behind the flag (no `&` outside `if [ "$stopping" = 0 ]`)
            foreach (preg_split('/\n/', substr($sh, $trap)) as $line) {
                if (preg_match('/ & /', $line) && !str_contains($line, 'sleep 1 & wait $!')) {
                    $this->assertMatchesRegularExpression('/^\s*(if \[ "\$stopping" = 0 \]; then |if \[ "\$\(id -u\)" = 0 \]; then su-exec)/', $line, "$f: $line");
                }
            }
            // a signal lost right after a fork is sent again until the process has exited
            $this->assertMatchesRegularExpression('/while kill -0 "\$[12]" 2>\/dev\/null; do kill (-TERM|"-\$1") "\$[12]" 2>\/dev\/null \|\| true; sleep 0\.2; done/', $sh, $f);
            // L-1472: the web server's own exit status is the container's
            $this->assertStringContainsString('exited unexpectedly (exit $code), stopping', $sh, $f);
            // compose keeps PostgreSQL's files in ./data/postgres, inside the app's /data: never chowned by the app
            $this->assertStringContainsString('find "$DATA_DIR" -path "$DATA_DIR/postgres" -prune -o \! -user www-data -exec chown -h www-data:www-data {} +', $sh, $f);
        }
        $worker = (string)file_get_contents("$root/docker/jobs-worker.sh");
        $this->assertStringContainsString('spawn php spark --no-header jobs:work', $worker);
        $this->assertStringContainsString('spawn sleep "$delay"', $worker);
        // L-1470: a stop that came before child=$! is passed on right after it
        $this->assertStringContainsString("  if [ \"\$stopping\" = 1 ]; then return 0; fi\n  \"\$@\" &\n  child=\$!\n  if [ \"\$stopping\" = 1 ]; then kill -TERM \"\$child\" 2>/dev/null || true; fi\n", $worker);
        // after a stop the child gets TERM again until it has exited (one sent before its exec is lost)
        $this->assertStringContainsString("    if [ \"\$stopping\" = 1 ]; then\n      kill -TERM \"\$child\" 2>/dev/null || true\n      sleep 0.2\n", $worker);
        $this->assertStringContainsString('trap stop TERM INT', $worker);
        $this->assertStringContainsString('if [ "$delay" -gt 60 ]; then delay=60; fi', $worker);
        $this->assertDoesNotMatchRegularExpression('/\benv\b|printenv|set -x|APP_PASSWORD|APP_KEY|DB_PASSWORD/', $worker, 'never prints the environment');
        $compose = (string)file_get_contents("$root/docker-compose.yml");
        $this->assertStringContainsString('      APOTHEKE_JOBS_WORKER: ${APOTHEKE_JOBS_WORKER:-1}', $compose);
        // the Cloudflare container runs no worker (jobs will run through a Cron Trigger or Queues there)
        $this->assertStringContainsString('"APOTHEKE_JOBS_WORKER": "0"', (string)file_get_contents("$root/edge/wrangler.jsonc"));
        $this->assertStringContainsString('APOTHEKE_JOBS_WORKER: this.env.APOTHEKE_JOBS_WORKER ?? "0",', (string)file_get_contents("$root/edge/src/index.ts"));
    }

    public function testEntrypointRefusesToStartWithSignInOff(): void
    {
        $sh = (string)file_get_contents(dirname(__DIR__, 2) . '/docker/entrypoint.sh');
        $check = strpos($sh, "bin/settings.php auth status");
        $this->assertNotFalse($check);
        $this->assertLessThan(strpos($sh, 'php-fpm -F &'), $check, 'checked before php-fpm starts');
        $this->assertGreaterThan(strpos($sh, 'exec "$@"'), $check, 'other commands (recovery) still run');
        $this->assertStringContainsString('exit 78', $sh);
        // no answer from the database fails closed too (#75 QA L-1)
        $this->assertMatchesRegularExpression('/^\s*if \[ "\$rc" != 0 \]; then echo "apotheke: could not read the sign-in setting[^\n]*exit 78; fi$/m', $sh);
        $this->assertStringContainsString('su -p -s /bin/sh www-data', $sh);
    }

    /**
     * The image serves public/ only on PHP 8.4, after the sign-in check (#75 port); APOTHEKE_APP=legacy is refused.
     * The packages, writable dir and .env from a checkout never reach the image.
     */
    public function testServesPublicAfterTheSignInCheck(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = (string)file_get_contents($root . '/docker/entrypoint.sh');
        $this->assertStringContainsString('        root __ROOT__;', (string)file_get_contents($root . '/docker/nginx.conf'));
        $this->assertStringContainsString('-e "s#__ROOT__#$ROOT#g"', $sh);
        $this->assertSame(1, preg_match('/^  legacy\) echo "APOTHEKE_APP=legacy: [^\n]*exit 64 ;;$/m', $sh), $sh);
        $this->assertLessThan(strpos($sh, 'bin/settings.php auth status'), strpos($sh, 'ROOT=/var/www/app/public'), 'the root is set before the sign-in check');
        $ignore = (string)file_get_contents($root . '/.dockerignore');
        foreach (['vendor/', 'writable/', '.env'] as $line) {
            $this->assertStringContainsString("\n{$line}\n", "\n{$ignore}", $line);
        }
        $this->assertStringNotContainsString('APOTHEKE_APP', $this->dockerfile());
        $this->assertTrue(version_compare((string)$this->pin($this->dockerfile()), '8.4.0', '>='), 'the shipped image runs CI4');
    }

    /** L-430: root never follows a link in the www-data-owned data dir, and nginx keeps its temp files off /tmp. */
    public function testRootWritesInTheDataDirDoNotFollowLinks(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = (string)file_get_contents($root . '/docker/entrypoint.sh');
        $mkdir = strpos($sh, 'mkdir -p "$DATA_DIR/images"');
        $this->assertNotFalse($mkdir);
        $this->assertLessThan($mkdir, strpos($sh, 'if [ -L "$DATA_DIR/$d" ]; then'), 'links are refused before mkdir');
        $this->assertStringContainsString('for d in images thumbs originals originals/map sessions; do', $sh);
        $this->assertStringContainsString('-exec chown -h www-data:www-data {} +', $sh);
        $this->assertStringContainsString("mark='cd \"\$1\" && if [ -L .ci4 ] || [ ! -e .ci4 ]; then\n", $sh);
        $this->assertStringContainsString("\nif [ \"\$(id -u)\" = 0 ]; then su -s /bin/sh www-data -c \"\$mark\" sh \"\$DATA_DIR/sessions\"; else", $sh);
        $this->assertDoesNotMatchRegularExpression('/(touch|chown|chmod|mkdir)[^\n]*\.ci4/', $sh);
        $nginx = (string)file_get_contents($root . '/docker/nginx.conf');
        $this->assertStringNotContainsString('/tmp/', $nginx . $sh);
        $this->assertStringContainsString('client_body_temp_path /run/apotheke-nginx/body;', $nginx);
        $this->assertStringContainsString('fastcgi_temp_path /run/apotheke-nginx/fastcgi;', $nginx);
    }

    /** /run is often a fresh mount at runtime; the FrankenPHP entrypoint recreates Caddy's dirs before it starts. */
    public function testFrankenphpEntrypointRecreatesRuntimeDirs(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = (string)file_get_contents($root . '/docker/frankenphp/entrypoint.sh');
        $docker = (string)file_get_contents($root . '/Dockerfile.frankenphp');
        $mkdir = strpos($sh, 'mkdir -p /run/apotheke-caddy/config /run/apotheke-caddy/data');
        $this->assertNotFalse($mkdir);
        $validate = strpos($sh, 'frankenphp validate --config /etc/apotheke/Caddyfile');
        $run = strpos($sh, 'as_www frankenphp run');
        $this->assertNotFalse($validate);
        $this->assertNotFalse($run);
        $this->assertLessThan($validate, $mkdir, 'directories exist before frankenphp validate');
        $this->assertLessThan($run, $mkdir, 'directories exist before FrankenPHP starts');
        $this->assertStringContainsString('if [ "$(id -u)" = 0 ]; then', $sh);
        $chown = strpos($sh, 'chown -R www-data:www-data /run/apotheke-caddy');
        $this->assertNotFalse($chown);
        $this->assertGreaterThan($mkdir, $chown);
        $this->assertStringContainsString('chmod 0750 /run/apotheke-caddy /run/apotheke-caddy/config /run/apotheke-caddy/data', $sh);
        // the image still creates the directories for a persistent /run and --user www-data
        $this->assertStringContainsString('/run/apotheke-caddy/config /run/apotheke-caddy/data', $docker);
        $this->assertStringContainsString('chown -R www-data:www-data /var/www/app/writable /run/apotheke-caddy /data;', $docker);
        $this->assertDoesNotMatchRegularExpression('/^USER /m', $docker, 'the entrypoint starts as root so it can create /run');
    }

    /** Self-host (flag unset) must drop a client-supplied X-Apotheke-Client-IP before PHP. */
    public function testCaddyDropsTheEdgeClientIpHeaderUnlessTheWorkerSetTheFlag(): void
    {
        $caddy = (string)file_get_contents(dirname(__DIR__, 2) . '/docker/frankenphp/Caddyfile');
        // The placeholder must be quoted: an unquoted default expands to CEL 0 != "1" (int/string).
        $this->assertStringContainsString('"{$APOTHEKE_EDGE_CLIENT_IP:0}" != "1"', $caddy);
        $this->assertDoesNotMatchRegularExpression('/expression `\{\$APOTHEKE_EDGE_CLIENT_IP:0\} != "1"`/', $caddy);
        $this->assertStringContainsString('request_header @drop_edge_client_ip -X-Apotheke-Client-IP', $caddy);
        $this->assertDoesNotMatchRegularExpression(
            '/header_up X-Apotheke-Client-IP \{http\.request\.header/',
            $caddy,
            'php_hop must not copy a client value the way it copies X-Forwarded-*',
        );
    }

    /** Runs frankenphp validate when the binary is on PATH; skipped otherwise. */
    public function testFrankenphpValidatesTheCaddyfileWithAndWithoutTheEdgeClientIpFlag(): void
    {
        $bin = $this->frankenphpBinary();
        if ($bin === null) {
            $this->markTestSkipped('frankenphp is not on PATH');
        }
        $caddy = dirname(__DIR__, 2) . '/docker/frankenphp/Caddyfile';
        foreach (['1', '0', null] as $flag) {
            $env = [
                'PORT' => '9091',
                'FRANKENPHP_NUM_THREADS' => '2',
                'PATH' => (string)getenv('PATH'),
                'HOME' => (string)getenv('HOME'),
            ];
            if ($flag !== null) {
                $env['APOTHEKE_EDGE_CLIENT_IP'] = $flag;
            }
            $proc = proc_open(
                [$bin, 'validate', '--config', $caddy, '--adapter', 'caddyfile'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env,
            );
            $this->assertIsResource($proc, 'flag ' . ($flag ?? 'unset'));
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $this->assertSame(0, $code, 'validate failed for APOTHEKE_EDGE_CLIENT_IP=' . ($flag ?? '(unset)') . "\n" . $stdout . $stderr);
        }
    }

    public function testNginx421HasTheAppsText(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame(1, preg_match('/REFUSAL = "(Unknown host name\.[^"]*)\\\\n";/', (string)file_get_contents($root . '/modules/Core/Http/HostAllowList.php'), $app));
        $this->assertStringContainsString('return 421 "' . $app[1] . '\n";', (string)file_get_contents($root . '/docker/nginx.conf'), '#73 QA N-2');
    }

    /** Absolute path to a frankenphp binary on PATH, or null. */
    private function frankenphpBinary(): ?string
    {
        $path = getenv('PATH') ?: '';
        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            $candidate = $dir . DIRECTORY_SEPARATOR . 'frankenphp';
            if ($dir !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function dockerfile(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
    }

    /** The pinned X.Y.Z, or null when the CI pin assert would fail. Mirrors the CI step. */
    private function pin(string $docker): ?string
    {
        if (!preg_match_all('/^(?i:ARG) PHP_VERSION=(\d+\.\d+\.\d+)$/m', $docker, $m) || count($m[1]) !== 1) return null;
        preg_match_all('/^(?i:FROM) php:.*$/m', $docker, $froms);
        if (count($froms[0]) !== 1) return null;
        if (preg_match('/^(?i:FROM) php:\$\{PHP_VERSION\}-fpm-alpine$/', $froms[0][0]) !== 1) return null;
        return $m[1][0];
    }
}
