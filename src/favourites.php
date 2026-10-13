<?php
declare(strict_types=1);

/* Favourites (a star per item) and the "recently viewed" list. */

const RECENT_COOKIE = 'apo_dev';
const RECENT_MAX_AGE_DAYS = 90;

/** Star or unstar an item. Doesn't touch updated_at, so the list order stays put. */
function fav_set(int $id, bool $on): void
{
    $st = db()->prepare('UPDATE items SET favourite = ? WHERE id = ?');
    $st->execute([$on ? 1 : 0, $id]);
    if ($st->rowCount() === 0 && !item_get($id)) throw new UserError('Item not found.', 404);
}

function fav_count(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM items WHERE favourite = 1')->fetchColumn();
}

/** Whose list: '*' for the whole install, else a random per-device id kept in a cookie (set on first use). */
function recent_scope(bool $create = true): ?string
{
    if (setting('recent_scope') === 'install') return '*';
    $v = (string)($_COOKIE[RECENT_COOKIE] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/D', $v)) return 'd:' . $v;
    if (!$create || headers_sent()) return null;
    $v = bin2hex(random_bytes(16));
    setcookie(RECENT_COOKIE, $v, ['expires' => time() + 400 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[RECENT_COOKIE] = $v;
    return 'd:' . $v;
}

/** Remember that an item was opened; keeps at most recent_limit entries per scope. */
function recent_track(int $id): void
{
    $limit = (int)setting('recent_limit');
    if ($limit <= 0 || !($scope = recent_scope())) return;
    db_tx(function () use ($id, $scope, $limit) {
        $pdo = db();
        $st = $pdo->prepare('SELECT COALESCE(MAX(seq), 0) FROM recent_views WHERE scope = ?');
        $st->execute([$scope]);
        $seq = (int)$st->fetchColumn() + 1;
        $pdo->prepare('DELETE FROM recent_views WHERE scope = ? AND item_id = ?')->execute([$scope, $id]);
        $pdo->prepare('INSERT INTO recent_views (scope, item_id, seq, viewed_at) VALUES (?, ?, ?, ?)')->execute([$scope, $id, $seq, now_utc()]);
        $pdo->prepare('DELETE FROM recent_views WHERE scope = ? AND seq <= ?')->execute([$scope, $seq - $limit]);
        if ($seq % 50 === 0) {
            $pdo->prepare('DELETE FROM recent_views WHERE viewed_at < ?')->execute([gmdate('Y-m-d H:i:s', app_time() - RECENT_MAX_AGE_DAYS * 86400)]);
        }
    });
}

/** The recently viewed items for this device/install, newest first. */
function recent_items(): array
{
    $limit = (int)setting('recent_limit');
    if ($limit <= 0 || !($scope = recent_scope(false))) return [];
    $st = db()->prepare("SELECT i.id, i.name, i.image, i.favourite, COALESCE(l.name, '') AS location FROM recent_views r JOIN items i ON i.id = r.item_id
                         LEFT JOIN locations l ON l.id = i.location_id WHERE r.scope = ? ORDER BY r.seq DESC LIMIT " . $limit);
    $st->execute([$scope]);
    return $st->fetchAll();
}

/** Forget this device's (or the shared) list. */
function recent_clear(): void
{
    if ($scope = recent_scope(false)) db()->prepare('DELETE FROM recent_views WHERE scope = ?')->execute([$scope]);
}
