<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Core\Database\Writes;
use CodeIgniter\Database\BaseConnection;

/**
 * Barcode lookup (legacy items_by_barcode(), barcode_target()): a product code
 * can be on several items, so the lookup is exact and lists them all.
 */
final class Barcode
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return list<int> ids of the items with exactly this barcode (at most 20) */
    public function items(string $code): array
    {
        $code = ItemInput::barcode($code);
        if ($code === '') {
            return [];
        }
        return array_map(static fn (array $r): int => (int)$r['id'], Writes::rows($this->db, 'SELECT id FROM items WHERE barcode = ? ORDER BY id LIMIT 20', [$code]));
    }

    /** Where /barcode/{code} goes: the only item with it, the list of matches, or a new item with the code filled in. */
    public function target(string $code): string
    {
        $code = ItemInput::barcode($code);
        if ($code === '') {
            return '/barcode';
        }
        $ids = $this->items($code);
        if (count($ids) === 1) {
            return '/item/' . $ids[0];
        }
        if ($ids !== []) {
            return '/?q=' . rawurlencode($code);
        }

        return '/new?barcode=' . rawurlencode($code);
    }
}
