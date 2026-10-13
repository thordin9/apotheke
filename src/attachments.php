<?php
declare(strict_types=1);

/*
 * Apotheke – file attachments (receipts, manuals, warranty cards): PDF and images, stored in DATA_DIR/files under
 * random names "<32 hex>.<ext>", served only through /attachment/{id} as a download.
 */

const ATTACH_TYPES = [
    'pdf'  => ['application/pdf', 'PDF'],
    'jpg'  => ['image/jpeg', 'JPEG'],
    'png'  => ['image/png', 'PNG'],
    'webp' => ['image/webp', 'WebP'],
];
const ATTACH_TYPE_SETS = ['pdf_images' => 'PDF and images', 'pdf' => 'PDF only', 'images' => 'Images only'];

function files_dir(): string
{
    $d = DATA_DIR . '/files';
    if (!is_dir($d)) @mkdir($d, 0770, true);
    return $d;
}

function valid_attachment_name(?string $n): bool
{
    return is_string($n) && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/D', $n) === 1;
}

/** Extensions allowed by the Control Panel. @return string[] */
function attach_allowed(): array
{
    return match ((string)setting('attach_types')) {
        'pdf' => ['pdf'],
        'images' => ['jpg', 'png', 'webp'],
        default => array_keys(ATTACH_TYPES),
    };
}

function attach_max_bytes(): int { return (int)setting('attach_max_mb') * 1024 * 1024; }

/** Sniff the real type from the content (never trust the browser's MIME type or the file name). */
function attach_sniff(string $path): ?string
{
    $head = (string)@file_get_contents($path, false, null, 0, 16);
    if (str_starts_with($head, '%PDF-')) return 'pdf';
    if (str_starts_with($head, "\xFF\xD8\xFF")) return 'jpg';
    if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) return 'png';
    if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') return 'webp';
    return null;
}

/** Clean display name: no paths, no control characters, max 120 chars, keeps the extension of the real type. */
function attach_display_name(string $name, string $ext): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = trim(preg_replace('/[\x00-\x1f\x7f"<>|:*?\/\\\\]+/u', '', $name) ?? '');
    $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?: 'file';
    return mb_substr($base, 0, 110) . '.' . $ext;
}

/** @return array<int,array{id:int,item_id:int,file:string,name:string,mime:string,size:int,created_at:string}> */
function attachments_for(int $itemId): array
{
    $st = db()->prepare('SELECT id, item_id, file, name, mime, size, created_at FROM item_attachments WHERE item_id = ? ORDER BY id');
    $st->execute([$itemId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'item_id' => (int)$r['item_id'], 'size' => (int)$r['size']] + $r, $st->fetchAll());
}

function attachment_get(int $id): ?array
{
    $st = db()->prepare('SELECT id, item_id, file, name, mime, size, created_at FROM item_attachments WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'item_id' => (int)$r['item_id'], 'size' => (int)$r['size']] + $r : null;
}

/**
 * Validate and store uploaded files for an item (all or nothing). @param array<int,array> $files from pick_uploads()
 * @return int number stored
 */
function attachments_add(int $itemId, array $files): int
{
    $it = item_get($itemId);
    if (!$it) throw new UserError('Item not found.', 404);
    if (!$files) throw new UserError('Choose a file to attach.', 422);
    $max = (int)setting('attach_max_per_item');
    if (count(attachments_for($itemId)) + count($files) > $max) throw new UserError("An item can have at most $max attachments.", 422);
    $allowed = attach_allowed();
    $ready = [];
    foreach ($files as $f) {
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) throw new UserError(upload_error_text($err), 422);
        $size = (int)filesize((string)$f['tmp_name']);
        if ($size > attach_max_bytes()) throw new UserError('“' . attach_display_name((string)$f['name'], 'x') . '” is larger than ' . setting('attach_max_mb') . ' MB.', 422);
        if ($size === 0) throw new UserError('That file is empty.', 422);
        $ext = attach_sniff((string)$f['tmp_name']);
        if ($ext === null || !in_array($ext, $allowed, true)) {
            $names = implode(', ', array_map(fn($e) => ATTACH_TYPES[$e][1], $allowed));
            throw new UserError('Only ' . $names . ' files can be attached.', 422);
        }
        $ready[] = [$f, $ext, $size];
    }
    $dir = files_dir();
    $stored = [];
    try {
        foreach ($ready as [$f, $ext, $size]) {
            $file = bin2hex(random_bytes(16)) . '.' . $ext;
            $ok = is_uploaded_file((string)$f['tmp_name']) ? move_uploaded_file((string)$f['tmp_name'], "$dir/$file") : copy((string)$f['tmp_name'], "$dir/$file");
            if (!$ok) throw new RuntimeException('could not store attachment');
            $stored[] = [$file, attach_display_name((string)$f['name'], $ext), ATTACH_TYPES[$ext][0], $size];
        }
        db_tx(function (PDO $pdo) use ($itemId, $it, $stored): void {
            foreach ($stored as [$file, $name, $mime, $size]) {
                db_insert($pdo, 'INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (?,?,?,?,?,?)', [$itemId, $file, $name, $mime, $size, now_utc()]);
                activity_log($itemId, (string)$it['name'], 'attachment', ['change' => 'added', 'file' => $name]);
            }
            $pdo->prepare('UPDATE items SET updated_at = ? WHERE id = ?')->execute([now_utc(), $itemId]);
        });
    } catch (Throwable $e) {
        foreach ($stored as [$file]) @unlink("$dir/$file");
        throw $e;
    }
    return count($stored);
}

function attachment_delete(int $itemId, int $id): void
{
    $a = attachment_get($id);
    if (!$a || $a['item_id'] !== $itemId) throw new UserError('Attachment not found.', 404);
    $it = item_get($itemId);
    db_tx(function (PDO $pdo) use ($a, $it): void {
        $pdo->prepare('DELETE FROM item_attachments WHERE id = ?')->execute([$a['id']]);
        activity_log((int)$a['item_id'], (string)($it['name'] ?? ''), 'attachment', ['change' => 'removed', 'file' => $a['name']]);
    });
    attachment_file_delete($a['file']);
}

function attachment_file_delete(?string $file): void
{
    if (valid_attachment_name($file)) @unlink(files_dir() . '/' . $file);
}

/** Send an attachment as a download; the browser never renders it inside the app's origin. */
function attachment_serve(array $a): never
{
    $path = files_dir() . '/' . $a['file'];
    if (!valid_attachment_name($a['file']) || !is_file($path)) { http_response_code(404); exit; }
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $a['name']);
    header('Content-Type: ' . (in_array($a['mime'], array_column(ATTACH_TYPES, 0), true) ? $a['mime'] : 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($a['name']));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function fmt_bytes(int $n): string
{
    if ($n < 1024) return $n . ' B';
    if ($n < 1024 * 1024) return round($n / 1024) . ' KB';
    return number_format($n / 1048576, 1) . ' MB';
}
