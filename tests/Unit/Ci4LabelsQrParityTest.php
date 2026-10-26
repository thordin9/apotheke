<?php

declare(strict_types=1);

use Apotheke\Labels\QrCode as Ci4QrCode;
use Apotheke\Labels\QrJit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Labels/QrJit.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Labels/QrCode.php';

/**
 * Golden (PLAN step 13): CI4's QR encoder (Labels\QrCode, QrJit) gives the
 * same SVG as legacy qr_svg() byte for byte, for every ECC level, versions 1
 * to 40, UTF-8, markup in the title, and seeded random input; the SVG decodes
 * back to the data with a real decoder (zbarimg) and carries nothing but the
 * QR code; the JIT guard classifies every mode as legacy does.
 */
final class Ci4LabelsQrParityTest extends TestCase
{
    /** @return array<string,array{0:string,1:string,2:string}> data, title, ECC level */
    public static function corpus(): array
    {
        $cases = [
            'item url' => ['https://apotheke.example.org/item/123456', 'QR code for Drill', 'M'],
            'location url' => ['http://192.168.1.10:9091/?loc=12', 'QR code for Garage › Shelf 2', 'M'],
            'single char' => ['x', '', 'L'],
            'utf-8' => ['Κουτί 5 – box', 'Κουτί', 'H'],
            'markup in the title' => ['http://h/item/1', 'QR code for <b>"Lamp" & \'co\'</b>', 'Q'],
            'markup in the data' => ['<svg onload=alert(1)>"&\'', '', 'M'],
            'invalid utf-8 title' => ['http://h/item/2', "bad \xC3\x28 title", 'M'],
            'version 7+' => [str_repeat('A', 160), 'x', 'L'],
            'version 10+' => [str_repeat('Apotheke!', 30), '', 'M'],
            'version 40' => [str_repeat('a', 2331), '', 'M'],
            'empty' => ['', '', 'M'],
        ];
        mt_srand(1312);
        for ($i = 0; $i < 40; $i++) {
            $s = '';
            for ($j = 0, $n = mt_rand(1, 300); $j < $n; $j++) {
                $s .= chr(mt_rand(0, 255));
            }
            $cases['random ' . $i] = [$s, 'QR code for ' . substr($s, 0, 20), ['L', 'M', 'Q', 'H'][$i % 4]];
        }

        return $cases;
    }

    #[DataProvider('corpus')]
    public function testSvgEqualsLegacy(string $data, string $title, string $ecl): void
    {
        $this->assertSame(qr_svg($data, $title, $ecl), QrJit::svg($data, $title, $ecl));
        $legacy = new QrCode($data, $ecl);
        $ci4 = new Ci4QrCode($data, $ecl);
        $this->assertSame([$legacy->version, $legacy->size, $legacy->mask], [$ci4->version, $ci4->size, $ci4->mask]);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function payloads(): array
    {
        return [
            'item url' => ['https://apotheke.example.org/item/42', 'M'],
            'location url' => ['http://192.168.1.10:9091/?loc=12', 'M'],
            'utf-8' => ['Κουτί 5 – box', 'H'],
            'version 10+' => [str_repeat('Apotheke!', 30), 'Q'],
        ];
    }

    #[DataProvider('payloads')]
    public function testRoundTripsThroughARealDecoder(string $data, string $ecl): void
    {
        if (trim((string)shell_exec('command -v zbarimg 2>/dev/null')) === '') {
            $this->markTestSkipped('zbarimg not installed');
        }
        $svg = QrJit::svg($data, 'QR code', $ecl);
        $this->assertSame(1, preg_match('/viewBox="0 0 (\d+) \d+"/', $svg, $vb));
        $this->assertSame(1, preg_match('/<path fill="#000" d="([^"]*)"/', $svg, $pm));
        $n = (int)$vb[1];
        $im = imagecreatetruecolor($n * 6, $n * 6);
        imagefill($im, 0, 0, (int)imagecolorallocate($im, 255, 255, 255));
        $black = (int)imagecolorallocate($im, 0, 0, 0);
        preg_match_all('/M(\d+),(\d+)/', $pm[1], $mm, PREG_SET_ORDER);
        foreach ($mm as [, $x, $y]) {
            imagefilledrectangle($im, (int)$x * 6, (int)$y * 6, ((int)$x + 1) * 6 - 1, ((int)$y + 1) * 6 - 1, $black);
        }
        $dir = tmpdir('qr-ci4');
        try {
            imagepng($im, $dir . '/qr.png');
            $out = (string)shell_exec('zbarimg -q --raw ' . escapeshellarg($dir . '/qr.png') . ' 2>/dev/null');
        } finally {
            rrmdir($dir);
        }
        $this->assertSame($data, rtrim($out, "\n"));
    }

    public function testSvgIsOnlyTheCode(): void
    {
        $svg = QrJit::svg('https://h/item/1', 'QR code for <script>alert(1)</script>" onload="x');
        $this->assertStringStartsWith('<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ', $svg);
        $this->assertStringContainsString('aria-label="QR code for &lt;script&gt;alert(1)&lt;/script&gt;&quot; onload=&quot;x"', $svg);
        $this->assertStringNotContainsString('<script', $svg);
        $this->assertSame(1, preg_match('#^<svg [^<>]+><rect width="100%" height="100%" fill="\#fff"/><path fill="\#000" d="[Mhvz0-9,\-]+"/></svg>$#D', $svg), 'one rect, one path, no other element, attribute or style');
    }

    public function testBadInputIsRefusedAsOnLegacy(): void
    {
        foreach ([[str_repeat('a', 3000), 'M'], ['a', 'X']] as [$data, $ecl]) {
            $legacy = $ci4 = null;
            try {
                new QrCode($data, $ecl);
            } catch (InvalidArgumentException $e) {
                $legacy = $e->getMessage();
            }
            try {
                new Ci4QrCode($data, $ecl);
            } catch (InvalidArgumentException $e) {
                $ci4 = $e->getMessage();
            }
            $this->assertNotNull($legacy);
            $this->assertSame($legacy, $ci4);
        }
    }

    public function testJitModesAreClassifiedAsOnLegacy(): void
    {
        foreach (['tracing', 'on', 'ON ', 'function', '1254', '0255', '1055', '1235', '1205', '1', '0', 'off', 'disable', '', '12345', 'abcd'] as $jit) {
            foreach ([-1, 0, 3, 5] as $kind) {
                $this->assertSame(qr_jit_is_tracing($jit, $kind), QrJit::isTracing($jit, $kind), $jit . ' ' . $kind);
            }
        }
    }
}
