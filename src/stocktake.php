<?php
declare(strict_types=1);

/*
 * Stocktakes: walk through a location (and everything inside it), tick or scan the items you find, then finish with a
 * report of what was found, what is missing and what turned up although it belongs elsewhere (misplaced). Misplaced
 * items can be moved to the checked location when finishing. Finished stocktakes stay as history.
 */

const STOCKTAKE_STATUSES = ['open', 'done', 'cancelled'];

/** @return array{id:int,location_id:?int,location_name:string,status:string,started_at:string,finished_at:?string,moves_applied:int,summary:array}|null */
function stocktake_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM stocktakes WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? stocktake_row($r) : null;
}

function stocktake_row(array $r): array
{
    return ['id' => (int)$r['id'], 'location_id' => $r['location_id'] === null ? null : (int)$r['location_id'], 'location_name' => (string)$r['location_name'],
        'status' => (string)$r['status'], 'started_at' => (string)$r['started_at'], 'finished_at' => $r['finished_at'] === null ? null : (string)$r['finished_at'],
        'moves_applied' => (int)$r['moves_applied'], 'summary' => json_decode((string)$r['summary'], true) ?: []];
}

/** Every stocktake, open ones first, then newest first. */
function stocktake_list(int $limit = 100): array
{
    $rows = db()->query("SELECT * FROM stocktakes ORDER BY CASE WHEN status = 'open' THEN 0 ELSE 1 END, id DESC LIMIT " . max(1, $limit))->fetchAll();
    return array_map('stocktake_row', $rows);
}

