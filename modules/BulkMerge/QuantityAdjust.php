<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use CodeIgniter\Database\BaseConnection;

/** The +/- buttons (legacy item_adjust_qty()): never below 0, a history entry when it changed. */
final class QuantityAdjust
{
    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
        ?UnitsOfWork $units = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    /**
     * The new quantity; null when the item is gone. A concurrent change of the
     * same row is retried (M-1752): the write is conditional on the quantity
     * that was read, so a lost update cannot land, and history matches the row.
     */
    public function adjust(int $id, int $delta): ?int
    {
        $conflict = static fn (): UserError => new UserError('This item was changed by someone else. Try again.', 409);
        $last = $conflict();
        for ($try = 0; $try < 16; $try++) {
            try {
                return $this->units->run('items', function (UnitOfWork $uow) use ($id, $delta, $conflict): ?int {
                    $row = Writes::rows($this->db, 'SELECT name, quantity FROM items WHERE id = ?', [$id])[0] ?? null;
                    if ($row === null) {
                        return null;
                    }
                    $from = (int)$row['quantity'];
                    $q = max(0, $from + $delta);
                    if ($q === $from) {
                        return $q;
                    }
                    // Conditional on the read value so expect-1 catches a lost update (M-1752).
                    $uow->write(
                        'UPDATE items SET quantity = ?, updated_at = ? WHERE id = ? AND quantity = ?',
                        [$q, $this->clock->now(), $id, $from],
                        'adjust quantity',
                        1,
                        $conflict,
                    );
                    $this->history->queue($uow, $id, (string)$row['name'], 'quantity', ['from' => $from, 'to' => $q]);

                    return $q;
                }, (string)$id);
            } catch (UserError $e) {
                if ($e->getCode() !== 409) {
                    throw $e;
                }
                $last = $e;
                usleep(min(50_000, 500 * (1 << min($try, 6))));
            }
        }

        throw $last;
    }
}
