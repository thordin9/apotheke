<?php

declare(strict_types=1);

namespace Tests\Catalog;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Controllers\ItemController;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Undo\UndoTokens;
use Closure;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * POST /item/{id}/delete when its unit of work races another request
 * (L-1667), on each driver in batch mode, where the race lands between the
 * unit's read and its batch:
 * - N-1740: a delete skipped because the item is gone issues no undo token
 *   (the first delete owns it);
 * - N-1741: any other error of the unit is the request's error, never a skip.
 */
final class ItemDeleteControllerTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        Services::reset(true);
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /** N-1740: the item is deleted by another request while this one runs: no token, no second history row. */
    #[DataProvider('drivers')]
    public function testASkippedDeleteIssuesNoUndoToken(string $driver): void
    {
        $db = $this->wire($driver, static function (BaseConnection $db, ItemHistory $logger): void {
            $logger->log(1, 'Saw', 'deleted', ['quantity' => 2]);   // the other request
            $db->query('DELETE FROM items WHERE id = 1');
        });
        $r = $this->controller()->delete('1');
        $this->assertSame([303, '/?deleted=1'], [$r->getStatusCode(), $r->getHeaderLine('Location')], 'no undo token in the redirect');
        $this->assertSame(0, $this->rowsIn($db, 'undo_tokens'), 'and none stored');
        $this->assertSame(1, $this->rowsIn($db, 'activity'), 'only the other request\'s history row');
    }

    /** The same delete with no race issues the token, so the test above can tell the two apart. */
    #[DataProvider('drivers')]
    public function testADeleteIssuesAnUndoToken(string $driver): void
    {
        $db = $this->wire($driver, null);
        $r = $this->controller()->delete('1');
        $this->assertSame(1, preg_match('#^/\?deleted=1&undo=[0-9a-f]{32}$#', $r->getHeaderLine('Location')));
        $this->assertSame([1, 0], [$this->rowsIn($db, 'undo_tokens'), $this->rowsIn($db, 'items')]);
    }

    /**
     * N-1741: an error of the unit other than "gone", even a 404 that reads
     * the same, fails the request: the item stays, and no token is issued.
     */
    #[DataProvider('drivers')]
    public function testAnotherErrorOfTheDeleteIsNotASkip(string $driver): void
    {
        $other = new UserError('Item not found.', 404);
        $db = $this->wire($driver, static function () use ($other): void {
            throw $other;
        });
        try {
            $this->controller()->delete('1');
            $this->fail('the error must reach the request');
        } catch (UserError $e) {
            $this->assertSame($other, $e);
        }
        $this->assertSame([1, 0, 0], [$this->rowsIn($db, 'items'), $this->rowsIn($db, 'undo_tokens'), $this->rowsIn($db, 'activity')]);
    }

    private function controller(): ItemController
    {
        $c = new ItemController();
        $c->initController(Services::request(), Services::response(), Services::logger());

        return $c;
    }

    /**
     * The item "Saw" and the services of the delete in batch mode; $race runs
     * once, when the unit queues the delete's history row.
     *
     * @param (Closure(BaseConnection<object|resource, object|resource>, ItemHistory):void)|null $race
     * @return BaseConnection<object|resource, object|resource>
     */
    private function wire(string $driver, ?Closure $race): BaseConnection
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Saw', 2, '2026-01-01 08:00:00', '2026-01-01 08:00:00')");
        $logger = new ActivityLogger($db, new Clock(), static fn (): ?string => null);
        $history = new class ($logger, $race === null ? null : static fn () => $race($db, $logger)) implements ItemHistory {
            public function __construct(private readonly ItemHistory $inner, private ?Closure $race)
            {
            }

            public function log(int $itemId, string $itemName, string $action, array $details = []): void
            {
                $this->inner->log($itemId, $itemName, $action, $details);
            }

            public function queue(UnitOfWork $uow, int|Ref $itemId, string $itemName, string $action, array $details = []): void
            {
                $race = $this->race;
                $this->race = null;
                $race !== null && $race();
                $this->inner->queue($uow, $itemId, $itemName, $action, $details);
            }
        };
        Services::injectMock('itemRepository', $this->database->items($db, UnitsOfWork::on(new LocalBatchExecutor($db)), $history));
        Services::injectMock('undoTokens', new UndoTokens($db, new Clock(), 600, static function (string $f): void {
        }, static function (string $f): void {
        }));

        return $db;
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    private function rowsIn(BaseConnection $db, string $table): int
    {
        return (int)Writes::rows($db, 'SELECT COUNT(*) AS n FROM ' . $table)[0]['n'];
    }
}
