<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Auth\UserRepository;
use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

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
        $this->assertSame(1, $this->uploads()->count(), 'nothing for the guest');
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

    public function testConfirmRestoresOnceAndKeepsOnlyTheOutcome(): void
    {
        $u = $this->uploads();
        $ready = function () use ($u): string {
            [$id] = $u->create(3, null);
            $u->append($id, 0, $this->chunk('abc'), 3);

            return $id;
        };
        $id = $ready();
        $this->assertRefused(fn () => $u->confirm($id, static fn (): array => []), 409, 'before finish');
        $u->finish($id, static fn (): array => []);
        $this->assertRefused(fn () => $u->confirm($id, static fn (): never => throw new UserError('busy', 409)), 409, 'another restore runs');
        $this->assertSame('ready', $u->get($id)['status'], 'kept to confirm again');
        $calls = 0;
        $restore = static function (string $zip) use (&$calls): array {
            $calls++;

            return ['tables' => ['items' => 1], 'photos' => 0, 'warnings' => [(string)file_get_contents($zip)], 'token_cleared' => false];
        };
        $done = $u->confirm($id, $restore);
        $this->assertSame(['abc'], $done['warnings']);
        $this->assertSame(['manifest.json'], $this->files($id), 'only the outcome is kept');
        $this->assertSame(['restored', $done], [$u->get($id)['status'], $u->get($id)['result'] ?? null], 'a client that lost the answer can read it');
        $this->assertSame($done, $u->confirm($id, $restore), 'confirm again: the same answer');
        $this->assertSame(1, $calls, 'restored once');
        $this->assertRefused(fn () => $u->finish($id, static fn (): array => []), 409, 'finish after confirm');
        $id = $ready();
        $u->finish($id, static fn (): array => []);
        $this->assertRefused(fn () => $u->confirm($id, static fn (): never => throw new UserError('failed')), 0, 'a failed restore', 'failed');
        $this->assertSame(['failed', ['error' => 'failed']], [$u->get($id)['status'], $u->get($id)['result'] ?? null]);
        $this->assertSame(['manifest.json'], $this->files($id));
        $this->assertRefused(fn () => $u->confirm($id, $restore), 0, 'confirm again: the same refusal', 'failed');
        $id = $ready();
        $u->delete($id);
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id, 'cancelled');
        $this->assertSame([], glob($this->root() . '/.*-*') ?: [], 'no removal leftovers');
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

    /** L-1580: the sweep removes only real upload folders: never a link (or what it points at), a file or another name. */
    public function testOnlyRealUploadFoldersAreSwept(): void
    {
        $this->uploads()->sweep();
        $old = time() - RestoreUploads::IDLE - 5;
        $elsewhere = $this->data . '-elsewhere/' . str_repeat('b', 32);
        mkdir($elsewhere, 0770, true);
        file_put_contents($elsewhere . '/manifest.json', '{}');
        touch($elsewhere . '/manifest.json', $old);
        touch($elsewhere, $old);
        symlink($elsewhere, $this->root() . '/' . str_repeat('c', 32));
        file_put_contents($this->root() . '/' . str_repeat('d', 32), 'x');
        mkdir($this->root() . '/x' . str_repeat('e', 32));
        mkdir($this->root() . '/' . str_repeat('f', 32) . 'x');
        foreach (['c', 'd', 'xe', 'fx'] as $n) {
            $name = $this->root() . '/' . match ($n) { 'xe' => 'x' . str_repeat('e', 32), 'fx' => str_repeat('f', 32) . 'x', default => str_repeat($n, 32) };
            @touch($name, $old);
            exec('touch -h -d @' . $old . ' ' . escapeshellarg($name));
        }
        $this->uploads()->sweep();
        $this->assertTrue(is_link($this->root() . '/' . str_repeat('c', 32)), 'a link is not removed');
        $this->assertFileExists($elsewhere . '/manifest.json', 'nor what it points at');
        $this->assertFileExists($this->root() . '/' . str_repeat('d', 32), 'a file');
        $this->assertDirectoryExists($this->root() . '/x' . str_repeat('e', 32), 'a name with something before the id');
        $this->assertDirectoryExists($this->root() . '/' . str_repeat('f', 32) . 'x', 'a name with something after the id');
        $this->assertSame(0, $this->uploads()->count());
    }

    /** L-1580: a data file that is a link is never appended to or restored; the id pattern is anchored at the start. */
    public function testADataFileThatIsALinkIsRefused(): void
    {
        $u = $this->uploads();
        [$id] = $u->create(4, null);
        $outside = $this->data . '/outside';
        file_put_contents($outside, 'wxyz');
        symlink($outside, $this->root() . '/' . $id . '/data.part');
        $this->assertRefused(fn () => $u->append($id, 0, $this->chunk('abcd'), 4), 500, 'append through a link');
        $this->assertSame('wxyz', file_get_contents($outside), 'nothing written through it');
        unlink($this->root() . '/' . $id . '/data.part');
        $u->append($id, 0, $this->chunk('abcd'), 4);
        unlink($this->root() . '/' . $id . '/data.part');
        symlink($outside, $this->root() . '/' . $id . '/data.part');
        $this->assertRefused(fn () => $u->finish($id, static fn (): array => []), 409, 'finish through a link');
        $this->assertFileDoesNotExist($this->root() . '/' . $id . '/backup.zip');
        $this->assertSame('wxyz', file_get_contents($outside));
        $this->me = 'user:2';
        [$other] = $u->create(4, null);
        $this->me = 'user:1';
        $this->assertRefused(fn () => $u->get($other . '/../' . $id), 404, 'a path that ends in the id', RestoreUploads::NOT_FOUND);
    }

    /** L-1581: parallel creates for one account leave one upload. */
    public function testParallelCreatesLeaveOneUploadPerAccount(): void
    {
        $this->uploads()->sweep();
        $script = $this->data . '/create.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
$u = new Apotheke\Backup\RestoreUploads($argv[1], 4, 100, static fn (): string => "user:1");
while (microtime(true) < (float)$argv[2]) { usleep(200); }
try { $u->create(3, null); echo "ok"; } catch (Throwable $e) { echo $e->getMessage(); }');
        $start = sprintf('%.3F', microtime(true) + 1.0);
        $procs = [];
        for ($i = 0; $i < 8; $i++) {
            $procs[] = proc_open([PHP_BINARY, $script, $this->data, $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $out[] = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
            proc_close($p);
        }
        $this->assertSame(array_fill(0, 8, 'ok'), $out);
        $this->assertSame(1, $this->uploads()->count(), 'one upload per account');
    }

    /** L-1581: a cap on open uploads (any account), and the declared size must fit on the disk with a margin. */
    public function testTheOpenUploadCapAndTheFreeDisk(): void
    {
        $u = new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me, 2, static fn (): float => 1e12);
        foreach (['user:1', 'user:2'] as $who) {
            $this->me = $who;
            $u->create(3, null);
        }
        $this->me = 'user:3';
        $this->assertRefused(fn () => $u->create(3, null), 503, 'over the cap');
        $this->me = 'user:2';
        $u->create(3, null);
        $this->assertSame(2, $u->count(), 'replacing your own upload is not over the cap');
        $tight = new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me, 8, static fn (): float => (float)(RestoreUploads::DISK_MARGIN + 99));
        $this->me = 'user:4';
        $this->assertRefused(fn () => $tight->create(100, null), 507, 'not enough free disk');
        $this->assertSame(2, $u->count(), 'nothing made');
        $this->assertSame(93, $tight->create(93, null)[1]['size'], 'the size plus the margin fits beside the 6 bytes the others expect');
    }

    /** N-1583: the sweep skips an upload while a request holds its manifest lock. */
    public function testTheSweepSkipsAnUploadInUse(): void
    {
        [$id] = $this->uploads()->create(3, null);
        touch($this->root() . '/' . $id . '/manifest.json', time() - RestoreUploads::IDLE - 5);
        $fh = fopen($this->root() . '/' . $id . '/manifest.json', 'r');
        $this->assertNotFalse($fh);
        flock($fh, LOCK_EX);
        try {
            $this->uploads()->sweep();
            $this->assertDirectoryExists($this->root() . '/' . $id, 'in use');
        } finally {
            fclose($fh);
        }
        $this->uploads()->sweep();
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id, 'swept once free');
    }

    /** N-1651: a sweep takes no lock on a fresh upload, so its owner replacing it meanwhile is not told it is in use. */
    public function testASweepLeavesAFreshUploadUnlocked(): void
    {
        $this->uploads()->create(3, null);
        $during = [];
        $sweeper = new RestoreUploads($this->data, 4, 100, static fn (): string => 'user:2', 8, null, function () use (&$during): void {
            try {
                $during[] = $this->uploads()->create(3, null)[1]['status'];
            } catch (UserError $e) {
                $during[] = $e->getMessage();
            }
        });
        $sweeper->sweep();
        $this->assertSame([], $during, 'no lock taken on a fresh upload');
        $this->assertSame(1, $this->uploads()->count());
    }

    /**
     * N-1594: a confirm that stops after the restore but before its outcome is written (here the process is killed)
     * leaves status applying with the backup's SHA-256; the next request finds it interrupted and never restores again.
     */
    public function testAConfirmThatStopsIsInterruptedNotRestoredAgain(): void
    {
        $u = $this->uploads();
        [$id] = $u->create(3, null);
        $u->append($id, 0, $this->chunk('abc'), 3);
        $u->finish($id, static fn (): array => []);
        $applied = $this->data . '/applied';
        $script = $this->data . '/confirm.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';
$u = new Apotheke\Backup\RestoreUploads($argv[1], 4, 100, static fn (): string => "user:1");
$u->confirm($argv[2], static function (string $zip) use ($argv): array {
    file_put_contents($argv[3], file_get_contents($zip), FILE_APPEND);   // the restore is in
    posix_kill(getmypid(), SIGKILL);   // and the process ends before the outcome is written
    return [];
});');
        $p = proc_open([PHP_BINARY, $script, $this->data, $id, $applied], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($p);
        $err = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($p);
        $this->assertSame('abc', @file_get_contents($applied), 'applied once: ' . $err);
        $raw = json_decode((string)file_get_contents($this->root() . '/' . $id . '/manifest.json'), true);
        $this->assertSame(['applying', hash('sha256', 'abc')], [$raw['status'] ?? null, $raw['fingerprint'] ?? null], 'the marker was on disk first');

        $m = $u->get($id);
        $this->assertSame(['interrupted', ['error' => RestoreUploads::INTERRUPTED, 'sha256' => hash('sha256', 'abc')]], [$m['status'], $m['result'] ?? null]);
        $this->assertSame(['manifest.json'], $this->files($id), 'the backup is not kept');
        $calls = 0;
        $this->assertRefused(fn () => $u->confirm($id, static function () use (&$calls): array {
            $calls++;

            return [];
        }), 409, 'confirm again', RestoreUploads::INTERRUPTED);
        $this->assertSame([0, 'abc'], [$calls, file_get_contents($applied)], 'never restored again');
        $this->assertRefused(fn () => $u->finish($id, static fn (): array => []), 409, 'finish after it');
        $u->delete($id);
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id);
    }

    /** N-1594: an upload's owner key is the account's identity; another account with the same id has another key. */
    public function testAnotherAccountWithTheSameIdIsNotTheOwner(): void
    {
        $this->me = 'user:1:' . hash('sha256', '2026-01-01 00:00:00');
        [$id] = $this->uploads()->create(3, null);
        $this->me = 'user:1:' . hash('sha256', '2026-02-02 00:00:00');
        $this->assertRefused(fn () => $this->uploads()->get($id), 404, 'same id, another account', RestoreUploads::NOT_FOUND);
        $this->assertRefused(fn () => $this->uploads()->delete($id), 404, 'nor deleted');
        $this->me = 'user:1:' . hash('sha256', '2026-01-01 00:00:00');
        $this->assertSame('uploading', $this->uploads()->get($id)['status']);
    }

    /** N-1591: a create.lock that is a link is refused as a server fault, without its path. */
    public function testALinkedCreateLockIsAServerFault(): void
    {
        $root = $this->uploads()->sweep();
        file_put_contents($this->data . '/target', 'keep');
        symlink($this->data . '/target', $root . '/create.lock');
        $this->assertRefused(fn () => $this->uploads()->create(3, null), 500, 'linked lock', RestoreUploads::LOCK_UNUSABLE);
        $this->assertStringNotContainsString('lock', strtolower(RestoreUploads::LOCK_UNUSABLE));
        $this->assertSame('keep', file_get_contents($this->data . '/target'), 'nothing written through it');
        $this->assertSame(0, $this->uploads()->count());
    }

    /** N-1592: while the cap is reached, another account's unconfirmed upload idle for IDLE_FULL gives way. */
    public function testAFullCapRemovesUploadsIdleForTheShorterTime(): void
    {
        $u = new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me, 3, static fn (): float => 1e12);
        $ids = [];
        foreach (['user:1', 'user:2', 'user:5'] as $who) {
            $this->me = $who;
            $ids[$who] = $u->create(3, null)[0];
        }
        $stopped = $this->root() . '/' . $ids['user:5'] . '/manifest.json';
        file_put_contents($stopped, (string)json_encode(['status' => 'applying'] + (array)json_decode((string)file_get_contents($stopped), true)));
        foreach (['user:2' => -5, 'user:5' => -5, 'user:1' => 60] as $who => $by) {
            touch($this->root() . '/' . $ids[$who] . '/manifest.json', time() - RestoreUploads::IDLE_FULL + $by);
        }
        $this->me = 'user:3';
        $u->create(3, null);
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $ids['user:2'], 'idle while the cap is full');
        $this->assertDirectoryExists($this->root() . '/' . $ids['user:1'], 'not idle long enough');
        $this->assertDirectoryExists($this->root() . '/' . $ids['user:5'], 'a stopped restore keeps its record');
        $this->me = 'user:4';
        $this->assertRefused(fn () => $u->create(3, null), 503, 'still full');
    }

    /** N-1634: other accounts' idle uploads give way only once the cap is reached, never on every start. */
    public function testIdleUploadsAreKeptBelowTheCap(): void
    {
        $u = new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me, 3, static fn (): float => 1e12);
        $ids = [];
        foreach (['user:1', 'user:2'] as $who) {
            $this->me = $who;
            $ids[$who] = $u->create(3, null)[0];
            touch($this->root() . '/' . $ids[$who] . '/manifest.json', time() - RestoreUploads::IDLE_FULL - 60);
        }
        $this->me = 'user:3';
        $u->create(3, null);
        foreach ($ids as $who => $id) {
            $this->assertDirectoryExists($this->root() . '/' . $id, $who . ': idle, but the cap of 3 is not reached');
        }
        $this->me = 'user:4';
        $u->create(3, null);
        foreach ($ids as $who => $id) {
            $this->assertDirectoryDoesNotExist($this->root() . '/' . $id, $who . ': the cap reached, idle ones give way');
        }
        $this->assertSame(2, $u->count());
    }

    /** N-1636, N-1634: an account that cannot be read is a server fault (500), not a guest (403); nothing is stored. */
    public function testAnUnreadableAccountIsAServerFaultNotAGuest(): void
    {
        $u = new RestoreUploads($this->data, 4, 100, static fn (): never => throw new WriteFailed('Database read failed'));
        $this->assertRefused(fn () => $u->create(3, null), 500, 'unreadable account', RestoreUploads::OWNER_UNREADABLE);
        $this->assertRefused(fn () => $u->get(str_repeat('a', 32)), 500, 'unreadable account, get', RestoreUploads::OWNER_UNREADABLE);
        $this->me = null;
        $this->assertRefused(fn () => $this->uploads()->create(3, null), 403, 'no account', RestoreUploads::GUEST);
        $this->assertSame([], is_dir($this->root()) ? $this->ids() : [], 'nothing stored');
    }

    /**
     * N-1642: the owner lookup of an account deleted after the request signed in reads as no account, so the upload
     * is refused as for a guest (403), not stored under a key; a guest, and the admin with sign-in off, read nothing.
     */
    #[DataProvider('drivers')]
    public function testAnAccountDeletedMidRequestIsRefusedAsAGuest(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $users = new UserRepository($database->db);
            $id = $users->create('kim', password_hash('x', PASSWORD_BCRYPT, ['cost' => 4]), 'admin', 'active', '2026-01-01 00:00:00');
            $user = ['id' => $id, 'anon' => false];
            $owner = static fn (): ?string => Services::restoreOwner($user, $users->createdAt(...));
            $this->assertSame('user:' . $id . ':' . hash('sha256', '2026-01-01 00:00:00'), $owner());
            $unread = static fn (int $id): never => throw new \LogicException('not read');
            $this->assertNull(Services::restoreOwner(null, $unread), 'a guest');
            $this->assertNull(Services::restoreOwner(['id' => 1, 'anon' => true], $unread), 'anonymous');
            $this->assertSame('local', Services::restoreOwner(['id' => null, 'anon' => false], $unread), 'sign-in off');
            $database->db->query('DELETE FROM users WHERE id = ?', [$id]);
            $this->assertNull($owner(), 'deleted after sign-in');
            $u = new RestoreUploads($this->data, 4, 100, $owner);
            $this->assertRefused(fn () => $u->create(3, null), 403, 'a deleted account', RestoreUploads::GUEST);
            $this->assertRefused(fn () => $u->get(str_repeat('a', 32)), 403, 'a deleted account, get', RestoreUploads::GUEST);
            $this->assertSame([], is_dir($this->root()) ? $this->ids() : [], 'nothing stored');
        } finally {
            $database->drop();
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    /** N-1593: the free disk must hold what the other open uploads still expect, besides the new one. */
    public function testTheFreeDiskCountsWhatOpenUploadsStillExpect(): void
    {
        $u = new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me, 8, static fn (): float => (float)(RestoreUploads::DISK_MARGIN + 150));
        [$id] = $u->create(100, null);
        $u->append($id, 0, $this->chunk('abcd'), 4);
        $this->me = 'user:2';
        $this->assertRefused(fn () => $u->create(55, null), 507, '96 still expected, 150 free');
        $this->assertSame(54, $u->create(54, null)[1]['size'], 'what fits beside it');
        $this->me = 'user:3';
        $this->assertRefused(fn () => $u->create(1, null), 507, 'both reserved');
        $this->me = 'user:2';
        $this->assertSame(54, $u->create(54, null)[1]['size'], 'your own earlier upload is not counted');
    }

    /** N-1595: confirmed outcome records (restored, failed, interrupted) never count toward the cap. */
    public function testOutcomeRecordsDoNotCountTowardTheCap(): void
    {
        $u = new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me, 1, static fn (): float => 1e12);
        foreach (['restored' => static fn (): array => [], 'failed' => static fn (): never => throw new UserError('no'), 'interrupted' => null] as $status => $restore) {
            $this->me = 'user:' . $status;
            [$id] = $u->create(3, null);
            $u->append($id, 0, $this->chunk('abc'), 3);
            $u->finish($id, static fn (): array => []);
            if ($restore === null) {
                $file = $this->root() . '/' . $id . '/manifest.json';
                file_put_contents($file, (string)json_encode(['status' => 'applying', 'fingerprint' => null] + (array)json_decode((string)file_get_contents($file), true)));
                $u->get($id);
            } else {
                try {
                    $u->confirm($id, $restore);
                } catch (UserError) {
                }
            }
            $this->assertSame($status, $u->get($id)['status']);
        }
        $this->me = 'user:new';
        $u->create(3, null);
        $this->assertSame(4, $u->count());
        $this->me = 'user:other';
        $this->assertRefused(fn () => $u->create(3, null), 503, 'one open upload fills a cap of 1');
    }

    /** N-1595: a start does not remove your earlier upload while a request uses it. */
    public function testYourEarlierUploadInUseIsNotReplaced(): void
    {
        [$old] = $this->uploads()->create(3, null);
        $fh = fopen($this->root() . '/' . $old . '/manifest.json', 'r');
        $this->assertNotFalse($fh);
        flock($fh, LOCK_EX);
        try {
            $this->assertRefused(fn () => $this->uploads()->create(3, null), 409, 'in use');
        } finally {
            fclose($fh);
        }
        $this->assertSame([$old], $this->ids(), 'kept, and nothing new');
    }

    /** N-1595: a manifest swapped between its open and its lock is not used (a request) or removed (the sweep). */
    public function testAManifestSwappedBeforeItsLockIsNotUsed(): void
    {
        [$id] = $this->uploads()->create(3, null);
        $file = $this->root() . '/' . $id . '/manifest.json';
        $swap = static function (string $path) use ($file): void {
            if ($path === $file) {
                copy($file, $file . '.new');
                touch($file . '.new', (int)filemtime($file));
                rename($file . '.new', $file);   // the same bytes and time, another file
            }
        };
        $swapping = new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me, 8, null, $swap);
        $this->assertRefused(fn () => $swapping->get($id), 404, 'swapped under a request', RestoreUploads::NOT_FOUND);
        touch($file, time() - RestoreUploads::IDLE - 5);
        $swapping->sweep();
        $this->assertDirectoryExists($this->root() . '/' . $id, 'not removed by the sweep');
        touch($file, time() - RestoreUploads::IDLE - 5);
        $this->uploads()->sweep();
        $this->assertDirectoryDoesNotExist($this->root() . '/' . $id, 'removed once it holds still');
    }

    /** N-1595: the sweep removes what an interrupted removal left (.removing-<16 hex>), and nothing else. */
    public function testTheSweepClearsRemovalLeftovers(): void
    {
        $root = $this->uploads()->sweep();
        $left = $root . '/.removing-' . bin2hex(random_bytes(8));
        mkdir($left);
        file_put_contents($left . '/data.part', 'x');
        mkdir($root . '/.removing-short');
        $this->uploads()->sweep();
        $this->assertDirectoryDoesNotExist($left);
        $this->assertDirectoryExists($root . '/.removing-short', 'only the exact name');
    }

    /** N-1587: the chunk size follows PHP's upload_max_filesize and post_max_size (less room for the multipart framing). */
    public function testTheChunkSizeFollowsThePhpLimits(): void
    {
        foreach ([['2M', 2097152], ['8m', 8388608], ['1G', 1073741824], ['512K', 524288], ['100', 100], [' 3 M ', 3145728], ['0', 0], ['', 0], [false, 0], ['-1', 0]] as [$ini, $bytes]) {
            $this->assertSame($bytes, RestoreUploads::iniBytes($ini), (string)json_encode($ini));
        }
        $m = RestoreUploads::MULTIPART_MARGIN;
        foreach ([[8388608, '2M', '8M', 2097152], [8388608, '20M', '22M', 8388608], [8388608, '2M', '2M', 2097152 - $m], [8388608, '0', '0', 8388608],
            [65536, '2M', '8M', 65536], [8388608, '64M', '4M', 4194304 - $m]] as [$conf, $upload, $post, $want]) {
            $this->assertSame($want, RestoreUploads::effectiveChunk($conf, $upload, $post), "$conf $upload $post");
        }
        $this->assertSame(1024, RestoreUploads::effectiveChunk(8388608, '1K', '1K'), 'never below 1 KiB');
    }

    private function uploads(): RestoreUploads
    {
        return new RestoreUploads($this->data, 4, 100, fn (): ?string => $this->me);
    }

    /** @return list<string> */
    private function files(string $id): array
    {
        return array_values(array_diff(scandir($this->root() . '/' . $id) ?: [], ['.', '..']));
    }

    /** @return list<string> the upload folders */
    private function ids(): array
    {
        return array_values(preg_grep('/^[a-f0-9]{32}$/D', scandir($this->root()) ?: []) ?: []);
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
