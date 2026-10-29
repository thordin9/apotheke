<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

use Apotheke\Core\Database\Writes;
use Apotheke\Search\SearchIndex;
use CodeIgniter\Database\BaseConnection;

/**
 * The list's queries (legacy items_search(), stats(), fav_count(),
 * item_get() for a name). Both drivers run the same statements; reads that
 * fail throw (Writes::rows).
 */
final class ItemList
{
    public const PAGE_MAX = 100000;

    private const FROM = 'FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id';

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly SearchIndex $search,
        private readonly Calendar $calendar,
    ) {
    }

    public static function clampPage(int $page): int
    {
        return max(1, min(self::PAGE_MAX, $page));
    }

    /**
     * One page of the list.
     *
     * @param array<string,string> $f
     * @return array{items: list<array<string,mixed>>, total: int, more: bool, fixed: array<string,string>}
     */
    public function search(string $q, array $f, string $sort, string $defaultSort, int $page, int $pageSize): array
    {
        $where = [];
        $args = [];
        $match = null;
        if (SearchIndex::tokens($q) !== []) {
            // every word must match name, notes, tags, custom fields, category or location path
            $match = $this->search->match($q);
            $where[] = $match['ids'] !== [] ? 'i.id IN (' . implode(',', $match['ids']) . ')' : '1 = 0';
        }
        foreach (['loc' => 'i.location_id', 'cat' => 'i.category_id'] as $k => $col) {
            $v = $f[$k] ?? '';
            if ($v === 'none') {
                $where[] = "$col IS NULL";
            } elseif ($v !== '' && ctype_digit($v)) {
                // a location filter includes everything stored in its sub-locations
                $ids = $k === 'loc' ? $this->subtree((int)$v) : [(int)$v];
                $where[] = "$col IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($args, ...$ids);
            }
        }
        if (ctype_digit($f['tag'] ?? '')) {
            $where[] = 'EXISTS (SELECT 1 FROM item_tags it WHERE it.item_id = i.id AND it.tag_id = ?)';
            $args[] = (int)$f['tag'];
        }
        $today = $this->calendar->today();
        if (($f['stock'] ?? '') === 'low') {
            $where[] = '(i.min_qty IS NOT NULL AND i.quantity <= i.min_qty)';
        }
        if (($f['exp'] ?? '') === 'expired') {
            $where[] = 'i.expires_on IS NOT NULL AND i.expires_on < ?';
            $args[] = $today;
        }
        if (($f['exp'] ?? '') === 'soon') {
            $where[] = 'i.expires_on IS NOT NULL AND i.expires_on >= ? AND i.expires_on <= ?';
            array_push($args, $today, $this->calendar->soonUntil());
        }
        if (($f['fav'] ?? '') === '1') {
            $where[] = 'i.favourite = 1';
        }
        if (($f['lend'] ?? '') === 'out') {
            $where[] = 'i.lent_to IS NOT NULL';
        }
        if (($f['lend'] ?? '') === 'overdue') {
            $where[] = 'i.lent_to IS NOT NULL AND i.lent_due IS NOT NULL AND i.lent_due < ?';
            $args[] = $today;
        }
        $w = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
        $order = match ($sort) {
            'name' => 'i.sort_key ASC, i.id DESC',
            'location' => 'CASE WHEN l.id IS NULL THEN 1 ELSE 0 END, l.sort_key ASC, i.sort_key ASC, i.id DESC',
            'category' => 'CASE WHEN c.id IS NULL THEN 1 ELSE 0 END, c.sort_key ASC, i.sort_key ASC, i.id DESC',
            'qty' => 'i.quantity DESC, i.sort_key ASC, i.id DESC',
            'value' => 'CASE WHEN i.value_cents IS NULL THEN 1 ELSE 0 END, i.value_cents DESC, i.sort_key ASC, i.id DESC',
            default => 'i.updated_at DESC, i.id DESC',
        };
        if ($match !== null && $match['ids'] !== [] && $sort === $defaultSort) {
            // searching without an explicit sort: best match first
            $rank = array_slice($match['ids'], 0, 1000);
            $order = 'CASE i.id ' . implode(' ', array_map(static fn (int $id, int $n): string => 'WHEN ' . $id . ' THEN ' . $n, $rank, array_keys($rank))) . ' ELSE ' . count($rank) . ' END, ' . $order;
        }
        $total = (int)($this->rows('SELECT COUNT(*) AS n ' . self::FROM . " $w", $args)[0]['n'] ?? 0);
        $page = self::clampPage($page);
        $offset = ($page - 1) * $pageSize;
        $items = $this->rows("SELECT i.id, i.name, i.location_id, COALESCE(l.name, '') AS location, i.category_id, COALESCE(c.name, '') AS category, i.quantity, i.min_qty, i.image, i.lent_to, i.lent_due, i.expires_on, i.favourite "
            . self::FROM . " $w ORDER BY $order LIMIT " . $pageSize . " OFFSET $offset", $args);
        if ($match !== null) {
            foreach ($items as &$it) {
                $it['hl'] = SearchIndex::highlight((string)$it['name'], $match['terms']);
                $it['snip'] = $this->search->snippet((int)$it['id'], $match['terms']);
            }
            unset($it);
        }

        return ['items' => $items, 'total' => $total, 'more' => $offset + count($items) < $total, 'fixed' => $match['fixed'] ?? []];
    }

    /** @return array{items:int,qty:int,unassigned:int,uncategorized:int} */
    public function stats(): array
    {
        $r = $this->rows('SELECT COUNT(*) AS items, COALESCE(SUM(quantity),0) AS qty,
            COALESCE(SUM(CASE WHEN location_id IS NULL THEN 1 ELSE 0 END),0) AS unassigned,
            COALESCE(SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END),0) AS uncategorized FROM items')[0];

        return ['items' => (int)$r['items'], 'qty' => (int)$r['qty'], 'unassigned' => (int)$r['unassigned'], 'uncategorized' => (int)$r['uncategorized']];
    }

    public function favouriteCount(): int
    {
        return (int)($this->rows('SELECT COUNT(*) AS n FROM items WHERE favourite = 1')[0]['n'] ?? 0);
    }

    /** @return array{name:string,favourite:bool}|null the item's name and star, null when it does not exist */
    public function find(int $id): ?array
    {
        $r = $this->rows('SELECT name, favourite FROM items WHERE id = ?', [$id])[0] ?? null;

        return $r === null ? null : ['name' => (string)$r['name'], 'favourite' => !empty($r['favourite'])];
    }

    /** @return list<int> the location and every location below it (legacy location_subtree_ids()) */
    private function subtree(int $id): array
    {
        $byParent = [];
        foreach ($this->rows('SELECT id, parent_id FROM locations WHERE parent_id IS NOT NULL') as $r) {
            $byParent[(int)$r['parent_id']][] = (int)$r['id'];
        }
        $out = [$id];
        $seen = [$id => true];
        for ($i = 0; $i < count($out); $i++) {
            foreach ($byParent[$out[$i]] ?? [] as $c) {
                if (!isset($seen[$c])) {
                    $seen[$c] = true;
                    $out[] = $c;
                }
            }
        }

        return $out;
    }

    /**
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db, $sql, $binds);
    }
}
