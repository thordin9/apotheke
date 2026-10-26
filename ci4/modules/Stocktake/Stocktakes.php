<?php

declare(strict_types=1);

namespace Apotheke\Stocktake;

use Apotheke\Catalog\Barcode;
use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;

/**
 * Stocktakes (legacy src/stocktake.php): walk through a location and
 * everything inside it, tick or scan what you find, then finish with a
 * report of what was found, what is missing and what turned up although it
 * belongs elsewhere (misplaced). Misplaced items can be moved to the checked
 * location when finishing. Every change is one transaction.
 *
 * @phpstan-type Take array{id:int,location_id:?int,location_name:string,status:string,started_at:string,finished_at:?string,moves_applied:int,summary:array<string,mixed>}
 * @phpstan-type Line array{id:int,item_id:?int,item_name:string,expected:bool,home_location_id:?int,home_location:string,found:bool,found_at:?string,moved:bool,image:?string}
 * @phpstan-type Report array{found:list<Line>,missing:list<Line>,misplaced:list<Line>,expected:int}
 */
final class Stocktakes
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly TaxonomyRepository $taxonomy,
        private readonly ItemRepository $items,
        private readonly ItemHistory $history,
        private readonly Barcode $barcode,
    ) {
    }

    /** @return Take|null */
    public function get(int $id): ?array
    {
        $r = Writes::rows($this->db, 'SELECT * FROM stocktakes WHERE id = ?', [$id])[0] ?? null;

        return $r === null ? null : self::row($r);
    }

    /**
     * Every stocktake, open ones first, then newest first.
     *
     * @return list<Take>
     */
    public function all(int $limit = 100): array
    {
        return array_map(self::row(...), Writes::rows($this->db, "SELECT * FROM stocktakes ORDER BY CASE WHEN status = 'open' THEN 0 ELSE 1 END, id DESC LIMIT " . max(1, $limit)));
    }

    /** Start checking a location; returns the id of the new (or already open) stocktake for it. */
    public function start(int $locationId): int
    {
        if ($this->taxonomy->get('location', $locationId) === null) {
            throw new UserError('That location no longer exists.', 404);
        }
        $open = Writes::rows($this->db, "SELECT id FROM stocktakes WHERE status = 'open' AND location_id = ? ORDER BY id DESC LIMIT 1", [$locationId])[0] ?? null;
        if ($open !== null) {
            return (int)$open['id'];
        }

        return Writes::transaction($this->db, function () use ($locationId): int {
            $byId = $this->taxonomy->locationsById();
            Writes::check($this->db->query('INSERT INTO stocktakes (location_id, location_name, status, started_at, summary) VALUES (?,?,?,?,?)',
                [$locationId, (string)($byId[$locationId]['path'] ?? ''), 'open', $this->clock->now(), '{}']), 'start stocktake');
            $id = (int)$this->db->insertID();
            $ids = $this->taxonomy->subtreeIds($locationId);
            $rows = Writes::rows($this->db, 'SELECT id, name, location_id FROM items WHERE location_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_key, id', $ids);
            foreach ($rows as $it) {
                Writes::check($this->db->query('INSERT INTO stocktake_lines (stocktake_id, item_id, item_name, expected, home_location_id, home_location) VALUES (?,?,?,1,?,?)',
                    [$id, (int)$it['id'], (string)$it['name'], (int)$it['location_id'], (string)($byId[(int)$it['location_id']]['path'] ?? '')]), 'stocktake line');
            }

            return $id;
        });
    }

    /** @return list<Line> */
    public function lines(int $stocktakeId): array
    {
        $rows = Writes::rows($this->db, 'SELECT s.*, i.image FROM stocktake_lines s LEFT JOIN items i ON i.id = s.item_id WHERE s.stocktake_id = ? ORDER BY s.expected DESC, s.item_name, s.id', [$stocktakeId]);

        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'item_id' => $r['item_id'] === null ? null : (int)$r['item_id'], 'item_name' => (string)$r['item_name'],
            'expected' => (int)$r['expected'] === 1, 'home_location_id' => $r['home_location_id'] === null ? null : (int)$r['home_location_id'],
            'home_location' => (string)$r['home_location'], 'found' => (int)$r['found'] === 1, 'found_at' => $r['found_at'] === null ? null : (string)$r['found_at'],
            'moved' => (int)$r['moved'] === 1, 'image' => isset($r['image']) ? (string)$r['image'] : null], $rows);
    }

    /**
     * Which item a scanned or typed code means: an Apotheke item link (QR
     * label: …/item/42), "#42", or a barcode that exactly one item has.
     */
    public function resolveCode(string $code): int
    {
        $code = trim($code);
        if ($code === '') {
            throw new UserError('Scan or type a code.', 422);
        }
        if (preg_match('#/item/(\d{1,12})(?:[/?\#]|$)#', $code, $m) === 1 || preg_match('/^#(\d{1,12})$/D', $code, $m) === 1) {
            if ($this->items->get((int)$m[1]) !== null) {
                return (int)$m[1];
            }
            throw new UserError('No item has that QR code.', 404);
        }
        $ids = $this->barcode->items($code);
        if (count($ids) === 1) {
            return $ids[0];
        }
        if ($ids !== []) {
            throw new UserError(count($ids) . ' items have this barcode – tick the right one in the list.', 409);
        }
        throw new UserError('No item has the barcode “' . ItemInput::barcode($code) . '”.', 404);
    }

    /**
     * Tick ($found) or untick an item. An item that was not expected here is
     * added as misplaced; unticking a misplaced item removes it again. The
     * transaction writes first (stillOpen()), so it holds the write lock
     * before its first read: on SQLite a tick during another client's write
     * waits for it instead of failing on a read lock it cannot upgrade.
     *
     * @return array{state:string,name:string,home:string} state: found, misplaced, missing or none
     */
    public function check(int $stocktakeId, int $itemId, bool $found = true): array
    {
        $s = $this->get($stocktakeId) ?? throw new UserError('Stocktake not found.', 404);
        if ($s['status'] !== 'open') {
            throw new UserError('This stocktake is finished.', 409);
        }
        $it = $this->items->get($itemId) ?? throw new UserError('Item not found.', 404);

        return Writes::transaction($this->db, function () use ($stocktakeId, $it, $found): array {
            $this->stillOpen($stocktakeId);
            $id = (int)$it['id'];
            $name = (string)$it['name'];
            $loc = is_int($it['location_id']) ? $it['location_id'] : null;
            $line = Writes::rows($this->db, 'SELECT id, expected FROM stocktake_lines WHERE stocktake_id = ? AND item_id = ?', [$stocktakeId, $id])[0] ?? null;
            $home = $this->taxonomy->locationPath($loc);
            $r = ['name' => $name, 'home' => $home];
            if ($line === null) {
                if (!$found) {
                    return ['state' => 'none'] + $r;
                }
                Writes::check($this->db->query('INSERT INTO stocktake_lines (stocktake_id, item_id, item_name, expected, home_location_id, home_location, found, found_at) VALUES (?,?,?,0,?,?,1,?)',
                    [$stocktakeId, $id, $name, $loc, $home, $this->clock->now()]), 'misplaced line');

                return ['state' => 'misplaced'] + $r;
            }
            if ((int)$line['expected'] === 0) {
                if (!$found) {
                    Writes::check($this->db->query('DELETE FROM stocktake_lines WHERE id = ?', [(int)$line['id']]), 'remove line');

                    return ['state' => 'none'] + $r;
                }

                return ['state' => 'misplaced'] + $r;
            }
            Writes::check($this->db->query('UPDATE stocktake_lines SET found = ?, found_at = ? WHERE id = ?', [$found ? 1 : 0, $found ? $this->clock->now() : null, (int)$line['id']]), 'tick line');

            return ['state' => $found ? 'found' : 'missing'] + $r;
        });
    }

    /** @return Report */
    public function report(int $stocktakeId): array
    {
        $r = ['found' => [], 'missing' => [], 'misplaced' => []];
        foreach ($this->lines($stocktakeId) as $l) {
            $r[$l['expected'] ? ($l['found'] ? 'found' : 'missing') : 'misplaced'][] = $l;
        }

        return $r + ['expected' => count($r['found']) + count($r['missing'])];
    }

    /**
     * Progress of an open stocktake.
     *
     * @return array{found:int,expected:int,misplaced:int}
     */
    public function progress(int $stocktakeId): array
    {
        $r = $this->report($stocktakeId);

        return ['found' => count($r['found']), 'expected' => $r['expected'], 'misplaced' => count($r['misplaced'])];
    }

    /** Finish: store the counts and, with $applyMoves, move every misplaced item into the checked location. Returns the number moved. */
    public function finish(int $stocktakeId, bool $applyMoves): int
    {
        $s = $this->get($stocktakeId) ?? throw new UserError('Stocktake not found.', 404);
        if ($s['status'] !== 'open') {
            throw new UserError('This stocktake is already finished.', 409);
        }
        $to = $s['location_id'];
        if ($applyMoves && ($to === null || $this->taxonomy->get('location', $to) === null)) {
            throw new UserError('The location was deleted – misplaced items cannot be moved there.', 409);
        }

        return Writes::transaction($this->db, function () use ($s, $to, $applyMoves): int {
            $this->close($s['id'], 'done');
            $rep = $this->report($s['id']);
            $moved = 0;
            if ($applyMoves) {
                $path = $this->taxonomy->locationPath($to);
                foreach ($rep['misplaced'] as $l) {
                    $it = $l['item_id'] !== null ? $this->items->get($l['item_id']) : null;
                    if ($it === null || (int)$it['location_id'] === $to) {
                        continue;
                    }
                    Writes::check($this->db->query('UPDATE items SET location_id = ?, updated_at = ? WHERE id = ?', [$to, $this->clock->now(), (int)$it['id']]), 'move item');
                    Writes::check($this->db->query('UPDATE stocktake_lines SET moved = 1 WHERE id = ?', [$l['id']]), 'mark moved');
                    $this->history->log((int)$it['id'], (string)$it['name'], 'moved', ['from' => $this->taxonomy->locationPath(is_int($it['location_id']) ? $it['location_id'] : null), 'to' => $path, 'reason' => 'stocktake']);
                    $moved++;
                }
            }
            $sum = ['expected' => $rep['expected'], 'found' => count($rep['found']), 'missing' => count($rep['missing']), 'misplaced' => count($rep['misplaced']), 'moved' => $moved];
            Writes::check($this->db->query('UPDATE stocktakes SET moves_applied = ?, summary = ? WHERE id = ?',
                [$moved, (string)json_encode($sum), $s['id']]), 'finish stocktake');

            return $moved;
        });
    }

    public function cancel(int $stocktakeId): void
    {
        $s = $this->get($stocktakeId) ?? throw new UserError('Stocktake not found.', 404);
        if ($s['status'] !== 'open') {
            throw new UserError('This stocktake is already finished.', 409);
        }
        $this->close($stocktakeId, 'cancelled');
    }

    /**
     * Mark an open stocktake done or cancelled, as the first write of its
     * transaction. Only one finish or cancel gets the open stocktake: one
     * that read it as open before another closed it answers 409 and changes
     * nothing (on PostgreSQL it waits for the other's row lock first).
     */
    private function close(int $stocktakeId, string $status): void
    {
        Writes::check($this->db->query("UPDATE stocktakes SET status = ?, finished_at = ? WHERE id = ? AND status = 'open'",
            [$status, $this->clock->now(), $stocktakeId]), $status === 'done' ? 'finish stocktake' : 'cancel stocktake');
        if ($this->db->affectedRows() !== 1) {
            throw new UserError('This stocktake is already finished.', 409);
        }
    }

    /**
     * The first write of a tick's transaction: it goes on only while the
     * stocktake is still open. A tick that read it as open before a finish
     * or cancel closed it answers as a finished stocktake (409) and adds or
     * changes no line (on PostgreSQL it waits for the closing row lock first).
     */
    private function stillOpen(int $stocktakeId): void
    {
        Writes::check($this->db->query("UPDATE stocktakes SET status = status WHERE id = ? AND status = 'open'", [$stocktakeId]), 'check stocktake');
        if ($this->db->affectedRows() !== 1) {
            throw new UserError('This stocktake is finished.', 409);
        }
    }

    /**
     * @param array<string,mixed> $r
     * @return Take
     */
    private static function row(array $r): array
    {
        $sum = json_decode((string)$r['summary'], true);

        return ['id' => (int)$r['id'], 'location_id' => $r['location_id'] === null ? null : (int)$r['location_id'], 'location_name' => (string)$r['location_name'],
            'status' => (string)$r['status'], 'started_at' => (string)$r['started_at'], 'finished_at' => $r['finished_at'] === null ? null : (string)$r['finished_at'],
            'moves_applied' => (int)$r['moves_applied'], 'summary' => is_array($sum) && $sum !== [] ? $sum : []];
    }
}
