<?php
declare(strict_types=1);

/** The generated QR label print stylesheet follows the Control Panel settings. */
final class LabelsCssTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
    }

    public function testDefaults(): void
    {
        $css = labels_css();
        $this->assertStringContainsString('@media print', $css);
        $this->assertStringContainsString('repeat(3,60mm)', $css);
        $this->assertStringContainsString('height:70mm', $css);
        $this->assertStringNotContainsString('border:0', $css);
    }

    public function testCustomGridAndQrFits(): void
    {
        $this->assertSame([], settings_save(['label_width_mm' => '25', 'label_height_mm' => '15', 'label_columns' => '7', 'label_gap_mm' => '0', 'label_border' => '0']));
        settings_cache(null, true);
        $css = labels_css();
        $this->assertStringContainsString('repeat(7,25mm)', $css);
        $this->assertStringContainsString('gap:0mm', $css);
        $this->assertStringContainsString('border:0', $css);
        preg_match('/\.qr\{width:(\d+)mm/', $css, $m);
        $this->assertGreaterThanOrEqual(8, (int)$m[1]);
        $this->assertLessThanOrEqual(15, (int)$m[1]);
    }

    public function testOutOfRangeRejected(): void
    {
        $this->assertArrayHasKey('label_columns', settings_save(['label_columns' => '99', 'label_width_mm' => '50']));
        settings_cache(null, true);
        $this->assertSame(60, (int)setting('label_width_mm'), 'nothing saved when one value is invalid');
    }
}
