<?php
declare(strict_types=1);

/* Merging duplicate items: everything of the source moves into the target, then the source is gone. */

const MERGE_QTY_MAX = 1000000;
const MERGE_NOTES_MAX = 5000;

/**
 * What a merge would produce, without changing anything (the confirm screen shows this).
 * @return array{target:array,source:array,result:array,fills:array<string,string>,photos:int,attachments:int,history:int,lending:string}
 */
function merge_preview(int $sourceId, int $targetId): array
{
    if ($sourceId === $targetId) throw new UserError('Pick a different item to merge into.', 422);
    $src = item_get($sourceId);
    $dst = item_get($targetId);
    if (!$src || !$dst) throw new UserError('Item not found.', 404);
    $in = item_input($dst);
    $fills = [];
    $in['quantity'] = min(MERGE_QTY_MAX, (int)$dst['quantity'] + (int)$src['quantity']);
    foreach (['location_id' => 'Location', 'category_id' => 'Category', 'min_qty' => 'Running low at', 'value_cents' => 'Purchase price',
              'purchase_date' => 'Purchase date', 'expires_on' => 'Expiry / warranty', 'serial' => 'Serial number', 'barcode' => 'Barcode'] as $k => $label) {
        $empty = fn($v) => $v === null || $v === '';
        $sv = item_input($src)[$k];
        if ($empty($in[$k]) && !$empty($sv)) {
            $in[$k] = $sv;
            $fills[$k] = $label;
        }
    }
    $in['tags'] = array_values(array_unique(array_merge($in['tags'], item_input($src)['tags'])));
    $sn = trim((string)$src['notes']);
    if ($sn !== '' && !str_contains((string)$dst['notes'], $sn)) {
        $in['notes'] = mb_substr(trim(trim((string)$dst['notes']) . "\n\n" . $sn), 0, MERGE_NOTES_MAX);
        $fills['notes'] = 'Notes (appended)';
    }
    $lending = '';
    if ($src['lent_to'] !== null) $lending = $dst['lent_to'] === null ? 'copied' : 'kept';
    $cnt = function (string $sql) use ($sourceId): int { $st = db()->prepare($sql); $st->execute([$sourceId]); return (int)$st->fetchColumn(); };
    return ['target' => $dst, 'source' => $src, 'result' => $in, 'fills' => $fills,
            'photos' => $cnt('SELECT COUNT(*) FROM item_photos WHERE item_id = ?'),
            'attachments' => $cnt('SELECT COUNT(*) FROM item_attachments WHERE item_id = ?'),
            'history' => $cnt('SELECT COUNT(*) FROM activity WHERE item_id = ?'),
            'lending' => $lending];
}

/**
 * Merge $sourceId into $targetId in one transaction: quantities are added, photos, attachments, tags and history move
 * over, empty fields of the target are filled from the source, notes are appended, a loan is taken over when the
 * target isn't lent, the star is kept if either had it. The source item is then deleted (its files now belong to the
 * target, so none are removed). Returns the preview that was applied.
 */
function item_merge(int $sourceId, int $targetId): array
{
    return db_tx(function (PDO $pdo) use ($sourceId, $targetId): array {
        $p = merge_preview($sourceId, $targetId);
        $src = $p['source']; $dst = $p['target']; $in = $p['result'];
        // photos: the source's go after the target's, in their order
        $st = $pdo->prepare('SELECT COALESCE(MAX(position), -1) FROM item_photos WHERE item_id = ?');
        $st->execute([$targetId]);
        $base = (int)$st->fetchColumn() + 1;
        $pdo->prepare('UPDATE item_photos SET item_id = ?, position = position + ? WHERE item_id = ?')->execute([$targetId, $base, $sourceId]);
        $pdo->prepare('UPDATE item_attachments SET item_id = ? WHERE item_id = ?')->execute([$targetId, $sourceId]);
        $pdo->prepare('UPDATE activity SET item_id = ? WHERE item_id = ?')->execute([$targetId, $sourceId]);
        $pdo->prepare('DELETE FROM recent_views WHERE item_id = ?')->execute([$sourceId]);
        $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$sourceId]);
        $cover = $pdo->prepare('SELECT file FROM item_photos WHERE item_id = ? ORDER BY position, id LIMIT 1');
        $cover->execute([$targetId]);
        $lend = $p['lending'] === 'copied' ? [$src['lent_to'], $src['lent_at'], $src['lent_due']] : [$dst['lent_to'], $dst['lent_at'], $dst['lent_due']];
        $pdo->prepare('UPDATE items SET location_id=?, category_id=?, quantity=?, min_qty=?, notes=?, value_cents=?, purchase_date=?, expires_on=?, serial=?, barcode=?,
                       image=?, lent_to=?, lent_at=?, lent_due=?, favourite=?, search=?, updated_at=? WHERE id=?')
            ->execute([$in['location_id'], $in['category_id'], $in['quantity'], $in['min_qty'], $in['notes'], $in['value_cents'], $in['purchase_date'], $in['expires_on'],
                       $in['serial'], $in['barcode'] === '' ? null : $in['barcode'], $cover->fetchColumn() ?: null, ...$lend,
                       (int)(!empty($dst['favourite']) || !empty($src['favourite'])),
                       search_blob($in['name'], $in['notes'], $in['serial'], $in['tags'], $in['barcode']), now_utc(), $targetId]);
        tags_set($targetId, $in['tags']);
        $pdo->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)');
        activity_log($targetId, (string)$dst['name'], 'merged', array_filter([
            'from' => (string)$src['name'], 'from_id' => $sourceId,
            'quantity' => [(int)$dst['quantity'], (int)$in['quantity']],
            'photos' => $p['photos'] ?: null, 'attachments' => $p['attachments'] ?: null,
            'filled' => array_values($p['fills']) ?: null, 'lending' => $p['lending'] ?: null,
        ], fn($v) => $v !== null));
        return $p;
    });
}

/** Items that look like duplicates of $it (same name ignoring case/accents first, then same barcode, then similar names). */
function merge_candidates(array $it, string $q = '', int $limit = 30): array
{
    $id = (int)$it['id'];
    if ($q !== '') {
        $rows = items_search($q, [], 'name', 1, $limit + 1)['items'];
    } else {
        $words = array_slice(preg_split('/\s+/u', fold((string)$it['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2);
        $rows = $words ? items_search(implode(' ', $words), [], 'name', 1, $limit + 1)['items'] : [];
        if (($it['barcode'] ?? '') !== '') {
            $st = db()->prepare("SELECT i.id, i.name, i.location_id, COALESCE(l.name, '') AS location, i.category_id, COALESCE(c.name, '') AS category, i.quantity, i.min_qty, i.image, i.lent_to, i.lent_due, i.expires_on, i.favourite "
                . ITEM_FROM . ' WHERE i.barcode = ? AND i.id <> ? LIMIT ' . $limit);
            $st->execute([$it['barcode'], $id]);
            $rows = array_merge($st->fetchAll(), $rows);
        }
    }
    $seen = [$id => true];
    $out = [];
    $key = fold((string)$it['name']);
    foreach ($rows as $r) {
        if (isset($seen[(int)$r['id']])) continue;
        $seen[(int)$r['id']] = true;
        $out[] = $r;
    }
    usort($out, fn($a, $b) => (fold((string)$b['name']) === $key) <=> (fold((string)$a['name']) === $key));
    return array_slice($out, 0, $limit);
}
