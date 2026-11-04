<?php

declare(strict_types=1);

namespace Apotheke\Core\Libraries;

use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;

/**
 * Writes one row of an item's history (legacy activity_log()). Core only
 * names it; the Activity module writes it, so Taxonomy and later Catalog can
 * log changes without depending on Activity.
 */
interface ItemHistory
{
    /** @param array<string,mixed> $details */
    public function log(int $itemId, string $itemName, string $action, array $details = []): void;

    /**
     * The same row, queued on $uow (P4-2b): written with the unit's other
     * steps, in both modes. $itemId may be the Ref of an item the unit inserts.
     *
     * @param array<string,mixed> $details
     */
    public function queue(UnitOfWork $uow, int|Ref $itemId, string $itemName, string $action, array $details = []): void;
}
