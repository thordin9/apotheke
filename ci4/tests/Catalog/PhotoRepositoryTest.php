<?php

declare(strict_types=1);

namespace Tests\Catalog;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Items\PhotoRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Media\ImageStore;
use Apotheke\Media\Storage\ImagickDecoder;
use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use Apotheke\Undo\UndoTokens;
use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Photo writes that the golden test cannot interleave: a double-submitted
 * crop/rotate (M-90), an edit whose old file another row or a pending undo
 * still uses (P7, P8), and OriginalStore::copy() of a missing original.
 * Concurrent requests over HTTP are in tests/Unit/Ci4PhotoRacesTest.php.
 */
final class PhotoRepositoryTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** Runs once, right after the next read of a served full-size photo. */
    private ?Closure $afterRead = null;

    private ImageStore $images;

    private OriginalStore $originals;

    private PhotoRepository $photos;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /**
     * M-90: the second request of a double submit read the same photo; the
     * first commits (and removes the old file and its original) while the
     * second decodes. The second changes nothing, keeps no file, and answers
     * as legacy does for a photo that changed underneath it.
     */
    #[DataProvider('drivers')]
    public function testADoubleSubmittedEditKeepsTheOriginal(string $driver): void
    {
        [$item, $pid, $old] = $this->seed($driver);
        $winner = null;
        $this->afterRead = function () use ($item, $pid, &$winner): void {
            $winner = $this->photos->edit($item, $pid, 180, [0.0, 0.0, 1.0, 1.0]);
        };
        try {
            $this->photos->edit($item, $pid, 90, [0.0, 0.0, 1.0, 1.0]);
            $this->fail('the second edit replaced the first one');
        } catch (UserError $e) {
            $this->assertSame(['Could not read that photo.', 500], [$e->getMessage(), $e->getCode()]);
        }
        $this->assertIsArray($winner);
        $row = $this->database->db->query('SELECT file, version FROM item_photos WHERE id = ?', [$pid])->getRowArray();
        $this->assertSame([$winner['file'], 2], [$row['file'], (int)$row['version']]);
        $this->assertNotNull($this->originals->read($winner['file']), 'the photo kept its original');
        $this->assertNull($this->originals->read($old));
        $this->assertSame([$winner['file']], $this->servedNames(), 'the loser removed its own new files');
        $this->assertSame(1, $this->originalCount());
        $this->assertSame(2, (int)$this->database->db->query("SELECT COUNT(*) AS n FROM activity WHERE action = 'photo'")->getRowArray()['n'], 'the add and one edit');
    }

    /** The second request comes after the first one has gone: the photo row is gone too, so 404 as legacy. */
    #[DataProvider('drivers')]
    public function testAnEditOfAPhotoDeletedMeanwhileIs404(string $driver): void
    {
        [$item, $pid] = $this->seed($driver);
        $this->afterRead = function () use ($item, $pid): void {
            $this->photos->action($item, $pid, 'delete');
        };
        try {
            $this->photos->edit($item, $pid, 90, [0.0, 0.0, 1.0, 1.0]);
            $this->fail('edited a deleted photo');
        } catch (UserError $e) {
            $this->assertSame(['Photo not found.', 404], [$e->getMessage(), $e->getCode()]);
        }
        $this->assertSame([], $this->servedNames());
        $this->assertSame(0, $this->originalCount());
    }

    /** P8: another row still shows the old file, so the edit keeps it. */
    #[DataProvider('drivers')]
    public function testAnEditKeepsAFileAnotherRowUses(string $driver): void
    {
        [$item, $pid, $old] = $this->seed($driver);
        $this->database->db->query('UPDATE items SET image = NULL WHERE id = ?', [$item]);
        $this->database->db->query("INSERT INTO items (name, quantity, notes, search, image, created_at, updated_at) VALUES ('Copy', 1, '', 'copy', ?, '2026-01-02 08:00:00', '2026-01-02 08:00:00')", [$old]);
        $new = $this->photos->edit($item, $pid, 90, [0.0, 0.0, 1.0, 1.0]);
        $this->assertEqualsCanonicalizing([$old, $new['file']], $this->servedNames());
        $this->assertNotNull($this->originals->read($old));
    }

    /** P7: a pending undo still needs the old file, so the edit keeps it. */
    #[DataProvider('drivers')]
    public function testAnEditKeepsAFileAPendingUndoNeeds(string $driver): void
    {
        [$item, $pid, $old] = $this->seed($driver);
        $this->database->db->query("INSERT INTO undo_tokens (token, action, label, payload, files, created_at, expires_at) VALUES (?, 'edit', 'Saved', ?, '[]', '2026-01-02 08:00:00', '2999-01-01 00:00:00')",
            [str_repeat('a', 32), json_encode(['photos' => [['file' => $old]]])]);
        $new = $this->photos->edit($item, $pid, 90, [0.0, 0.0, 1.0, 1.0]);
        $this->assertEqualsCanonicalizing([$old, $new['file']], $this->servedNames());
        $this->assertNotNull($this->originals->read($old));
    }

    /** P7/P8 control: with nothing else using it, the old file and its original go. */
    #[DataProvider('drivers')]
    public function testAnEditRemovesAnUnusedOldFile(string $driver): void
    {
        [$item, $pid, $old] = $this->seed($driver);
        $new = $this->photos->edit($item, $pid, 90, [0.0, 0.0, 1.0, 1.0]);
        $this->assertSame([$new['file']], $this->servedNames());
        $this->assertNull($this->originals->read($old));
        $this->assertSame(1, $this->originalCount());
    }

    public function testCopyFailsWhenTheOriginalIsMissing(): void
    {
        [, , $old] = $this->seed('sqlite');
        $to = str_repeat('b', 32) . '.jpg';
        $this->assertTrue($this->originals->copy($old, $to));
        $this->assertSame($this->originals->read($old), $this->originals->read($to));
        $this->assertTrue($this->originals->copy(str_repeat('c', 32) . '.jpg', str_repeat('d', 32) . '.jpg'), 'a photo from before originals were kept has none to copy');
        $base = trim((string)file_get_contents($this->database->config->originalsDir() . '/map/' . substr($old, 0, 32)));
        unlink($this->database->config->originalsDir() . '/' . $base);
        $this->assertFalse($this->originals->copy($old, str_repeat('e', 32) . '.jpg'), 'the map entry is there, its original is not');
        $this->assertFalse(is_file($this->database->config->originalsDir() . '/map/' . str_repeat('e', 32)));
    }

    /** @return array{0:int,1:int,2:string} item id, photo id, its served name */
    /**
     * N-126: a save that replaces the cover inside ItemRepository::atomically()
     * keeps the old cover's file until the outer transaction commits; a
     * rollback keeps it, a commit removes it.
     */
    #[DataProvider('drivers')]
    public function testACoverReplacedInsideAnOuterTransactionGoesOnlyAfterItsCommit(string $driver): void
    {
        [$item, , $old] = $this->seed($driver);
        $db = $this->database->db;
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $repo = new ItemRepository($db, $clock, $history, new TaxonomyRepository($db, $clock, $history), new TagRepository($db, $clock),
            $this->images, new LocalStorage($this->database->dataDir()), $this->photos);
        $existing = $repo->get($item);
        $this->assertNotNull($existing);
        $in = ['name' => 'Lamp', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''];
        try {
            $repo->atomically(function () use ($repo, $existing, $in, $old): void {
                $repo->save($existing, $in, null, true);
                $this->assertSame([$old], $this->servedNames(), 'still there inside the transaction');
                throw new \RuntimeException('the outer write failed');
            });
            $this->fail('the outer transaction must fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('the outer write failed', $e->getMessage());
        }
        $this->assertSame([$old], $this->servedNames(), 'rolled back: the cover keeps its file');
        $this->assertSame($old, $repo->get($item)['image'] ?? null);
        $repo->atomically(fn (): int => $repo->save($existing, $in, null, true));
        $this->assertSame([], $this->servedNames(), 'committed: the file is gone');
        $this->assertNull($repo->get($item)['image'] ?? null);
    }

    private function seed(string $driver): array
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $settings = new SettingsService(new SettingsRepository($db), new SettingsSchema(), new LocalBrandStore($this->database->dataDir() . '/brand'), new Clock(), $this->database->config->values(), static fn (string $m): null => null, new CommandLine());
        $served = new class (new LocalStorage($this->database->dataDir()), fn (): ?Closure => $this->takeHook()) implements Storage {
            public function __construct(private readonly Storage $inner, private readonly Closure $hook)
            {
            }

            public function read(string $key): ?string
            {
                $bytes = $this->inner->read($key);
                if (str_starts_with($key, 'images/')) {
                    (($this->hook)())?->__invoke();
                }

                return $bytes;
            }

            public function write(string $key, string $bytes): void
            {
                $this->inner->write($key, $bytes);
            }

            public function writeStream(string $key, mixed $stream): void
            {
                $this->inner->writeStream($key, $stream);
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
        $this->originals = new OriginalStore(new LocalStorage($this->database->config->originalsDir(), 0640));
        $this->images = new ImageStore($settings, $this->originals, new ServedImageWriter($served), new ImagickDecoder(), new RequestCleanup(static function (): void {
        }));
        $clock = new Clock();
        $undo = new UndoTokens($db, $clock, 600, fn (string $f) => $this->images->delete($f), static function (): void {
        });
        $this->photos = new PhotoRepository($db, $clock, new ActivityLogger($db, $clock, static fn (): ?string => null), $this->images, $undo);
        $db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Lamp', 1, '', 'lamp', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
        $item = (int)$db->insertID();
        $im = imagecreatetruecolor(200, 150);
        imagefilledrectangle($im, 0, 0, 50, 40, (int)imagecolorallocate($im, 200, 30, 30));
        $tmp = (string)tempnam(sys_get_temp_dir(), 'ph');
        imagejpeg($im, $tmp, 90);
        $name = $this->images->store($tmp, (int)filesize($tmp));
        @unlink($tmp);
        $pid = $this->photos->add($item, $name, 6);

        return [$item, $pid, $name];
    }

    private function takeHook(): ?Closure
    {
        $hook = $this->afterRead;
        $this->afterRead = null;

        return $hook;
    }

    /** @return list<string> served names with a full copy and a thumbnail */
    private function servedNames(): array
    {
        $dir = $this->database->dataDir();
        $out = [];
        foreach (glob($dir . '/images/*.jpg') ?: [] as $f) {
            $this->assertFileExists($dir . '/thumbs/' . basename($f));
            $out[] = basename($f);
        }
        $this->assertCount(count($out), glob($dir . '/thumbs/*.jpg') ?: []);

        return $out;
    }

    private function originalCount(): int
    {
        $dir = $this->database->config->originalsDir();
        $this->assertCount(count(glob($dir . '/*.*') ?: []), glob($dir . '/map/*') ?: [], 'one map entry per original');

        return count(glob($dir . '/*.*') ?: []);
    }
}
