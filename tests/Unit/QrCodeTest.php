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
}
