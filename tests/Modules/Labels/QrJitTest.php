<?php

declare(strict_types=1);

namespace Tests\Labels;

use Apotheke\Labels\QrCode;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * QrJit::guard() at run time (legacy's runtime check is
 * tests/Unit/QrCodeTest.php): in a PHP with function JIT on, QrJit::svg()
 * turns it off before the encoder is compiled and gives the interpreter's
 * SVG; tracing JIT is left on.
 */
final class QrJitTest extends CIUnitTestCase
{
    public function testSvgTurnsFunctionJitOffAndKeepsTracing(): void
    {
        if (!function_exists('opcache_get_status')) {
            $this->markTestSkipped('opcache not loaded');
        }
        $data = 'http://labels.example/item/42';
        $want = md5((new QrCode($data, 'M'))->svg(4, 'QR code for Drill'));
        foreach (['1235' => 'disable', 'function' => 'disable', 'tracing' => 'tracing'] as $mode => $after) {
            $r = $this->encodeUnder((string)$mode, $data);
            if ($r === null) {
                $this->markTestSkipped("opcache.jit=$mode did not turn on");
            }
            $this->assertSame([$after, $want], [$r['jit'], $r['svg']], "opcache.jit=$mode");
        }
    }

    /** @return array{jit:string,svg:string}|null null when JIT did not turn on */
    private function encodeUnder(string $mode, string $data): ?array
    {
        $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
$st = opcache_get_status(false);
if (empty($st["jit"]["on"])) { fwrite(STDERR, "jit-off"); exit(2); }
$svg = Apotheke\Labels\QrJit::svg(' . var_export($data, true) . ', "QR code for Drill");
echo json_encode(["jit" => (string)ini_get("opcache.jit"), "svg" => md5($svg)]);';
        $proc = proc_open([PHP_BINARY, '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.jit=' . $mode, '-d', 'opcache.jit_buffer_size=64M',
            '-d', 'pcov.enabled=0', '-d', 'max_execution_time=10', '-r', $code], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        $out = (string)stream_get_contents($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit === 2 && str_contains($err, 'jit-off')) {
            return null;
        }
        $this->assertSame(0, $exit, "opcache.jit=$mode: $err");
        /** @var array{jit:string,svg:string} $r */
        $r = json_decode($out, true, 4, JSON_THROW_ON_ERROR);

        return $r;
    }
}
