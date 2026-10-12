<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Runs the end-to-end HTTP suite (tests/integration.php: real `php -S` server, uploads, CSRF,
 * password mode, migrations…) against the configured driver and fails if any check fails.
 */
final class HttpSuiteTest extends TestCase
{
    public function testHttpSuitePasses(): void
    {
        $env = getenv();
        $env['TEST_DB_DRIVER'] = db_driver();
        if (db_driver() === 'pgsql') {
            $db = cfg()['db'];
            $env += ['TEST_PG_HOST' => $db['host'], 'TEST_PG_PORT' => (string)$db['port'], 'TEST_PG_DB' => $db['name'], 'TEST_PG_USER' => $db['user'], 'TEST_PG_PASSWORD' => $db['password']];
        }
        unset($env['DATA_DIR'], $env['DB_SCHEMA']);
        $p = proc_open([PHP_BINARY, dirname(__DIR__) . '/integration.php'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(__DIR__, 2), $env);
        $out = stream_get_contents($pipes[1]);
        $code = proc_close($p);
        $plain = (string)preg_replace('/\e\[[0-9;]*m/', '', (string)$out);
        preg_match_all('/^\s*✘ .*$/mu', $plain, $fails);
        $this->assertSame(0, $code, "integration suite failed:\n" . implode("\n", $fails[0]) . "\n\n" . substr($plain, -3000));
        $this->assertMatchesRegularExpression('/\d+ passed, 0 failed/', $plain);
    }
}