function stocktake_open_for(int $locationId): ?array
{
    $st = db()->prepare("SELECT * FROM stocktakes WHERE status = 'open' AND location_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$locationId]);
    $r = $st->fetch();
    return $r ? stocktake_row($r) : null;
}

/** Start checking a location; returns the id of the new (or already open) stocktake for it. */
function stocktake_start(int $locationId): int
{
    $loc = tax_get('location', $locationId);
    if (!$loc) throw new UserError('That location no longer exists.', 404);
    if ($open = stocktake_open_for($locationId)) return $open['id'];
    return db_tx(function (PDO $pdo) use ($locationId): int {
        $path = location_path($locationId);
        $id = db_insert($pdo, 'INSERT INTO stocktakes (location_id, location_name, status, started_at, summary) VALUES (?,?,?,?,?)',
            [$locationId, $path, 'open', now_utc(), '{}']);
        $ids = location_subtree_ids($locationId);
        $st = $pdo->prepare('SELECT id, name, location_id FROM items WHERE location_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY sort_key, id');
        $st->execute($ids);
        $ins = $pdo->prepare('INSERT INTO stocktake_lines (stocktake_id, item_id, item_name, expected, home_location_id, home_location) VALUES (?,?,?,1,?,?)');
        $byId = locations_by_id();
        foreach ($st->fetchAll() as $it) {
            $ins->execute([$id, (int)$it['id'], (string)$it['name'], (int)$it['location_id'], (string)($byId[(int)$it['location_id']]['path'] ?? '')]);
        }
        return $id;
    });
}

/** @return array<int,array{id:int,item_id:?int,item_name:string,expected:bool,home_location_id:?int,home_location:string,found:bool,found_at:?string,moved:bool,image:?string}> */
function stocktake_lines(int $stocktakeId): array
{
    $st = db()->prepare('SELECT s.*, i.image FROM stocktake_lines s LEFT JOIN items i ON i.id = s.item_id WHERE s.stocktake_id = ? ORDER BY s.expected DESC, s.item_name, s.id');
    $st->execute([$stocktakeId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'item_id' => $r['item_id'] === null ? null : (int)$r['item_id'], 'item_name' => (string)$r['item_name'],
        'expected' => (int)$r['expected'] === 1, 'home_location_id' => $r['home_location_id'] === null ? null : (int)$r['home_location_id'],
        'home_location' => (string)$r['home_location'], 'found' => (int)$r['found'] === 1, 'found_at' => $r['found_at'] === null ? null : (string)$r['found_at'],
        'moved' => (int)$r['moved'] === 1, 'image' => $r['image'] ?? null], $st->fetchAll());
}

/**
 * Which item a scanned or typed code means: an Apotheke item link (QR label: …/item/42), "#42", or a barcode that
 * exactly one item has. Returns the item id, or throws a UserError explaining why not.
 */
function stocktake_resolve_code(string $code): int
{
    $code = trim($code);
    if ($code === '') throw new UserError('Scan or type a code.', 422);
    if (preg_match('#/item/(\d{1,12})(?:[/?\#]|$)#', $code, $m) || preg_match('/^#(\d{1,12})$/D', $code, $m)) {
        if (item_get((int)$m[1])) return (int)$m[1];
        throw new UserError('No item has that QR code.', 404);
    }
    $ids = items_by_barcode($code);
    if (count($ids) === 1) return $ids[0];
    if ($ids) throw new UserError(count($ids) . ' items have this barcode – tick the right one in the list.', 409);
    throw new UserError('No item has the barcode “' . barcode_clean($code) . '”.', 404);
}

/**
 * Tick ($found = true) or untick an item. An item that was not expected here is added as misplaced; unticking a
 * misplaced item removes it again. Returns ['state' => found|misplaced|missing|none, 'name' => …, 'home' => …].
 */
function stocktake_check(int $stocktakeId, int $itemId, bool $found = true): array
{
    $s = stocktake_get($stocktakeId);
    if (!$s) throw new UserError('Stocktake not found.', 404);
    if ($s['status'] !== 'open') throw new UserError('This stocktake is finished.', 409);
    $it = item_get($itemId);
    if (!$it) throw new UserError('Item not found.', 404);
    return db_tx(function (PDO $pdo) use ($stocktakeId, $it, $found): array {
        // first write: still open (a finish or cancel may have closed it since the check above)
        $st = $pdo->prepare("UPDATE stocktakes SET status = status WHERE id = ? AND status = 'open'");
        $st->execute([$stocktakeId]);
        if ($st->rowCount() !== 1) throw new UserError('This stocktake is finished.', 409);
        $st = $pdo->prepare('SELECT id, expected FROM stocktake_lines WHERE stocktake_id = ? AND item_id = ?');
        $st->execute([$stocktakeId, (int)$it['id']]);
        $line = $st->fetch();
        $home = location_path($it['location_id'] === null ? null : (int)$it['location_id']);
        if (!$line) {
            if (!$found) return ['state' => 'none', 'name' => $it['name'], 'home' => $home];
            db_insert($pdo, 'INSERT INTO stocktake_lines (stocktake_id, item_id, item_name, expected, home_location_id, home_location, found, found_at) VALUES (?,?,?,0,?,?,1,?)',
                [$stocktakeId, (int)$it['id'], (string)$it['name'], $it['location_id'], $home, now_utc()]);
            return ['state' => 'misplaced', 'name' => $it['name'], 'home' => $home];
        }
        if ((int)$line['expected'] === 0) {
            if (!$found) { $pdo->prepare('DELETE FROM stocktake_lines WHERE id = ?')->execute([(int)$line['id']]); return ['state' => 'none', 'name' => $it['name'], 'home' => $home]; }
            return ['state' => 'misplaced', 'name' => $it['name'], 'home' => $home];
        }
        $pdo->prepare('UPDATE stocktake_lines SET found = ?, found_at = ? WHERE id = ?')->execute([$found ? 1 : 0, $found ? now_utc() : null, (int)$line['id']]);
        return ['state' => $found ? 'found' : 'missing', 'name' => $it['name'], 'home' => $home];
    });
}

/** @return array{found:array,missing:array,misplaced:array,expected:int} */
function stocktake_report(int $stocktakeId): array
{
    $r = ['found' => [], 'missing' => [], 'misplaced' => []];
    foreach (stocktake_lines($stocktakeId) as $l) {
        $r[$l['expected'] ? ($l['found'] ? 'found' : 'missing') : 'misplaced'][] = $l;
    }
    return $r + ['expected' => count($r['found']) + count($r['missing'])];
}

/** Finish: store the counts and, with $applyMoves, move every misplaced item into the checked location. Returns the number moved. */
function stocktake_finish(int $stocktakeId, bool $applyMoves): int
{
    $s = stocktake_get($stocktakeId);
    if (!$s) throw new UserError('Stocktake not found.', 404);
    if ($s['status'] !== 'open') throw new UserError('This stocktake is already finished.', 409);
    if ($applyMoves && ($s['location_id'] === null || !tax_get('location', $s['location_id']))) throw new UserError('The location was deleted – misplaced items cannot be moved there.', 409);
    return db_tx(function (PDO $pdo) use ($s, $applyMoves): int {
        stocktake_close($pdo, $s['id'], 'done');
        $rep = stocktake_report($s['id']);
        $moved = 0;
        if ($applyMoves) {
            $to = location_path($s['location_id']);
            $upd = $pdo->prepare('UPDATE items SET location_id = ?, updated_at = ? WHERE id = ?');
            foreach ($rep['misplaced'] as $l) {
                $it = $l['item_id'] !== null ? item_get($l['item_id']) : null;
                if (!$it || (int)$it['location_id'] === $s['location_id']) continue;
                $upd->execute([$s['location_id'], now_utc(), $it['id']]);
                $pdo->prepare('UPDATE stocktake_lines SET moved = 1 WHERE id = ?')->execute([$l['id']]);
                activity_log((int)$it['id'], (string)$it['name'], 'moved', ['from' => location_path($it['location_id'] === null ? null : (int)$it['location_id']), 'to' => $to, 'reason' => 'stocktake']);
                $moved++;
            }
        }
        $sum = ['expected' => $rep['expected'], 'found' => count($rep['found']), 'missing' => count($rep['missing']), 'misplaced' => count($rep['misplaced']), 'moved' => $moved];
        $pdo->prepare('UPDATE stocktakes SET moves_applied = ?, summary = ? WHERE id = ?')->execute([$moved, json_encode($sum), $s['id']]);
        return $moved;
    });
}

function stocktake_cancel(int $stocktakeId): void
{
    $s = stocktake_get($stocktakeId);
    if (!$s) throw new UserError('Stocktake not found.', 404);
    if ($s['status'] !== 'open') throw new UserError('This stocktake is already finished.', 409);
    stocktake_close(db(), $stocktakeId, 'cancelled');
}

/**
 * Mark an open stocktake done or cancelled (the first write of a finish). Only one finish or cancel gets it: one that
 * read it as open before another closed it answers 409 and changes nothing.
 */
function stocktake_close(PDO $pdo, int $stocktakeId, string $status): void
{
    $st = $pdo->prepare("UPDATE stocktakes SET status = ?, finished_at = ? WHERE id = ? AND status = 'open'");
    $st->execute([$status, now_utc(), $stocktakeId]);
    if ($st->rowCount() !== 1) throw new UserError('This stocktake is already finished.', 409);
}

/** Progress of an open stocktake: [found of expected, misplaced]. */
function stocktake_progress(int $stocktakeId): array
{
    $r = stocktake_report($stocktakeId);
    return ['found' => count($r['found']), 'expected' => $r['expected'], 'misplaced' => count($r['misplaced'])];
}
