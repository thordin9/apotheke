<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

final class QrCodeTest extends TestCase
{
    public static function payloads(): array
    {
        return [
            'short url'       => ['http://192.168.1.10:9091/?loc=12', 'M'],
            'item url'        => ['https://apotheke.example.org/item/123456', 'Q'],
            'single char'     => ['x', 'L'],
            'utf-8'           => ['Κουτί 5 – box', 'H'],
            'version 10+'     => [str_repeat('Apotheke!', 30), 'M'],
            'version 7+ (L)'  => [str_repeat('A', 160), 'L'],
        ];
    }

    #[DataProvider('payloads')]
    public function testRoundTripsThroughARealDecoder(string $data, string $ecl): void
    {
        $decoded = qr_decode_svg(qr_svg($data, '', $ecl));
        if ($decoded === null) $this->markTestSkipped('zbarimg not installed');
        $this->assertSame($data, $decoded);
    }

    public function testPicksSmallestVersion(): void
    {
        $this->assertSame(1, (new QrCode('hello', 'M'))->version);
        $this->assertSame(21, (new QrCode('hello', 'M'))->size);
        $this->assertSame(2, (new QrCode(str_repeat('a', 20), 'M'))->version, '20 bytes do not fit v1-M (14)');
        $this->assertSame(40, (new QrCode(str_repeat('a', 2331), 'M'))->version);
    }

    public function testFinderPatternsAndTiming(): void
    {
        $q = new QrCode('finder', 'M');
        foreach ([[0, 0], [$q->size - 7, 0], [0, $q->size - 7]] as [$ox, $oy]) {
            $this->assertTrue($q->get($ox, $oy) && $q->get($ox + 6, $oy + 6) && $q->get($ox + 3, $oy + 3), 'finder corners + centre dark');
            $this->assertFalse($q->get($ox + 1, $oy + 1), 'finder ring light');
        }
        for ($i = 8; $i < $q->size - 8; $i++) $this->assertSame($i % 2 === 0, $q->get($i, 6), "timing $i");
        $this->assertTrue($q->get(8, $q->size - 8), 'dark module');
    }

    public function testSvgIsDeterministicAndSelfContained(): void
    {
        $a = qr_svg('same', 'Label');
        $this->assertSame($a, qr_svg('same', 'Label'));
        $this->assertStringStartsWith('<svg class="qr"', $a);
        $this->assertStringContainsString('aria-label="Label"', $a);
        $this->assertStringNotContainsString('<script', $a);
        $this->assertStringNotContainsString('style=', $a, 'no inline styles (CSP)');
    }

    public function testTooLongAndBadLevelRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QrCode(str_repeat('a', 3000), 'M');
    }

    public function testBadEccLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QrCode('a', 'X');
    }

    public function testJitModeClassification(): void
    {
        $this->assertTrue(qr_jit_is_tracing('tracing', 0));
        $this->assertTrue(qr_jit_is_tracing('on', 5));
        $this->assertTrue(qr_jit_is_tracing('1254', 5));
        $this->assertTrue(qr_jit_is_tracing('0255', 5));
        $this->assertTrue(qr_jit_is_tracing('1055', 5));
        $this->assertTrue(qr_jit_is_tracing('1', 5), '"on" is reported as 1; kind 5 is tracing');
        $this->assertFalse(qr_jit_is_tracing('function', 0));
        $this->assertFalse(qr_jit_is_tracing('1235', 3));
        $this->assertFalse(qr_jit_is_tracing('1205', 0));
        $this->assertFalse(qr_jit_is_tracing('1215', 1));
        $this->assertFalse(qr_jit_is_tracing('1225', 2));
        $this->assertFalse(qr_jit_is_tracing('0205', 0));
    }

    /**
     * Function JIT miscompiles the finder loops, which count up from a negative index.
     * On PHP 8.2 and 8.3 the matrix can be silently wrong; on PHP 8.4 that mode can hang.
     * Every function-JIT mode is disabled before the encoder loads. Tracing JIT stays on.
     */
    public function testFunctionJitMatchesInterpreter(): void
    {
        if (!function_exists('opcache_get_status')) $this->markTestSkipped('opcache not loaded');
        $payloads = [
            ['http://127.0.0.1:9091/?loc=1', 'M'],
            ['https://catalog.example/?loc=3', 'Q'],
            [str_repeat('A', 160), 'L'],
            [str_repeat('Apotheke!', 30), 'M'],
        ];
        $interpreter = $this->jitMatrix('disable', $payloads, false);
        foreach (['1235', '1205', '1215', 'function'] as $mode) {
            $jit = $this->jitMatrix($mode, $payloads, true);
            if ($jit === null) $this->markTestSkipped("opcache.jit=$mode did not turn on");
            $this->assertSame('disable', $jit['jit'], "opcache.jit=$mode is disabled before encode");
            $this->assertSame($interpreter['rows'], $jit['rows'], "opcache.jit=$mode matrix");
        }
        foreach ([['tracing', 'tracing'], ['1254', '1254'], ['0255', '0255'], ['on', '1']] as [$mode, $kept]) {
            $jit = $this->jitMatrix($mode, $payloads, true);
            if ($jit === null) $this->markTestSkipped("opcache.jit=$mode did not turn on");
            $this->assertSame($kept, $jit['jit'], "opcache.jit=$mode stays tracing");
            $this->assertSame($interpreter['rows'], $jit['rows'], "opcache.jit=$mode matrix");
        }
    }

    /**
     * @param list<array{0:string,1:string}> $payloads
     * @return array{jit:string,rows:list<string>}|null
     */
    private function jitMatrix(string $mode, array $payloads, bool $requireOn): ?array
    {
        $script = tempnam(sys_get_temp_dir(), 'qrjit');
        $this->assertNotFalse($script);
        $root = dirname(__DIR__, 2);
        file_put_contents($script, '<?php
$want = ' . var_export($mode, true) . ';
$requireOn = ' . ($requireOn ? 'true' : 'false') . ';
if ($requireOn) {
    $st = opcache_get_status(false);
    $ini = (string)ini_get("opcache.jit");
    $alive = !empty($st["jit"]["on"]) && ($ini === $want || ($want === "on" && $ini === "1"));
    if (!$alive) {
        fwrite(STDERR, "jit-off\n");
        exit(2);
    }
}
require ' . var_export($root . '/src/qr.php', true) . ';
$payloads = ' . var_export($payloads, true) . ';
$out = [];
foreach ($payloads as [$data, $ecl]) {
    $q = new QrCode($data, $ecl);
    $bits = "";
    for ($y = 0; $y < $q->size; $y++) for ($x = 0; $x < $q->size; $x++) $bits .= $q->get($x, $y) ? "1" : "0";
    $out[] = $q->version . ":" . $q->mask . ":" . md5($bits);
}
echo json_encode(["jit" => (string)ini_get("opcache.jit"), "rows" => $out]);
');
        $cmd = [
            PHP_BINARY,
            '-d', 'opcache.enable=1',
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.jit=' . $mode,
            '-d', 'opcache.jit_buffer_size=64M',
            '-d', 'pcov.enabled=0',
            '-d', 'max_execution_time=5',
            $script,
        ];
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + 8;
        while (microtime(true) < $deadline) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) break;
            usleep(20000);
        }
        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            @unlink($script);
            $this->fail("opcache.jit=$mode did not finish within 8s");
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($script);
        if ($code === 2 && str_contains($stderr, 'jit-off')) return null;
        $this->assertSame(0, $code, "opcache.jit=$mode exited $code\n$stderr");
        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }
}
