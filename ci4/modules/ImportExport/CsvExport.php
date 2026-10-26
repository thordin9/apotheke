<?php

declare(strict_types=1);

namespace Apotheke\ImportExport;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use Generator;

/**
 * The CSV export (legacy GET /export.csv): a UTF-8 BOM, the header, then
 * every item by location, category and name, each cell guarded against
 * formulas (CsvCells::protect()). It imports as it is.
 *
 * lines() yields the file line by line, as legacy streams it with fputcsv(),
 * so memory stays the same for any number of items: items are read in
 * batches (SQLite steps through one statement; PostgreSQL fetches from a
 * cursor in a read-only snapshot, since pg_query() would hold every row) and
 * each batch reads only its own items' tags.
 */
final class CsvExport
{
    /** Items per batch (and per tag query). */
    public const BATCH = 500;

    private const HEADER = ['id', 'name', 'location', 'category', 'quantity', 'notes', 'image', 'created_at', 'updated_at', 'tags', 'value', 'currency', 'purchase_date', 'serial', 'min_qty', 'lent_to', 'lent_at', 'lent_due', 'expires_on', 'barcode'];

    private const ITEMS = "SELECT i.id, i.name, COALESCE(l.name, '') AS location, COALESCE(c.name, '') AS category, i.quantity, i.notes, i.image, i.created_at, i.updated_at, i.value_cents, i.purchase_date, i.serial, i.min_qty, i.lent_to, i.lent_at, i.lent_due, i.expires_on, i.barcode
            FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id
            ORDER BY CASE WHEN l.id IS NULL THEN 1 ELSE 0 END, l.sort_key, CASE WHEN c.id IS NULL THEN 1 ELSE 0 END, c.sort_key, i.sort_key, i.id";

    private const CURSOR = 'apotheke_export';

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * The file, one CSV line per value; $currency is the currency setting.
     * The items query runs when the generator starts (current()), so a
     * failing read can still answer 500 before anything is sent.
     *
     * @return Generator<int, string>
     */
    public function lines(string $currency): Generator
    {
        $batches = $this->batches();
        $batches->valid();   // runs the items query now
        yield "\xEF\xBB\xBF" . CsvCells::line(self::HEADER);
        $s = static fn (mixed $v): string => is_scalar($v) ? (string)$v : '';
        for (; $batches->valid(); $batches->next()) {
            $rows = $batches->current();
            $tagMap = $this->tags(array_map(static fn (array $r): int => (int)$r['id'], $rows));
            foreach ($rows as $r) {
                $v = $r['value_cents'];
                $cells = [$r['id'], $r['name'], $r['location'], $r['category'], $r['quantity'], $r['notes'], $r['image'], $r['created_at'], $r['updated_at'],
                    implode(', ', $tagMap[(int)$r['id']] ?? []),
                    $v === null ? '' : number_format((int)$v / 100, 2, '.', ''), $v === null ? '' : $currency,
                    $s($r['purchase_date']), $s($r['serial']), $r['min_qty'] === null ? '' : (string)(int)$r['min_qty'],
                    $s($r['lent_to']), $s($r['lent_at']), $s($r['lent_due']), $s($r['expires_on']), $s($r['barcode'])];
                yield CsvCells::line(array_map(CsvCells::protect(...), $cells));
            }
        }
    }

    /**
     * The items in export order, at most BATCH per list.
     *
     * @return Generator<int, non-empty-list<array<string,mixed>>>
     */
    private function batches(): Generator
    {
        if ($this->db->DBDriver !== 'Postgre') {
            $result = $this->db->query(self::ITEMS);
            if (!$result instanceof BaseResult) {
                throw new WriteFailed('Database read failed');
            }
            $rows = [];
            while (is_array($r = $result->getUnbufferedRow('array'))) {
                /** @var array<string,mixed> $r */
                $rows[] = $r;
                if (count($rows) === self::BATCH) {
                    yield $rows;
                    $rows = [];
                }
            }
            if ($rows !== []) {
                yield $rows;
            }

            return;
        }
        // Not Writes::transaction(): nothing is written, and the snapshot
        // only has to last while the lines are sent.
        if (!$this->db->transBegin()) {
            throw new WriteFailed('Database read failed');
        }
        try {
            Writes::check($this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'), 'export snapshot');
            Writes::check($this->db->query('DECLARE ' . self::CURSOR . ' NO SCROLL CURSOR FOR ' . self::ITEMS), 'export cursor');
            while (($rows = Writes::rows($this->db, 'FETCH FORWARD ' . self::BATCH . ' FROM ' . self::CURSOR)) !== []) {
                yield $rows;
            }
        } finally {
            $this->db->transRollback();
        }
    }

    /**
     * Tag names by item for $ids, in legacy's order (tag sort key, id).
     *
     * @param non-empty-list<int> $ids
     * @return array<int, list<string>>
     */
    private function tags(array $ids): array
    {
        $map = [];
        foreach (Writes::rows($this->db, 'SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id IN (' . implode(',', $ids) . ') ORDER BY t.sort_key, t.id') as $t) {
            $map[(int)$t['item_id']][] = (string)$t['name'];
        }

        return $map;
    }
}
