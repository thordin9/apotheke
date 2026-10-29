<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Restorer;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use Tests\Support\TestDatabase;

/**
 * N-701: the two N-611 parts a fatal error depends on. A fatal error skips
 * every catch; the exception handler then runs RequestCleanup. Here a hook
 * runs RequestCleanup at the point a fatal error could strike, records what
 * is left, and stops the restore. The process-level fatal error test is
 * tests/Unit/Ci4BackupRestoreChecksTest.php.
 */
final class RestorerFatalCleanupTest extends CIUnitTestCase
{
    private const FILE = '0123456789abcdef0123456789abcdef.pdf';

    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    private ?TestDatabase $database = null;

    private string $zip = '';

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        if ($this->zip !== '') {
            @unlink($this->zip);
        }
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /** A fatal error while backup.json is read (its temp copy is in tmp/): the cleanup removes that copy. */
    #[DataProvider('drivers')]
    public function testAFatalErrorWhileTheBackupIsReadLeavesNoTempCopy(string $driver): void
    {
        $seen = null;
        $cleanup = new RequestCleanup();
        $restorer = $this->restorer($driver, $cleanup, function (string $tmp) use ($cleanup, &$seen): void {
            $before = glob($tmp . '/restore-*.part') ?: [];
            $cleanup->run();
            clearstatcache();
            $seen = [count($before), glob($tmp . '/restore-*.part') ?: []];

            throw new RuntimeException('stopped while the backup is read');
        }, null);
        try {
            $restorer->restore($this->zip, false);
            $this->fail('the restore was not stopped');
        } catch (RuntimeException $e) {
            $this->assertSame('stopped while the backup is read', $e->getMessage());
        }
        $this->assertSame([1, []], $seen, 'the temp copy of backup.json was there, and the cleanup removed it');
    }

    /** A fatal error right after a restored file is written: the cleanup removes it, as it was noted first. */
    #[DataProvider('drivers')]
    public function testAFatalErrorRightAfterAFileIsWrittenRemovesIt(string $driver): void
    {
        $seen = null;
        $cleanup = new RequestCleanup();
        $restorer = $this->restorer($driver, $cleanup, null, function (string $path) use ($cleanup, &$seen): void {
            $written = is_file($path);
            $cleanup->run();
            clearstatcache();
            $seen = [$written, is_file($path)];

            throw new RuntimeException('stopped after the write');
        });
        try {
            $restorer->restore($this->zip, false);
            $this->fail('the restore was not stopped');
        } catch (Throwable) {
            // the restore's own answer to the hook's exception; the point is what the cleanup left
        }
        $this->assertSame([true, false], $seen, 'the file was written, and the cleanup removed it');
    }

    /**
     * N-881: a thumbnail drawn again (the backup has none) is written from
     * memory; it is noted before that write, so a fatal error right after
     * it still removes the file.
     */
    #[DataProvider('drivers')]
    public function testAFatalErrorRightAfterADrawnThumbnailIsWrittenRemovesIt(string $driver): void
    {
        $seen = null;
        $cleanup = new RequestCleanup();
        $restorer = $this->restorer($driver, $cleanup, null, function (string $path) use ($cleanup, &$seen): void {
            $written = is_file($path);
            $cleanup->run();
            clearstatcache();
            $seen = [$written, is_file($path)];

            throw new RuntimeException('stopped after the write');
        }, 'thumbs/');
        try {
            $restorer->restore($this->zip, false);
            $this->fail('the restore was not stopped');
        } catch (Throwable) {
            // the restore's own answer to the hook's exception; the point is what the cleanup left
        }
        $this->assertSame([true, false], $seen, 'the thumbnail was written, and the cleanup removed it');
    }

    /**
     * A database with one item and its attachment, a backup of it in
     * $this->zip, and the attachment removed again (so the restore writes
     * it). $onRead runs while the backup is read, $onWrite right after the
     * attachment is written.
     *
     * @param ?Closure(string):void $onRead gets DATA_DIR/tmp
     * @param ?Closure(string):void $onWrite gets the file's path
     * @param string $written the folder whose writes run $onWrite; for thumbs/, the item also has a photo
     *                        whose thumbnail is not in the backup, so the restore draws it again
     */
    private function restorer(string $driver, RequestCleanup $cleanup, ?Closure $onRead, ?Closure $onWrite, string $written = 'files/'): Restorer
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $dir = $this->database->dataDir();
        $clock = new Clock();
        $settings = new SettingsService(new SettingsRepository($db), new SettingsSchema(), new LocalBrandStore($dir . '/brand'), $clock, $this->database->config->values(), static fn (string $m): null => null, new CommandLine());
        $backups = new Backups($db, $clock, $settings, $dir, $this->database->config->originalsDir());
        $db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Lamp', 1, '', 'lamp', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
        $item = (int)$db->insertID();
        $pdf = "%PDF-1.4\n%made up\n";
        $db->table('item_attachments')->insert(['item_id' => $item, 'file' => self::FILE, 'name' => 'manual.pdf', 'mime' => 'application/pdf', 'size' => strlen($pdf), 'created_at' => '2026-01-02 08:00:00']);
        @mkdir($dir . '/files', 0770, true);
        file_put_contents($dir . '/files/' . self::FILE, $pdf);
        if ($written === 'thumbs/') {
            $db->table('items')->where('id', $item)->update(['image' => self::PHOTO]);
            $db->table('item_photos')->insert(['item_id' => $item, 'file' => self::PHOTO, 'position' => 0, 'created_at' => '2026-01-02 08:00:00']);
            @mkdir($dir . '/images', 0770, true);
            $im = imagecreatetruecolor(64, 48);
            imagejpeg($im, $dir . '/images/' . self::PHOTO);
        }
        $this->zip = sys_get_temp_dir() . '/apotheke-ci4-fatal-' . bin2hex(random_bytes(4)) . '.zip';
        $backups->create($this->zip);
        unlink($dir . '/files/' . self::FILE);
        @unlink($dir . '/images/' . self::PHOTO);
        $undo = new UndoTokens($db, $clock, 600, static function (string $f): void {
        }, static function (string $f): void {
        });
        $known = static function () use ($onRead, $dir): array {
            if ($onRead !== null) {
                $onRead($dir . '/tmp');
            }

            return array_keys((new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available());
        };
        $data = new class (new LocalStorage($dir), $dir, $onWrite, $written) implements Storage {
            public function __construct(private readonly Storage $inner, private readonly string $dir, private readonly ?Closure $onWrite, private readonly string $written)
            {
            }

            public function read(string $key): ?string
            {
                return $this->inner->read($key);
            }

            public function write(string $key, string $bytes): void
            {
                $this->inner->write($key, $bytes);
                $this->hook($key);
            }

            public function writeStream(string $key, mixed $stream): void
            {
                $this->inner->writeStream($key, $stream);
                $this->hook($key);
            }

            private function hook(string $key): void
            {
                if ($this->onWrite !== null && str_starts_with($key, $this->written)) {
                    ($this->onWrite)($this->dir . '/' . $key);
                }
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }

            public function modifiedAt(string $key): ?int
            {
                return $this->inner->modifiedAt($key);
            }
        };

        return new Restorer($db, $backups, $settings, $undo, $known, $data, new LocalStorage($this->database->config->originalsDir(), 0640), 256, $cleanup);
    }
}
