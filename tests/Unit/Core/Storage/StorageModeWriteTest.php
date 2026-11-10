<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use PHPUnit\Framework\TestCase;

/** Storage-mode writes go to the R2 backend, not the data directory. */
final class StorageModeWriteTest extends TestCase
{
    public function testPhotoOriginalAttachmentAndImportWritesStayOffDisk(): void
    {
        $dir = sys_get_temp_dir() . '/storage-mode-disk-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $objects = [];
        $storage = new R2Storage(new R2Bridge(transport: function (string $method, string $url, $body) use (&$objects): array {
            $key = ltrim(rawurldecode((string)parse_url($url, PHP_URL_PATH)), '/');
            if ($method === 'PUT') {
                $bytes = is_resource($body) ? (string)stream_get_contents($body) : (string)$body;
                if (is_resource($body)) {
                    rewind($body);
                }
                $objects[$key] = $bytes;

                return ['status' => 200, 'body' => '{"ok":true}'];
            }
            if ($method === 'GET' || $method === 'HEAD') {
                if (!isset($objects[$key])) {
                    return ['status' => 404, 'body' => ''];
                }
                $payload = ['ok' => true, 'found' => true, 'modified' => 1, 'bytes' => base64_encode($objects[$key])];

                return ['status' => 200, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
            }
            if ($method === 'DELETE') {
                unset($objects[$key]);

                return ['status' => 200, 'body' => '{"ok":true}'];
            }

            return ['status' => 500, 'body' => ''];
        }));

        try {
            $name = str_repeat('ab', 16) . '.jpg';
            $im = imagecreatetruecolor(8, 8);
            $this->assertTrue((new ServedImageWriter($storage))->writePair($im, $name, 8, 8, 80));
            $this->assertTrue((new OriginalStore($storage->forOriginals()))->save($name, 'camera-bytes', 'jpg'));
            $storage->write('files/attach.bin', 'attachment-bytes');
            $storage->write('tmp/import-1.csv', "name\nwidget\n");

            $this->assertSame('attachment-bytes', $storage->read('files/attach.bin'));
            $this->assertSame("name\nwidget\n", $storage->read('tmp/import-1.csv'));
            $this->assertArrayHasKey('images/' . $name, $objects);
            $this->assertArrayHasKey('thumbs/' . $name, $objects);
            $this->assertArrayHasKey('originals/map/' . substr($name, 0, 32), $objects);
            $this->assertSame([], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])));
        } finally {
            @rmdir($dir);
        }
    }
}
