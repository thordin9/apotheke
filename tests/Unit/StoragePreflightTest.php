<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

use Apotheke\Core\Boot\StoragePreflight;
use Apotheke\Core\Boot\StorageRefused;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Boot/StoragePreflight.php';

final class StoragePreflightTest extends TestCase
{
    public function testDirInWebRootAgreesWithLegacyIncludingASymlink(): void
    {
        $pre = new StoragePreflight();
        $web = sys_get_temp_dir() . '/apo-web-' . bin2hex(random_bytes(3));
        $outside = sys_get_temp_dir() . '/apo-out-' . bin2hex(random_bytes(3));
        mkdir($web . '/nested', 0775, true);
        mkdir($outside, 0775, true);
        $link = $outside . '/link';
        symlink($web . '/nested', $link);
        $this->assertSame(
            originals_dir_in_web_root($web . '/nested', $web),
            $pre->dirInWebRoot($web . '/nested', $web)
        );
        $this->assertTrue($pre->dirInWebRoot($link, $web));
        $this->assertTrue(originals_dir_in_web_root($link, $web));
        $this->assertFalse($pre->dirInWebRoot($outside, $web));
        $this->assertSame(originals_htaccess(), $pre->htaccess());
    }

    public function testPrepareSetsModesAndDoesNotCreateARefusedDirectory(): void
    {
        $pre = new StoragePreflight();
        $root = sys_get_temp_dir() . '/apo-pre-' . bin2hex(random_bytes(3));
        $web = $root . '/public';
        mkdir($web, 0775, true);
        try {
            $pre->prepare($root . '/data', $web . '/originals', $web);
            $this->fail('expected refusal');
        } catch (StorageRefused $e) {
            $this->assertStringNotContainsString($web, $e->getMessage());
            $this->assertStringContainsString($web, $e->logMessage);
            $this->assertDirectoryDoesNotExist($web . '/originals');
        }
        $data = $root . '/data';
        $pre->prepare($data, $data . '/originals', $web);
        $this->assertSame(0750, fileperms($data . '/originals') & 0777);
        $this->assertSame(0750, fileperms($data . '/originals/map') & 0777);
        $this->assertSame(0640, fileperms($data . '/originals/.htaccess') & 0777);
        $this->assertSame($pre->htaccess(), (string)file_get_contents($data . '/originals/.htaccess'));
    }
}
