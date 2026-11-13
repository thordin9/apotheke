<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Auth\UserRepository;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Items\PhotoRepository;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\BridgeLimits;
use Apotheke\Core\Hosted\HostedLimits;
use Apotheke\Core\Hosted\PlanLimits;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Media\Storage\ImagickDecoder;
use Apotheke\Media\ImageStore;
use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Operator console C1: the hosted plan's ceilings. Off without APOTHEKE_HOSTED (nothing read, nothing refused).
 * Hosted, item, photo and account counts are checked inside the unit of work that adds the rows, so rows queued
 * earlier in the same unit count and two units at once cannot both pass; the limits come only from the bridge.
 */
final class HostedLimitsTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:bool}> driver, batch (one batch per unit, as on D1) */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver, false];
            if ($driver !== 'd1') {
                yield $driver . ' batch' => [$driver, true];
            }
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /** @param array<string,array{soft?:?int,hard?:?int}> $limits */
    private static function plan(array $limits): HostedLimits
    {
        return HostedLimits::hosted(static fn (): PlanLimits => PlanLimits::fromBridge(['ok' => true, 'plan' => 'test-plan', 'limits' => $limits]) ?? throw new \LogicException('bad fixture'));
    }

    public function testSelfHostedReadsNoLimitsAndRefusesNothing(): void
    {
        $read = 0;
        $off = HostedLimits::fromConfig(['hosted' => false], static function () use (&$read): string {
            $read++;

            return '{"ok":true,"limits":{"items":{"hard":0}}}';
        });
        $this->assertFalse($off->isHosted());
        $this->assertSame([], $off->lockKeys('items', 'photos', 'users'));
        $this->assertNull($off->ceiling('max_upload_mb'));
        $this->assertFalse($off->fixed('autofill_url'));
        $this->assertNull($off->limits()->hard('items'));
        $this->assertSame(0, $read, 'self-hosted never asks the bridge');
        $this->assertFalse(HostedLimits::fromConfig([])->isHosted());
    }

    /** @param array<string,mixed> $body */
    private static function bridge(array|string $body): HostedLimits
    {
        return HostedLimits::fromConfig(['hosted' => true], static fn (string $url): string => $url === 'http://d1.internal/limits'
            ? (is_string($body) ? $body : (string)json_encode($body)) : throw new \LogicException('unexpected URL ' . $url));
    }

    public function testHostedReadsTheBridgeOncePerRequest(): void
    {
        $calls = 0;
        $limits = HostedLimits::fromConfig(['hosted' => true], static function (string $url) use (&$calls): string {
            $calls++;

            return '{"ok":true,"plan":"p","limits":{"items":{"soft":8,"hard":10},"import_file_bytes":{"soft":null,"hard":5242880}}}';
        });
        $this->assertTrue($limits->isHosted());
        $this->assertSame(10, $limits->limits()->hard('items'));
        $this->assertSame(8, $limits->limits()->soft('items'));
        $this->assertSame('p', $limits->limits()->plan());
        $this->assertSame(['plan-limit:items'], $limits->lockKeys('items', 'photos', 'users'));
        $this->assertSame(5, $limits->ceiling('max_upload_mb'));
        $this->assertSame(5, $limits->ceiling('attach_max_mb'));
        $this->assertNull($limits->ceiling('max_photos'));
        $this->assertTrue($limits->fixed('autofill_url'));
        $this->assertSame(1, $calls);
    }

    public function testAnUnreadableAnswerIsRefusedNotSkipped(): void
    {
        foreach (['', 'not json', '{"ok":false,"code":"unbound"}', '{"ok":"true","limits":{}}', '{"ok":true}',
            '{"ok":true,"limits":{"items":{"hard":-1}}}', '{"ok":true,"limits":{"items":{"hard":"5"}}}', '{"ok":true,"limits":{"Bad Key":{"hard":1}}}'] as $raw) {
            try {
                self::bridge($raw)->lockKeys('items');
                $this->fail('accepted ' . $raw);
            } catch (UserError $e) {
                $this->assertSame(503, $e->getCode(), $raw);
            }
        }
        $this->expectException(\RuntimeException::class);
        new BridgeLimits('http://d1.internal/limits');
    }

    #[DataProvider('modes')]
    public function testTheItemLimitIsRefusedInsideTheUnit(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $items = $this->items($batch, self::plan(['items' => ['soft' => 1, 'hard' => 2]]));
        $items->save(null, self::item('one'), null, false);
        $items->save(null, self::item('two'), null, false);
        try {
            $items->save(null, self::item('three'), null, false);
            $this->fail('a third item past the limit');
        } catch (UserError $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('Your plan allows 2 items.', $e->getMessage());
        }
        $this->assertSame(['one', 'two'], $this->names());
        // Editing is not adding: it still works at the limit.
        $id = (int)Writes::rows($this->db(), "SELECT id FROM items WHERE name = 'one'")[0]['id'];
        $items->save($items->get($id), self::item('one again'), null, false);
        $this->assertSame(['one again', 'two'], $this->names());
    }

    /** Two adds in one unit, one place left: the second counts the first (queued in the same unit), so neither lands. */
    #[DataProvider('modes')]
    public function testRowsQueuedEarlierInTheSameUnitCount(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $units = $this->units($batch);
        $items = $this->items($batch, self::plan(['items' => ['hard' => 2]]), $units);
        $items->save(null, self::item('one'), null, false);
        try {
            $units->run('items', static function (UnitOfWork $uow) use ($items): void {
                $items->saveRef(null, self::item('two'), null, false);
                $items->saveRef(null, self::item('three'), null, false);
            });
            $this->fail('two adds past the limit in one unit');
        } catch (UserError $e) {
            $this->assertSame('Your plan allows 2 items.', $e->getMessage());
        }
        $this->assertSame(['one'], $this->names(), 'the whole unit failed');
    }

    /** @return iterable<string,array{0:bool}> */
    public static function pgModes(): iterable
    {
        yield 'connection' => [false];
        yield 'batch' => [true];
    }

    /**
     * Two adds at once on PostgreSQL: the other client takes the plan-limit key, adds the last row the plan allows
     * and commits a moment later. This add waits for the key, then counts the other row and is refused.
     *
     * @return array{0:\Closure():string, 1:HostedLimits}
     */
    private function raceOnPostgres(string $table, string $limit, string $insert): array
    {
        $this->assertNotNull($this->database);

        return [$this->database->holdInAnotherClient([['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys($table, 'plan-limit:' . $limit)], [$insert, []]], 400),
            self::plan([$limit => ['hard' => 2]])];
    }

    #[DataProvider('pgModes')]
    public function testParallelItemAddsOnPostgresKeepTheLimit(bool $batch): void
    {
        $this->onPostgres();
        $items = $this->items($batch, self::plan([]));
        $items->save(null, self::item('one'), null, false);
        [$wait, $limits] = $this->raceOnPostgres('items', 'items', "INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('other', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        try {
            $this->items($batch, $limits)->save(null, self::item('two'), null, false);
            $this->fail('two adds at once passed the limit');
        } catch (UserError $e) {
            $this->assertSame([422, 'Your plan allows 2 items.'], [$e->getCode(), $e->getMessage()]);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame(['one', 'other'], $this->names());
    }

    #[DataProvider('pgModes')]
    public function testParallelPhotoAddsOnPostgresKeepTheLimit(bool $batch): void
    {
        $this->onPostgres();
        $id = $this->items($batch, self::plan([]))->save(null, self::item('one'), self::photo(), false);
        [$wait, $limits] = $this->raceOnPostgres('items', 'photos', "INSERT INTO item_photos (item_id, file, position, created_at) VALUES ({$id}, '" . self::photo() . "', 1, '2026-01-01 00:00:00')");
        try {
            $this->photos($batch, $limits)->add($id, self::photo(), 12);
            $this->fail('two photo adds at once passed the limit');
        } catch (UserError $e) {
            $this->assertSame([422, 'Your plan allows 2 photos.'], [$e->getCode(), $e->getMessage()]);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame(2, $this->rows('item_photos'));
    }

    #[DataProvider('pgModes')]
    public function testParallelAccountsOnPostgresKeepTheLimit(bool $batch): void
    {
        $this->onPostgres();
        (new UserRepository($this->db()))->create('ann', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        [$wait, $limits] = $this->raceOnPostgres('users', 'users', "INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES ('other', 'other', 'x', 'viewer', 'active', '2026-01-01 00:00:00')");
        try {
            (new UserRepository($this->db(), units: $this->units($batch), limits: $limits))->create('bob', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
            $this->fail('two accounts at once passed the limit');
        } catch (UserError $e) {
            $this->assertSame([422, 'Your plan allows 2 user accounts.'], [$e->getCode(), $e->getMessage()]);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame(2, $this->rows('users'));
        // Without a limit for a key no extra key is taken (self-hosted keys are unchanged).
        $this->assertSame([], self::plan([])->lockKeys('items', 'photos', 'users'));
    }

    private function onPostgres(): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL only');
        }
        $this->database = TestDatabase::create('pgsql');
    }

    private static function photo(): string
    {
        return bin2hex(random_bytes(16)) . '.jpg';
    }

    #[DataProvider('modes')]
    public function testThePhotoLimitCountsEveryItemsPhotos(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $limits = self::plan(['photos' => ['hard' => 2]]);
        $items = $this->items($batch, $limits);
        $photos = $this->photos($batch, $limits);
        $a = $items->save(null, self::item('a'), self::photo(), false);   // a cover photo is one
        $b = $items->save(null, self::item('b'), null, false);
        $photos->add($b, self::photo(), 12);
        foreach (['another photo' => fn () => $photos->add($a, self::photo(), 12), 'a new item with a cover' => fn () => $items->save(null, self::item('c'), self::photo(), false)] as $what => $add) {
            try {
                $add();
                $this->fail($what . ' past the limit');
            } catch (UserError $e) {
                $this->assertSame('Your plan allows 2 photos.', $e->getMessage(), $what);
            }
        }
        $this->assertSame(2, $this->rows('item_photos'));
        $this->assertSame(['a', 'b'], $this->names());
        // A replaced cover keeps the count: allowed at the limit.
        $items->save($items->get($a), self::item('a'), self::photo(), false);
        $this->assertSame(2, $this->rows('item_photos'));
    }

    #[DataProvider('modes')]
    public function testTheAccountLimit(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $users = new UserRepository($this->db(), units: $this->units($batch), limits: self::plan(['users' => ['hard' => 2]]));
        $users->create('ann', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $users->create('bob', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        try {
            $users->create('cid', 'x', 'viewer', 'pending', '2026-01-01 00:00:00');
            $this->fail('a third account');
        } catch (UserError $e) {
            $this->assertSame('Your plan allows 2 user accounts.', $e->getMessage());
        }
        $this->assertSame(2, $this->rows('users'));
    }

    #[DataProvider('modes')]
    public function testSelfHostedHasNoCeilings(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $off = HostedLimits::off();
        $items = $this->items($batch, $off);
        for ($i = 0; $i < 3; $i++) {
            $items->save(null, self::item('i' . $i), self::photo(), false);
        }
        $users = new UserRepository($this->db(), units: $this->units($batch), limits: $off);
        foreach (['ann', 'bob', 'cid'] as $name) {
            $users->create($name, 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        }
        $this->assertSame([3, 3, 3], [$this->rows('items'), $this->rows('item_photos'), $this->rows('users')]);
    }

    #[DataProvider('modes')]
    public function testLimitsThatCannotBeReadRefuseTheAdd(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $items = $this->items($batch, self::bridge('{"ok":false,"code":"unbound"}'));
        try {
            $items->save(null, self::item('one'), null, false);
            $this->fail('added without readable limits');
        } catch (UserError $e) {
            $this->assertSame(503, $e->getCode());
        }
        $this->assertSame(0, $this->rows('items'));
    }

    public function testHostedSettingCeilingsAndFixedSettings(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $hosted = $this->settings(self::plan(['import_file_bytes' => ['hard' => 5 * 1048576]]));
        $this->assertSame(['max_upload_mb' => 'Your plan allows at most 5.'], $hosted->save(['max_upload_mb' => '6']));
        $this->assertSame(['attach_max_mb' => 'Your plan allows at most 5.'], $hosted->save(['attach_max_mb' => '50']));
        $this->assertSame([], $hosted->save(['max_upload_mb' => '5', 'attach_max_mb' => '1']));
        $this->assertSame(['autofill_url' => 'Set by your hosting plan.'], $hosted->save(['autofill_url' => 'http://192.168.1.10:8080']));
        $this->assertSame([], $hosted->save(['autofill_url' => '']), 'saving the same value is fine');
        $r = $hosted->import((string)json_encode(['format' => 'apotheke-settings', 'version' => 1, 'settings' => ['autofill_url' => 'http://192.168.1.10:8080', 'max_upload_mb' => 9, 'app_name' => 'Mine']]));
        $this->assertSame(['max_upload_mb' => 'Your plan allows at most 5.'], $r['errors']);
        $this->assertSame(['autofill_url'], $r['ignored']);
        $fresh = $this->settings(self::plan([]));
        $this->assertSame(['', 5, 'Mine'], [$fresh->get('autofill_url'), $fresh->get('max_upload_mb'), $fresh->get('app_name')]);
        // No ceiling in the plan: any valid value.
        $this->assertSame([], $fresh->save(['max_upload_mb' => '150']));
        // Self-hosted: as before.
        $self = $this->settings(HostedLimits::off());
        $this->assertSame([], $self->save(['max_upload_mb' => '200', 'autofill_url' => 'http://192.168.1.10:8080']));
        $this->assertSame('http://192.168.1.10:8080', $this->settings(HostedLimits::off())->get('autofill_url'));
    }

    public function testHostedSignInStaysOnAndRegistrationWaitsForTheFirstAccount(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $hosted = $this->settings(self::plan([]));
        $hosted->store(['auth_enabled' => false, 'auth_register_disabled' => false]);
        $this->assertTrue($hosted->signInEnabled(), 'on with no account and no APP_PASSWORD');
        $this->assertFalse($hosted->registrationOpen(), 'the first account comes from the claim');
        $self = $this->settings(HostedLimits::off());
        $this->assertFalse($self->signInEnabled(), 'self-hosted: the setting decides, as before');
        $this->assertFalse($self->registrationOpen());
        (new UserRepository($this->db()))->create('ann', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $hosted = $this->settings(self::plan([]));
        $this->assertTrue($hosted->registrationOpen());
        $self = $this->settings(HostedLimits::off());
        $self->store(['auth_enabled' => true]);
        $this->assertTrue($self->registrationOpen());
    }

    /** @return array{name:string,location_id:?int,category_id:?int,quantity:int,notes:string} */
    private static function item(string $name): array
    {
        return ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''];
    }

    private function units(bool $batch): UnitsOfWork
    {
        return $batch ? UnitsOfWork::on(new LocalBatchExecutor($this->db())) : UnitsOfWork::forSite($this->db(), null);
    }

    private function items(bool $batch, HostedLimits $limits, ?UnitsOfWork $units = null, ?\Apotheke\Core\Libraries\ItemHistory $history = null): ItemRepository
    {
        $db = $this->db();
        $units ??= $this->units($batch);
        $clock = new Clock();
        $history ??= new ActivityLogger($db, $clock, static fn (): ?string => null);
        $images = $this->images();

        return new ItemRepository($db, $clock, $history, new TaxonomyRepository($db, $clock, $history, $units), new TagRepository($db, $clock, $units),
            $images, new LocalStorage($this->dir()), $this->photos($batch, $limits, $units, $history), $units, null, $limits);
    }

    private function photos(bool $batch, HostedLimits $limits, ?UnitsOfWork $units = null, ?\Apotheke\Core\Libraries\ItemHistory $history = null): PhotoRepository
    {
        $db = $this->db();
        $clock = new Clock();
        $undo = new UndoTokens($db, $clock, 600, static function (string $f): void {
        }, static function (): void {
        });

        return new PhotoRepository($db, $clock, $history ?? new ActivityLogger($db, $clock, static fn (): ?string => null), $this->images(), $undo, $units ?? $this->units($batch), $limits);
    }

    private function images(): ImageStore
    {
        $this->assertNotNull($this->database);

        return new ImageStore($this->settings(HostedLimits::off()), new OriginalStore(new LocalStorage($this->database->config->originalsDir(), 0640)),
            new ServedImageWriter(new LocalStorage($this->dir())), new ImagickDecoder(), new RequestCleanup(static function (): void {
            }));
    }

    private function settings(HostedLimits $limits): SettingsService
    {
        $this->assertNotNull($this->database);

        return new SettingsService(new SettingsRepository($this->db()), new SettingsSchema(), new LocalBrandStore(new LocalStorage($this->dir())), new Clock(),
            $this->database->config->values(), static fn (string $m): null => null, new CommandLine(), null, $limits);
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_column(Writes::rows($this->db(), 'SELECT name FROM items ORDER BY name'), 'name');
    }

    private function rows(string $table): int
    {
        return (int)(Writes::rows($this->db(), 'SELECT COUNT(*) AS n FROM ' . $table)[0]['n'] ?? 0);
    }

    private function dir(): string
    {
        $this->assertNotNull($this->database);

        return $this->database->dataDir();
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }
}
