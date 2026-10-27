<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The restore lock (DATA_DIR/tmp/restore.lock): let go after every restore, any owner's file, never a link. */
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
        foreach (glob($this->dir . '/tmp/*') ?: [] as $f) @unlink($f);
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
}
