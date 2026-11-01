<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Items\Attachments;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Storage\LocalStorage;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * The parity gate of P4-2b for the item-history sites (ADR-002): item
 * history queued on a unit (ItemHistory::queue(), with a Ref for a new
 * item), adding and removing attachments and deleting an item. Each runs as
 * a real transaction and as one batch (LocalBatchExecutor), and both leave
 * the same rows and ids, history included, on SQLite and PostgreSQL.
 */
final class UnitOfWorkHistorySitesTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    private string $files = '';

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ' transaction' => [$driver, false];
            yield $driver . ' batch' => [$driver, true];
        }
    }

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
        putenv('APOTHEKE_CLOCK');
        parent::tearDown();
    }

    #[DataProvider('modes')]
    public function testTheHistorySitesInEachMode(string $driver, bool $batch): void
    {
        $out = $this->scenario($driver, $batch);
        $this->assertSame([], $out['uow_refs'], 'no Ref is left behind');
        $this->assertSame([], $out['uow_guard']);
    }

    #[DataProvider('drivers')]
    public function testBothModesLeaveTheSameRowsAndIds(string $driver): void
    {
        $this->assertSame($this->scenario($driver, false), $this->scenario($driver, true));
    }

    /** A queued history row binds the Ref of the item its unit inserts, and a failed unit keeps no history. */
    #[DataProvider('modes')]
    public function testQueuedHistoryTakesARefAndFailsWithItsUnit(string $driver, bool $batch): void
    {
        [$db, $units, $history] = $this->wire($driver, $batch);
        $ref = $units->run('items', static function (UnitOfWork $uow) use ($history): Ref {
            $item = $uow->insert('items', ['name' => 'Kettle', 'created_at' => '2026-06-15 09:00:00', 'updated_at' => '2026-06-15 09:00:00'], 'add item');
            $history->queue($uow, $item, 'Kettle', 'created', ['quantity' => 1]);

            return $item;
        });
        $this->assertSame([[(string)$ref->id(), 'Kettle', 'created', '{"quantity":1}', '2026-06-15 09:00:00', null]], $this->history($db));
        try {
            $units->run('items', static function (UnitOfWork $uow) use ($history): void {
                $history->queue($uow, 1, 'Kettle', 'edited');
                throw new RuntimeException('stop');
            });
            $this->fail('the unit must fail');
        } catch (RuntimeException) {
        }
        $this->assertCount(1, $this->history($db), 'a failed unit keeps no history');
    }

    /** @return array<string,mixed> */
    private function scenario(string $driver, bool $batch): array
    {
        [$db, $units, $history] = $this->wire($driver, $batch);
        $this->assertNotNull($this->database);
        foreach (['Lamp', 'Saw'] as $name) {
            $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES (?, 2, '2026-01-01 08:00:00', '2026-01-01 08:00:00')", [$name]);
        }
        foreach ([['red', 1], ['blue', 2]] as $i => [$tag, $item]) {
            $db->query("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES (?, ?, ?, '2026-01-01 08:00:00')", [$tag, $tag, $tag]);
            $db->query('INSERT INTO item_tags (item_id, tag_id) VALUES (?, ?)', [$item, $i + 1]);
        }
        $items = $this->database->items($db, $units);
        $attachments = new Attachments($db, new Clock(), $history, $items, new LocalStorage($this->files), 'pdf_images', 5, 3, $units);
        $this->assertSame(2, $attachments->add(1, [$this->upload('manual.pdf'), $this->upload('receipt.pdf')], static fn (int $e): string => 'error ' . $e));
        $this->assertSame(1, $attachments->add(2, [$this->upload('warranty.pdf')], static fn (int $e): string => 'error ' . $e));
        $attachments->delete(1, 1);
        $files = glob($this->files . '/files/*') ?: [];
        $this->assertCount(2, $files, 'the removed attachment\'s file is gone');
        $saw = $items->get(2);
        $this->assertNotNull($saw);
        $items->delete($saw);
        $this->assertCount(1, glob($this->files . '/files/*') ?: [], 'and the deleted item\'s');
        $out = [];
        foreach (['items' => 'id, name, quantity, updated_at', 'item_attachments' => 'id, item_id, name, mime, size, created_at', 'tags' => 'id, name',
            'item_tags' => 'item_id, tag_id', 'uow_refs' => '*', 'uow_guard' => '*'] as $t => $cols) {
            $out[$t] = array_map(static fn (array $r): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, array_values($r)), Writes::rows($db, "SELECT $cols FROM $t ORDER BY " . (str_starts_with($t, 'uow_') ? '1' : '1, 2')));
        }
        $out['activity'] = $this->history($db);
        $this->assertSame([['1', 'Lamp', '2', '2026-06-15 09:00:00']], $out['items'], 'adding touches the item; Saw is gone');
        $this->assertSame([['2', '1', 'receipt.pdf', 'application/pdf', '9', '2026-06-15 09:00:00']], $out['item_attachments']);
        $this->assertSame([['1', 'red']], $out['tags'], 'the unused tag is dropped');
        $this->assertSame([['1', '1']], $out['item_tags']);
        $this->assertSame([
            ['1', 'Lamp', 'attachment', '{"change":"added","file":"manual.pdf"}', '2026-06-15 09:00:00', null],
            ['1', 'Lamp', 'attachment', '{"change":"added","file":"receipt.pdf"}', '2026-06-15 09:00:00', null],
            ['2', 'Saw', 'attachment', '{"change":"added","file":"warranty.pdf"}', '2026-06-15 09:00:00', null],
            ['1', 'Lamp', 'attachment', '{"change":"removed","file":"manual.pdf"}', '2026-06-15 09:00:00', null],
            ['2', 'Saw', 'deleted', '{"quantity":2}', '2026-06-15 09:00:00', null],
        ], $out['activity']);
        $this->assertSame(0, (int)$db->transDepth);

        return $out;
    }

    /** @return array{name:string,tmp:string,error:int} */
    private function upload(string $name): array
    {
        $tmp = $this->files . '/upload-' . $name;
        file_put_contents($tmp, '%PDF-1.4' . "\n");

        return ['name' => $name, 'tmp' => $tmp, 'error' => UPLOAD_ERR_OK];
    }

    /** @return list<list<string|null>> */
    private function history(BaseConnection $db): array
    {
        return array_map(static fn (array $r): array => array_map(static fn (mixed $v): ?string => $v === null ? null : (string)$v, array_values($r)),
            Writes::rows($db, 'SELECT item_id, item_name, action, details, created_at, actor FROM activity ORDER BY id'));
    }

    /** @return array{0:BaseConnection<object|resource, object|resource>,1:UnitsOfWork,2:ActivityLogger} */
    private function wire(string $driver, bool $batch): array
    {
        putenv('APOTHEKE_CLOCK=2026-06-15 09:00:00');
        $this->database?->drop();
        $this->database = TestDatabase::create($driver);
        $this->files = $this->database->dataDir();   // the item repository removes attachment files there
        if (!is_dir($this->files . '/files')) {
            mkdir($this->files . '/files', 0770, true);
        }
        $db = $this->database->db;

        return [$db, UnitsOfWork::on($batch ? new LocalBatchExecutor($db) : new ConnectionExecutor($db)), new ActivityLogger($db, new Clock(), static fn (): ?string => null)];
    }
}
