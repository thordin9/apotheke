<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Commands\BackupCli;
use Apotheke\Backup\Restorer;
use Apotheke\Media\ImageLimits;
use Apotheke\Core\Exceptions\UserError;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;
use ZipArchive;

/**
 * The Backup module's pure helpers: which zip entries a backup may hold,
 * the summary line, the names the rows refer to, the thumbnail decode
 * limit and the arguments bin/backup.php hands to spark.
 */
final class BackupHelpersTest extends CIUnitTestCase
{
    public function testOnlyTheNamesABackupWritesAreAccepted(): void
    {
        $h32 = str_repeat('a', 32);
        $h64 = str_repeat('0', 64);
        foreach (['backup.json', 'originals/index.json', 'images/', 'thumbs/', 'files/', 'originals/',
            "images/$h32.jpg", "thumbs/$h32.jpg", "files/$h32.pdf", "files/$h32.webp", "originals/$h64.heic", "originals/$h64.gif"] as $ok) {
            $this->assertTrue(Restorer::entryNameOk($ok), $ok);
        }
        foreach (['', '/backup.json', 'backup.json/', './backup.json', '../backup.json', 'app.key', 'config.php',
            'images', "images/../$h32.jpg", "images/$h32.png", "images/{$h32}0.jpg", 'images/' . strtoupper($h32) . '.jpg',
            "images/$h32.jpg\n", "thumbs/sub/$h32.jpg", "files/$h32.exe", "files/$h32.heic", "originals/$h32.jpg",
            "originals/$h64.pdf", "originals/index.json\n", "files\\$h32.pdf", "other/$h32.jpg"] as $bad) {
            $this->assertFalse(Restorer::entryNameOk($bad), json_encode($bad, JSON_THROW_ON_ERROR));
        }
        $this->assertTrue(Restorer::originalNameOk("$h64.png"));
        $this->assertFalse(Restorer::originalNameOk("$h64.png\n"));
        $this->assertFalse(Restorer::originalNameOk("../$h64.png"));
    }

    public function testTheSummaryListsWhatIsThere(): void
    {
        $this->assertSame('3 items, 1 locations, 0 categories, 2 tags, 7 history entries, 4 photos',
            Backups::summaryText(['tags' => 2, 'items' => 3, 'activity' => 7, 'categories' => 0, 'locations' => 1, 'item_photos' => 9], 4));
        $this->assertSame('2 items, 0 photos', Backups::summaryText(['items' => 2], 0));
    }

    public function testPhotoAndAttachmentNamesComeFromTheirColumns(): void
    {
        $a = str_repeat('a', 32);
        $b = str_repeat('b', 32);
        $dump = ['tables' => [
            'items' => ['columns' => ['id', 'image'], 'rows' => [[1, "$b.jpg"], [2, null], [3, '../x.jpg'], 'odd']],
            'item_photos' => ['columns' => ['item_id', 'file'], 'rows' => [[1, "$a.jpg"], [1, "$b.jpg"], [1, 5]]],
            'item_attachments' => ['columns' => ['file', 'item_id'], 'rows' => [["$a.pdf", 1], ["$a.exe", 1], ["$b.png", 2]]],
        ]];
        $this->assertSame(["$a.jpg", "$b.jpg"], Restorer::photoFiles($dump));
        $this->assertSame(["$a.pdf", "$b.png"], Restorer::attachmentFiles($dump));
        $this->assertSame([], Restorer::photoFiles(['tables' => ['items' => ['columns' => ['id'], 'rows' => [[1]]]]]));
        $this->assertSame([], Restorer::attachmentFiles(['tables' => ['item_attachments' => 'odd']]));
    }

    public function testAMissingThumbnailIsDrawnOnlyWithinTheDecodeLimits(): void
    {
        $this->assertFalse(Restorer::overDecodeLimits(4000, 3000));
        $this->assertFalse(Restorer::overDecodeLimits(8000, 5000), 'exactly the GD budget');
        $this->assertTrue(Restorer::overDecodeLimits(8001, 5000));
        $this->assertSame(40_000_000, ImageLimits::GD_MAX_PIXELS);
        $this->assertTrue(Restorer::overDecodeLimits(0, 10));
    }

    public function testTheWrapperArgumentsWinOverArgv(): void
    {
        $this->assertSame(['--help'], BackupCli::args('["--help"]', ['x']));
        $this->assertSame(['a.zip', '--yes', '1', ''], BackupCli::args('["a.zip","--yes",1,[]]', []));
        $this->assertSame([], BackupCli::args('[]', ['x']));
        foreach ([false, '', 'nope', '{"a":"b"}', '"s"'] as $json) {
            $this->assertSame(['x'], BackupCli::args($json, ['x']), var_export($json, true));
        }
    }

    public function testAZipIsRefusedOnItsEntriesBeforeItsContents(): void
    {
        $h32 = str_repeat('a', 32);
        $cases = [
            'not a zip' => [null, 'That file is not a zip archive.'],
            'an unknown name' => [['backup.json' => '{}', 'app.key' => 'x'], 'Unexpected file in backup: app.key'],
            // Stricter than legacy: two entries of one name (made by renaming the second after zipping).
            'a name twice' => [['backup.json' => '{}', "images/$h32.jpg" => 'a', "images/$h32.jpX" => 'b'], "Unexpected file in backup: images/$h32.jpg"],
            'no backup.json' => [["images/$h32.jpg" => 'a'], 'This is not an Apotheke backup (backup.json is missing).'],
        ];
        $restorer = (new ReflectionClass(Restorer::class))->newInstanceWithoutConstructor();
        foreach ($cases as $label => [$entries, $message]) {
            $path = tempnam(sys_get_temp_dir(), 'apotheke-backup-test-');
            $this->assertIsString($path);
            try {
                if ($entries === null) {
                    file_put_contents($path, 'PK not really');
                } else {
                    $zip = new ZipArchive();
                    $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
                    foreach ($entries as $name => $data) {
                        $zip->addFromString($name, $data);
                    }
                    $zip->close();
                    file_put_contents($path, str_replace("images/$h32.jpX", "images/$h32.jpg", (string)file_get_contents($path)));
                }
                try {
                    $restorer->open($path);
                    $this->fail($label . ': accepted');
                } catch (UserError $e) {
                    $this->assertSame($message, $e->getMessage(), $label);
                }
            } finally {
                @unlink($path);
            }
        }
    }
}
