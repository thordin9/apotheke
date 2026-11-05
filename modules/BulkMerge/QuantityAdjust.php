<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
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

    /** The new quantity; null when the item is gone. */
    public function adjust(int $id, int $delta): ?int
    {
        // One unit (ADR-002, guard): the new quantity is computed from the
        // read, so the batch path needs no read after the write.
        return $this->units->run('items', function (UnitOfWork $uow) use ($id, $delta): ?int {
            $row = \Apotheke\Core\Database\Writes::rows($this->db, 'SELECT name, quantity FROM items WHERE id = ?', [$id])[0] ?? null;
            if ($row === null) {
                return null;
            }
            $from = (int)$row['quantity'];
            $q = max(0, $from + $delta);
            $uow->write('UPDATE items SET quantity = ?, updated_at = ? WHERE id = ?', [$q, $this->clock->now(), $id], 'adjust quantity', 1);
            if ($q !== $from) {
                $this->history->queue($uow, $id, (string)$row['name'], 'quantity', ['from' => $from, 'to' => $q]);
            }

            return $q;
        }, (string)$id);
    }
}
