<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Exceptions\UserError;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Chunked restore uploads: everything stays in DATA_DIR/uploads/restore/<id>/, ids are strict and never followed
 * through a link, chunks come in order within the caps, an upload is its owner's only, and idle, finished, refused
 * or cancelled uploads leave no files.
 */
final class RestoreUploadsTest extends CIUnitTestCase
{
    private string $data = '';

    private ?string $me = 'user:1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->data = sys_get_temp_dir() . '/apotheke-restore-uploads-' . bin2hex(random_bytes(4));
        mkdir($this->data, 0770);
        $this->me = 'user:1';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->data) . ' ' . escapeshellarg($this->data . '-elsewhere'));
        parent::tearDown();
    }

    public function testAnUploadIsMadeOnlyUnderTheRestoreFolder(): void
    {
        [$id, $m] = $this->uploads()->create(10, null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $id);
        $this->assertSame(['owner' => 'user:1', 'size' => 10, 'sha256' => null, 'chunk_size' => 4, 'received' => 0, 'next' => 0, 'status' => 'uploading'], $m);
        $this->assertSame(['manifest.json'], array_values(array_diff(scandir($this->root() . '/' . $id) ?: [], ['.', '..'])));
        $this->assertSame(0, fileperms($this->root()) & 0007, 'no access for others');
        $this->assertNotSame($id, $this->uploads()->create(10, null)[0], 'random ids');
    }

    public function testTheSizeAndChecksumAreChecked(): void
    {
        foreach ([[null, null, 422], ['10', null, 422], [0, null, 422], [101, null, 413], [10, 'ABC', 422], [10, str_repeat('A', 64), 422], [10, 5, 422]] as [$size, $sha, $status]) {
            $this->assertRefused(fn () => $this->uploads()->create($size, $sha), $status, json_encode([$size, $sha]) ?: '');
        }
        $this->assertSame(100, $this->uploads()->create(100, str_repeat('a', 64))[1]['size'], 'the cap itself is allowed');
        $this->me = null;
        $this->assertRefused(fn () => $this->uploads()->create(10, null), 403, 'a guest');
        $this->assertFalse(is_dir($this->root()) && count(scandir($this->root()) ?: []) > 3, 'nothing for the guest');
    }

    public function testAMalformedLinkedOrOtherUsersIdIsNotFound(): void
    {
        [$id] = $this->uploads()->create(10, null);
        $elsewhere = $this->data . '-elsewhere';
        mkdir($elsewhere . '/' . str_repeat('b', 32), 0770, true);
        copy($this->root() . '/' . $id . '/manifest.json', $elsewhere . '/' . str_repeat('b', 32) . '/manifest.json');
        symlink($elsewhere . '/' . str_repeat('b', 32), $this->root() . '/' . str_repeat('c', 32));
        mkdir($this->root() . '/' . str_repeat('d', 32));
        symlink($this->root() . '/' . $id . '/manifest.json', $this->root() . '/' . str_repeat('d', 32) . '/manifest.json');
        $bad = ['', '..', '../' . $id, $id . '/..', strtoupper($id), substr($id, 1), $id . 'a', $id . "\n", "$id/", str_repeat('c', 32), str_repeat('d', 32), str_repeat('e', 32)];
        foreach ($bad as $try) {
            $this->assertRefused(fn () => $this->uploads()->get($try), 404, json_encode($try) ?: '', RestoreUploads::NOT_FOUND);
        }
        $this->me = 'user:2';
        foreach (['get' => fn () => $this->uploads()->get($id), 'append' => fn () => $this->uploads()->append($id, 0, __FILE__, 4),
            'finish' => fn () => $this->uploads()->finish($id, static fn (): array => []), 'confirm' => fn () => $this->uploads()->confirm($id, static fn (): int => 1),
            'delete' => fn () => $this->uploads()->delete($id)] as $what => $call) {
            $this->assertRefused($call, 404, 'another user: ' . $what, RestoreUploads::NOT_FOUND);
        }
        $this->me = 'user:1';
        $this->assertSame(0, $this->uploads()->get($id)['received'], 'still there for its owner');
        $this->assertFileExists($elsewhere . '/' . str_repeat('b', 32) . '/manifest.json', 'nothing removed through a link');
        $this->me = null;
        $this->assertRefused(fn () => $this->uploads()->get($id), 403, 'a guest');
    }

    public function testALinkedUploadFolderIsRefused(): void
    {
        mkdir($this->data . '-elsewhere');
        symlink($this->data . '-elsewhere', $this->data . '/uploads');
        $this->assertRefused(fn () => $this->uploads()->create(10, null), 500, 'uploads is a link');
        $this->assertSame(['.', '..'], scandir($this->data . '-elsewhere'), 'nothing written through it');
        unlink($this->data . '/uploads');
        mkdir($this->data . '/uploads');
        symlink($this->data . '-elsewhere', $this->data . '/uploads/restore');
        $this->assertRefused(fn () => $this->uploads()->create(10, null), 500, 'uploads/restore is a link');
        $this->assertSame(['.', '..'], scandir($this->data . '-elsewhere'));
    }

    public function testChunksComeInOrderWithinTheCaps(): void
    {
        $u = $this->uploads();
        [$id] = $u->create(10, hash('sha256', 'abcdefghij'));
        $this->assertRefused(fn () => $u->append($id, 1, $this->chunk('abcd'), 4), 409, 'chunk 1 first');
        $this->assertSame(['received' => 4, 'next' => 1], array_intersect_key($u->append($id, 0, $this->chunk('abcd'), 4), ['received' => 0, 'next' => 0]));
        $this->assertRefused(fn () => $u->append($id, 0, $this->chunk('abcd'), 4), 409, 'chunk 0 again');
        $this->assertRefused(fn () => $u->append($id, 1, $this->chunk('efghi'), 5), 413, 'over the chunk size');
        $this->assertRefused(fn () => $u->append($id, 1, $this->chunk('ef'), 2), 422, 'short, not the last');
        $this->assertRefused(fn () => $u->append($id, 1, $this->chunk(''), 0), 422, 'empty');
        $this->assertRefused(fn () => $u->finish($id, static fn (): array => []), 409, 'incomplete');
        $u->append($id, 1, $this->chunk('efgh'), 4);
        $this->assertRefused(fn () => $u->append($id, 2, $this->chunk('ijk'), 3), 413, 'past the declared size');
        $this->assertRefused(fn () => $u->append($id, 2, $this->chunk('ijk'), 2), 500, 'a length that is not the file\'s');
        $this->assertSame(8, filesize($this->root() . '/' . $id . '/data.part'), 'every refused chunk changed nothing');
        $m = $u->append($id, 2, $this->chunk('ij'), 2);
        $this->assertSame([10, 3, 'uploading'], [$m['received'], $m['next'], $m['status']]);
        $seen = $u->finish($id, static fn (string $zip, array $m): array => ['bytes' => file_get_contents($zip), 'status' => $m['status'], 'zip' => $zip]);
        $this->assertSame(['abcdefghij', 'ready', $this->root() . '/' . $id . '/backup.zip'], [$seen['bytes'], $seen['status'], $seen['zip']]);
        $this->assertRefused(fn () => $u->append($id, 3, $this->chunk('k'), 1), 409, 'after finish');
        $this->assertSame('ready', $u->finish($id, static fn (string $zip, array $m): array => $m)['status'], 'finish again: the dry run again');
    }

    public function testAChecksumMismatchOrARefusedBackupRemovesTheUpload(): void
    {
        $u = $this->uploads();
        [$id] = $u->create(3, hash('sha256', 'xyz'));
        $u->append($id, 0, $this->chunk('abc'), 3);
        $this->assertRefused(fn () => $u->finish($id, static fn (): array => []), 422, 'sha256');
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id);
        [$id] = $u->create(3, null);
        $u->append($id, 0, $this->chunk('abc'), 3);
        $this->assertRefused(fn () => $u->finish($id, static fn (): array => throw new UserError('This is not an Apotheke backup.')), 0, 'not a backup');
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id);
    }

    public function testConfirmRestoresOnceAndRemovesTheFiles(): void
    {
        $u = $this->uploads();
        $ready = function () use ($u): string {
            [$id] = $u->create(3, null);
            $u->append($id, 0, $this->chunk('abc'), 3);

            return $id;
        };
        $id = $ready();
        $this->assertRefused(fn () => $u->confirm($id, static fn (): int => 1), 409, 'before finish');
        $u->finish($id, static fn (): array => []);
        $this->assertRefused(fn () => $u->confirm($id, static fn (): never => throw new UserError('busy', 409)), 409, 'another restore runs');
        $this->assertDirectoryExists($this->root() . '/' . $id, 'kept to confirm again');
        $this->assertSame('abc', $u->confirm($id, static fn (string $zip): string => (string)file_get_contents($zip)));
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id);
        $id = $ready();
        $u->finish($id, static fn (): array => []);
        $this->assertRefused(fn () => $u->confirm($id, static fn (): never => throw new UserError('failed')), 0, 'a failed restore');
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id);
        $id = $ready();
        $u->delete($id);
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id, 'cancelled');
    }

    public function testABusyUploadIsRefusedNotWaitedFor(): void
    {
        [$id] = $this->uploads()->create(3, null);
        $fh = fopen($this->root() . '/' . $id . '/manifest.json', 'r');
        $this->assertNotFalse($fh);
        flock($fh, LOCK_EX);
        try {
            $this->assertRefused(fn () => $this->uploads()->append($id, 0, $this->chunk('abc'), 3), 409, 'locked');
        } finally {
            fclose($fh);
        }
        $this->assertSame(3, $this->uploads()->append($id, 0, $this->chunk('abc'), 3)['received']);
    }

    public function testIdleUploadsAreSweptAndANewUploadReplacesYourOld(): void
    {
        $u = $this->uploads();
        [$old] = $u->create(3, null);
        [$fresh] = $u->create(3, null);
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $old, 'one upload per account');
        $this->me = 'user:2';
        [$theirs] = $u->create(3, null);
        $this->assertDirectoryExists($this->root() . '/' . $fresh, 'another account\'s upload stays');
        mkdir($this->root() . '/not-an-upload');
        touch($this->root() . '/' . $theirs . '/manifest.json', time() - RestoreUploads::IDLE - 5);
        touch($this->root() . '/' . $fresh . '/manifest.json', time() - RestoreUploads::IDLE + 60);
        $this->assertSame(2, $u->count());
        $u->sweep();
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $theirs, 'idle for an hour');
        $this->assertDirectoryExists($this->root() . '/' . $fresh);
        $this->assertDirectoryExists($this->root() . '/not-an-upload', 'only upload folders are touched');
        $this->me = 'user:1';
        $u->get($fresh);
        $u->sweep(time() + RestoreUploads::IDLE - 5);
        $this->assertDirectoryExists($this->root() . '/' . $fresh, 'a request restarts the idle time');
        $this->assertRefused(fn () => $u->sweep(time() + RestoreUploads::IDLE + 5) && $u->get($fresh), 404, 'swept');

        exec('rm -rf ' . escapeshellarg($this->data . '/uploads'));
        $this->assertSame(0, $u->count());
        $u->sweep(null, false);
        $this->assertFileDoesNotExist($this->data . '/uploads', 'the command line makes no folder');
    }

    private function uploads(): RestoreUploads
    {
        return new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me);
    }

    private function root(): string
    {
        return $this->data . '/uploads/restore';
    }

    private function chunk(string $bytes): string
    {
        $f = $this->data . '/chunk-' . bin2hex(random_bytes(4));
        file_put_contents($f, $bytes);

        return $f;
    }

    private function assertRefused(\Closure $call, int $status, string $why, ?string $message = null): void
    {
        try {
            $call();
            $this->fail('not refused: ' . $why);
        } catch (UserError $e) {
            $this->assertSame($status, $e->getCode(), $why . ': ' . $e->getMessage());
            if ($message !== null) {
                $this->assertSame($message, $e->getMessage(), $why);
            }
        }
    }
}
