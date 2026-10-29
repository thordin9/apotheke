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

    /** Hardening (SEC-M3): compose publishes the app on localhost only, and the image needs sign-in to start. */
    public function testComposeBindsLocalhostAndPassesTheSignInVariables(): void
    {
        $compose = (string)file_get_contents(dirname(__DIR__, 2) . '/docker-compose.yml');
        $this->assertSame(1, preg_match('/^services:\n  apotheke:\n(?:    .*\n|\s*\n)*?    ports:\n((?:      .*\n)+)/m', $compose, $m), $compose);
        preg_match_all('/^      - "([^"]+)"$/m', $m[1], $ports);
        $this->assertSame(['127.0.0.1:9091:9091'], $ports[1], 'the only published port is bound to 127.0.0.1');
        $this->assertStringContainsString('      APP_PASSWORD: ${APP_PASSWORD:-}', $compose);
        $this->assertStringContainsString('      ALLOW_NO_LOGIN: ${ALLOW_NO_LOGIN:-}', $compose);
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
     * PLAN step 15: APOTHEKE_APP=ci4 serves public only, needs PHP 8.4, and goes through the same sign-in check
     * as legacy (#75 port). The CI4 packages, writable dir and .env from a checkout never reach the image.
     */
    public function testCi4ServesItsPublicDirAfterTheSameSignInCheck(): void
    {
        $root = dirname(__DIR__, 2);
        $sh = (string)file_get_contents($root . '/docker/entrypoint.sh');
        $this->assertStringContainsString('        root __ROOT__;', (string)file_get_contents($root . '/docker/nginx.conf'));
        $this->assertStringContainsString('-e "s#__ROOT__#$ROOT#g"', $sh);
        $this->assertSame(1, preg_match('/^  ci4\)\n    ROOT=\/var\/www\/app\/ci4\/public\n    php -r \'exit\(PHP_VERSION_ID >= 80400 \? 0 : 1\);\' \|\| \{[^\n]*exit 64; \}$/m', $sh), $sh);
        $this->assertLessThan(strpos($sh, 'bin/settings.php auth status'), strpos($sh, 'ROOT=/var/www/app/public'), 'the app is chosen before the sign-in check, which runs for both');
        $ignore = (string)file_get_contents($root . '/.dockerignore');
        foreach (['vendor/', 'writable/', '.env'] as $line) {
            $this->assertStringContainsString("\n{$line}\n", "\n{$ignore}", $line);
        }
        $this->assertStringContainsString("ARG APOTHEKE_APP=legacy\n", $this->dockerfile());
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
        $this->assertStringContainsString("  mark='cd \"\$1\" && if [ -L .ci4 ] || [ ! -e .ci4 ]; then\n", $sh);
        $this->assertStringContainsString('  if [ "$(id -u)" = 0 ]; then su -s /bin/sh www-data -c "$mark" sh "$DATA_DIR/sessions"; else', $sh);
        $this->assertDoesNotMatchRegularExpression('/(touch|chown|chmod|mkdir)[^\n]*\.ci4/', $sh);
        $nginx = (string)file_get_contents($root . '/docker/nginx.conf');
        $this->assertStringNotContainsString('/tmp/', $nginx . $sh);
        $this->assertStringContainsString('client_body_temp_path /run/apotheke-nginx/body;', $nginx);
        $this->assertStringContainsString('fastcgi_temp_path /run/apotheke-nginx/fastcgi;', $nginx);
    }

    public function testNginx421HasTheAppsText(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertSame(1, preg_match('/echo "(Unknown host name\.[^"]*)\\\\n";/', (string)file_get_contents($root . '/src/hosts.php'), $app));
        $this->assertStringContainsString('return 421 "' . $app[1] . '\n";', (string)file_get_contents($root . '/docker/nginx.conf'), '#73 QA N-2');
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
