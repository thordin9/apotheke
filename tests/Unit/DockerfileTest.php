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

    /** One exact X.Y.Z line, and every FROM php line is that line. Mirrors the CI sed. */
    private function pinOk(string $docker): bool
    {
        if (!preg_match_all('/^FROM php:(\d+\.\d+\.\d+)-fpm-alpine$/m', $docker, $m) || count($m[1]) !== 1) return false;
        preg_match_all('/^FROM php:.*$/m', $docker, $froms);
        return $froms[0] === ['FROM php:' . $m[1][0] . '-fpm-alpine'];
    }
}
