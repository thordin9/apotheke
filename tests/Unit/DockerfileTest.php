<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The image base is one full PHP patch tag, read from the Dockerfile.
 * Same rule as the docker job: no trailing space, no CR, and no later floating php stage.
 */
final class DockerfileTest extends TestCase
{
    public function testFromLinePinsAFullPatchTag(): void
    {
        $docker = (string)file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        $this->assertTrue($this->pinOk($docker), $docker);
    }

    public function testPinRejectsTrailingSpaceCrlfAndAFloatingStage(): void
    {
        $docker = (string)file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        $this->assertSame(1, preg_match('/^FROM php:(\d+\.\d+\.\d+)-fpm-alpine$/m', $docker, $m));
        $pin = $m[1];
        $good = "FROM php:{$pin}-fpm-alpine\n";
        $this->assertTrue($this->pinOk($good));
        $this->assertFalse($this->pinOk("FROM php:{$pin}-fpm-alpine \n"));
        $this->assertFalse($this->pinOk("FROM php:{$pin}-fpm-alpine\r\n"));
        $this->assertFalse($this->pinOk($good . "FROM php:8-fpm-alpine\n"));
        $this->assertFalse($this->pinOk($good . "FROM php:fpm-alpine\n"));
        $this->assertFalse($this->pinOk($good . "FROM php:8.3-fpm-alpine\n"));
        $this->assertFalse($this->pinOk($good . $good));
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

    /** One exact X.Y.Z line, and every FROM php line is that line. Mirrors the CI sed. */
    private function pinOk(string $docker): bool
    {
        if (!preg_match_all('/^FROM php:(\d+\.\d+\.\d+)-fpm-alpine$/m', $docker, $m) || count($m[1]) !== 1) return false;
        preg_match_all('/^FROM php:.*$/m', $docker, $froms);
        return $froms[0] === ['FROM php:' . $m[1][0] . '-fpm-alpine'];
    }
}
