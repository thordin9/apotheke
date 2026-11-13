<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Catalog\Items\Attachments;
use Apotheke\Core\Database\SqlExecutor;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Storage\LeaseDenied;
use Apotheke\Core\Storage\LeaseHold;
use Apotheke\Core\Storage\ObjectLease;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Media\ImageLimits;
use Apotheke\Media\ImageStore;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use Closure;
use CodeIgniter\Database\BaseConnection;
use GdImage;
use PgSql\Connection as PgConnection;
use SQLite3;
use JsonException;
use LengthException;
use Throwable;
use ZipArchive;

/**
 * Checking and restoring a backup zip (legacy backup_open() and
 * backup_restore()). Restore replaces all catalog data in one transaction;
 * the files go first, under their own random names, so nothing that exists
 * is overwritten, and files no longer named are kept.
 *
 * Streamed (L-441, L-451, N-442): backup.json is copied out of the zip and
 * read a value at a time (JsonReader), once to check it and once for the
 * rows, which go in through prepared statements; every other entry is
 * copied to a temp file, checked against its size and CRC, and written
 * through Storage from there. So memory stays about the same for any size
 * of backup; only one value larger than the memory left is refused
 * (TOO_LARGE). Each entry may be at most MAX_ENTRY bytes.
 *
 * Stricter than legacy (L-440): a restore that fails at any point leaves
 * the files as they were. Every file it creates is noted and removed again
 * on failure, with any folder it had to create (N-453), and the
 * camera-original links (originals/map/) are rewritten only after the rows
 * commit. A listed entry that cannot be read fails the restore. One restore
 * runs at a time: the whole restore holds an exclusive lock on
 * DATA_DIR/tmp/restore.lock, and a second one is refused at once with BUSY
 * (L-450). On PostgreSQL a restore that loses a deadlock to another write
 * is tried again, and then refused with CONFLICT (N-445).
 *
 * Every entry name must be one the backup writes, so no entry can name a
 * path outside its folder. Files are written through Storage (no symlinked
 * path, written whole or not at all). A missing thumbnail is drawn again
 * only within Media's decode limits. The backup's settings are restored only
 * for a user who may change settings (N-443, the command line may).
 *
 * On a batch-only database ($staged, P4-2b) the rows go through RestoreStage
 * instead of one transaction: the pre-flight on the staged size
 * (RESTORE_DB_MAX_MB, N-1703), then the copies staged under the stage's lock
 * and swapped in by one batch marked with the run id, then the copies dropped.
 *
 * @phpstan-type Opened array{zip:ZipArchive,tables:array<string,int>,photos:int,created_at:string,driver:string,warnings:list<string>}
 * @phpstan-type Inspected array{zip:ZipArchive,json:string,tables:array<string,int>,photos:int,created_at:string,driver:string,warnings:list<string>,photoFiles:list<string>,attachmentFiles:list<string>,settings:mixed,offsets:array<string,int>,columns:array<string,list<string>>}
 */
final class Restorer
{
    /** Zip-bomb guard: the sizes the entries declare, unpacked. */
    public const MAX_UNPACKED = 4 * 1024 * 1024 * 1024;

    public const MAX_ENTRIES = 100_000;

    /** One file in the zip, unpacked (N-442): the largest photo or attachment the upload limits allow. */
    public const MAX_ENTRY = 200 * 1024 * 1024;

    public const FAILED = 'The backup could not be restored. Nothing was changed.';

    /** The activity row of a restore: its action, and the name it shows in place of an item. */
    public const RESTORED_ACTION = 'catalog_restored';

    public const CATALOG = 'Whole catalog';

    public const BUSY = 'Another restore is running. Try again when it has finished.';

    /** N-459: why a lock file that is a link (or not a plain file) stops the restore. */
    public const LOCK_REFUSED = 'The restore lock DATA_DIR/tmp/restore.lock is a link or not a plain file. Remove it, then try again. Nothing was changed.';

    /** N-445: the restore lost a deadlock to another write, also when tried again. */
    public const CONFLICT = 'Another change was being saved at the same time, so the restore was stopped. Nothing was changed. Try again.';

    /** In DATA_DIR/tmp; held for the whole restore (L-450). */
    public const LOCK_FILE = 'restore.lock';

    public const TOO_LARGE = 'This backup is too large to restore within the memory limit. Nothing was changed.';

    /** The columns that name a photo or an attachment file (legacy backup_photo_files(), backup_attachment_files()). */
    public const FILE_COLUMNS = ['items' => 'image', 'item_photos' => 'file', 'item_attachments' => 'file'];

    /** Rows tried in all, when a restore loses a deadlock on PostgreSQL (N-445). */
    private const ATTEMPTS = 3;

    /** WriteFailed's code for a deadlock or a serialization failure. */
    private const CONFLICT_CODE = 409;

    /** Kept free beside the estimate: the restore's own work after decoding. */
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    /** Bytes per read or write of a stream. */
    private const CHUNK = 65536;

    /** backup.json's depth, as legacy decodes it. */
    private const DEPTH = 64;

    /** The RequestCleanup key of the files a restore has written but not committed. */
    private const CLEANUP = 'backup:restore';

    /** files/ when a restore makes it, before the umask (legacy backup_restore()). */
    private const FILES_MODE = 0770;

    /** Legacy's quality for a thumbnail drawn again. */
    private const THUMB_QUALITY = 76;

    /** @var list<string> N-611: the temp copies in DATA_DIR/tmp this restore made (backup.json, an entry being unpacked) */
    private array $parts = [];

    /** @var list<LeaseHold> catalog lease, and the upload lease when a chunked confirm is inside it */
    private array $fences = [];

