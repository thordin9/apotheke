<?php
declare(strict_types=1);

/* ---------- taxonomies: locations & categories (same shape, managed lists) ---------- */

const KINDS = ['location', 'category'];

/** Fixed per-kind config. Table/column names come only from here, so interpolating them into SQL is safe. */
function tax(string $kind): array
{
    return match ($kind) {
        'location' => ['kind' => 'location', 'table' => 'locations', 'col' => 'location_id', 'param' => 'loc', 'alias' => 'l',
                       'one' => 'location', 'many' => 'locations', 'One' => 'Location', 'Many' => 'Locations',
                       'none' => 'No location', 'path' => '/locations', 'icon' => 'pin', 'example' => 'box 1024'],
        'category' => ['kind' => 'category', 'table' => 'categories', 'col' => 'category_id', 'param' => 'cat', 'alias' => 'c',
                       'one' => 'category', 'many' => 'categories', 'One' => 'Category', 'Many' => 'Categories',
                       'none' => 'No category', 'path' => '/categories', 'icon' => 'tag', 'example' => 'Tools'],
    };
}

/** All entries, naturally sorted, with item counts. @return array<int,array{id:int,name:string,is_default:int,n:int}> */
function tax_list(string $kind): array
{
    $t = tax($kind);
    return db()->query("SELECT x.id, x.name, x.is_default, COUNT(i.id) AS n
                        FROM {$t['table']} x LEFT JOIN items i ON i.{$t['col']} = x.id
                        GROUP BY x.id ORDER BY x.sort_key, x.id")->fetchAll();
}

function tax_get(string $kind, int $id): ?array
{
    $t = tax($kind);
    $st = db()->prepare("SELECT x.id, x.name, x.is_default, (SELECT COUNT(*) FROM items WHERE {$t['col']} = x.id) AS n FROM {$t['table']} x WHERE x.id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function tax_find(string $kind, string $name): ?array
{
    $st = db()->prepare('SELECT id, name, is_default FROM ' . tax($kind)['table'] . ' WHERE name_key = ?');
    $st->execute([fold(clean_line($name, 120))]);
    return $st->fetch() ?: null;
}

function tax_default_id(string $kind): ?int
{
    $id = db()->query('SELECT id FROM ' . tax($kind)['table'] . ' WHERE is_default = 1 LIMIT 1')->fetchColumn();
    return $id === false ? null : (int)$id;
}

function tax_name_valid(string $kind, string $name): string
{
    $name = clean_line($name, 120);
    if ($name === '') throw new UserError(tax($kind)['One'] . ' name cannot be empty.');
    return $name;
}

/** Create an entry, or return the existing one with the same (case/accent-insensitive) name. @return array{id:int,name:string,created:bool} */
function tax_create(string $kind, string $name): array
{
    $name = tax_name_valid($kind, $name);
    if ($ex = tax_find($kind, $name)) return ['id' => (int)$ex['id'], 'name' => $ex['name'], 'created' => false];
    $id = db_insert(db(), 'INSERT INTO ' . tax($kind)['table'] . ' (name, name_key, sort_key, created_at) VALUES (?,?,?,?)', [$name, fold($name), natkey($name), now_utc()]);
    return ['id' => $id, 'name' => $name, 'created' => true];
}

function tax_rename(string $kind, int $id, string $name): void
{
    $name = tax_name_valid($kind, $name);
    $ex = tax_find($kind, $name);
    if ($ex && (int)$ex['id'] !== $id) throw new UserError('A ' . tax($kind)['one'] . ' called “' . $ex['name'] . '” already exists.', 409);
    db()->prepare('UPDATE ' . tax($kind)['table'] . ' SET name = ?, name_key = ?, sort_key = ? WHERE id = ?')->execute([$name, fold($name), natkey($name), $id]);
}

/** Make $id the only default, or clear the default when $on is false. */
function tax_set_default(string $kind, int $id, bool $on): void
{
    $tb = tax($kind)['table'];
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec("UPDATE $tb SET is_default = 0 WHERE is_default = 1");
    if ($on) $pdo->prepare("UPDATE $tb SET is_default = 1 WHERE id = ?")->execute([$id]);
    $pdo->commit();
}

/** Delete an entry; its items are moved to $moveTo or left unassigned (null). Returns number of items affected. */
function tax_delete(string $kind, int $id, ?int $moveTo): int
{
    $t = tax($kind);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("UPDATE items SET {$t['col']} = ?, updated_at = ? WHERE {$t['col']} = ?");
        $st->execute([$moveTo, now_utc(), $id]);
        $n = $st->rowCount();
        $pdo->prepare("DELETE FROM {$t['table']} WHERE id = ?")->execute([$id]);
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// Short aliases used by views/router.
function locations(): array { return tax_list('location'); }
function categories(): array { return tax_list('category'); }

/* ---------- item data access ---------- */

const ITEM_COLS = 'i.id, i.name, i.location_id, l.name AS location, i.category_id, c.name AS category, i.quantity, i.notes, i.image, i.created_at, i.updated_at';
const ITEM_FROM = 'FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id';

function item_get(int $id): ?array
{
    $st = db()->prepare('SELECT ' . ITEM_COLS . ' ' . ITEM_FROM . ' WHERE i.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['location'] = (string)($row['location'] ?? '');
    $row['category'] = (string)($row['category'] ?? '');
    return $row;
}

function search_blob(string $name, string $notes): string
{
    return fold($name . "\n" . $notes);
}

/** Normalise a filter value: '' = all, 'none' = unassigned, digits = id. */
function filter_value(mixed $v): string
{
    $v = is_string($v) ? $v : '';
    return ($v === 'none' || ctype_digit($v)) ? $v : '';
}

/**
 * @param array{loc:string,cat:string} $f
 * @return array{items: array<int,array>, total: int, more: bool}
 */
function items_search(string $q, array $f, string $sort, int $page): array
{
    $where = [];
    $args  = [];
    foreach (preg_split('/\s+/u', fold(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
        // every word must match the item (name/notes), its location or its category
        $where[] = "(i.search LIKE ? ESCAPE '\\' OR COALESCE(l.name_key, '') LIKE ? ESCAPE '\\' OR COALESCE(c.name_key, '') LIKE ? ESCAPE '\\')";
        $like = '%' . like_escape($tok) . '%';
        array_push($args, $like, $like, $like);
    }
    foreach (['loc' => 'i.location_id', 'cat' => 'i.category_id'] as $k => $col) {
        $v = $f[$k] ?? '';
        if ($v === 'none') {
            $where[] = "$col IS NULL";
        } elseif ($v !== '' && ctype_digit($v)) {
            $where[] = "$col = ?";
            $args[]  = (int)$v;
        }
    }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $order = match ($sort) {
        'name'     => 'i.sort_key ASC, i.id DESC',
        'location' => 'CASE WHEN l.id IS NULL THEN 1 ELSE 0 END, l.sort_key ASC, i.sort_key ASC, i.id DESC',
        'category' => 'CASE WHEN c.id IS NULL THEN 1 ELSE 0 END, c.sort_key ASC, i.sort_key ASC, i.id DESC',
        'qty'      => 'i.quantity DESC, i.sort_key ASC, i.id DESC',
        default    => 'i.updated_at DESC, i.id DESC',
    };
    $st = db()->prepare('SELECT COUNT(*) ' . ITEM_FROM . " $w");
    $st->execute($args);
    $total = (int)$st->fetchColumn();

    $page   = max(1, $page);
    $offset = ($page - 1) * PAGE_SIZE;
    $st = db()->prepare("SELECT i.id, i.name, i.location_id, COALESCE(l.name, '') AS location, i.category_id, COALESCE(c.name, '') AS category, i.quantity, i.image "
        . ITEM_FROM . " $w ORDER BY $order LIMIT " . PAGE_SIZE . " OFFSET $offset");
    $st->execute($args);
    $items = $st->fetchAll();
    return ['items' => $items, 'total' => $total, 'more' => $offset + count($items) < $total];
}

function stats(): array
{
    $r = db()->query('SELECT COUNT(*) AS items, COALESCE(SUM(quantity),0) AS qty,
                             COALESCE(SUM(CASE WHEN location_id IS NULL THEN 1 ELSE 0 END),0) AS unassigned,
                             COALESCE(SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END),0) AS uncategorized FROM items')->fetch();
    $r['items'] = (int)$r['items'];
    $r['qty'] = (int)$r['qty'];
    $r['unassigned'] = (int)$r['unassigned'];
    $r['uncategorized'] = (int)$r['uncategorized'];
    $r['none_location'] = $r['unassigned'];
    $r['none_category'] = $r['uncategorized'];
    return $r;
}

/** @param array{name:string,location_id:?int,category_id:?int,quantity:int,notes:string} $in */
function item_save(?array $existing, array $in, ?string $newImage, bool $removeImage): int
{
    $now  = now_utc();
    $blob = search_blob($in['name'], $in['notes']);
    if ($existing === null) {
        return db_insert(db(), 'INSERT INTO items (name, location_id, category_id, quantity, notes, image, search, sort_key, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$in['name'], $in['location_id'], $in['category_id'], $in['quantity'], $in['notes'], $newImage, $blob, natkey($in['name']), $now, $now]);
    }
    $image = $existing['image'];
    if ($newImage !== null || $removeImage) {
        image_delete($image);
        $image = $newImage;
    }
    $st = db()->prepare('UPDATE items SET name=?, location_id=?, category_id=?, quantity=?, notes=?, image=?, search=?, sort_key=?, updated_at=? WHERE id=?');
    $st->execute([$in['name'], $in['location_id'], $in['category_id'], $in['quantity'], $in['notes'], $image, $blob, natkey($in['name']), $now, $existing['id']]);
    return (int)$existing['id'];
}

function item_delete(array $item): void
{
    db()->prepare('DELETE FROM items WHERE id = ?')->execute([$item['id']]);
    image_delete($item['image']);
}

function item_adjust_qty(int $id, int $delta): ?int
{
    $st = db()->prepare('UPDATE items SET quantity = CASE WHEN quantity + ? < 0 THEN 0 ELSE quantity + ? END, updated_at = ? WHERE id = ?');
    $st->execute([$delta, $delta, now_utc(), $id]);
    if ($st->rowCount() === 0) return null;
    $st = db()->prepare('SELECT quantity FROM items WHERE id = ?');
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/* ---------- images ---------- */

function valid_image_name(?string $n): bool
{
    return is_string($n) && preg_match('/^[a-f0-9]{32}\.jpg$/D', $n) === 1;
}

function image_delete(?string $name): void
{
    if (!valid_image_name($name)) return;
    @unlink(IMG_DIR . '/' . $name);
    @unlink(THUMB_DIR . '/' . $name);
}

function upload_error_text(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is too large (max ' . (MAX_UPLOAD >> 20) . ' MB).',
        UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server could not store the upload.',
        default => 'Upload failed (code ' . $code . ').',
    };
}

/** Pick the first provided file among the given field names. */
function pick_upload(array $fields): ?array
{
    foreach ($fields as $f) {
        $file = $_FILES[$f] ?? null;
        if (is_array($file) && !is_array($file['error']) && $file['error'] !== UPLOAD_ERR_NO_FILE) return $file;
    }
    return null;
}

/**
 * Validate an uploaded image with GD, normalise orientation, re-encode as JPEG
 * (which also strips EXIF/GPS metadata) and create a thumbnail.
 * Returns the random file name stored in images/ and thumbs/.
 */
function image_store(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) throw new UserError(upload_error_text((int)$file['error']));
    if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') throw new UserError('Invalid upload.');
    if ($file['size'] > MAX_UPLOAD) throw new UserError(upload_error_text(UPLOAD_ERR_INI_SIZE));

    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!$info || !in_array($info[2], $types, true)) {
        throw new UserError('Unsupported image. Please use JPEG, PNG or WebP (on iPhone, HEIC photos are converted automatically).');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) throw new UserError('Image dimensions are too large.');

    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file['tmp_name']) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($file['tmp_name']),
    };
    if (!$src) throw new UserError('Could not read that image.');

    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $src  = apply_orientation($src, (int)($exif['Orientation'] ?? 1));
    }

    $name = bin2hex(random_bytes(16)) . '.jpg';
    $full = image_fit($src, FULL_MAX);
    $thumb = image_fit($src, THUMB_MAX);
    imageinterlace($full, true);
    imageinterlace($thumb, true);
    $ok = imagejpeg($full, IMG_DIR . '/' . $name, 82) && imagejpeg($thumb, THUMB_DIR . '/' . $name, 76);
    if (!$ok) {
        image_delete($name);
        throw new UserError('Server could not save the image.');
    }
    return $name;
}

function apply_orientation(GdImage $im, int $o): GdImage
{
    $out = match ($o) {
        2 => imageflip($im, IMG_FLIP_HORIZONTAL) ? $im : $im,
        3 => imagerotate($im, 180, 0),
        4 => imageflip($im, IMG_FLIP_VERTICAL) ? $im : $im,
        5 => imageflip($im, IMG_FLIP_HORIZONTAL) ? imagerotate($im, 90, 0) : $im,
        6 => imagerotate($im, -90, 0),
        7 => imageflip($im, IMG_FLIP_HORIZONTAL) ? imagerotate($im, -90, 0) : $im,
        8 => imagerotate($im, 90, 0),
        default => $im,
    };
    if ($out !== false && $out !== $im) {
        return $out;
    }
    return $im;
}

/** Scale to fit within $max x $max on a white background (flattens transparency). */
function image_fit(GdImage $src, int $max): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $s = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $s));
    $nh = max(1, (int)round($h * $s));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

function serve_image(string $kind, string $name): never
{
    if (!valid_image_name($name)) {
        http_response_code(404);
        exit;
    }
    $path = ($kind === 'thumb' ? THUMB_DIR : IMG_DIR) . '/' . $name;
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    $etag = '"' . $name . '-' . filemtime($path) . '"';
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ---------- statistics (cheap aggregates) ---------- */

/** UTC 'Y-m-d H:i:s' for a local-time expression like 'monday this week' / 'first day of this month'. */
function local_boundary_utc(string $expr): string
{
    $d = new DateTimeImmutable($expr, new DateTimeZone(date_default_timezone_get()));
    return $d->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function stats_totals(): array
{
    $st = db()->prepare('SELECT COUNT(*) AS items, COALESCE(SUM(quantity), 0) AS qty,
                                COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS week,
                                COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS month
                         FROM items');
    $st->execute([local_boundary_utc('monday this week'), local_boundary_utc('first day of this month')]);
    $r = $st->fetch();
    return [
        'items'     => (int)$r['items'],
        'qty'       => (int)$r['qty'],
        'week'      => (int)$r['week'],
        'month'     => (int)$r['month'],
        'locations' => (int)db()->query('SELECT COUNT(*) FROM locations')->fetchColumn(),
        'categories' => (int)db()->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
    ];
}

/**
 * Items added per local day (last $days days) or per month (last $months months), zero-filled.
 * @return array<int,array{key:string,label:string,n:int}>
 */
function stats_added_series(string $unit): array
{
    $tz  = new DateTimeZone(date_default_timezone_get());
    [$expr, $mod] = db_local_bucket(db_driver(), 'created_at', $unit === 'day' ? 'day' : 'month', $tz->getName());
    $sql = "SELECT $expr AS k, COUNT(*) AS n FROM items WHERE created_at >= ? GROUP BY 1";
    if ($unit === 'day') {
        $start = (new DateTimeImmutable('today', $tz))->modify('-29 days');
        $step = '+1 day'; $fmtKey = 'Y-m-d'; $count = 30;
    } else {
        $start = (new DateTimeImmutable('first day of this month', $tz))->setTime(0, 0)->modify('-11 months');
        $step = '+1 month'; $fmtKey = 'Y-m'; $count = 12;
    }
    $st = db()->prepare($sql);
    $st->execute([$mod, $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]);
    $byKey = array_column($st->fetchAll(), 'n', 'k');
    $out = [];
    for ($i = 0, $d = $start; $i < $count; $i++, $d = $d->modify($step)) {
        $k = $d->format($fmtKey);
        $out[] = ['key' => $k, 'label' => $unit === 'day' ? $d->format('j M') : $d->format('M y'), 'n' => (int)($byKey[$k] ?? 0)];
    }
    return $out;
}

/** Top $n entries of a kind by item count (+ "Other" and "No …" buckets). */
function stats_by(string $kind, int $n = 5): array
{
    $t = tax($kind);
    $rows = db()->query("SELECT x.id, x.name, COUNT(i.id) AS n
                         FROM items i JOIN {$t['table']} x ON x.id = i.{$t['col']}
                         GROUP BY x.id ORDER BY n DESC, x.sort_key, x.id")->fetchAll();
    $top = array_slice($rows, 0, $n);
    $rest = array_slice($rows, $n);
    if ($rest) $top[] = ['id' => null, 'name' => 'Other (' . count($rest) . ')', 'n' => array_sum(array_column($rest, 'n')), 'other' => true];
    $un = (int)db()->query("SELECT COUNT(*) FROM items WHERE {$t['col']} IS NULL")->fetchColumn();
    if ($un > 0) $top[] = ['id' => 'none', 'name' => $t['none'], 'n' => $un];
    return $top;
}
