<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Runs the CI4 module tests (ci4/phpunit.xml.dist, CIUnitTestCase) in their
 * own process, so the main PHPUnit run in CI covers them on both database
 * legs without a workflow change. The CI4 suite needs its own process: it
 * boots CodeIgniter, which the legacy tests in this process must not see.
 */
final class Ci4ModuleSuiteTest extends TestCase
{
    public function testCi4ModuleSuitePasses(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $cmd = [PHP_BINARY, $root . '/vendor/bin/phpunit', '-c', $root . '/ci4/phpunit.xml.dist', '--no-progress', '--colors=never'];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        $this->assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $out);
        $this->assertMatchesRegularExpression('/^OK \(\d+ tests?, \d+ assertions?\)$/m', $out, $out);
    }
}
