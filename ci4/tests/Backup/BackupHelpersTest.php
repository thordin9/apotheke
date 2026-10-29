<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Commands\BackupCli;
use Apotheke\Backup\Commands\DataDirOwner;
use Apotheke\Backup\JsonReader;
use Apotheke\Backup\Restorer;
use Apotheke\Media\ImageLimits;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
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
    /** Not root, the restore runs as it is, whoever owns DATA_DIR (the root cases run under sudo in Ci4BackupRestoreChecksTest). */
    public function testTheDataDirOwnerCheckLetsANonRootUserThrough(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('runs as a non-root user');
        }
        $ids = [posix_getuid(), posix_geteuid(), posix_getgid(), posix_getegid()];
        $err = fopen('php://memory', 'w+b');
        $this->assertTrue(DataDirOwner::become('/', $err), 'a folder root owns');
        $this->assertTrue(DataDirOwner::become('/nonexistent-' . bin2hex(random_bytes(4)), $err), 'a missing folder');
        rewind($err);
        $this->assertSame('', stream_get_contents($err));
        $this->assertSame($ids, [posix_getuid(), posix_geteuid(), posix_getgid(), posix_getegid()], 'the ids are unchanged');
    }

    /** L-622: each data command's message suggests that tool's own command, as legacy does. */
    public function testEachDataCommandNamesItsOwnToolInTheMessage(): void
    {
        $this->assertSame(['apotheke:restore', 'apotheke:backup', 'apotheke:migrate', 'apotheke:settings', 'apotheke:accounts'], array_keys(DataDirOwner::COMMANDS));
        $this->assertSame('php bin/restore.php backup.zip', DataDirOwner::COMMANDS['apotheke:restore']);
        $this->assertSame('php bin/backup.php', DataDirOwner::COMMANDS['apotheke:backup']);
    }

    /**
     * N-520: where the file system has no hard links (link() always fails
     * here), the lock is made with 'x' in place: a plain file, locked, with
     * no temp name left.
     */
    public function testWithoutHardLinksTheLockIsMadeInPlace(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-nolink-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $lock = $dir . '/' . Restorer::LOCK_FILE;
        try {
            $calls = 0;
            $fh = Restorer::openLock($lock, null, static function () use (&$calls): bool {
                $calls++;

                return false;
            });
            $this->assertSame(1, $calls, 'link() was tried');
            $this->assertTrue(Restorer::plainFile(lstat($lock)));
            $this->assertSame([Restorer::LOCK_FILE], array_values(array_diff(scandir($dir) ?: [], ['.', '..'])), 'no temp name is left');
            $other = fopen($lock, 'r');
            $this->assertFalse(flock($other, LOCK_EX | LOCK_NB), 'it is held');
            fclose($other);
            fclose($fh);
        } finally {
            array_map('unlink', glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: []);
            @rmdir($dir);
        }
    }

    /**
     * N-521: when the lock's name is gone again between making and opening
     * it, the restore says why (LOCK_REFUSED), not the general message.
     */
    public function testALockNameGoneBeforeTheOpenSaysWhy(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-gone-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $lock = $dir . '/' . Restorer::LOCK_FILE;
        try {
            $fh = Restorer::openLock($lock, null, static fn (string $target, string $link): bool => link($target, $link) && unlink($link));
            fclose($fh);
            $this->fail('a lock was taken with nothing at its name');
        } catch (UserError $e) {
            $this->assertSame(Restorer::LOCK_REFUSED, $e->getMessage());
        } finally {
            array_map('unlink', glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: []);
            @rmdir($dir);
        }
    }

    /**
     * N-562, N-581: the name is looked up afresh before the open. A link
     * that pointed nowhere when one restore opened the name (refused) is
     * not remembered, nor where a link it resolved led, so a plain file
     * another process puts there next is the one locked.
     */
    public function testALinkSeenAtTheOpenIsNotRememberedForTheNextLock(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-fresh-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        $lock = $dir . '/' . Restorer::LOCK_FILE;
        $target = $dir . '/elsewhere';
        try {
            try {
                fclose(Restorer::openLock($lock, static function () use ($lock, $target): void {
                    symlink($target, $lock);
                }));
                $this->fail('a link at the open was not refused');
            } catch (UserError $e) {
                $this->assertSame(Restorer::LOCK_REFUSED, $e->getMessage());
            }
            $this->assertFileDoesNotExist($target);
            // Another process swaps the name (PHP's own unlink(), touch() and symlink() would clear its
            // caches): first a link to a file, which this process resolves, then the plain file.
            $sh = static fn (string $cmd): string => (string)shell_exec($cmd . ' 2>&1');
            $this->assertSame('', $sh('rm -f ' . escapeshellarg($lock) . ' && touch ' . escapeshellarg($target) . ' && ln -s ' . escapeshellarg($target) . ' ' . escapeshellarg($lock)));
            $this->assertSame(realpath($target), realpath($lock));
            $this->assertSame('', $sh('rm -f ' . escapeshellarg($lock) . ' && touch ' . escapeshellarg($lock)));
            $fh = Restorer::openLock($lock);
            clearstatcache();
            $this->assertSame(fstat($fh)['ino'], lstat($lock)['ino'], 'the plain file now at the name is the one locked');
            fclose($fh);
        } finally {
            array_map('unlink', glob($dir . '/{,.}[!.]*', GLOB_BRACE) ?: []);
            @rmdir($dir);
        }
    }

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
            "originals/$h64.pdf", "originals/index.json\n", "files\\$h32.pdf", "other/$h32.jpg",
            // N-444: every rule is anchored at its end.
            "files/$h32.pdf.php", "files/$h32.pdfx", "images/$h32.jpg.php", "thumbs/$h32.jpgx", "originals/$h64.png.php", 'images/x', "files/$h32.pdf\n"] as $bad) {
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
        $this->assertSame(['items' => 'image', 'item_photos' => 'file', 'item_attachments' => 'file'], Restorer::FILE_COLUMNS);
        $this->assertSame("$a.jpg", Restorer::fileName('items', "$a.jpg"));
        $this->assertSame("$a.jpg", Restorer::fileName('item_photos', "$a.jpg"));
        $this->assertSame("$a.pdf", Restorer::fileName('item_attachments', "$a.pdf"));
        $this->assertNull(Restorer::fileName('item_photos', "$a.pdf"), 'a photo is a .jpg');
        $this->assertNull(Restorer::fileName('item_attachments', "$a.exe"));
        foreach ([null, 5, '../x.jpg', ["$a.jpg"]] as $v) {
            $this->assertNull(Restorer::fileName('items', $v), var_export($v, true));
        }
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
            // Entries that say they unpack to 3 × 4 GB - 1 (the central directory's sizes, patched after zipping).
            'too large' => [['backup.json' => '{}', "images/$h32.jpg" => 'a', 'images/' . str_repeat('b', 32) . '.jpg' => 'b'], 'The backup is too large to unpack.'],
            'too many files' => ['many', 'The backup has too many files.'],
            // N-442: one entry that says it unpacks to more than MAX_ENTRY (backup.json may).
            'one too large' => [['backup.json' => '{}', "images/$h32.jpg" => 'a'], "A file in the backup is too large to restore: images/$h32.jpg"],
        ];
        $restorer = (new ReflectionClass(Restorer::class))->newInstanceWithoutConstructor();
        foreach ($cases as $label => [$entries, $message]) {
            $path = tempnam(sys_get_temp_dir(), 'apotheke-backup-test-');
            $this->assertIsString($path);
            try {
                if ($entries === null) {
                    file_put_contents($path, 'PK not really');
                } elseif ($entries === 'many') {
                    $zip = new ZipArchive();
                    $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
                    for ($i = 0; $i <= Restorer::MAX_ENTRIES; $i++) {
                        $zip->addEmptyDir('d' . $i);
                    }
                    $zip->close();
                } else {
                    $zip = new ZipArchive();
                    $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE));
                    foreach ($entries as $name => $data) {
                        $zip->addFromString($name, $data);
                    }
                    $zip->close();
                    $bytes = str_replace("images/$h32.jpX", "images/$h32.jpg", (string)file_get_contents($path));
                    if ($label === 'too large' || $label === 'one too large') {
                        $size = $label === 'too large' ? "\xff\xff\xff\xff" : pack('V', Restorer::MAX_ENTRY + 1);
                        for ($at = strpos($bytes, "PK\x01\x02"); $at !== false; $at = strpos($bytes, "PK\x01\x02", $at + 4)) {
                            $bytes = substr_replace($bytes, $size, $at + 24, 4);
                        }
                    }
                    file_put_contents($path, $bytes);
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

    public function testTheMemoryEstimateCoversWhatDecodingTakes(): void
    {
        $shapes = [
            'pairs of integers' => array_map(static fn (int $i): array => [$i, $i + 1], range(1, 20000)),
            'single values' => array_map(static fn (int $i): array => [$i], range(1, 20000)),
            'short strings' => array_map(static fn (int $i): array => ['a', 'b', 'c'], range(1, 20000)),
            'long notes' => array_map(static fn (int $i): array => [$i, "Item $i", str_repeat('n', 2000), null], range(1, 2000)),
            'objects' => array_map(static fn (int $i): array => ['k' => $i], range(1, 20000)),
        ];
        foreach ($shapes as $label => $rows) {
            $json = (string)json_encode(['tables' => ['items' => ['columns' => ['a', 'b'], 'rows' => $rows]]]);
            unset($rows);
            gc_collect_cycles();
            $before = memory_get_usage();
            $decoded = json_decode($json, true);
            $used = memory_get_usage() - $before;
            unset($decoded);
            $this->assertGreaterThanOrEqual($used, JsonReader::decodedSize($json), $label);
            $this->assertLessThan(4 * $used, JsonReader::decodedSize($json), $label . ': not far above');
        }
        $this->assertSame(5 + 48 + 200, JsonReader::decodedSize('[1,2]'));
        $this->assertSame(7 + 450, JsonReader::decodedSize('{"a":1}'));
    }

    public function testTheMemoryCheckKeepsAMargin(): void
    {
        $margin = 4 * 1024 * 1024;
        $this->assertTrue(Restorer::fits(PHP_INT_MAX - $margin, null), 'no memory limit');
        $this->assertTrue(Restorer::fits(1000, 1000 + $margin));
        $this->assertFalse(Restorer::fits(1001, 1000 + $margin));
        $this->assertFalse(Restorer::fits(0, $margin - 1));
        $limit = ini_get('memory_limit');
        try {
            ini_set('memory_limit', '-1');
            $this->assertNull(Restorer::headroom());
            ini_set('memory_limit', '512M');
            $this->assertSame(512 * 1024 * 1024 - memory_get_usage(), Restorer::headroom());
        } finally {
            ini_set('memory_limit', (string)$limit);
        }
    }

    /** L-450: restore() lets the lock go when it returns or throws, so the same process can restore again. */
    public function testTheRestoreLockIsReleasedInTheSameProcess(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-lock-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tmp', 0700, true);
        $backups = (new ReflectionClass(Backups::class))->newInstanceWithoutConstructor();
        (function (string $d): void { $this->dataDir = $d; })->call($backups, $dir);
        $restorer = (new ReflectionClass(Restorer::class))->newInstanceWithoutConstructor();
        (function (Backups $b): void { $this->backups = $b; $this->cleanup = new RequestCleanup(); })->call($restorer, $backups);
        try {
            foreach ([1, 2] as $round) {
                try {
                    $restorer->restore($dir . '/missing.zip', true);
                    $this->fail('a missing zip was restored');
                } catch (UserError $e) {
                    $this->assertNotSame(Restorer::BUSY, $e->getMessage(), "round $round");
                }
                $fh = fopen($dir . '/tmp/' . Restorer::LOCK_FILE, 'c');
                $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB), "released after round $round");
                flock($fh, LOCK_UN);
                fclose($fh);
            }
        } finally {
            array_map('unlink', glob($dir . '/tmp/*') ?: []);
            @rmdir($dir . '/tmp');
            @rmdir($dir);
        }
    }

    /** L-455: a lock file this user cannot write (made by root's CLI restore) still locks; N-456: a linked one is refused. */
    public function testTheRestoreLockCopesWithAReadOnlyFileAndRefusesALink(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-lock-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tmp', 0700, true);
        $backups = (new ReflectionClass(Backups::class))->newInstanceWithoutConstructor();
        (function (string $d): void { $this->dataDir = $d; })->call($backups, $dir);
        $restorer = (new ReflectionClass(Restorer::class))->newInstanceWithoutConstructor();
        (function (Backups $b): void { $this->backups = $b; $this->cleanup = new RequestCleanup(); })->call($restorer, $backups);
        $lockPath = $dir . '/tmp/' . Restorer::LOCK_FILE;
        try {
            touch($lockPath);
            chmod($lockPath, 0444);
            if (is_writable($lockPath)) {
                $this->markTestSkipped('running as root: a 0444 file stays writable');
            }
            try {
                $restorer->restore($dir . '/missing.zip', true);
                $this->fail('a missing zip was restored');
            } catch (UserError $e) {
                $this->assertNotSame(Restorer::FAILED, $e->getMessage(), 'a read-only lock file must still lock');
                $this->assertNotSame(Restorer::BUSY, $e->getMessage());
            }
            // Held by someone else through a read handle: the restore is refused as busy.
            $held = fopen($lockPath, 'r');
            $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));
            try {
                $restorer->restore($dir . '/missing.zip', true);
                $this->fail('a second restore ran');
            } catch (UserError $e) {
                $this->assertSame(Restorer::BUSY, $e->getMessage());
            } finally {
                flock($held, LOCK_UN);
                fclose($held);
            }

            chmod($lockPath, 0644);
            unlink($lockPath);
            $target = $dir . '/elsewhere';
            symlink($target, $lockPath);
            try {
                $restorer->restore($dir . '/missing.zip', true);
                $this->fail('a restore ran through a linked lock file');
            } catch (UserError $e) {
                $this->assertSame(Restorer::LOCK_REFUSED, $e->getMessage(), 'N-459: it says why');
            }
            $this->assertFileDoesNotExist($target, 'the link target was created');
        } finally {
            @chmod($lockPath, 0644);
            array_map('unlink', glob($dir . '/tmp/*') ?: []);
            @rmdir($dir . '/tmp');
            @unlink($dir . '/elsewhere');
            @rmdir($dir);
        }
    }

    /** N-452: an hour-old upload or entry copy in DATA_DIR/tmp is swept; a newer one and other files stay. */
    public function testTheTempFolderIsSwept(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-sweep-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tmp', 0700, true);
        try {
            $old = time() - 3700;
            foreach (['restore-a.zip' => $old, 'restore-b.part' => $old, 'restore-c.part' => time(), 'restore.lock' => $old, 'other.part' => $old] as $f => $t) {
                touch($dir . '/tmp/' . $f, $t);
            }
            $backups = (new ReflectionClass(Backups::class))->newInstanceWithoutConstructor();
            (function (string $d): void { $this->dataDir = $d; })->call($backups, $dir);
            $this->assertSame($dir . '/tmp', $backups->tmpDir());
            $left = array_map('basename', glob($dir . '/tmp/*') ?: []);
            sort($left);
            $this->assertSame(['other.part', 'restore-c.part', 'restore.lock'], $left);
        } finally {
            array_map('unlink', glob($dir . '/tmp/*') ?: []);
            @rmdir($dir . '/tmp');
            @rmdir($dir);
        }
    }

    /** N-458: the lock opened must be the plain file its name points at (a link swapped in after the check is refused). */
    public function testTheLockFileMustBeThePlainFileItsNameNames(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-restore-lock-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700);
        try {
            file_put_contents($dir . '/a', '');
            file_put_contents($dir . '/b', '');
            symlink($dir . '/a', $dir . '/link');
            $fh = fopen($dir . '/a', 'r');
            $open = fstat($fh);
            fclose($fh);
            $this->assertTrue(Restorer::sameFile($open, lstat($dir . '/a')));
            $this->assertFalse(Restorer::sameFile($open, lstat($dir . '/b')), 'another file');
            $this->assertFalse(Restorer::sameFile($open, lstat($dir . '/link')), 'a link to it');
            $this->assertFalse(Restorer::sameFile($open, false), 'gone');
            $this->assertFalse(Restorer::sameFile(false, lstat($dir . '/a')));
            $this->assertFalse(Restorer::sameFile($open, ['mode' => 0100644, 'dev' => $open['dev']] + ['ino' => $open['ino'] + 1]), 'same device, other inode');
            $this->assertFalse(Restorer::sameFile($open, ['mode' => 0100644, 'ino' => $open['ino'], 'dev' => $open['dev'] + 1]), 'same inode, other device');
            $this->assertTrue(Restorer::plainFile(lstat($dir . '/a')));
            $this->assertFalse(Restorer::plainFile(lstat($dir . '/link')));
            $this->assertFalse(Restorer::plainFile(lstat($dir)));

            // A link swapped in after the check: to a file it is refused, and a dangling one creates nothing.
            $lock = $dir . '/lock';
            foreach (['to a file' => $dir . '/b', 'dangling' => $dir . '/nothing'] as $what => $target) {
                @unlink($lock);
                try {
                    $fh = Restorer::openLock($lock, static function () use ($lock, $target): void {
                        @unlink($lock);
                        symlink($target, $lock);
                    });
                    fclose($fh);
                    $this->fail("a lock was taken through a link $what");
                } catch (UserError $e) {
                    $this->assertContains($e->getMessage(), [Restorer::LOCK_REFUSED, Restorer::FAILED], $what);
                }
                $this->assertTrue(is_link($lock), $what);
            }
            $this->assertFileDoesNotExist($dir . '/nothing', 'nothing is created through a dangling link');
            unlink($lock);
            $fh = Restorer::openLock($lock, static fn () => null);
            $this->assertTrue(Restorer::plainFile(lstat($lock)));
            $this->assertSame([], glob($dir . '/.lock-*') ?: [], 'the name it was made under is gone');
            fclose($fh);

            // A directory where the lock file goes is refused, with the reason.
            mkdir($dir . '/tmp/' . Restorer::LOCK_FILE, 0700, true);
            $backups = (new ReflectionClass(Backups::class))->newInstanceWithoutConstructor();
            (function (string $d): void { $this->dataDir = $d; })->call($backups, $dir);
            $restorer = (new ReflectionClass(Restorer::class))->newInstanceWithoutConstructor();
            (function (Backups $b): void { $this->backups = $b; $this->cleanup = new RequestCleanup(); })->call($restorer, $backups);
            try {
                $restorer->restore($dir . '/missing.zip', true);
                $this->fail('a restore ran with a folder as its lock');
            } catch (UserError $e) {
                $this->assertSame(Restorer::LOCK_REFUSED, $e->getMessage());
            }
        } finally {
            @rmdir($dir . '/tmp/' . Restorer::LOCK_FILE);
            @rmdir($dir . '/tmp');
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }
}
