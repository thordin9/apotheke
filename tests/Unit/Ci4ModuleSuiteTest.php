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
        $this->assertTrue(self::passed($out), $out);
    }

    /**
     * PHPUnit's summary is a pass: a plain OK, or an "OK, but" whose only
     * issue is skipped tests (#98 N-39: a PostgreSQL-only test skips on a
     * SQLite-only machine). Failures, errors, risky tests, warnings,
     * deprecations, notices and incomplete tests still fail.
     */
    public static function passed(string $out): bool
    {
        if (preg_match('/^OK \(\d+ tests?, \d+ assertions?\)$/m', $out) === 1) {
            return true;
        }

        return preg_match('/^OK, but (?:there were issues|some tests were skipped)!\R(Tests: \d+, Assertions: \d+, Skipped: \d+\.)$/m', $out) === 1;
    }

    /** @return array<string, array{string, bool}> */
    public static function summaries(): array
    {
        return [
            'ok' => ["...\n\nOK (356 tests, 900 assertions)\n", true],
            'skipped only' => ["\nOK, but there were issues!\nTests: 292, Assertions: 800, Skipped: 1.\n", true],
            'skipped only, older wording' => ["\nOK, but some tests were skipped!\nTests: 292, Assertions: 800, Skipped: 1.\n", true],
            'risky' => ["\nOK, but there were issues!\nTests: 292, Assertions: 800, Risky: 1.\n", false],
            'skipped and risky' => ["\nOK, but there were issues!\nTests: 292, Assertions: 800, Skipped: 1, Risky: 1.\n", false],
            'deprecation' => ["\nOK, but there were issues!\nTests: 292, Assertions: 800, Deprecations: 1, Skipped: 1.\n", false],
            'incomplete' => ["\nOK, but there were issues!\nTests: 292, Assertions: 800, Incomplete: 1.\n", false],
            'failure' => ["\nFAILURES!\nTests: 292, Assertions: 800, Failures: 1, Skipped: 1.\n", false],
            'error' => ["\nERRORS!\nTests: 292, Assertions: 800, Errors: 1.\n", false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('summaries')]
    public function testOnlySkippedTestsCountAsAPass(string $out, bool $passed): void
    {
        $this->assertSame($passed, self::passed($out));
    }
}
