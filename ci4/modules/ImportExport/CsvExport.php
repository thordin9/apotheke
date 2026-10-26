<?php

declare(strict_types=1);

namespace Apotheke\ImportExport;

use Apotheke\Core\Database\Writes;
use CodeIgniter\Database\BaseConnection;

/**
 * The CSV export (legacy GET /export.csv): a UTF-8 BOM, the header, then
 * every item by location, category and name, each cell guarded against
 * formulas (CsvCells::protect()). It imports as it is.
 */
final class CsvExport
{
    private const HEADER = ['id', 'name', 'location', 'category', 'quantity', 'notes', 'image', 'created_at', 'updated_at', 'tags', 'value', 'currency', 'purchase_date', 'serial', 'min_qty', 'lent_to', 'lent_at', 'lent_due', 'expires_on', 'barcode'];

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** The whole file; $currency is the currency setting. */
    public function csv(string $currency): string
    {
        $out = "\xEF\xBB\xBF" . CsvCells::line(self::HEADER);
        $tagMap = [];
        foreach (Writes::rows($this->db, 'SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY t.sort_key, t.id') as $t) {
            $tagMap[(int)$t['item_id']][] = (string)$t['name'];
        }
        $rows = Writes::rows($this->db, "SELECT i.id, i.name, COALESCE(l.name, '') AS location, COALESCE(c.name, '') AS category, i.quantity, i.notes, i.image, i.created_at, i.updated_at, i.value_cents, i.purchase_date, i.serial, i.min_qty, i.lent_to, i.lent_at, i.lent_due, i.expires_on, i.barcode
            FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id
            ORDER BY CASE WHEN l.id IS NULL THEN 1 ELSE 0 END, l.sort_key, CASE WHEN c.id IS NULL THEN 1 ELSE 0 END, c.sort_key, i.sort_key, i.id");
        $s = static fn (mixed $v): string => is_scalar($v) ? (string)$v : '';
        foreach ($rows as $r) {
            $v = $r['value_cents'];
            $cells = [$r['id'], $r['name'], $r['location'], $r['category'], $r['quantity'], $r['notes'], $r['image'], $r['created_at'], $r['updated_at'],
                implode(', ', $tagMap[(int)$r['id']] ?? []),
                $v === null ? '' : number_format((int)$v / 100, 2, '.', ''), $v === null ? '' : $currency,
                $s($r['purchase_date']), $s($r['serial']), $r['min_qty'] === null ? '' : (string)(int)$r['min_qty'],
                $s($r['lent_to']), $s($r['lent_at']), $s($r['lent_due']), $s($r['expires_on']), $s($r['barcode'])];
            $out .= CsvCells::line(array_map(CsvCells::protect(...), $cells));
        }

        return $out;
    }
}
