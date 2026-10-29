<?php

declare(strict_types=1);

namespace Apotheke\Core\Libraries;

/**
 * Writes one row of an item's history (legacy activity_log()). Core only
 * names it; the Activity module writes it, so Taxonomy and later Catalog can
 * log changes without depending on Activity.
 */
interface ItemHistory
{
    /** @param array<string,mixed> $details */
    public function log(int $itemId, string $itemName, string $action, array $details = []): void;
}
