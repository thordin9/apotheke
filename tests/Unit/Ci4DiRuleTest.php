<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Ci4DiRuleTest extends TestCase
{
    public function testServiceCallOutsideAControllerIsReported(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/vendor/bin/phpstan') || !is_file($root . '/ci4/vendor/autoload.php')) {
            $this->markTestSkipped('phpstan and the CI4 vendor tree are required');
        }
        $cmd = escapeshellarg($root . '/vendor/bin/phpstan')
            . ' analyse -c ' . escapeshellarg($root . '/phpstan-di.neon')
            . ' --no-progress --memory-limit=1G --error-format=raw '
            . escapeshellarg($root . '/ci4/tools/PhpStan/fixtures/BadCall.php')
            . ' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $text = implode("\n", $out);
        $this->assertSame(1, $code, $text);
        $this->assertStringContainsString('service() is only allowed in controllers and Config/Services.php', $text);
        $this->assertStringContainsString('Services:: is only allowed in controllers and Config/Services.php', $text);
    }
}
