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
