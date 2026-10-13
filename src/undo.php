<?php
declare(strict_types=1);

/*
 * Undo for edit, move, delete and bulk actions. Before such an action the affected items are snapshotted into an
 * undo token (random, valid for setting('undo_seconds')). Deleting keeps the photo/attachment files on disk until the
 * token expires, so undoing a delete puts the item back with its original id, photos, tags and attachments.
 */

const UNDO_ACTIONS = ['edit', 'move', 'category', 'tag_add', 'tag_remove', 'delete'];

function undo_enabled(): bool
{
    return (int)setting('undo_seconds') > 0;
}

/** Everything needed to put items back exactly as they are now. @param int[] $ids */
function undo_snapshot(array $ids): array
{
    $pdo = db();
    $out = [];
    foreach ($ids as $id) {
        $st = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) continue;
        $q = function (string $sql) use ($pdo, $id): array { $s = $pdo->prepare($sql); $s->execute([(int)$id]); return $s->fetchAll(PDO::FETCH_ASSOC); };
        $out[] = ['row' => $row,
                  'tags' => array_column($q('SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = ? ORDER BY t.name'), 'name'),
                  'photos' => $q('SELECT file, position, created_at FROM item_photos WHERE item_id = ? ORDER BY position, id'),
                  'attachments' => $q('SELECT file, name, mime, size, created_at FROM item_attachments WHERE item_id = ? ORDER BY id')];
    }
    return $out;
}

/** Files a snapshot of deleted items references (kept on disk while the token lives). */
function undo_snapshot_files(array $snap): array
{
    $f = [];
    foreach ($snap as $s) {
        foreach ($s['photos'] as $p) $f[] = ['img', (string)$p['file']];
        if (!empty($s['row']['image'])) $f[] = ['img', (string)$s['row']['image']];
        foreach ($s['attachments'] as $a) $f[] = ['att', (string)$a['file']];
    }
    return array_values(array_unique($f, SORT_REGULAR));
}

/** Store an undo token; null when undo is off or nothing was snapshotted. */
function undo_create(string $action, string $label, array $snap, array $extra = [], array $files = []): ?string
{
    if (!undo_enabled() || !$snap) return null;
    undo_prune();
    $token = bin2hex(random_bytes(16));
    $now = app_time();
    db()->prepare('INSERT INTO undo_tokens (token, action, label, payload, files, created_at, expires_at) VALUES (?,?,?,?,?,?,?)')->execute([
        $token, $action, mb_substr($label, 0, 200),
        json_encode(['items' => $snap] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        json_encode($files, JSON_THROW_ON_ERROR),
        gmdate('Y-m-d H:i:s', $now), gmdate('Y-m-d H:i:s', $now + (int)setting('undo_seconds'))]);
    return $token;
}

/** A live token (not expired), or null. */
function undo_get(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $token)) return null;
    $st = db()->prepare('SELECT * FROM undo_tokens WHERE token = ? AND expires_at > ?');
    $st->execute([$token, now_utc()]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/** Seconds left for a token. */
function undo_left(array $t): int
{
    return max(0, (int)strtotime($t['expires_at'] . ' UTC') - app_time());
}

/** Drop expired tokens and delete the files they were keeping, unless something references them again. */
function undo_prune(bool $all = false): int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT token, files FROM undo_tokens' . ($all ? '' : ' WHERE expires_at <= ?'));
    $st->execute($all ? [] : [now_utc()]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $pdo->prepare('DELETE FROM undo_tokens WHERE token = ?')->execute([$t['token']]);
        undo_release_files(json_decode((string)$t['files'], true) ?: []);
        $n++;
    }
    return $n;
}

function undo_release_files(array $files): void
{
    $pdo = db();
    foreach ($files as [$kind, $file]) {
        if ($kind === 'img') {
            $s = $pdo->prepare('SELECT 1 FROM item_photos WHERE file = ? UNION ALL SELECT 1 FROM items WHERE image = ?');
            $s->execute([$file, $file]);
            if (!$s->fetchColumn()) image_delete($file);
        } elseif ($kind === 'att') {
            $s = $pdo->prepare('SELECT 1 FROM item_attachments WHERE file = ?');
            $s->execute([$file]);
            if (!$s->fetchColumn()) attachment_file_delete($file);
        }
    }
}

