<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\BulkMerge\MergeService;
use Apotheke\Catalog\Barcode;
use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\ItemList;
use Apotheke\Catalog\Items\PhotoRepository;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Media\ImageStore;
use Apotheke\Media\Storage\ImagickDecoder;
use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use Apotheke\Search\SearchIndex;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Stocktake\Stocktakes;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The parity gate of P4-2b for the follow-up sites (ADR-002): photos,
 * taxonomy default/delete/move, stocktake tick/finish, settings rewrite and
 * merge. Each runs as a real transaction and as one batch, on SQLite and
 * PostgreSQL.
 */
final class UnitOfWorkRemainingSites2Test extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ' transaction' => [$driver, false];
            yield $driver . ' batch' => [$driver, true];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        putenv('APOTHEKE_CLOCK');
        parent::tearDown();
    }

    #[DataProvider('modes')]
    public function testPhotoAddReorderAndDeleteInEachMode(string $driver, bool $batch): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        [$db, $units, $images] = $this->wirePhotos($driver, $batch);
        $photos = $this->photos($db, $units, $images);
        $db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Lamp', 1, '', 'lamp', '2026-06-15 09:00:00', '2026-06-15 09:00:00')");
        $item = (int)$db->insertID();
        $a = $this->storeJpeg($images, 'a');
        $b = $this->storeJpeg($images, 'b');
        $pa = $photos->add($item, $a, 6);
        $pb = $photos->add($item, $b, 6);
        $this->assertSame([$pa, $pb], array_column($photos->list($item), 'id'));
        $this->assertSame($a, Writes::rows($db, 'SELECT image FROM items WHERE id = ?', [$item])[0]['image']);
        $photos->reorder($item, [$pb, $pa]);
        $this->assertSame([$pb, $pa], array_column($photos->list($item), 'id'));
        $this->assertSame($b, Writes::rows($db, 'SELECT image FROM items WHERE id = ?', [$item])[0]['image']);
        $photos->action($item, $pa, 'delete');
        $this->assertSame([$pb], array_column($photos->list($item), 'id'));
        $this->assertSame(['photo', 'photo', 'photo', 'photo'], array_column(Writes::rows($db, 'SELECT action FROM activity WHERE item_id = ? ORDER BY id', [$item]), 'action'));
        $this->assertSame([], Writes::rows($db, 'SELECT * FROM uow_refs'));
        $this->assertSame([], Writes::rows($db, 'SELECT * FROM uow_guard'));
    }

    /** P6 / guard: another add that fills the limit while this one runs fails the unit and keeps no row. */
    #[DataProvider('modes')]
    public function testPhotoLimitIsCheckedInTheUnit(string $driver, bool $batch): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        [$db, $units, $images] = $this->wirePhotos($driver, $batch);
        $photos = $this->photos($db, $units, $images);
        $db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Lamp', 1, '', 'lamp', '2026-06-15 09:00:00', '2026-06-15 09:00:00')");
        $item = (int)$db->insertID();
        $existing = $this->storeJpeg($images, 'lim0');
        $photos->add($item, $existing, 2);
        $pending = $this->storeJpeg($images, 'lim1');
        $keys = UnitsOfWork::lockKeys('items', (string)$item);
        $hold = $driver === 'pgsql'
            ? [['SELECT pg_advisory_xact_lock(?, ?)', $keys]]
            : [['UPDATE items SET updated_at = updated_at WHERE id = ?', [$item]]];
        $otherFile = 'other-' . bin2hex(random_bytes(8)) . '.jpg';
        $wait = $this->database->holdInAnotherClient([
            ...$hold,
            ['INSERT INTO item_photos (item_id, file, position, created_at) VALUES (?, ?, 1, ?)', [$item, $otherFile, '2026-06-15 09:00:00']],
            ['UPDATE items SET image = image WHERE id = ?', [$item]],
        ], 400);
        try {
            $photos->add($item, $pending, 2);
            $this->fail('a second add must not pass a limit of 2');
        } catch (UserError $e) {
            $this->assertSame([422, 'An item can have at most 2 photos.'], [$e->getCode(), $e->getMessage()]);
        }
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertCount(2, $photos->list($item));
        $this->assertSame([], Writes::rows($db, 'SELECT * FROM uow_guard'));
    }

    #[DataProvider('modes')]
    public function testTaxonomyDefaultDeleteAndMoveInEachMode(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $tax = new TaxonomyRepository($db, new Clock(), new ActivityLogger($db, new Clock(), static fn (): ?string => null), $units);
        $root = $tax->create('location', 'Garage');
        $child = $tax->createUnder('location', 'Shelf', $root['id']);
        $other = $tax->create('location', 'Shed');
        $tax->setDefault('location', $root['id'], true);
        $this->assertSame($root['id'], $tax->defaultId('location'));
        $tax->setDefault('location', $root['id'], false);
        $this->assertNull($tax->defaultId('location'));
        $this->assertTrue($tax->setParent($child['id'], $other['id']));
        $this->assertSame($other['id'], (int)Writes::rows($db, 'SELECT parent_id FROM locations WHERE id = ?', [$child['id']])[0]['parent_id']);
        $db->query("INSERT INTO items (name, quantity, location_id, notes, search, created_at, updated_at) VALUES ('Box', 1, ?, '', 'box', '2026-06-15 09:00:00', '2026-06-15 09:00:00')", [$root['id']]);
        $this->assertSame(1, $tax->delete('location', $root['id'], $other['id']));
        $this->assertSame($other['id'], (int)Writes::rows($db, 'SELECT location_id FROM items WHERE name = ?', ['Box'])[0]['location_id']);
        $this->assertNull($tax->get('location', $root['id']));
    }

    #[DataProvider('modes')]
    public function testStocktakeTickAndFinishInEachMode(string $driver, bool $batch): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 12:00:00');
        [$db, $units] = $this->wire($driver, $batch);
        $this->assertNotNull($this->database);
        $history = new ActivityLogger($db, new Clock(), static fn (): ?string => null);
        $tax = new TaxonomyRepository($db, new Clock(), $history, $units);
        $loc = $tax->create('location', 'Garage');
        $items = $this->database->items($db, $units);
        $id = $items->save(null, ['name' => 'Drill', 'location_id' => $loc['id'], 'category_id' => null, 'quantity' => 1, 'notes' => '', 'tags' => []], null, false);
        $takes = new Stocktakes($db, new Clock(), $tax, $items, $history, new Barcode($db), $units);
        $sid = $takes->start($loc['id']);
        $this->assertSame('found', $takes->check($sid, $id, true)['state']);
        $this->assertSame(0, $takes->finish($sid, false));
        $this->assertSame('done', $takes->get($sid)['status'] ?? null);
    }

    #[DataProvider('modes')]
    public function testSettingsRewriteInEachMode(string $driver, bool $batch): void
    {
        [$db, $units] = $this->wire($driver, $batch);
        $repo = new SettingsRepository($db, $units);
        $repo->replace(['timezone' => '"UTC"'], '2026-06-15 09:00:00');
        $repo->rewrite(static fn (array $rows): array => ['timezone' => '"Europe/Athens"', 'locale' => '"en"'], '2026-06-15 10:00:00');
        $rows = $repo->rows();
        $this->assertSame('"Europe/Athens"', $rows['timezone']);
        $this->assertSame('"en"', $rows['locale']);
    }

    #[DataProvider('modes')]
    public function testMergeInEachMode(string $driver, bool $batch): void
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        [$db, $units] = $this->wire($driver, $batch);
        $this->assertNotNull($this->database);
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $tax = new TaxonomyRepository($db, $clock, $history, $units);
        $items = $this->database->items($db, $units);
        $src = $items->save(null, ['name' => 'Lamp A', 'location_id' => null, 'category_id' => null, 'quantity' => 2, 'notes' => 'note', 'tags' => ['red']], null, false);
        $dst = $items->save(null, ['name' => 'Lamp B', 'location_id' => null, 'category_id' => null, 'quantity' => 3, 'notes' => '', 'tags' => []], null, false);
        $list = new ItemList($db, new SearchIndex($db, $tax, false), new Calendar($clock, 'UTC', 7));
        $merge = new MergeService($db, $clock, $history, $items, new TagRepository($db, $clock, $units), $list, 'asc', $units);
        $p = $merge->merge($src, $dst);
        $this->assertSame(5, (int)$p['result']['quantity']);
        $this->assertNull($items->get($src));
        $kept = $items->get($dst);
        $this->assertNotNull($kept);
        $this->assertSame(5, (int)$kept['quantity']);
        $this->assertSame(['red'], array_column($kept['tags'], 'name'));
    }

    private function photos(BaseConnection $db, UnitsOfWork $units, ImageStore $images): PhotoRepository
    {
        $clock = new Clock();
        $undo = new UndoTokens($db, $clock, 0, static function (): void {
        }, static function (): void {
        });

        return new PhotoRepository($db, $clock, new ActivityLogger($db, $clock, static fn (): ?string => null), $images, $undo, $units);
    }

    private function storeJpeg(ImageStore $images, string $tag): string
    {
        $im = imagecreatetruecolor(40, 30);
        imagefilledrectangle($im, 0, 0, 40, 30, (int)imagecolorallocate($im, 10, 20, 30));
        $tmp = '/tmp/coder2-sites2-' . $tag . '.jpg';
        imagejpeg($im, $tmp, 90);
        imagedestroy($im);
        $name = $images->store($tmp, (int)filesize($tmp));
        @unlink($tmp);

        return $name;
    }

    /**
     * @return array{0:BaseConnection<object|resource, object|resource>,1:UnitsOfWork,2:ImageStore}
     */
    private function wirePhotos(string $driver, bool $batch): array
    {
        [$db, $units] = $this->wire($driver, $batch);
        $this->assertNotNull($this->database);
        $settings = new SettingsService(new SettingsRepository($db, $units), new SettingsSchema(), new LocalBrandStore($this->database->dataDir() . '/brand'), new Clock(), $this->database->config->values(), static fn (string $m): null => null, new CommandLine());
        $images = new ImageStore($settings, new OriginalStore(new LocalStorage($this->database->config->originalsDir(), 0640)), new ServedImageWriter(new LocalStorage($this->database->dataDir())), new ImagickDecoder(), new RequestCleanup(static function (): void {
        }));

        return [$db, $units, $images];
    }

    /**
     * @return array{0:BaseConnection<object|resource, object|resource>,1:UnitsOfWork}
     */
    private function wire(string $driver, bool $batch): array
    {
        $this->database?->drop();
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;

        return [$db, UnitsOfWork::on($batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db))];
    }
}