    /** Token of the catalog fence row, set only for an R2 restore. */
    private ?string $catalogFence = null;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param Closure():list<string> $known the migration versions this install has
     * @param Storage $data rooted at DATA_DIR (images/, thumbs/, files/)
     * @param Storage $originals rooted at ORIGINALS_DIR
     * @param RequestCleanup $cleanup removes the files of a restore stopped by a fatal error
     * @param ?SqlExecutor $staged the executor of a staged restore (batch-only databases), null for one transaction
     * @param int $dbMaxBytes a staged restore's database size limit (0: none)
     * @param ?StoredBackups $stored where the backup made before each restore is kept (null: none is made)
     * @param ?Closure(string):void $log the server log line of a failed restore (null: error_log)
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Backups $backups,
        private readonly SettingsService $settings,
        private readonly UndoTokens $undo,
        private readonly Closure $known,
        private readonly Storage $data,
        private readonly Storage $originals,
        private readonly int $thumbMax,
        private readonly RequestCleanup $cleanup,
        private readonly ?SqlExecutor $staged = null,
        private readonly int $dbMaxBytes = 0,
        private readonly ?StoredBackups $stored = null,
        private readonly ?Closure $log = null,
    ) {
    }

    /** The step a restore is at, for the server log line when it fails. */
    private string $step = 'reading the backup';

    /** The account a restore runs for, for its activity row (null: the command line). */
    private ?string $by = null;

    /** @var array{id:string,created_at:string,kind:string,size:int,sha256:string,parts:int,by:?string}|null the backup made before the last restore */
    private ?array $before = null;

    /** @return array{id:string,created_at:string,kind:string,size:int,sha256:string,parts:int,by:?string}|null the backup made before the last restore */
    public function backupBefore(): ?array
    {
        return $this->before;
    }

    /** Whether this is the hosted database (the D1 driver): its rows are staged and swapped in as one batch. */
    public function hosted(): bool
    {
        return Writes::onD1($this->db);
    }

    public static function originalNameOk(string $base): bool
    {
        return preg_match('/^[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$/D', $base) === 1;
    }

    /** May a backup hold an entry of this name? (the folders, and only the names the backup writes) */
    public static function entryNameOk(string $name): bool
    {
        return $name === 'backup.json' || $name === 'originals/index.json'
            || preg_match('#^(images|thumbs)/[a-f0-9]{32}\.jpg$#D', $name) === 1
            || preg_match('#^(images|thumbs|files|originals)/$#D', $name) === 1
            || preg_match('#^files/[a-f0-9]{32}\.(pdf|jpg|png|webp)$#D', $name) === 1
            || preg_match('#^originals/[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$#D', $name) === 1;
    }

    /** The file a row's value in $table's FILE_COLUMNS column names, if it is a valid name. */
    public static function fileName(string $table, mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $ok = $table === 'item_attachments' ? Attachments::validName($value) : ImageStore::validName($value);

        return $ok ? $value : null;
    }

    /**
     * Open and check a backup zip without touching the database (legacy
     * backup_open()). The caller closes the zip.
     *
     * @return Opened
     */
    public function open(string $zipPath): array
    {
        $b = $this->inspect($zipPath);
        @unlink($b['json']);

        return ['zip' => $b['zip'], 'tables' => $b['tables'], 'photos' => $b['photos'], 'created_at' => $b['created_at'], 'driver' => $b['driver'], 'warnings' => $b['warnings']];
    }

    /**
     * Replace all catalog data with the backup's (legacy backup_restore()).
     * $settings: may the backup's settings be restored too (the user may
     * change settings, or the command line)? $run: a staged restore's run id
     * (the upload's id; RestoreStage::applied()), random when null. $by: the account it runs for (null: the command
     * line). With $stored, the current catalog is backed up and kept first
     * (backupBefore()); a backup that cannot be made stops the restore before anything changes. A restore that
     * went in for an account adds one activity row: who replaced the catalog, and the backup kept before.
     *
     * @return array{tables:array<string,int>,photos:int,warnings:list<string>,token_cleared:bool}
     */
    public function restore(string $zipPath, bool $settings, ?string $run = null, ?LeaseHold $upload = null, ?string $by = null): array
    {
        $this->by = $by;
        $this->before = null;
        // One restore at a time, before any file or row changes. On explicit R2 the lease is that same
        // storage and is renewed before each later write. Local disk keeps the filesystem lock.
        // The database stage lock is a different lease; it does not keep this one.
        $runId = $run ?? bin2hex(random_bytes(16));
        if ($this->data instanceof R2Storage) {
            try {
                return (new RestoreUnit(new ObjectLease($this->data)))->run(
                    function (LeaseHold $catalog) use ($zipPath, $settings, $runId, $upload): array {
                        RestoreFence::claim($this->db, $catalog->token());
                        $this->catalogFence = $catalog->token();
                        $this->fences = $upload === null ? [$catalog] : [$catalog, $upload];
                        try {
                            return $this->restoreLocked($zipPath, $settings, $runId);
                        } finally {
                            $token = $this->catalogFence ?? '';
                            $this->catalogFence = null;
                            $this->fences = [];
                            RestoreFence::release($this->db, $token);
                        }
                    },
                    time(),
                );
            } catch (LeaseDenied $e) {
                throw new UserError(self::BUSY, 409, $e);
            }
        }
        $lock = $this->lock();
        try {
            return $this->restoreLocked($zipPath, $settings, $runId);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Renew every held object lease. A different token fails on that read.
     * This does not wait for a claim() blocked on the fence row, so the two
     * cannot deadlock. The open database transaction stays the owner until it ends.
     */
    private function fence(): void
    {
        foreach ($this->fences as $hold) {
            $hold->checkpoint();
        }
    }

    /** The catalog transaction rejects a fence token another restore has committed. */
    private function rejectStaleFence(): void
    {
        if ($this->catalogFence !== null) {
            RestoreFence::rejectUnless($this->db, $this->catalogFence);
        }
    }

    /**
     * DATA_DIR/tmp/restore.lock, held exclusively. Not waited for: while
     * another restore holds it, the answer is BUSY (409).
     *
     * @return resource
     */
    private function lock()
    {
        return self::openLock($this->backups->tmpDir() . '/' . self::LOCK_FILE);
    }

    /**
     * Open and lock the lock file $path (see lock()). $afterCheck runs
     * between the check of the name and the open, where another process
     * could swap the file (a test seam for N-458). $hardLink stands in for
     * link() (a test seam for a file system without hard links, N-520).
     * $wait: wait for the lock instead of BUSY (a short section, as RestoreUploads::create()).
     *
     * @param ?Closure():void $afterCheck
     * @param ?Closure(string, string):bool $hardLink
     * @return resource
     */
    public static function openLock(string $path, ?Closure $afterCheck = null, ?Closure $hardLink = null, bool $wait = false)
    {
        // N-456, N-459: never through a link or anything but a plain file, and say why.
        $named = @lstat($path);
        if ($named !== false && !self::plainFile($named)) {
            throw new UserError(self::LOCK_REFUSED);
        }
        if ($afterCheck !== null) {
            $afterCheck();
        }
        if ($named === false) {
            self::createLock($path, $hardLink ?? static fn (string $target, string $link): bool => @link($target, $link));
        }
        // A file that is there is opened read-only, which creates nothing (L-455: flock() works on a read
        // handle, whoever made the file). N-562: no cached stat of the name is used for the open.
        clearstatcache(true, $path);
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            // N-521: nothing, a link or anything but a plain file at the name by now says why, as the check does.
            clearstatcache(true, $path);
            $now = @lstat($path);

            throw new UserError($now === false || !self::plainFile($now) ? self::LOCK_REFUSED : self::FAILED);
        }
        // N-458: what was opened must be the plain file at $path, not a link swapped in meanwhile.
        clearstatcache(true, $path);
        if (!self::sameFile(fstat($fh), @lstat($path))) {
            fclose($fh);
            throw new UserError(self::LOCK_REFUSED);
        }
        if (!flock($fh, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new UserError(self::BUSY, 409);
        }

        return $fh;
    }

    /**
     * Create the lock file $path when nothing is there. N-458: PHP's
     * fopen() follows a link at the name even with 'x', so the file is made
     * under a random name and hard-linked into place: link() never follows
     * a link and fails when the name exists. Only where the file system has
     * no hard links is 'x' used.
     *
     * @param Closure(string, string):bool $hardLink link()
     */
    private static function createLock(string $path, Closure $hardLink): void
    {
        $tmp = dirname($path) . '/.' . basename($path) . '-' . bin2hex(random_bytes(8));
        $fh = @fopen($tmp, 'x');
        if ($fh === false) {
            return;   // the open below says FAILED
        }
        fclose($fh);
        try {
            if (!$hardLink($tmp, $path) && @lstat($path) === false && ($fh = @fopen($path, 'x')) !== false) {
                fclose($fh);
            }
        } finally {
            @unlink($tmp);
        }
    }

    /** @param array<int|string,int> $stat */
    public static function plainFile(array $stat): bool
    {
        return (($stat['mode'] ?? 0) & 0170000) === 0100000;
    }

    /**
     * Is the open file ($open, fstat()) the plain file the name points at ($named, lstat())?
     *
     * @param array<int|string,int>|false $open
     * @param array<int|string,int>|false $named
     */
    public static function sameFile(array|false $open, array|false $named): bool
    {
        return $open !== false && $named !== false && self::plainFile($named)
            && ($open['dev'] ?? null) === ($named['dev'] ?? -1) && ($open['ino'] ?? null) === ($named['ino'] ?? -1);
    }

    /**
     * @return array{tables:array<string,int>,photos:int,warnings:list<string>,token_cleared:bool}
     */
    private function restoreLocked(string $zipPath, bool $settings, string $run): array
    {
        // N-611: a fatal error while the backup is read leaves no temp copy in tmp/.
        $this->parts = [];
        $this->step = 'reading the backup';
        $this->cleanup->add(self::CLEANUP, function (): void {
            $this->dropParts();
        });
        try {
            $b = $this->inspect($zipPath);
        } catch (Throwable $e) {
            $this->cleanup->drop(self::CLEANUP);

            throw $e;
        }
        $zip = $b['zip'];
        $warnings = $b['warnings'];
        // Files this restore creates, removed again if it fails; the original links it would write.
        $created = [];
        $links = [];
        $folders = $this->missingFolders();
        // A fatal error (the time limit, running out of memory) skips the
        // catch below: the exception handler then removes the files and
        // folders through RequestCleanup, unless the rows are in by then
        // (legacy backup_restore_locked() does it on shutdown), and the temp
        // copies in tmp/ (N-611).
        $this->cleanup->add(self::CLEANUP, function () use (&$created, $folders): void {
            $this->discard($created);
            self::removeEmpty($folders);
            $this->dropParts();
        });
        $tmp = $this->backups->tmpDir();
        try {
            if ($this->stored !== null) {
                $this->step = 'keeping the backup before the restore';
                $this->before = $this->stored->store(StoredBackups::KIND_PRE_RESTORE, $this->by);
            }
            try {
                $this->fence();
                $this->step = 'photos';
                $written = $this->restorePhotos($zip, $b['photoFiles'], $tmp, $warnings, $created);
                $this->step = 'attachments';
                $this->restoreAttachments($zip, $b['attachmentFiles'], $tmp, $warnings, $created);
                $this->step = 'camera originals';
                $this->restoreOriginals($zip, $b['photoFiles'], $tmp, $warnings, $created, $links);
            } finally {
                $zip->close();
            }
            $this->fence();
            if ($this->staged === null) {
                $this->step = 'rows';
                $this->restoreRows($b['json'], $b['offsets']);
            } else {
                $this->restoreStaged($this->staged, $b['json'], $b['offsets'], $b['columns'], $run, (string)hash_file('sha256', $zipPath));
            }
            $this->cleanup->drop(self::CLEANUP);
        } catch (Throwable $e) {
            $this->discard($created);
            self::removeEmpty($folders);
            $this->cleanup->drop(self::CLEANUP);
            $out = $e instanceof UserError || $e instanceof LeaseDenied ? $e : new UserError(self::FAILED, 0, $e);
            if ($out->getMessage() === self::FAILED) {
                $this->logFailure($out);
            }
            throw $out;
        } finally {
            @unlink($b['json']);
        }
        $this->fence();
        $this->linkOriginals($links, $warnings);
        $cleared = false;
        // Inside the settings and undo units: on the hosted database a step of that unit's batch, else the
        // UPDATE in the unit's transaction (rejectStaleFence()).
        $owned = $this->catalogFence === null ? null : function (?UnitOfWork $uow = null): void {
            if ($uow !== null && $this->catalogFence !== null && Writes::onD1($this->db)) {
                RestoreFence::assert($uow, $this->catalogFence);

                return;
            }
            $this->rejectStaleFence();
        };
        if (is_array($b['settings']) && !$settings) {
            $warnings[] = 'The settings were not restored: that needs the permission to change settings.';
        } elseif (is_array($b['settings'])) {
            $this->fence();
            $r = $this->settings->import((string)json_encode(['format' => 'apotheke-settings', 'settings' => $b['settings']]), null, $owned);
            foreach ($r['errors'] as $k => $msg) {
                $warnings[] = "Setting $k was not restored: $msg";
            }
            $cleared = $r['token_cleared'];
        }
        // Undo tokens refer to the data that was just replaced.
        if ($this->backups->tableExists('undo_tokens')) {
            $this->fence();
            $this->undo->prune(true, $owned);
        }

        $this->logRestore($b['tables']);

        return ['tables' => $b['tables'], 'photos' => $written, 'warnings' => $warnings, 'token_cleared' => $cleared];
    }

    /**
     * One server log line for a restore that failed: its step, and the class of each cause. Only the database's own
     * diagnostic (an SQLite class and a fixed phrase or identifier, D1HttpExecutor) carries a message; nothing from
     * the backup, no SQL and no value is written.
     */
    private function logFailure(Throwable $e): void
    {
        $causes = [];
        for ($c = $e->getPrevious(); $c !== null && count($causes) < 4; $c = $c->getPrevious()) {
            $causes[] = $c instanceof WriteFailed || ($c instanceof \RuntimeException && $c->getPrevious() === null && preg_match('/^(?:SQLITE_[A-Z_]+|Error): [A-Za-z0-9 ,._:]+$/D', $c->getMessage()) === 1)
                ? $c::class . ': ' . $c->getMessage() : $c::class;
        }
        $line = '[apotheke] restore failed at ' . $this->step . ($causes === [] ? '' : ': ' . implode(' <- ', $causes));
        try {
            ($this->log ?? static function (string $line): void {
                error_log($line);
            })($line);
        } catch (Throwable) {
            // Best effort: a log that cannot be written does not change the restore's answer.
        }
    }

    /**
     * The activity row of a restore that went in for a signed-in account: after the swap (the history is one of the
     * replaced tables), for the whole catalog (no item), with who restored and the backup kept before. Best effort:
     * the restore stands.
     *
     * @param array<string,int> $tables
     */
    private function logRestore(array $tables): void
    {
        if ($this->by === null || !$this->backups->tableExists('activity')) {
            return;   // the command line, or sign-in off: as before, no row
        }
        $details = ['items' => (int)($tables['items'] ?? 0)] + ($this->before !== null ? ['backup' => $this->before['id']] : []);
        try {
            Writes::check($this->db->table('activity')->insert([
                'item_id' => null,
                'item_name' => self::CATALOG,
                'action' => self::RESTORED_ACTION,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_at' => (new \Apotheke\Core\Libraries\Clock())->now(),
                'actor' => $this->by,
            ]), 'restore history');
        } catch (Throwable) {
            // The catalog is already replaced; a missing history row does not undo that.
        }
    }

    /**
     * Open the zip, check its entries, copy backup.json out and check it
     * (legacy backup_open()). The caller closes the zip and removes the
     * copy ('json').
     *
     * @return Inspected
     */
    private function inspect(string $zipPath): array
    {
        $zip = new ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UserError('That file is not a zip archive.');
        }
        $json = null;
        try {
            $this->checkEntries($zip);
            $json = $this->extract($zip, 'backup.json', $this->backups->tmpDir(), 'This is not an Apotheke backup (backup.json is missing).');
            if ($json === null) {
                throw new UserError('This is not an Apotheke backup (backup.json is missing).');
            }
            $b = $this->scan($json);
            $warnings = [];
            $photos = 0;
            foreach ($b['photoFiles'] as $f) {
                if ($zip->locateName('images/' . $f) === false) {
                    $warnings[] = "Photo $f is missing from the archive.";
                    continue;
                }
                $photos++;
            }
            foreach ($b['attachmentFiles'] as $f) {
                if ($zip->locateName('files/' . $f) === false) {
                    $warnings[] = "Attachment $f is missing from the archive.";
                }
            }

            return ['zip' => $zip, 'json' => $json, 'photos' => $photos, 'warnings' => $warnings] + $b;
        } catch (Throwable $e) {
            $zip->close();
            if ($json !== null) {
                @unlink($json);
            }
            throw $e;
        }
    }

    /** Names, count and sizes, from the zip's directory alone. */
    private function checkEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new UserError('The backup has too many files.');
        }
        $total = 0;
        $largest = null;
        $seen = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $s = $zip->statIndex($i);
            $size = (int)($s['size'] ?? 0);
            $total += $size;
            $name = (string)($s['name'] ?? '');
            if (!self::entryNameOk($name)) {
                throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
            }
            // Stricter than legacy: one entry per name, so the check and the restore read the same bytes.
            if (isset($seen[$name])) {
                throw new UserError('Unexpected file in backup: ' . mb_substr($name, 0, 80));
            }
            $seen[$name] = true;
            if ($size > self::MAX_ENTRY && $name !== 'backup.json') {
                $largest ??= $name;
            }
        }
        if ($total > self::MAX_UNPACKED) {
            throw new UserError('The backup is too large to unpack.');
        }
        if ($largest !== null) {
            throw new UserError('A file in the backup is too large to restore: ' . mb_substr($largest, 0, 80));
        }
        if ($zip->statName('backup.json') === false) {
            throw new UserError('This is not an Apotheke backup (backup.json is missing).');
        }
    }

    /**
     * Copy entry $name to a new temp file in $tmp and return its path, or
     * null when the zip has no such entry. One that is damaged (its size or
     * CRC is not what the zip says) fails with $damaged; one that cannot be
     * written (a full disk) with FAILED. Nothing is left behind on failure.
     */
    private function extract(ZipArchive $zip, string $name, string $tmp, string $damaged = self::FAILED): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        $size = (int)$stat['size'];
        $in = @$zip->getStream($name);
        if ($in === false) {
            throw new UserError($damaged);
        }
        $path = $tmp . '/restore-' . bin2hex(random_bytes(8)) . '.part';
        // N-611: noted before it is made, so a fatal error right after the open still finds it
        $this->parts[] = $path;
        $out = @fopen($path, 'xb');
        if ($out === false) {
            array_pop($this->parts);
            fclose($in);
            throw new UserError(self::FAILED);
        }
        $error = null;
        try {
            $crc = hash_init('crc32b');
            $n = 0;
            while ($n <= $size && is_string($chunk = @fread($in, self::CHUNK)) && $chunk !== '') {
                $n += strlen($chunk);
                hash_update($crc, $chunk);
                if (@fwrite($out, $chunk) !== strlen($chunk)) {
                    $error = self::FAILED;
                    break;
                }
            }
            if ($error === null && ($n !== $size || hexdec(hash_final($crc)) !== (int)$stat['crc'])) {
                $error = $damaged;
            }
        } finally {
            fclose($in);
            // every byte, flushed and closed: a short write (a full disk) fails the restore
            $flushed = @fflush($out);
            if (!@fclose($out) || !$flushed) {
                $error ??= self::FAILED;
            }
            if ($error !== null) {
                @unlink($path);
            }
        }
        if ($error !== null) {
            throw new UserError($error);
        }

        return $path;
    }

    /** May $bytes more be taken from PHP's memory? (L-451) */
    private static function guard(): Closure
    {
        return static fn (int $bytes): bool => self::fits($bytes, self::headroom());
    }

    /**
     * Check backup.json (copied out to $path) a value at a time, in
     * legacy's order: valid JSON, format, version, tables and schema, the
     * schema, each table (name, shape, columns, rows), the core tables.
     * Stricter than legacy: a key twice in the backup's own objects, a
     * table's rows before its columns, and true or false as a value (N-448)
     * are refused.
     *
     * @return array{tables:array<string,int>,created_at:string,driver:string,photoFiles:list<string>,attachmentFiles:list<string>,settings:mixed,offsets:array<string,int>,columns:array<string,list<string>>}
     */
    private function scan(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        $top = [];
        $scan = ['counts' => [], 'offsets' => [], 'files' => [], 'error' => null, 'twice' => false, 'columns' => []];
        $tables = false;
        try {
            $r = new JsonReader($fh, self::DEPTH, self::CHUNK, self::guard());
            if ($r->kind() !== '{') {
                $r->skip();
            } else {
                foreach ($r->entries() as $key) {
                    if (array_key_exists($key, $top) || ($key === 'tables' && $tables)) {
                        $scan['twice'] = true;
                    }
                    if ($key === 'tables' && in_array($r->kind(), ['{', '['], true)) {
                        $tables = true;
                        $this->scanTables($r, $scan);
                    } elseif (in_array($key, ['format', 'version', 'created_at', 'driver', 'schema', 'tables', 'settings'], true)) {
                        $top[$key] = $r->value();
                    } else {
                        $top[$key] = null;
                    }
                }
            }
            $r->finish();
        } catch (JsonException) {
            throw new UserError('backup.json is not valid JSON.');
        } catch (LengthException) {
            throw new UserError(self::TOO_LARGE);
        } finally {
            fclose($fh);
        }
        if ($scan['twice']) {
            throw new UserError('backup.json names the same key twice.');
        }
        if (($top['format'] ?? null) !== Backups::FORMAT) {
            throw new UserError('This is not an Apotheke backup.');
        }
        if (!is_int($top['version'] ?? null) || $top['version'] > Backups::VERSION) {
            throw new UserError('This backup was made by a newer version of Apotheke.');
        }
        if (!($tables || is_array($top['tables'] ?? null)) || !is_array($top['schema'] ?? null)) {
            throw new UserError('The backup is incomplete.');
        }
        $unknown = array_diff(array_map(static fn (mixed $v): string => is_scalar($v) ? (string)$v : 'Array', $top['schema']), ($this->known)());
        if ($unknown !== []) {
            throw new UserError('This backup needs a newer version of Apotheke (schema ' . implode(', ', $unknown) . ').');
        }
        if ($scan['error'] !== null) {
            throw new UserError($scan['error']);
        }
        foreach (['locations', 'categories', 'items'] as $t) {
            if (!isset($scan['counts'][$t])) {
                throw new UserError("The backup has no $t table.");
            }
        }
        $photos = $scan['files']['photo'] ?? [];
        $attachments = $scan['files']['attachment'] ?? [];
        ksort($photos, SORT_STRING);
        ksort($attachments, SORT_STRING);

        return ['tables' => $scan['counts'], 'offsets' => $scan['offsets'], 'columns' => $scan['columns'],
            'photoFiles' => array_map('strval', array_keys($photos)), 'attachmentFiles' => array_map('strval', array_keys($attachments)),
            'settings' => $top['settings'] ?? null,
            'created_at' => is_scalar($top['created_at'] ?? null) ? (string)$top['created_at'] : '', 'driver' => is_scalar($top['driver'] ?? null) ? (string)$top['driver'] : '?'];
    }

    /**
     * Each table of backup.json's "tables": its rows counted and checked,
     * the file names they refer to collected, where its value starts noted
     * (for the rows pass). The first problem is kept in $scan['error'].
     *
     * @param array{counts:array<string,int>,offsets:array<string,int>,files:array<string,array<string,true>>,error:?string,twice:bool,columns:array<string,list<string>>} $scan
     */
    private function scanTables(JsonReader $r, array &$scan): void
    {
        $allowed = Backups::tables();
        foreach ($r->entries() as $t) {
            if (isset($scan['counts'][$t]) || isset($scan['offsets'][$t])) {
                $scan['twice'] = true;
            }
            if (!in_array($t, $allowed, true)) {
                $scan['error'] ??= 'Unknown table in backup: ' . mb_substr((string)$t, 0, 40);
                $scan['offsets'][(string)$t] = -1;
                $r->skip();
                continue;
            }
            /** @var string $t */
            $scan['offsets'][$t] = $r->offset();
            $error = $this->scanTable($r, $t, $scan);
            $scan['error'] ??= $error;
        }
    }

    /**
     * @param array{counts:array<string,int>,offsets:array<string,int>,files:array<string,array<string,true>>,error:?string,twice:bool,columns:array<string,list<string>>} $scan
     * @return ?string the table's first problem
     */
    private function scanTable(JsonReader $r, string $t, array &$scan): ?string
    {
        $malformed = "Table $t is malformed.";
        if (!in_array($r->kind(), ['{', '['], true)) {
            $r->skip();

            return $malformed;
        }
        $columns = null;
        $error = null;
        $rows = null;
        $badShape = false;
        $seen = [];
        $file = isset(self::FILE_COLUMNS[$t]) ? ($t === 'item_attachments' ? 'attachment' : 'photo') : null;
        foreach ($r->entries() as $k) {
            if (isset($seen[$k])) {
                $scan['twice'] = true;
            }
            $seen[$k] = true;
            if ($k === 'columns') {
                $columns = $r->value();
                if (!is_array($columns)) {
                    continue;
                }
                $have = $this->backups->columns($t);
                $named = [];
                foreach ($columns as $c) {
                    if (!is_string($c) || !in_array($c, $have, true)) {
                        $error ??= "Unknown column $t." . mb_substr(is_scalar($c) ? (string)$c : 'Array', 0, 40) . ' in backup.';
                        break;
                    }
                    // N-1704: stricter than legacy, one value per column (SQLite would take `id, id`), on every path
                    if (isset($named[$c])) {
                        $error ??= "Column $t.$c is named twice in backup.";
                        break;
                    }
                    $named[$c] = true;
                }
                continue;
            }
            if ($k !== 'rows') {
                continue;
            }
            // Stricter than legacy: the columns come first, as backups write them.
            if (!in_array($r->kind(), ['{', '['], true) || !is_array($columns)) {
                $r->skip();
                $badShape = true;
                continue;
            }
            $n = count($columns);
            $at = $file === null ? false : array_search(self::FILE_COLUMNS[$t], $columns, true);
            $rows = 0;
            foreach ($r->entries() as $_) {
                $row = $r->value();
                $rows++;
                if (!is_array($row) || count($row) !== $n) {
                    $error ??= "A row in $t has the wrong number of values.";
                    continue;
                }
                foreach ($row as $v) {
                    if (is_bool($v) || (!is_scalar($v) && $v !== null)) {
                        $error ??= "A value in $t is not a plain value.";
                        break;
                    }
                }
                if ($at !== false && ($name = self::fileName($t, $row[$at] ?? null)) !== null) {
                    $scan['files'][(string)$file][$name] = true;
                }
            }
        }
        if ($badShape || !is_array($columns) || $rows === null) {
            return $malformed;
        }
        $scan['counts'][$t] = $rows;
        $scan['columns'][$t] = array_values(array_map('strval', $columns));

        return $error;
    }

    /** The bytes PHP may still allocate, or null without a memory limit. */
    public static function headroom(): ?int
    {
        $limit = ini_parse_quantity((string)ini_get('memory_limit'));

        return $limit <= 0 ? null : $limit - memory_get_usage();
    }

    /** Does $need, plus the margin, fit in $headroom (null: no limit)? */
    public static function fits(int $need, ?int $headroom): bool
    {
        return $headroom === null || $need + self::MEMORY_MARGIN <= $headroom;
    }

    /**
     * Write a file that is not there yet from the temp file $path, and note
     * it, so a failed restore can remove it again.
     *
     * @param list<array{Storage,string}> $created
     */
    private static function createFrom(Storage $store, string $key, string $path, array &$created): void
    {
        if ($store instanceof R2Storage) {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new UserError(self::FAILED);
            }
            self::create($store, $key, $bytes, $created);

            return;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        // Noted before it is written (the name is new; removing a missing key is fine), so a
        // fatal error right after the write still removes it.
        $created[] = [$store, $key];
        try {
            $store->writeStream($key, $fh);
        } finally {
            fclose($fh);
        }
    }

    /** @param list<array{Storage,string}> $created */
    private static function create(Storage $store, string $key, string $bytes, array &$created): void
    {
        if ($store instanceof R2Storage) {
            // The name is the content. A file that is already there is kept, as the exists()
            // check does on the local path. Only an object this call created is removed on failure.
            if (!RestoreMutation::createIfAbsent($store, $key, $bytes)) {
                return;
            }
            $created[] = [$store, $key];

            return;
        }
        $created[] = [$store, $key];   // before the write, as in createFrom()
        $store->write($key, $bytes);
    }

    /** N-611: remove the temp copies a restore stopped by a fatal error still has in tmp/. */
    private function dropParts(): void
    {
        foreach ($this->parts as $path) {
            if (is_file($path) && !is_link($path)) {
                @unlink($path);
            }
        }
        $this->parts = [];
    }

    /** @param list<array{Storage,string}> $created */
    private function discard(array $created): void
    {
        if ($created === []) {
            return;
        }
        if ($this->catalogFence === null) {
            $this->deleteCreated($created);

            return;
        }
        if (Writes::onD1($this->db)) {
            // No row lock on the hosted database: the object lease is renewed first (no other restore can claim
            // the fence while it is held), then the fence is checked as one batch, then the deletes run.
            try {
                $this->fence();
                $this->rejectStaleFence();
            } catch (Throwable) {
                return;
            }
            $this->deleteCreated($created);

            return;
        }
        try {
            // The row stays locked until these deletes return, so the next claim waits.
            // Once that claim has committed, the deletes are skipped: R2 delete is not
            // conditional, and the newer restore may be keeping these names.
            Writes::transaction($this->db, function () use ($created): void {
                $this->rejectStaleFence();
                $this->deleteCreated($created);
            });
        } catch (Throwable) {
            // A lost fence, or a lock that cannot be taken, leaves the objects.
            // Deleting them without the row would remove a newer restore's files.
            return;
        }
    }

    /** @param list<array{Storage,string}> $created */
    private function deleteCreated(array $created): void
    {
        foreach (array_reverse($created) as [$store, $key]) {
            try {
                $store->delete($key);
            } catch (Throwable) {
                // Best effort: the name is random and no row refers to it.
            }
        }
    }

    /**
     * N-453: the local folders the files go into that are not there yet; a
     * failed restore removes them again when they are empty.
     *
     * @return list<string>
     */
    private function missingFolders(): array
    {
        $data = rtrim($this->backups->dataDir(), '/');

        return array_values(array_filter([$data . '/images', $data . '/thumbs', $data . '/files', rtrim($this->backups->originalsDir(), '/')],
            static fn (string $d): bool => !is_dir($d)));
    }

    /** @param list<string> $folders */
    private static function removeEmpty(array $folders): void
    {
        foreach ($folders as $d) {
            if (is_dir($d) && !is_link($d)) {
                @rmdir($d);   // only when empty
            }
        }
    }

    /**
     * The camera-original links, once the rows are in, and each linked
     * original 0640 like a new one (legacy originals_chmod_file(); an
     * original kept from an older install is changed), only while it is a plain
     * file at its name (L-560: never through a link). Each map entry goes
     * through a temp file in the map folder and is renamed into place, so it
     * is never written through a link. A map name that is a link, or anything
     * but a plain file, is skipped with the camera-files warning and left as
     * it is; the rows are in by now, so it no longer stops the restore
     * (legacy backup_restore_link_originals()). A write that fails stops the
     * rest with the same warning.
     *
     * @param array<string,string> $links map key => original name
     * @param list<string> $warnings
     */
    private function linkOriginals(array $links, array &$warnings): void
    {
        $warning = 'Camera files could not be restored (originals directory is not writable).';
        $dir = rtrim($this->backups->originalsDir(), '/');
        foreach ($links as $key => $base) {
            $file = $dir . '/' . $base;
            clearstatcache(true, $file);
            $stat = @lstat($file);
            // A camera file this restore wrote is 0640 from the start (the originals storage's mode).
            // Only one kept from an older install with another mode is changed, by name, while it is a
            // plain file; the restore runs as DATA_DIR's owner (N-582). Ignored where the filesystem cannot chmod.
            if ($stat !== false && self::plainFile($stat) && ($stat['mode'] & 0777) !== 0640) {
                @chmod($file, 0640);
            }
            clearstatcache(true, $dir . '/' . $key);
            $named = @lstat($dir . '/' . $key);
            if ($named !== false && !self::plainFile($named)) {
                if (!in_array($warning, $warnings, true)) {
                    $warnings[] = $warning;
                }
                continue;
            }
            try {
                $this->fence();
                if ($this->originals instanceof R2Storage) {
                    $token = $this->catalogFence;
                    if ($token === null) {
                        throw new LeaseDenied('The lease is held.');
                    }
                    // A local restore overwrites this pointer. putIfMissing would keep the old one.
                    RestoreMutation::replace($this->db, $token, $this->originals, $key, $base);
                } else {
                    $this->originals->write($key, $base);
                }
            } catch (LeaseDenied $e) {
                throw $e;
            } catch (Throwable) {
                $warnings[] = $warning;

                return;
            }
        }
    }

    /**
     * Served photos and thumbnails: each one must be a JPEG; one that exists
     * is kept. A missing thumbnail is drawn from the photo.
     *
     * @param list<string> $files
     * @param list<string> $warnings
     * @param list<array{Storage,string}> $created
     */
    private function restorePhotos(ZipArchive $zip, array $files, string $tmp, array &$warnings, array &$created): int
    {
        $written = 0;
        foreach ($files as $f) {
            $this->fence();
            $photo = $this->extract($zip, 'images/' . $f, $tmp);
            if ($photo === null) {
                continue;
            }
            try {
                $info = @getimagesize($photo);
                if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
                    $warnings[] = "Photo $f is not a JPEG and was skipped.";
                    continue;
                }
                if (!$this->data->exists('images/' . $f)) {
                    self::createFrom($this->data, 'images/' . $f, $photo, $created);
                }
                if (!$this->data->exists('thumbs/' . $f)) {
                    $thumb = $this->extract($zip, 'thumbs/' . $f, $tmp);
                    try {
                        $ti = $thumb === null ? false : @getimagesize($thumb);
                        if (is_string($thumb) && $ti !== false && $ti[2] === IMAGETYPE_JPEG) {
                            self::createFrom($this->data, 'thumbs/' . $f, $thumb, $created);
                        } elseif (!self::overDecodeLimits((int)$info[0], (int)$info[1]) && ($im = @imagecreatefromjpeg($photo)) !== false) {
                            self::create($this->data, 'thumbs/' . $f, $this->thumbJpeg($im), $created);
                        }
                    } finally {
                        if ($thumb !== null) {
                            @unlink($thumb);
                        }
                    }
                }
                $written++;
            } finally {
                @unlink($photo);
            }
        }

        return $written;
    }

    /** Stricter than legacy: a thumbnail is drawn again only within Media's decode limits. */
    public static function overDecodeLimits(int $w, int $h): bool
    {
        return ImageLimits::rasterHeaderOverLimit($w, $h, IMAGETYPE_JPEG) || (float)$w * (float)$h > (float)ImageLimits::GD_MAX_PIXELS;
    }

    /** Legacy imagejpeg(image_fit($im, image_thumb_max()), …, 76). */
    private function thumbJpeg(GdImage $src): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $s = min(1, $this->thumbMax / max($w, $h));
        $nw = max(1, (int)round($w * $s));
        $nh = max(1, (int)round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white === false ? 0 : $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($dst, null, self::THUMB_QUALITY);

        return (string)ob_get_clean();
    }

    /**
     * Attachments whose content is the type their name says; one that exists is kept.
     * files/ is made with legacy's mode (0770 before the umask), also when the
     * backup has no attachments, as legacy does.
     *
     * @param list<string> $files
     * @param list<string> $warnings
     * @param list<array{Storage,string}> $created
     */
    private function restoreAttachments(ZipArchive $zip, array $files, string $tmp, array &$warnings, array &$created): void
    {
        $dir = rtrim($this->backups->dataDir(), '/') . '/files';
        if (!is_dir($dir)) {
            @mkdir($dir, self::FILES_MODE, true);   // the app does not make it at start; a failure shows at the first write
        }
        foreach ($files as $f) {
            $this->fence();
            $path = $this->extract($zip, 'files/' . $f, $tmp);
            if ($path === null) {
                continue;
            }
            try {
                if ($this->data->exists('files/' . $f)) {
                    continue;
                }
                if (Attachments::sniff((string)@file_get_contents($path, false, null, 0, 16)) !== pathinfo($f, PATHINFO_EXTENSION)) {
                    $warnings[] = "Attachment $f does not match its type and was skipped.";
                    continue;
                }
                self::createFrom($this->data, 'files/' . $f, $path, $created);
            } finally {
                @unlink($path);
            }
        }
    }

    /**
     * Camera files back next to their served photos (legacy
     * backup_restore_originals()). Bytes are written only when missing; the
     * map entry that points at the file from this zip goes into $links, for
     * after the rows commit. originals/index.json is read a value at a
     * time; when it is not valid JSON nothing of it is used.
     *
     * @param list<string> $photoFiles
     * @param list<string> $warnings
     * @param list<array{Storage,string}> $created
     * @param array<string,string> $links
     */
    private function restoreOriginals(ZipArchive $zip, array $photoFiles, string $tmp, array &$warnings, array &$created, array &$links): void
    {
        $path = $this->extract($zip, 'originals/index.json', $tmp);
        if ($path === null) {
            return;
        }
        $photos = array_flip($photoFiles);
        $pairs = [];
        try {
            if ((int)@filesize($path) === 0) {
                return;
            }
            $fh = @fopen($path, 'rb');
            if ($fh === false) {
                throw new UserError(self::FAILED);
            }
            try {
                $r = new JsonReader($fh, 8, self::CHUNK, self::guard());
                if (in_array($r->kind(), ['{', '['], true)) {
                    foreach ($r->entries() as $served) {
                        $base = $r->value();
                        if (is_string($served) && ImageStore::validName($served) && isset($photos[$served]) && is_string($base) && self::originalNameOk($base)) {
                            $pairs[$served] = $base;
                        } else {
                            unset($pairs[$served]);   // the last value of a key counts, as json_decode() keeps it
                        }
                    }
                } else {
                    $r->skip();
                }
                $r->finish();
            } catch (JsonException|LengthException) {
                $warnings[] = 'originals/index.json was skipped.';

                return;
            } finally {
                fclose($fh);
            }
        } finally {
            @unlink($path);
        }
        foreach ($pairs as $served => $base) {
            $data = $this->extract($zip, 'originals/' . $base, $tmp);
            if ($data === null) {
                $warnings[] = "Original $base is missing from the archive.";
                continue;
            }
            // L-560: a link at a camera file's name, to anything or nowhere, stops the restore
            if (is_link(rtrim($this->backups->originalsDir(), '/') . '/' . $base)) {
                @unlink($data);

                throw new UserError(self::FAILED);
            }
            try {
                if (!$this->originals->exists($base)) {
                    self::createFrom($this->originals, $base, $data, $created);
                }
                $links['map/' . substr((string)$served, 0, 32)] = $base;
            } catch (Throwable) {
                $warnings[] = 'Camera files could not be restored (originals directory is not writable).';

                return;
            } finally {
                @unlink($data);
            }
        }
    }

    /**
     * The rows: one transaction, children deleted first, parents inserted
     * first, then every id sequence past the highest id restored. Each
     * table's rows are read from backup.json ($json, where $offsets says
     * the table starts) and inserted one by one through a prepared
     * statement, so no value is copied into SQL text (L-451).
     *
     * Lock first: on PostgreSQL the catalog tables are locked against other
     * writers before the first delete, so a write that started earlier
     * finishes first and is replaced, and one that starts later waits. On
     * SQLite the first statement is a write (the delete), which takes the
     * database's one write lock. A PostgreSQL restore that loses a deadlock
     * (a write that holds one catalog table and then wants another) is
     * tried again, and after ATTEMPTS refused with CONFLICT (N-445).
     *
     * @param array<string,int> $offsets
     */
    private function restoreRows(string $json, array $offsets): void
    {
        $tables = array_values(array_filter(Backups::tables(), fn (string $t): bool => $this->backups->tableExists($t)));
        $pg = $this->backups->driver() === 'pgsql';
        $fh = @fopen($json, 'rb');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    Writes::transaction($this->db, function () use ($tables, $offsets, $pg, $fh): void {
                        $this->rejectStaleFence();
                        $this->fence();
                        if ($pg) {
                            self::pg($this->pgConnection(), 'LOCK TABLE ' . implode(', ', $tables) . ' IN SHARE ROW EXCLUSIVE MODE', null, 'lock tables');
                        } else {
                            Writes::check($this->db->query('PRAGMA defer_foreign_keys = ON'), 'defer foreign keys');
                        }
                        foreach (array_reverse($tables) as $t) {
                            Writes::check($this->db->query("DELETE FROM $t"), 'delete ' . $t);
                        }
                        foreach ($tables as $t) {
                            $this->fence();
                            if (($offsets[$t] ?? -1) >= 0) {
                                $this->insertRows($fh, $t, $offsets[$t], $pg);
                            }
                        }
                        $this->advanceSequences($tables, $pg);
                        $this->rejectStaleFence();
                    });

                    return;
                } catch (WriteFailed $e) {
                    if ($e->getCode() !== self::CONFLICT_CODE) {
                        throw new UserError(self::FAILED, 0, $e);
                    }
                    if ($attempt >= self::ATTEMPTS) {
                        throw new UserError(self::CONFLICT, 409, $e);
                    }
                    usleep(random_int(50_000, 250_000));
                } catch (LengthException) {
                    throw new UserError(self::TOO_LARGE);
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * The rows through RestoreStage (batch-only databases): measured, checked
     * against the size limit before anything is written, staged under the
     * stage's lock and swapped in as one batch marked $run. The copies are
     * dropped and the lock given up however it ends.
     *
     * @param array<string,int> $offsets
     * @param array<string,list<string>> $columns
     */
    private function restoreStaged(SqlExecutor $exec, string $json, array $offsets, array $columns, string $run, string $sha256): void
    {
        $tables = array_values(array_filter(Backups::tables(), fn (string $t): bool => $this->backups->tableExists($t)));
        $restored = array_values(array_filter($tables, static fn (string $t): bool => ($offsets[$t] ?? -1) >= 0 && isset($columns[$t])));
        $stage = new RestoreStage($exec);
        $fh = @fopen($json, 'rb');
        if ($fh === false) {
            throw new UserError(self::FAILED);
        }
        try {
            // Measured only against a limit (RESTORE_DB_MAX_MB 0 is none).
            if ($this->dbMaxBytes > 0) {
                $bytes = 0;
                foreach ($restored as $t) {
                    $bytes += $stage->bytes($columns[$t], $this->tableRows($fh, $t, $offsets[$t]));
                }
                $this->step = 'size check';
                RestoreStage::preflight($stage->liveBytes(), $bytes, $this->dbMaxBytes);
            }
            try {
                $this->fence();
                $this->step = 'staging copies';
                $stage->begin($restored);
                $staged = [];
                foreach ($restored as $t) {
                    $this->fence();
                    $this->step = 'staging ' . $t;
                    $staged[$t] = ['columns' => $columns[$t], 'rows' => $stage->stage($t, $columns[$t], $this->tableRows($fh, $t, $offsets[$t]), $this->fence(...))];
                }
                $withId = array_values(array_filter($tables, fn (string $t): bool => in_array('id', $this->backups->columns($t), true)));
                $this->fence();
                $this->step = 'swap';
                $stage->swap($tables, $staged, $withId, $run, $sha256, $this->catalogFence);
            } finally {
                try {
                    $stage->finish();
                } catch (Throwable) {
                    // the sweep drops the copies, and the lock expires (RestoreStage::LEASE)
                }
            }
        } catch (WriteFailed $e) {
            throw new UserError(self::FAILED, 0, $e);
        } catch (LengthException) {
            throw new UserError(self::TOO_LARGE);
        } finally {
            fclose($fh);
        }
    }

    /**
     * One table's rows (a list of values each), read from backup.json where its value starts.
     *
     * @param resource $fh
     * @return \Generator<int, list<scalar|null>>
     */
    private function tableRows($fh, string $t, int $offset): \Generator
    {
        if (fseek($fh, $offset) !== 0) {
            throw new WriteFailed('Database write failed: read ' . $t);
        }
        $r = new JsonReader($fh, self::DEPTH - 2, self::CHUNK, self::guard(), $offset);
        foreach ($r->entries() as $k) {
            if ($k === 'rows') {
                foreach ($r->entries() as $_) {
                    /** @var list<scalar|null> $row */
                    $row = array_values((array)$r->value());
                    yield $row;
                }
            }
        }
    }

    /**
     * One table's rows, from where its value starts in backup.json.
     *
     * @param resource $fh
     */
    private function insertRows($fh, string $t, int $offset, bool $pg): void
    {
        if (fseek($fh, $offset) !== 0) {
            throw new WriteFailed('Database write failed: read ' . $t);
        }
        // Inside the document and its "tables": two levels of legacy's depth are taken.
        $r = new JsonReader($fh, self::DEPTH - 2, self::CHUNK, self::guard(), $offset);
        $cols = null;
        foreach ($r->entries() as $k) {
            if ($k === 'columns') {
                $cols = $r->value();
                continue;
            }
            if ($k !== 'rows' || !is_array($cols)) {
                continue;
            }
            /** @var list<string> $cols */
            $sql = "INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(',', $pg ? array_map(static fn (int $i): string => '$' . $i, range(1, max(1, count($cols)))) : array_fill(0, max(1, count($cols)), '?')) . ')';
            $insert = null;
            foreach ($r->entries() as $_) {
                $row = $r->value();
                /** @var list<int|float|string|null> $binds */
                $binds = array_values((array)$row);
                $bytes = array_sum(array_map(static fn (mixed $v): int => is_string($v) ? strlen($v) : 8, $binds));
                if (!self::fits($bytes, self::headroom())) {   // the driver copies each value once
                    throw new LengthException('A row is too large for the memory left.');
                }
                $insert ??= $pg ? $this->pgInsert($sql, $t) : $this->sqliteInsert($sql, $t);
                $this->fence();
                $insert($binds);
            }
        }
    }

    /** @return Closure(list<int|float|string|null>):void */
    private function pgInsert(string $sql, string $t): Closure
    {
        $conn = $this->pgConnection();
        self::pgResult($conn, @pg_send_prepare($conn, '', $sql), 'prepare ' . $t);

        return static function (array $binds) use ($conn, $t): void {
            self::pgResult($conn, @pg_send_execute($conn, '', array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, $binds)), 'insert ' . $t);
        };
    }

    /** @return Closure(list<int|float|string|null>):void */
    private function sqliteInsert(string $sql, string $t): Closure
    {
        $conn = $this->db->connID;
        if (!$conn instanceof SQLite3) {
            throw new WriteFailed('Database write failed: ' . $t);
        }
        try {
            $stmt = @$conn->prepare($sql);
        } catch (Throwable) {
            $stmt = false;
        }
        if ($stmt === false) {
            throw new WriteFailed('Database write failed: prepare ' . $t);
        }

        return static function (array $binds) use ($stmt, $t): void {
            try {
                $stmt->reset();
                $stmt->clear();
                foreach ($binds as $i => $v) {
                    $stmt->bindValue($i + 1, $v, match (true) {
                        $v === null => SQLITE3_NULL,
                        is_int($v) => SQLITE3_INTEGER,
                        is_float($v) => SQLITE3_FLOAT,
                        default => SQLITE3_TEXT,
                    });
                }
                $ok = @$stmt->execute();
            } catch (Throwable) {
                $ok = false;
            }
            if ($ok === false) {
                throw new WriteFailed('Database write failed: insert ' . $t);
            }
            $ok->finalize();
        };
    }

    private function pgConnection(): PgConnection
    {
        $conn = $this->db->connID;
        if (!$conn instanceof PgConnection) {
            throw new WriteFailed('Database write failed: connection');
        }

        return $conn;
    }

    /**
     * One statement on PostgreSQL.
     *
     * @param list<string|null>|null $params
     */
    private static function pg(PgConnection $conn, string $sql, ?array $params, string $step): void
    {
        self::pgResult($conn, $params === null ? @pg_send_query($conn, $sql) : @pg_send_query_params($conn, $sql, $params), $step);
    }

    /** The results of what was sent; an error throws WriteFailed, with CONFLICT_CODE for a deadlock or a serialization failure. */
    private static function pgResult(PgConnection $conn, bool $sent, string $step): void
    {
        if (!$sent) {
            throw new WriteFailed('Database write failed: ' . $step);
        }
        $state = null;
        while (($res = pg_get_result($conn)) !== false) {
            if (pg_result_status($res) === PGSQL_FATAL_ERROR) {
                $state ??= (string)pg_result_error_field($res, PGSQL_DIAG_SQLSTATE);
            }
        }
        if ($state !== null) {
            throw new WriteFailed('Database write failed: ' . $step, in_array($state, ['40P01', '40001'], true) ? self::CONFLICT_CODE : 0);
        }
    }

    /**
     * Never hand out an id again: past the highest restored id and, for
     * items, past every id in the history (the activity log outlives
     * deleted items).
     *
     * @param list<string> $tables
     */
    private function advanceSequences(array $tables, bool $pg): void
    {
        foreach ($tables as $t) {
            if (!in_array('id', $this->backups->columns($t), true)) {
                continue;
            }
            $max = "SELECT MAX(id) AS m FROM $t" . ($t === 'items' && in_array('activity', $tables, true) ? ' UNION ALL SELECT MAX(item_id) AS m FROM activity' : '');
            $next = (int)(Writes::rows($this->db, "SELECT COALESCE(MAX(m), 0) AS n FROM ($max) x")[0]['n'] ?? 0);
            if ($pg) {
                // identity columns do not advance on explicit ids
                Writes::rows($this->db, "SELECT setval(pg_get_serial_sequence('$t', 'id'), " . ($next + 1) . ', false) AS v');
            } else {
                Writes::check($this->db->query('DELETE FROM sqlite_sequence WHERE name = ?', [$t]), 'sequence');
                Writes::check($this->db->query('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$t, (string)$next]), 'sequence');   // text, as legacy binds it
            }
        }
    }
}
