<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use CodeIgniter\Database\BaseConnection;

/** The +/- buttons (legacy item_adjust_qty()): never below 0, a history entry when it changed. */
final class QuantityAdjust
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
    ) {
    }

    /** The new quantity; null when the item is gone. */
    public function adjust(int $id, int $delta): ?int
    {
        return Writes::transaction($this->db, function () use ($id, $delta): ?int {
            $row = Writes::rows($this->db, 'SELECT name, quantity FROM items WHERE id = ?', [$id])[0] ?? null;
            if ($row === null) {
                return null;
            }
            Writes::check($this->db->query('UPDATE items SET quantity = CASE WHEN quantity + ? < 0 THEN 0 ELSE quantity + ? END, updated_at = ? WHERE id = ?',
                [$delta, $delta, $this->clock->now(), $id]), 'adjust quantity');
            $q = (int)(Writes::rows($this->db, 'SELECT quantity FROM items WHERE id = ?', [$id])[0]['quantity'] ?? 0);
            if ($q !== (int)$row['quantity']) {
                $this->history->log($id, (string)$row['name'], 'quantity', ['from' => (int)$row['quantity'], 'to' => $q]);
            }

            return $q;
        });
    }
}
