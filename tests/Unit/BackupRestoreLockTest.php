<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The restore lock (DATA_DIR/tmp/restore.lock): let go after every restore, any owner's file, only ever a plain file. */
final class BackupRestoreLockTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/apotheke-restore-lock-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/tmp', 0700, true);
    }

    protected function tearDown(): void
    {
        @chmod($this->dir . '/tmp/' . BACKUP_RESTORE_LOCK, 0644);
        foreach (array_diff(@scandir($this->dir . '/tmp') ?: [], ['.', '..']) as $f) {
            $f = $this->dir . '/tmp/' . $f;
            is_dir($f) && !is_link($f) ? @rmdir($f) : @unlink($f);
        }
        @unlink($this->dir . '/elsewhere');
        @rmdir($this->dir . '/tmp');
        @rmdir($this->dir);
    }

    private function restoreMissing(): UserError
    {
        try {
            backup_restore(new PDO('sqlite::memory:'), 'sqlite', $this->dir, $this->dir . '/missing.zip');
        } catch (UserError $e) {
            return $e;
        }
        $this->fail('a missing zip was restored');
    }

    public function testTheLockIsReleasedAfterARestoreInTheSameProcess(): void
    {
        foreach ([1, 2] as $round) {
            $e = $this->restoreMissing();
            $this->assertSame('That file is not a zip archive.', $e->getMessage(), "round $round");
            $fh = fopen($this->dir . '/tmp/' . BACKUP_RESTORE_LOCK, 'c');
            $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB), "released after round $round");
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function testARestoreWhileTheLockIsHeldIsRefusedAtOnce(): void
    {
        $held = fopen($this->dir . '/tmp/' . BACKUP_RESTORE_LOCK, 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));
        try {
            $t = microtime(true);
            $e = $this->restoreMissing();
            $this->assertSame([BACKUP_RESTORE_BUSY, 409], [$e->getMessage(), $e->getCode()]);
            $this->assertLessThan(2.0, microtime(true) - $t, 'not waited for');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
        $this->assertSame('That file is not a zip archive.', $this->restoreMissing()->getMessage(), 'free again');
    }

    public function testTheLockFolderIsMadeWhenMissing(): void
    {
        rmdir($this->dir . '/tmp');
        $this->assertSame('That file is not a zip archive.', $this->restoreMissing()->getMessage());
        $this->assertFileExists($this->dir . '/tmp/' . BACKUP_RESTORE_LOCK);
    }

    /** A lock file this user cannot write (made by root's CLI restore) still locks, both ways. */
    public function testAReadOnlyLockFileStillLocks(): void
    {
        $path = $this->dir . '/tmp/' . BACKUP_RESTORE_LOCK;
        touch($path);
        chmod($path, 0444);
        if (is_writable($path)) $this->markTestSkipped('running as root: a 0444 file stays writable');
        $this->assertSame('That file is not a zip archive.', $this->restoreMissing()->getMessage(), 'a read-only lock file must still lock');
        $held = fopen($path, 'r');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));
        try {
            $this->assertSame(BACKUP_RESTORE_BUSY, $this->restoreMissing()->getMessage(), 'held through a read handle');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }
    }

    /** A lock path that is a link is refused, and its target is not created. */
    public function testALinkedLockFileIsRefused(): void
    {
        $target = $this->dir . '/elsewhere';
        symlink($target, $this->dir . '/tmp/' . BACKUP_RESTORE_LOCK);
        $this->assertSame(BACKUP_RESTORE_FAILED, $this->restoreMissing()->getMessage());
        $this->assertFileDoesNotExist($target, 'the link target was created');
        touch($target);
        $this->assertSame(BACKUP_RESTORE_FAILED, $this->restoreMissing()->getMessage(), 'also when the target exists');
    }

    /** Anything but a plain file at the lock path (a folder, a pipe) is refused. */
    public function testALockPathThatIsNotAPlainFileIsRefused(): void
    {
        $path = $this->dir . '/tmp/' . BACKUP_RESTORE_LOCK;
        mkdir($path);
        $this->assertSame(BACKUP_RESTORE_FAILED, $this->restoreMissing()->getMessage(), 'a folder');
        rmdir($path);
        if (!function_exists('posix_mkfifo')) $this->markTestSkipped('no posix_mkfifo');
        posix_mkfifo($path, 0600);
        $this->assertSame(BACKUP_RESTORE_FAILED, $this->restoreMissing()->getMessage(), 'a pipe');
    }

    /** A new lock file is made in place, as a plain file, with no temp file left over. */
    public function testANewLockFileIsAPlainFile(): void
    {
        $this->assertSame('That file is not a zip archive.', $this->restoreMissing()->getMessage());
        $path = $this->dir . '/tmp/' . BACKUP_RESTORE_LOCK;
        $this->assertTrue(is_file($path) && !is_link($path));
        $this->assertSame([$path], glob($this->dir . '/tmp/{,.}*.lock*', GLOB_BRACE));
    }

    /**
     * The name is looked up afresh before the open (N-562, N-581): a link that pointed nowhere when one restore opened
     * the name (refused) is not remembered, so the plain file put there next locks.
     */
    public function testALinkSeenAtTheOpenIsNotRememberedForTheNextLock(): void
    {
        $path = $this->dir . '/tmp/' . BACKUP_RESTORE_LOCK;
        $target = $this->dir . '/elsewhere';
        try {
            fclose(backup_restore_lock($this->dir, fn() => symlink($target, $path)));
            $this->fail('a link at the open was not refused');
        } catch (UserError $e) {
            $this->assertSame(BACKUP_RESTORE_FAILED, $e->getMessage());
        }
        $this->assertFileDoesNotExist($target, 'the link target was created');
        // another process puts the plain file there: PHP's own unlink() and touch() would clear its caches
        $this->assertSame('', (string)shell_exec('rm -f ' . escapeshellarg($path) . ' && touch ' . escapeshellarg($path) . ' 2>&1'));
        $fh = backup_restore_lock($this->dir);
        $this->assertIsResource($fh, 'the plain file now at the name locks');
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    /**
     * A link put at the name between the check and the open is never followed: when nothing was there, its target is
     * not made; when a plain file was there, the file opened is not the one at the name, so the lock is refused.
     */
    public function testALinkSwappedInAfterTheCheckIsRefused(): void
    {
        $path = $this->dir . '/tmp/' . BACKUP_RESTORE_LOCK;
        $target = $this->dir . '/elsewhere';
        $fail = function (Closure $swap, ?Closure $afterOpen = null): string {
            try {
                $fh = backup_restore_lock($this->dir, $swap, $afterOpen);
                fclose($fh);
                return 'locked';
            } catch (UserError $e) {
                return $e->getMessage();
            }
        };
        $this->assertSame(BACKUP_RESTORE_FAILED, $fail(fn() => symlink($target, $path)), 'nothing there, then a link');
        $this->assertFileDoesNotExist($target, 'the link target was created');
        unlink($path);

        touch($path);
        touch($target);
        $this->assertSame(BACKUP_RESTORE_FAILED, $fail(function () use ($path, $target): void {
            unlink($path);
            symlink($target, $path);
        }), 'a plain file, then a link to a file');
        $held = fopen($target, 'r');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB), 'the target was not left locked');
        flock($held, LOCK_UN);
        fclose($held);
        unlink($path);

        $this->assertSame('locked', $fail(fn() => touch($path)), 'nothing there, then a plain file another restore made');

        $this->assertSame(BACKUP_RESTORE_FAILED, $fail(function () use ($path): void {
            rename($path, "$path.moved");   // the same file, now behind a link: what the name was checked as is not enough
            symlink("$path.moved", $path);
        }), 'the checked file, then a link to it');
        unlink($path);
        unlink("$path.moved");

        touch($path);
        $this->assertSame(BACKUP_RESTORE_FAILED, $fail(function () use ($path, $target): void {
            unlink($path);
            symlink($target, $path);
        }, function () use ($path): void {
            unlink($path);   // after the open: a plain file again, but not the one that was opened
            touch($path);
        }), 'a link opened, then a plain file at the name');
        unlink($path);

        touch($path);
        $this->assertSame(BACKUP_RESTORE_FAILED, $fail(function () use ($path): void {
            unlink($path);
            mkdir($path);
        }), 'a plain file, then a folder');
    }
}
