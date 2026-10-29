<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** cfg() in the Core module resolves paths from the install root, as it did from src/ before the cutover. */
final class ConfigFunctionsTest extends TestCase
{
    public function testCfgDefaultsToTheInstallRoot(): void
    {
        $root = dirname(__DIR__, 2);
        $code = 'require ' . var_export($root . '/modules/Core/Config/config-functions.php', true) . '; echo json_encode(cfg()["data_dir"]);';
        $env = ['PATH' => (string)getenv('PATH'), 'APOTHEKE_CONFIG' => '/nonexistent'];
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $env);
        $this->assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), $err);
        $this->assertSame($root . '/data', json_decode($out, true));
    }

    public function testCi4LoadsTheLoaderFromTheCoreModule(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['modules/Core/Config/DsnTranslator.php', 'modules/Migrations/PdoMigrationRunner.php', 'modules/ControlPanel/SystemInfo.php'] as $rel) {
            $php = (string)file_get_contents($root . '/' . $rel);
            $this->assertStringContainsString('config-functions.php', $php, $rel);
            $this->assertStringNotContainsString("'/src/", $php, $rel);
        }
    }
}
