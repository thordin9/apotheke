<?php
declare(strict_types=1);

/*
 * Photo editing: rotate in 90° steps and crop, done in the browser on a <canvas> (public/assets/app.js, no libraries)
 * and applied on the server with GD to the stored photo. The result is saved as a new file (new random name, so caches
 * never show the old version), the thumbnail is regenerated, item_photos.version counts the edits (migration 0021),
 * and the previous file is deleted. Reordering: POST /item/{id}/photos/order with the photo ids in their new order.
 */

const PHOTO_ROTATIONS = [0, 90, 180, 270];

/**
 * @param int   $rotate clockwise degrees (0/90/180/270), applied first
 * @param array $crop   [x, y, w, h] as fractions (0..1) of the rotated image; [0,0,1,1] = no crop
 * @return array{id:int,file:string,version:int}
 */
function photo_edit(int $itemId, int $photoId, int $rotate, array $crop): array
{
    if (!in_array($rotate, PHOTO_ROTATIONS, true)) throw new UserError('Rotate in steps of 90°.', 422);
    [$x, $y, $w, $h] = array_map('floatval', array_pad(array_values($crop), 4, 0));
    foreach ([$x, $y, $w, $h] as $v) if (!is_finite($v) || $v < 0 || $v > 1) throw new UserError('The crop area is outside the photo.', 422);
    if ($x + $w > 1.0001 || $y + $h > 1.0001) throw new UserError('The crop area is outside the photo.', 422);
    if ($w < 0.02 || $h < 0.02) throw new UserError('The crop area is too small.', 422);
    $p = photo_find($itemId, $photoId);
    if ($rotate === 0 && $x <= 0.0001 && $y <= 0.0001 && $w >= 0.9999 && $h >= 0.9999) throw new UserError('Nothing to change: rotate or crop the photo first.', 422);
    $src = @imagecreatefromjpeg(IMG_DIR . '/' . $p['file']);
    if (!$src) throw new UserError('Could not read that photo.', 500);
    if ($rotate !== 0) $src = imagerotate($src, -$rotate, 0) ?: throw new UserError('Could not rotate the photo.', 500);
    $W = imagesx($src);
    $H = imagesy($src);
    $cx = (int)round($x * $W);
    $cy = (int)round($y * $H);
    $cw = max(1, min($W - $cx, (int)round($w * $W)));
    $ch = max(1, min($H - $cy, (int)round($h * $H)));
    if ($cw < 16 || $ch < 16) throw new UserError('The crop area is too small.', 422);
    if ($cx !== 0 || $cy !== 0 || $cw !== $W || $ch !== $H) {
        $src = imagecrop($src, ['x' => $cx, 'y' => $cy, 'width' => $cw, 'height' => $ch]) ?: throw new UserError('Could not crop the photo.', 500);
    }
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $full = image_fit($src, image_full_max());
    $thumb = image_fit($src, image_thumb_max());
    imageinterlace($full, true);
    imageinterlace($thumb, true);
    if (!imagejpeg($full, IMG_DIR . '/' . $name, (int)setting('jpeg_quality')) || !imagejpeg($thumb, THUMB_DIR . '/' . $name, (int)setting('jpeg_quality') - 6)) {
        image_delete($name);
        throw new UserError('Server could not save the image.', 500);
    }
    try {
        image_original_copy($p['file'], $name);   // the camera file stays with the photo; the edit is only the served JPEG
    } catch (Throwable) {
        image_delete($name);
        throw new UserError('Server could not save the image.', 500);
    }
    try {
        $version = db_tx(function (PDO $pdo) use ($itemId, $photoId, $name, $rotate, $cw, $ch): int {
            $pdo->prepare('UPDATE item_photos SET file = ?, version = version + 1, edited_at = ? WHERE id = ? AND item_id = ?')->execute([$name, now_utc(), $photoId, $itemId]);
            photos_renumber($itemId);
            $pdo->prepare('UPDATE items SET updated_at = ? WHERE id = ?')->execute([now_utc(), $itemId]);
            activity_log($itemId, (string)(item_get($itemId)['name'] ?? ''), 'photo', array_filter(['change' => 'edited', 'rotate' => $rotate ?: null, 'size' => $cw . '×' . $ch]));
            $st = $pdo->prepare('SELECT version FROM item_photos WHERE id = ?');
            $st->execute([$photoId]);
            return (int)$st->fetchColumn();
        });
    } catch (Throwable $e) {
        image_delete($name);
        throw $e;
    }
    // the old file goes unless an undo snapshot or another row still points at it
    $st = db()->prepare('SELECT 1 FROM item_photos WHERE file = ? UNION ALL SELECT 1 FROM items WHERE image = ?');
    $st->execute([$p['file'], $p['file']]);
    if (!$st->fetchColumn() && !photo_file_in_undo($p['file'])) image_delete($p['file']);
    return ['id' => $photoId, 'file' => $name, 'version' => $version];
}

/** Is a photo file still needed by a pending undo? (deleted items keep their photos until the undo window ends) */
function photo_file_in_undo(string $file): bool
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM undo_tokens WHERE files LIKE ? OR payload LIKE ?');
        $st->execute(['%' . $file . '%', '%' . $file . '%']);
        return (int)$st->fetchColumn() > 0;
    } catch (PDOException) {
        return false;
    }
}

/** New order of an item's photos (all of its photo ids, each once); the first becomes the cover. */
function photo_reorder(int $itemId, array $ids): void
{
    db_tx(function (PDO $pdo) use ($itemId, $ids): void {
        $have = array_column(photos_for($itemId), 'id');
        $ids = array_values(array_map('intval', $ids));
        $a = $ids; sort($a);
        $b = $have; sort($b);
        if ($a !== $b) throw new UserError('That is not the list of this item’s photos.', 422);
        if ($ids === $have) return;
        photos_renumber($itemId, $ids);
        $pdo->prepare('UPDATE items SET updated_at = ? WHERE id = ?')->execute([now_utc(), $itemId]);
        activity_log($itemId, (string)(item_get($itemId)['name'] ?? ''), 'photo', ['change' => $ids[0] !== $have[0] ? 'cover' : 'reordered', 'count' => count($ids)]);
    });
}