/**
 * Apply an undo token in one transaction and consume it.
 * @return array{action:string,label:string,ids:int[],back:string}
 */
function undo_apply(string $token): array
{
    return db_tx(function (PDO $pdo) use ($token): array {
        $t = undo_get($token);
        if (!$t) throw new UserError('This can no longer be undone.', 410);
        $payload = json_decode((string)$t['payload'], true, 512, JSON_THROW_ON_ERROR);
        $ids = [];
        $locOk = fn($v) => $v !== null && tax_get('location', (int)$v) ? (int)$v : null;
        $catOk = fn($v) => $v !== null && tax_get('category', (int)$v) ? (int)$v : null;
        foreach ($payload['items'] as $s) {
            $row = $s['row'];
            $id = (int)$row['id'];
            $row['location_id'] = $locOk($row['location_id']);
            $row['category_id'] = $catOk($row['category_id']);
            if ($t['action'] === 'delete') {
                if (item_get($id)) continue;
                $cols = array_keys($row);
                $pdo->prepare('INSERT INTO items (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($row));
                foreach ($s['photos'] as $p) {
                    db_insert($pdo, 'INSERT INTO item_photos (item_id, file, position, created_at) VALUES (?,?,?,?)', [$id, $p['file'], (int)$p['position'], $p['created_at']]);
                }
                foreach ($s['attachments'] as $a) {
                    db_insert($pdo, 'INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (?,?,?,?,?,?)', [$id, $a['file'], $a['name'], $a['mime'], (int)$a['size'], $a['created_at']]);
                }
                tags_set($id, $s['tags']);
                activity_log($id, (string)$row['name'], 'restored', ['undo' => 'delete']);
            } else {
                $it = item_get($id);
                if (!$it) continue;   // deleted since; nothing to put back
                $in = ['name' => (string)$row['name'], 'location_id' => $row['location_id'], 'category_id' => $row['category_id'],
                       'quantity' => (int)$row['quantity'], 'min_qty' => $row['min_qty'] === null ? null : (int)$row['min_qty'], 'notes' => (string)$row['notes'],
                       'tags' => $s['tags'], 'value_cents' => $row['value_cents'] === null ? null : (int)$row['value_cents'],
                       'purchase_date' => $row['purchase_date'], 'expires_on' => $row['expires_on'], 'serial' => (string)$row['serial'], 'barcode' => (string)($row['barcode'] ?? '')];
                if ($t['action'] !== 'edit') {   // bulk move/category/tags only put back what they changed
                    $cur = item_input($it);
                    $in = ['location_id' => $in['location_id'], 'category_id' => $in['category_id'], 'tags' => $in['tags']] + $cur;
                }
                $in['reason'] = 'undo';
                item_save($it, $in, null, false);
            }
            $ids[] = $id;
        }
        $pdo->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)');
        $pdo->prepare('DELETE FROM undo_tokens WHERE token = ?')->execute([$token]);
        return ['action' => (string)$t['action'], 'label' => (string)$t['label'], 'ids' => $ids, 'back' => (string)($payload['back'] ?? '')];
    });
}

/** The "… · Undo" bar for a token passed in the URL (?undo=…); '' when there is none or it expired. */
function undo_bar_html(): string
{
    $tok = is_string($_GET['undo'] ?? null) ? $_GET['undo'] : '';
    if ($tok === '' || !($t = undo_get($tok))) return '';
    return '<div class="undo-bar" id="undo-bar" role="status" data-left="' . undo_left($t) . '">'
        . '<span class="undo-label">' . h($t['label']) . '</span>'
        . '<form method="post" action="/undo"><input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="token" value="' . h($tok) . '">'
        . '<button class="btn undo-btn" id="undo-btn">' . icon('undo') . ' Undo</button></form>'
        . '<button type="button" class="btn btn-ghost undo-x" id="undo-x" aria-label="Dismiss">' . icon('x') . '</button></div>';
}
