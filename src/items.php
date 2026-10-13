<?php
declare(strict_types=1);

require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/attachments.php';
require_once __DIR__ . '/favourites.php';
require_once __DIR__ . '/merge.php';
require_once __DIR__ . '/undo.php';
require_once __DIR__ . '/stocktake.php';

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

/**
 * All entries, naturally sorted, with item counts. Locations come in tree order (each parent followed by its
 * children) and carry parent_id, depth, path ("Garage › Shelf 2") and total (items here and in all sub-locations).
 * Categories are flat: depth 0, path = name, total = n.
 * @return array<int,array{id:int,name:string,is_default:int,n:int,parent_id:?int,depth:int,path:string,total:int}>
 */
function tax_list(string $kind): array
{
    $t = tax($kind);
    $isLoc = $kind === 'location';
    $rows = db()->query('SELECT x.id, x.name, x.is_default, ' . ($isLoc ? 'x.parent_id' : 'NULL') . " AS parent_id, COUNT(i.id) AS n
                         FROM {$t['table']} x LEFT JOIN items i ON i.{$t['col']} = x.id
                         GROUP BY x.id, x.name, x.is_default, x.sort_key" . ($isLoc ? ', x.parent_id' : '') . '
                         ORDER BY x.sort_key, x.id')->fetchAll();
    return location_tree_order($rows);
}

const LOCATION_SEP = ' › ';
const LOCATION_MAX_DEPTH = 6;   // levels below a top-level location

/** Order rows depth-first (siblings keep their order) and add depth/path/total. Rows whose parent is missing become roots. */
function location_tree_order(array $rows): array
{
    $ids = [];
    foreach ($rows as $r) $ids[(int)$r['id']] = true;
    $kids = [];
    foreach ($rows as $r) {
        $p = $r['parent_id'] !== null && isset($ids[(int)$r['parent_id']]) && (int)$r['parent_id'] !== (int)$r['id'] ? (int)$r['parent_id'] : 0;
        $kids[$p][] = $r;
    }
    $out = [];
    $seen = [];
    $walk = function (int $pid, int $depth, string $prefix) use (&$walk, &$kids, &$out, &$seen): int {
        $sum = 0;
        foreach ($kids[$pid] ?? [] as $r) {
            $id = (int)$r['id'];
            if (isset($seen[$id])) continue;          // defensive: never loop on bad data
            $seen[$id] = true;
            $r['id'] = $id;
            $r['n'] = (int)$r['n'];
            $r['parent_id'] = $pid > 0 ? $pid : null;
            $r['depth'] = $depth;
            $r['path'] = $prefix . $r['name'];
            $at = count($out);
            $out[] = $r;
            $total = $r['n'] + $walk($id, $depth + 1, $r['path'] . LOCATION_SEP);
            $out[$at]['total'] = $total;
            $sum += $total;
        }
        return $sum;
    };
    $walk(0, 0, '');
    // rows only reachable through a cycle (cannot be created through the app): show them as top-level entries
    foreach ($rows as $r) {
        if (isset($seen[(int)$r['id']])) continue;
        $kids[-(int)$r['id']] = [$r];
        $walk(-(int)$r['id'], 0, '');
    }
    return $out;
}

/** Ids of $id and every location below it. @return int[] */
function location_subtree_ids(int $id): array
{
    $byParent = [];
    foreach (db()->query('SELECT id, parent_id FROM locations WHERE parent_id IS NOT NULL')->fetchAll() as $r) $byParent[(int)$r['parent_id']][] = (int)$r['id'];
    $out = [$id];
    $seen = [$id => true];
    for ($i = 0; $i < count($out); $i++) {
        foreach ($byParent[$out[$i]] ?? [] as $c) if (!isset($seen[$c])) { $seen[$c] = true; $out[] = $c; }
    }
    return $out;
}

/**
 * Ids of locations whose name matches $like (a LIKE pattern on name_key) plus all of their sub-locations,
 * so searching "garage" also finds the items on the garage's shelves. @return int[]
 */
function location_ids_matching(string $like): array
{
    $st = db()->prepare("SELECT id FROM locations WHERE name_key LIKE ? ESCAPE '!'");
    $st->execute([$like]);
    $hits = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if (!$hits) return [];
    $kids = [];
    foreach (db()->query('SELECT id, parent_id FROM locations WHERE parent_id IS NOT NULL')->fetchAll() as $row) $kids[(int)$row['parent_id']][] = (int)$row['id'];
    $out = [];
    $todo = $hits;
    while ($todo) {
        $id = array_pop($todo);
        if (isset($out[$id])) continue;
        $out[$id] = true;
        foreach ($kids[$id] ?? [] as $c) $todo[] = $c;
    }
    $ids = array_keys($out);
    sort($ids);
    return $ids;
}

/** True when at least one location sits inside another. */
function locations_nested(): bool
{
    return (bool)db()->query('SELECT 1 FROM locations WHERE parent_id IS NOT NULL LIMIT 1')->fetchColumn();
}

/** Locations keyed by id (tree data from tax_list). */
function locations_by_id(): array
{
    $out = [];
    foreach (tax_list('location') as $l) $out[$l['id']] = $l;
    return $out;
}

/** "Garage › Shelf 2 › Box 1024" for a location id, '' when it does not exist. */
function location_path(?int $id): string
{
    return $id === null ? '' : (string)(locations_by_id()[$id]['path'] ?? '');
}

/** Ancestors of $id from the top down (without $id itself). @return array<int,array{id:int,name:string}> */
function location_ancestors(int $id, ?array $byId = null): array
{
    $byId ??= locations_by_id();
    $out = [];
    $p = $byId[$id]['parent_id'] ?? null;
    while ($p !== null && isset($byId[$p]) && count($out) <= LOCATION_MAX_DEPTH + 1) {
        array_unshift($out, ['id' => $p, 'name' => (string)$byId[$p]['name']]);
        $p = $byId[$p]['parent_id'];
    }
    return $out;
}

/** Put location $id inside $parent (null = top level). Rejects cycles and trees deeper than LOCATION_MAX_DEPTH. Returns true when it changed. */
function location_set_parent(int $id, ?int $parent): bool
{
    return db_tx(function (PDO $pdo) use ($id, $parent): bool {
        $byId = locations_by_id();
        if (!isset($byId[$id])) throw new UserError('Location not found.', 404);
        if ($byId[$id]['parent_id'] === $parent) return false;
        if ($parent !== null) {
            if (!isset($byId[$parent])) throw new UserError('That parent location no longer exists.', 422);
            $sub = location_subtree_ids($id);
            if (in_array($parent, $sub, true)) throw new UserError('A location cannot be put inside itself or one of its own sub-locations.', 422);
            // depth of the new position + height of the moved subtree must stay within the limit
            $height = 0;
            foreach ($sub as $s) if (isset($byId[$s])) $height = max($height, $byId[$s]['depth'] - $byId[$id]['depth']);
            if ($byId[$parent]['depth'] + 1 + $height > LOCATION_MAX_DEPTH) {
                throw new UserError('Locations can be nested at most ' . LOCATION_MAX_DEPTH . ' levels deep.', 422);
            }
        }
        $pdo->prepare('UPDATE locations SET parent_id = ? WHERE id = ?')->execute([$parent, $id]);
        return true;
    });
}

/** Option label for a <select>: indented by depth so the tree stays visible. */
function tax_option_label(array $l): string
{
    $d = (int)($l['depth'] ?? 0);
    return ($d > 0 ? str_repeat("\u{00A0}\u{00A0}\u{00A0}", $d) . '↳ ' : '') . $l['name'];
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

/** Make $id the only default, or clear the default on $id when $on is false (other rows untouched). */
function tax_set_default(string $kind, int $id, bool $on): void
{
    $tb = tax($kind)['table'];
    $pdo = db();
    $pdo->beginTransaction();
    if ($on) {
        $pdo->exec("UPDATE $tb SET is_default = 0 WHERE is_default = 1");
        $pdo->prepare("UPDATE $tb SET is_default = 1 WHERE id = ?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE $tb SET is_default = 0 WHERE id = ?")->execute([$id]);
    }
    $pdo->commit();
}

/** Delete an entry; its items are moved to $moveTo or left unassigned (null). Returns number of items affected. */
function tax_delete(string $kind, int $id, ?int $moveTo): int
{
    $t = tax($kind);
    return db_tx(function (PDO $pdo) use ($t, $kind, $id, $moveTo): int {
        $from = (string)(tax_get($kind, $id)['name'] ?? '');
        $to = $moveTo !== null ? (string)(tax_get($kind, $moveTo)['name'] ?? '') : '';
        $sel = $pdo->prepare("SELECT id, name FROM items WHERE {$t['col']} = ?");
        $sel->execute([$id]);
        foreach ($sel->fetchAll() as $it) {
            $kind === 'location'
                ? activity_log((int)$it['id'], (string)$it['name'], 'moved', ['from' => $from, 'to' => $to, 'reason' => 'location deleted'])
                : activity_log((int)$it['id'], (string)$it['name'], 'edited', ['changes' => ['category' => [$from, $to]], 'reason' => 'category deleted']);
        }
        $st = $pdo->prepare("UPDATE items SET {$t['col']} = ?, updated_at = ? WHERE {$t['col']} = ?");
        $st->execute([$moveTo, now_utc(), $id]);
        $n = $st->rowCount();
        if ($kind === 'location') {
            // sub-locations move up one level instead of disappearing from the tree
            $par = $pdo->prepare('SELECT parent_id FROM locations WHERE id = ?');
            $par->execute([$id]);
            $up = $par->fetchColumn();
            $pdo->prepare('UPDATE locations SET parent_id = ? WHERE parent_id = ?')->execute([$up === false || $up === null ? null : (int)$up, $id]);
        }
        $pdo->prepare("DELETE FROM {$t['table']} WHERE id = ?")->execute([$id]);
        return $n;
    });
}

// Short aliases used by views/router.
function locations(): array { return tax_list('location'); }
function categories(): array { return tax_list('category'); }

/* ---------- item data access ---------- */

const ITEM_COLS = 'i.id, i.name, i.location_id, l.name AS location, i.category_id, c.name AS category, i.quantity, i.min_qty, i.expires_on, i.notes, i.image, i.value_cents, i.lent_to, i.lent_at, i.lent_due, i.purchase_date, i.serial, i.barcode, i.favourite, i.created_at, i.updated_at';
const ITEM_FROM = 'FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id';

function item_get(int $id): ?array
{
    $st = db()->prepare('SELECT ' . ITEM_COLS . ' ' . ITEM_FROM . ' WHERE i.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return null;
    $row['location'] = (string)($row['location'] ?? '');
    $row['category'] = (string)($row['category'] ?? '');
    $row['value_cents'] = $row['value_cents'] === null ? null : (int)$row['value_cents'];
    $row['serial'] = (string)($row['serial'] ?? '');
    $row['barcode'] = (string)($row['barcode'] ?? '');
    $row['min_qty'] = $row['min_qty'] === null ? null : (int)$row['min_qty'];
    $row['tags'] = tags_for((int)$row['id']);
    return $row;
}

function search_blob(string $name, string $notes, string $serial = '', array $tags = [], string $barcode = ''): string
{
    return fold(implode("\n", array_merge([$name, $notes, $serial, $barcode], $tags)));
}

/* ---------- tags ---------- */

/** @return array<int,array{id:int,name:string}> */
function tags_for(int $itemId): array
{
    $st = db()->prepare('SELECT t.id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = ? ORDER BY t.sort_key, t.id');
    $st->execute([$itemId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $st->fetchAll());
}

/** All tags that are in use, with item counts. */
function tags_all(): array
{
    return db()->query('SELECT t.id, t.name, COUNT(it.item_id) AS n FROM tags t JOIN item_tags it ON it.tag_id = t.id GROUP BY t.id, t.name, t.sort_key ORDER BY t.sort_key, t.id')->fetchAll();
}

/** Split "a, b; c" (or an array) into clean, de-duplicated tag names (case/accent-insensitive). */
function tags_parse(string|array $in): array
{
    $parts = is_array($in) ? $in : preg_split('/[,;\n]+/u', $in);
    $out = [];
    foreach ($parts ?: [] as $p) {
        $p = ltrim(clean_line((string)$p, 40), '#');
        if ($p === '') continue;
        $out[fold($p)] ??= $p;
    }
    if (count($out) > 20) throw new UserError('An item can have at most 20 tags.');
    return array_values($out);
}

/** Replace an item's tags; creates missing tags, drops tags no item uses any more. */
function tags_set(int $itemId, array $names): void
{
    $pdo = db();
    $ids = [];
    $find = $pdo->prepare('SELECT id FROM tags WHERE name_key = ?');
    foreach ($names as $n) {
        $find->execute([fold($n)]);
        $tid = $find->fetchColumn();
        $ids[] = $tid !== false ? (int)$tid
            : db_insert($pdo, 'INSERT INTO tags (name, name_key, sort_key, created_at) VALUES (?,?,?,?)', [$n, fold($n), natkey($n), now_utc()]);
    }
    $pdo->prepare('DELETE FROM item_tags WHERE item_id = ?')->execute([$itemId]);
    $ins = $pdo->prepare('INSERT INTO item_tags (item_id, tag_id) VALUES (?,?)');
    foreach (array_unique($ids) as $tid) $ins->execute([$itemId, $tid]);
    $pdo->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)');
}

/* ---------- custom fields ---------- */

/** "12.5", "1,234.50", "1.234,50", "€ 12" → cents; '' → null. */
function parse_money(string $s): ?int
{
    $s = preg_replace('/[\p{Sc}\s]+/u', '', $s) ?? '';
    if ($s === '') return null;
    if (preg_match('/^(\d{1,3}(?:([.,])\d{3})*|\d+)(?:([.,])(\d{1,2}))?$/D', $s, $m) !== 1 || (($m[2] ?? '') !== '' && ($m[2] ?? '') === ($m[3] ?? ''))) {
        throw new UserError('Value must be a number like 49.90.');
    }
    if (($m[2] ?? '') !== '' && !isset($m[4])) throw new UserError('Value is ambiguous – write it like 1234.50.');
    $int = (int)preg_replace('/\D/', '', $m[1]);
    $cents = $int * 100 + (isset($m[4]) ? (int)str_pad($m[4], 2, '0') : 0);
    if ($cents > 100_000_000_000) throw new UserError('That value is too large.');
    return $cents;
}

function fmt_money(?int $cents, ?string $currency = null): string
{
    if ($cents === null) return '';
    $n = fmt_number($cents / 100);
    $c = $currency ?? (string)setting('currency');
    return setting('currency_position') === 'before' ? $c . ' ' . $n : $n . ' ' . $c;
}

function money_input(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
}

function parse_date_ymd(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s || $s < '1900-01-01') throw new UserError('Purchase date must be a valid date (YYYY-MM-DD).');
    if ($s > date('Y-m-d', app_time() + 86400)) throw new UserError('Purchase date cannot be in the future.');
    return $s;
}

/** An item is running low when it has a low-stock level and its quantity is at or below it. */
/* ---------- expiry / warranty ---------- */

/** Last day of the "Expiring soon" window (today + expiry_soon_days, local date). */
function expiry_soon_until(): string
{
    return (new DateTimeImmutable(local_today()))->modify('+' . (int)setting('expiry_soon_days') . ' days')->format('Y-m-d');
}

/** 'expired' (before today), 'soon' (today … today + N days) or '' (no date / later). */
function expiry_state(array $it, ?string $today = null): string
{
    $d = $it['expires_on'] ?? null;
    if ($d === null || $d === '') return '';
    $today ??= local_today();
    if ($d < $today) return 'expired';
    return $d <= expiry_soon_until() ? 'soon' : '';
}

/** Days from today until the date (negative = past). */
function expiry_days(string $date): int
{
    return (int)(new DateTimeImmutable(local_today()))->diff(new DateTimeImmutable($date))->format('%r%a');
}

/** Validate an expiry / warranty date (any valid date from 1900 on; future dates are the normal case). */
function parse_expiry_date(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s || $s < '1900-01-01' || $s > '2999-12-31') throw new UserError('Expiry date must be a valid date (YYYY-MM-DD).');
    return $s;
}

function is_low(array $it): bool
{
    return ($it['min_qty'] ?? null) !== null && (int)$it['quantity'] <= (int)$it['min_qty'];
}

/** Low-stock level from a form: '' → null (no alert), else 0…1,000,000. */
function parse_min_qty(string $s): ?int
{
    $s = trim($s);
    if ($s === '') return null;
    $v = filter_var($s, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
    if ($v === false) throw new UserError('“Running low at” must be a whole number (0 or more).');
    return $v;
}

const LOW_SQL = 'i.min_qty IS NOT NULL AND i.quantity <= i.min_qty';

/** Normalise a filter value: '' = all, 'none' = unassigned, digits = id. */
/** List sort orders (key => label); items_search() maps the keys to ORDER BY clauses. */
const SORTS = ['recent' => 'Recently added', 'name' => 'Name', 'location' => 'Location', 'category' => 'Category', 'qty' => 'Quantity', 'value' => 'Value (highest first)'];

/**
 * The list's choice filters besides location/category/tag: URL parameter => definition. Adding one here gives it a
 * select in the filter sheet, a removable chip, URL parsing and a place in the active-filter count; items_search()
 * applies it. 'chip' labels the chip per value, 'icon' and 'class' style it.
 */
function list_filter_defs(): array
{
    return [
        'stock' => ['label' => 'Stock', 'all' => 'All items', 'opts' => ['low' => 'Running low'], 'icon' => 'alert', 'class' => 'chip-low', 'what' => 'running-low filter'],
        'exp'   => ['label' => 'Expiry / warranty', 'all' => 'All items', 'opts' => ['soon' => 'Expiring soon', 'expired' => 'Expired'], 'icon' => 'clock', 'class' => 'chip-exp', 'what' => 'expiry filter'],
        'fav'   => ['label' => 'Favourites', 'all' => 'All items', 'opts' => ['1' => 'Favourites only'], 'icon' => 'star', 'class' => 'chip-fav', 'what' => 'favourites filter'],
        'lend'  => ['label' => 'Lending', 'all' => 'All items', 'opts' => ['out' => 'Lent out', 'overdue' => 'Overdue'], 'icon' => 'handoff', 'class' => 'chip-lend', 'what' => 'lending filter'],
    ];
}

/** Parse every list filter from a query array (e.g. $_GET). Unknown values become ''. @return array<string,string> */
function filters_from(array $src): array
{
    $f = ['loc' => filter_value($src['loc'] ?? ''), 'cat' => filter_value($src['cat'] ?? ''),
          'tag' => is_string($src['tag'] ?? null) && ctype_digit($src['tag']) ? $src['tag'] : ''];
    foreach (list_filter_defs() as $k => $d) {
        $v = $src[$k] ?? '';
        $f[$k] = is_string($v) && isset($d['opts'][$v]) ? $v : '';
    }
    return $f;
}

/** Fill in missing filter keys with ''. */
function filters_norm(array $f): array
{
    return $f + array_fill_keys(array_merge(['loc', 'cat', 'tag'], array_keys(list_filter_defs())), '');
}

/** True when any filter (not the sort) is set. */
function filters_active(array $f): bool
{
    foreach (filters_norm($f) as $v) if ($v !== '') return true;
    return false;
}

/** Query-string parameters for a list URL (empty values and the default sort dropped). */
function list_query(string $q, array $f, string $sort): array
{
    return array_filter(['q' => $q] + filters_norm($f) + ['sort' => $sort === default_sort() ? '' : $sort], fn($v) => $v !== '' && $v !== null);
}

function filter_value(mixed $v): string
{
    $v = is_string($v) ? $v : '';
    return ($v === 'none' || ctype_digit($v)) ? $v : '';
}

/**
 * @param array{loc:string,cat:string,tag?:string} $f
 * @return array{items: array<int,array>, total: int, more: bool}
 */
function items_search(string $q, array $f, string $sort, int $page, ?int $pageSize = null): array
{
    $where = [];
    $args  = [];
    foreach (preg_split('/\s+/u', fold(trim($q)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tok) {
        // every word must match the item (name/notes/serial/tags), its location or any location above it, or its category
        $like = '%' . like_escape($tok) . '%';
        $locIds = location_ids_matching($like);
        $where[] = "(i.search LIKE ? ESCAPE '!' OR COALESCE(c.name_key, '') LIKE ? ESCAPE '!'"
            . ($locIds ? ' OR i.location_id IN (' . implode(',', array_fill(0, count($locIds), '?')) . ')' : '') . ')';
        array_push($args, $like, $like, ...$locIds);
    }
    foreach (['loc' => 'i.location_id', 'cat' => 'i.category_id'] as $k => $col) {
        $v = $f[$k] ?? '';
        if ($v === 'none') {
            $where[] = "$col IS NULL";
        } elseif ($v !== '' && ctype_digit($v)) {
            // a location filter includes everything stored in its sub-locations
            $ids = $k === 'loc' ? location_subtree_ids((int)$v) : [(int)$v];
            $where[] = "$col IN (" . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($args, ...$ids);
        }
    }
    if (ctype_digit($f['tag'] ?? '')) {
        $where[] = 'EXISTS (SELECT 1 FROM item_tags it WHERE it.item_id = i.id AND it.tag_id = ?)';
        $args[]  = (int)$f['tag'];
    }
    if (($f['stock'] ?? '') === 'low') $where[] = '(' . LOW_SQL . ')';
    if (($f['exp'] ?? '') === 'expired') { $where[] = 'i.expires_on IS NOT NULL AND i.expires_on < ?'; $args[] = local_today(); }
    if (($f['exp'] ?? '') === 'soon') { $where[] = 'i.expires_on IS NOT NULL AND i.expires_on >= ? AND i.expires_on <= ?'; array_push($args, local_today(), expiry_soon_until()); }
    if (($f['fav'] ?? '') === '1') $where[] = 'i.favourite = 1';
    if (($f['lend'] ?? '') === 'out') $where[] = 'i.lent_to IS NOT NULL';
    if (($f['lend'] ?? '') === 'overdue') { $where[] = 'i.lent_to IS NOT NULL AND i.lent_due IS NOT NULL AND i.lent_due < ?'; $args[] = local_today(); }
    $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $order = match ($sort) {
        'name'     => 'i.sort_key ASC, i.id DESC',
        'location' => 'CASE WHEN l.id IS NULL THEN 1 ELSE 0 END, l.sort_key ASC, i.sort_key ASC, i.id DESC',
        'category' => 'CASE WHEN c.id IS NULL THEN 1 ELSE 0 END, c.sort_key ASC, i.sort_key ASC, i.id DESC',
        'qty'      => 'i.quantity DESC, i.sort_key ASC, i.id DESC',
        'value'    => 'CASE WHEN i.value_cents IS NULL THEN 1 ELSE 0 END, i.value_cents DESC, i.sort_key ASC, i.id DESC',
        default    => 'i.updated_at DESC, i.id DESC',
    };
    $st = db()->prepare('SELECT COUNT(*) ' . ITEM_FROM . " $w");
    $st->execute($args);
    $total = (int)$st->fetchColumn();

    $page   = max(1, $page);
    $pageSize ??= page_size();
    $offset = ($page - 1) * $pageSize;
    $st = db()->prepare("SELECT i.id, i.name, i.location_id, COALESCE(l.name, '') AS location, i.category_id, COALESCE(c.name, '') AS category, i.quantity, i.min_qty, i.image, i.lent_to, i.lent_due, i.expires_on, i.favourite "
        . ITEM_FROM . " $w ORDER BY $order LIMIT " . $pageSize . " OFFSET $offset");
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

/** Run $fn inside a transaction (joins an already open one). */
function db_tx(callable $fn): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) return $fn($pdo);
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ---------- activity log (written inside the same transaction as the change) ---------- */

const ACTIVITY_PAGE = 50;

function activity_log(int $itemId, string $itemName, string $action, array $details = []): void
{
    db_insert(db(), 'INSERT INTO activity (item_id, item_name, action, details, created_at, actor) VALUES (?,?,?,?,?,?)',
        [$itemId, $itemName, $action, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), now_utc(), current_actor()]);
}

/** @return array<int,array{id:int,item_id:?int,item_name:string,action:string,details:array,created_at:string,exists:bool}> */
function activity_list(?int $itemId = null, int $page = 1, int $per = ACTIVITY_PAGE): array
{
    $page = max(1, $page);
    $sql = 'SELECT a.id, a.item_id, a.item_name, a.action, a.details, a.created_at, a.actor, CASE WHEN i.id IS NULL THEN 0 ELSE 1 END AS item_exists
            FROM activity a LEFT JOIN items i ON i.id = a.item_id' . ($itemId !== null ? ' WHERE a.item_id = ?' : '')
         . ' ORDER BY a.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);
    $st = db()->prepare($sql);
    $st->execute($itemId !== null ? [$itemId] : []);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'item_id' => $r['item_id'] === null ? null : (int)$r['item_id'], 'item_name' => (string)$r['item_name'],
        'action' => (string)$r['action'], 'details' => json_decode((string)$r['details'], true) ?: [], 'created_at' => (string)$r['created_at'],
        'actor' => $r['actor'] === null ? null : (string)$r['actor'], 'exists' => (int)$r['item_exists'] === 1], $st->fetchAll());
}

function activity_short(?string $s): string
{
    $s = (string)$s;
    return mb_strlen($s) > 200 ? mb_substr($s, 0, 199) . '…' : $s;
}

/**
 * Create or update an item. $newImage (on edit) replaces the cover photo; $removeImage removes the cover
 * (the next photo, if any, becomes the cover).
 * @param array{name:string,location_id:?int,category_id:?int,quantity:int,notes:string} $in
 */
function item_save(?array $existing, array $in, ?string $newImage, bool $removeImage): int
{
    $now  = now_utc();
    $in += ['tags' => [], 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => '', 'min_qty' => null, 'copied_from' => null, 'reason' => null];
    $in['barcode'] = barcode_clean((string)$in['barcode']);
    $blob = search_blob($in['name'], $in['notes'], $in['serial'], $in['tags'], $in['barcode']);
    $drop = [];
    $id = db_tx(function (PDO $pdo) use ($existing, $in, $newImage, $removeImage, $now, $blob, &$drop): int {
        if ($existing === null) {
            $id = db_insert($pdo, 'INSERT INTO items (name, location_id, category_id, quantity, min_qty, notes, image, value_cents, purchase_date, expires_on, serial, barcode, search, sort_key, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$in['name'], $in['location_id'], $in['category_id'], $in['quantity'], $in['min_qty'], $in['notes'], null, $in['value_cents'], $in['purchase_date'], $in['expires_on'], $in['serial'], $in['barcode'] === '' ? null : $in['barcode'], $blob, natkey($in['name']), $now, $now]);
            tags_set($id, $in['tags']);
            if ($newImage !== null) photo_insert($id, $newImage, true);
            activity_log($id, $in['name'], 'created', array_filter([
                'location' => $in['location_id'] !== null ? (string)(tax_get('location', $in['location_id'])['name'] ?? '') : null,
                'category' => $in['category_id'] !== null ? (string)(tax_get('category', $in['category_id'])['name'] ?? '') : null,
                'quantity' => $in['quantity'], 'min_qty' => $in['min_qty'], 'photo' => $newImage !== null ? true : null, 'tags' => $in['tags'] ?: null,
                'copied_from' => $in['copied_from'],
            ], fn($v) => $v !== null));
            return $id;
        }
        $id = (int)$existing['id'];
        $old = item_get($id) ?? $existing;
        $nameOf = fn(string $k, ?int $v) => $v === null ? '' : (string)(tax_get($k, $v)['name'] ?? '');
        $changes = [];
        $cmp = [
            'name'          => [$old['name'], $in['name']],
            'category'      => [(string)$old['category'], $nameOf('category', $in['category_id'])],
            'notes'         => [activity_short($old['notes']), activity_short($in['notes'])],
            'value'         => [money_input($old['value_cents'] ?? null), money_input($in['value_cents'])],
            'purchase_date' => [(string)($old['purchase_date'] ?? ''), (string)($in['purchase_date'] ?? '')],
            'expires_on'    => [(string)($old['expires_on'] ?? ''), (string)($in['expires_on'] ?? '')],
            'serial'        => [(string)($old['serial'] ?? ''), $in['serial']],
            'barcode'       => [(string)($old['barcode'] ?? ''), $in['barcode']],
            'tags'          => [implode(', ', array_column($old['tags'] ?? [], 'name')), implode(', ', $in['tags'])],
            'min_qty'       => [($old['min_qty'] ?? null) === null ? '' : (string)$old['min_qty'], $in['min_qty'] === null ? '' : (string)$in['min_qty']],
        ];
        foreach ($cmp as $k => [$a, $b]) if ($a !== $b) $changes[$k] = [$a, $b];
        if ($changes) activity_log($id, $in['name'], 'edited', ['changes' => $changes] + array_filter(['reason' => $in['reason']]));
        if ((int)$old['location_id'] !== (int)$in['location_id'] || ($old['location_id'] === null) !== ($in['location_id'] === null)) {
            activity_log($id, $in['name'], 'moved', ['from' => (string)$old['location'], 'to' => $nameOf('location', $in['location_id'])] + array_filter(['reason' => $in['reason']]));
        }
        if ((int)$old['quantity'] !== (int)$in['quantity']) activity_log($id, $in['name'], 'quantity', ['from' => (int)$old['quantity'], 'to' => (int)$in['quantity']]);
        if ($newImage !== null || ($removeImage && $old['image'] !== null)) {
            activity_log($id, $in['name'], 'photo', ['change' => $newImage !== null ? ($old['image'] !== null ? 'replaced' : 'added') : 'removed']);
        }
        $st = $pdo->prepare('UPDATE items SET name=?, location_id=?, category_id=?, quantity=?, min_qty=?, notes=?, value_cents=?, purchase_date=?, expires_on=?, serial=?, barcode=?, search=?, sort_key=?, updated_at=? WHERE id=?');
        $st->execute([$in['name'], $in['location_id'], $in['category_id'], $in['quantity'], $in['min_qty'], $in['notes'], $in['value_cents'], $in['purchase_date'], $in['expires_on'], $in['serial'], $in['barcode'] === '' ? null : $in['barcode'], $blob, natkey($in['name']), $now, $id]);
        tags_set($id, $in['tags']);
        if ($newImage !== null || $removeImage) {
            $cover = photos_for($id)[0] ?? null;
            if ($cover) { $pdo->prepare('DELETE FROM item_photos WHERE id = ?')->execute([$cover['id']]); $drop[] = $cover['file']; }
            if ($newImage !== null) photo_insert($id, $newImage, true);
            photos_renumber($id);
        }
        return $id;
    });
    foreach ($drop as $f) image_delete($f);   // files only after the commit
    return $id;
}

/** Delete an item. With $keepFiles its photo/attachment files stay on disk (an undo token owns them). */
function item_delete(array $item, bool $keepFiles = false): void
{
    $files = array_column(photos_for((int)$item['id']), 'file');
    $att = array_column(attachments_for((int)$item['id']), 'file');
    db_tx(function (PDO $pdo) use ($item): void {
        activity_log((int)$item['id'], (string)$item['name'], 'deleted', array_filter(['location' => (string)($item['location'] ?? ''), 'quantity' => (int)$item['quantity']], fn($v) => $v !== ''));
        $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$item['id']]);
        $pdo->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)');
    });
    if ($keepFiles) return;
    foreach (array_unique(array_merge($files, [$item['image']])) as $f) image_delete($f);
    foreach ($att as $f) attachment_file_delete($f);
}

/* ---------- photos (many per item; position 0 = cover, mirrored in items.image) ---------- */


/** @return array<int,array{id:int,file:string,position:int}> */
function photos_for(int $itemId): array
{
    $st = db()->prepare('SELECT id, file, position FROM item_photos WHERE item_id = ? ORDER BY position, id');
    $st->execute([$itemId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'file' => (string)$r['file'], 'position' => (int)$r['position']], $st->fetchAll());
}

function photo_insert(int $itemId, string $file, bool $asCover = false): int
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COALESCE(MAX(position), -1) FROM item_photos WHERE item_id = ?');
    $st->execute([$itemId]);
    $pos = $asCover ? -1 : (int)$st->fetchColumn() + 1;
    $pid = db_insert($pdo, 'INSERT INTO item_photos (item_id, file, position, created_at) VALUES (?,?,?,?)', [$itemId, $file, $pos, now_utc()]);
    photos_renumber($itemId);
    return $pid;
}

/** Positions 0..n-1 in current order (optionally with $order = photo ids) and sync the cover into items.image. */
function photos_renumber(int $itemId, ?array $order = null): void
{
    $pdo = db();
    $ids = $order ?? array_column(photos_for($itemId), 'id');
    $up = $pdo->prepare('UPDATE item_photos SET position = ? WHERE id = ? AND item_id = ?');
    foreach (array_values($ids) as $i => $pid) $up->execute([$i, $pid, $itemId]);
    $cover = photos_for($itemId)[0]['file'] ?? null;
    $pdo->prepare('UPDATE items SET image = ? WHERE id = ?')->execute([$cover, $itemId]);
}

/** Add an uploaded photo (already stored by image_store) to an item. */
function photo_add(int $itemId, string $file): int
{
    return db_tx(function () use ($itemId, $file): int {
        if (count(photos_for($itemId)) >= max_photos()) throw new UserError('An item can have at most ' . max_photos() . ' photos.');
        $pid = photo_insert($itemId, $file);
        db()->prepare('UPDATE items SET updated_at = ? WHERE id = ?')->execute([now_utc(), $itemId]);
        activity_log($itemId, (string)(item_get($itemId)['name'] ?? ''), 'photo', ['change' => 'added', 'count' => count(photos_for($itemId))]);
        return $pid;
    });
}

function photo_find(int $itemId, int $photoId): array
{
    foreach (photos_for($itemId) as $i => $p) if ($p['id'] === $photoId) return $p + ['index' => $i];
    throw new UserError('Photo not found.', 404);
}

/** $action: cover | left | right | delete. Returns the photo's new index (or -1 when deleted). */
function photo_action(int $itemId, int $photoId, string $action): int
{
    $drop = null;
    $r = db_tx(function () use ($itemId, $photoId, $action, &$drop): int {
        $p = photo_find($itemId, $photoId);
        $ids = array_column(photos_for($itemId), 'id');
        $i = $p['index'];
        switch ($action) {
            case 'cover': array_splice($ids, $i, 1); array_unshift($ids, $photoId); $new = 0; break;
            case 'left':  $new = max(0, $i - 1); [$ids[$i], $ids[$new]] = [$ids[$new], $ids[$i]]; break;
            case 'right': $new = min(count($ids) - 1, $i + 1); [$ids[$i], $ids[$new]] = [$ids[$new], $ids[$i]]; break;
            case 'delete':
                db()->prepare('DELETE FROM item_photos WHERE id = ?')->execute([$photoId]);
                array_splice($ids, $i, 1);
                $drop = $p['file'];
                $new = -1;
                break;
            default: throw new UserError('Unknown photo action.', 404);
        }
        photos_renumber($itemId, $ids);
        db()->prepare('UPDATE items SET updated_at = ? WHERE id = ?')->execute([now_utc(), $itemId]);
        if ($new !== $i || $action === 'delete') {
            activity_log($itemId, (string)(item_get($itemId)['name'] ?? ''), 'photo',
                ['change' => ['cover' => 'cover', 'left' => 'reordered', 'right' => 'reordered', 'delete' => 'deleted'][$action], 'count' => count($ids)]);
        }
        return $new;
    });
    if ($drop !== null) image_delete($drop);
    return $r;
}

/** All uploaded files from a multi-file field (photos[]) plus single fields. */
function pick_uploads(array $fields): array
{
    $out = [];
    foreach ($fields as $f) {
        $file = $_FILES[$f] ?? null;
        if (!is_array($file)) continue;
        if (is_array($file['error'])) {
            foreach ($file['error'] as $k => $err) {
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                $out[] = ['name' => $file['name'][$k], 'type' => $file['type'][$k], 'tmp_name' => $file['tmp_name'][$k], 'error' => $err, 'size' => $file['size'][$k]];
            }
        } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
            $out[] = $file;
        }
    }
    return $out;
}

function item_adjust_qty(int $id, int $delta): ?int
{
    return db_tx(function (PDO $pdo) use ($id, $delta): ?int {
        $st = $pdo->prepare('SELECT name, quantity FROM items WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) return null;
        $st = $pdo->prepare('UPDATE items SET quantity = CASE WHEN quantity + ? < 0 THEN 0 ELSE quantity + ? END, updated_at = ? WHERE id = ?');
        $st->execute([$delta, $delta, now_utc(), $id]);
        $st = $pdo->prepare('SELECT quantity FROM items WHERE id = ?');
        $st->execute([$id]);
        $q = (int)$st->fetchColumn();
        if ($q !== (int)$row['quantity']) activity_log($id, (string)$row['name'], 'quantity', ['from' => (int)$row['quantity'], 'to' => $q]);
        return $q;
    });
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
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is too large (max ' . (max_upload() >> 20) . ' MB).',
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
    if ($file['size'] > max_upload()) throw new UserError(upload_error_text(UPLOAD_ERR_INI_SIZE));

    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!$info || !in_array($info[2], $types, true)) {
        throw new UserError('Unsupported image. Please use JPEG, PNG, WebP or GIF (on iPhone, HEIC photos are converted automatically).');
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
    $full = image_fit($src, image_full_max());
    $thumb = image_fit($src, image_thumb_max());
    imageinterlace($full, true);
    imageinterlace($thumb, true);
    $ok = imagejpeg($full, IMG_DIR . '/' . $name, (int)setting('jpeg_quality')) && imagejpeg($thumb, THUMB_DIR . '/' . $name, (int)setting('jpeg_quality') - 6);
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
    $d = app_now()->modify($expr);
    return $d->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function stats_totals(): array
{
    $st = db()->prepare('SELECT COUNT(*) AS items, COALESCE(SUM(quantity), 0) AS qty,
                                COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS week,
                                COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS month,
                                COALESCE(SUM(CASE WHEN min_qty IS NOT NULL AND quantity <= min_qty THEN 1 ELSE 0 END), 0) AS low,
                                COALESCE(SUM(CASE WHEN expires_on IS NOT NULL AND expires_on >= ? AND expires_on <= ? THEN 1 ELSE 0 END), 0) AS expiring,
                                COALESCE(SUM(CASE WHEN expires_on IS NOT NULL AND expires_on < ? THEN 1 ELSE 0 END), 0) AS expired
                         FROM items');
    $st->execute([local_boundary_utc('monday this week'), local_boundary_utc('first day of this month'), local_today(), expiry_soon_until(), local_today()]);
    $r = $st->fetch();
    return [
        'items'     => (int)$r['items'],
        'qty'       => (int)$r['qty'],
        'week'      => (int)$r['week'],
        'month'     => (int)$r['month'],
        'low'       => (int)$r['low'],
        'expiring'  => (int)$r['expiring'],
        'expired'   => (int)$r['expired'],
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
        $start = app_now($tz)->setTime(0, 0)->modify('-29 days');
        $step = '+1 day'; $fmtKey = 'Y-m-d'; $count = 30;
    } else {
        $start = app_now($tz)->modify('first day of this month')->setTime(0, 0)->modify('-11 months');
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
    if ($kind === 'location') {
        $rows = stats_location_rollup();
    } else {
        $rows = db()->query("SELECT x.id, x.name, COUNT(i.id) AS n
                             FROM items i JOIN {$t['table']} x ON x.id = i.{$t['col']}
                             GROUP BY x.id, x.name, x.sort_key ORDER BY n DESC, x.sort_key, x.id")->fetchAll();
    }
    $top = array_slice($rows, 0, $n);
    $rest = array_slice($rows, $n);
    if ($rest) $top[] = ['id' => null, 'name' => 'Other (' . count($rest) . ')', 'n' => array_sum(array_column($rest, 'n')), 'other' => true];
    $un = (int)db()->query("SELECT COUNT(*) FROM items WHERE {$t['col']} IS NULL")->fetchColumn();
    if ($un > 0) $top[] = ['id' => 'none', 'name' => $t['none'], 'n' => $un];
    return $top;
}

/**
 * Item counts per top-level location, each including everything in its sub-locations (every item is counted once,
 * under the top of its branch). Sorted by count, then tree order. @return array<int,array{id:int,name:string,n:int}>
 */
function stats_location_rollup(string $measure = 'count'): array
{
    // $measure picks one of two fixed aggregates (never user input): item count or summed purchase price (cents)
    $agg = $measure === 'value' ? 'COALESCE(SUM(value_cents), 0)' : 'COUNT(*)';
    $byId = locations_by_id();
    $root = [];
    foreach ($byId as $id => $l) {
        $r = $id;
        for ($i = 0; ($p = $byId[$r]['parent_id'] ?? null) !== null && isset($byId[$p]) && $i <= LOCATION_MAX_DEPTH + 1; $i++) $r = $p;
        $root[$id] = $r;
    }
    $sum = [];
    foreach (db()->query("SELECT location_id, $agg AS n FROM items WHERE location_id IS NOT NULL" . ($measure === 'value' ? ' AND value_cents IS NOT NULL' : '') . ' GROUP BY location_id')->fetchAll() as $row) {
        $r = $root[(int)$row['location_id']] ?? null;
        if ($r !== null) $sum[$r] = ($sum[$r] ?? 0) + (int)$row['n'];
    }
    $pos = array_flip(array_keys($byId));
    $out = [];
    foreach ($sum as $id => $cnt) $out[] = ['id' => $id, 'name' => (string)$byId[$id]['name'], 'n' => $cnt];
    usort($out, fn($a, $b) => [$b['n'], $pos[$a['id']]] <=> [$a['n'], $pos[$b['id']]]);
    return $out;
}

/**
 * Purchase-price totals for the overview (cents): overall, per top-level location (sub-locations rolled up) and per
 * category, each with "No location" / "No category" buckets. Items without a price are not counted.
 * @return array{total:int, priced:int, by_location: array, by_category: array}
 */
function stats_value(int $n = 5): array
{
    $r = db()->query('SELECT COALESCE(SUM(value_cents), 0) AS total, COUNT(value_cents) AS priced FROM items')->fetch();
    $byLoc = array_values(array_filter(stats_location_rollup('value'), fn($x) => $x['n'] > 0));
    $byCat = array_map(fn($x) => ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'n' => (int)$x['n']],
        db()->query('SELECT c.id, c.name, SUM(i.value_cents) AS n FROM items i JOIN categories c ON c.id = i.category_id
                     WHERE i.value_cents IS NOT NULL GROUP BY c.id, c.name, c.sort_key ORDER BY n DESC, c.sort_key, c.id')->fetchAll());
    $bucket = function (array $rows, string $kind) use ($n): array {
        $t = tax($kind);
        $top = array_slice($rows, 0, $n);
        $rest = array_slice($rows, $n);
        if ($rest) $top[] = ['id' => null, 'name' => 'Other (' . count($rest) . ')', 'n' => array_sum(array_column($rest, 'n')), 'other' => true];
        $un = (int)db()->query("SELECT COALESCE(SUM(value_cents), 0) FROM items WHERE {$t['col']} IS NULL")->fetchColumn();
        if ($un > 0) $top[] = ['id' => 'none', 'name' => $t['none'], 'n' => $un];
        return $top;
    };
    return ['total' => (int)$r['total'], 'priced' => (int)$r['priced'],
            'by_location' => $bucket($byLoc, 'location'), 'by_category' => $bucket($byCat, 'category')];
}

/* ---------- bulk actions ---------- */

const BULK_MAX = 500;
const BULK_ACTIONS = ['move', 'category', 'tag_add', 'tag_remove', 'delete'];

/** The editable fields of a stored item, in the shape item_save() takes. */
function item_input(array $it): array
{
    return ['name' => (string)$it['name'], 'location_id' => $it['location_id'] !== null ? (int)$it['location_id'] : null,
            'category_id' => $it['category_id'] !== null ? (int)$it['category_id'] : null, 'quantity' => (int)$it['quantity'],
            'min_qty' => $it['min_qty'] ?? null, 'notes' => (string)$it['notes'], 'tags' => array_column($it['tags'] ?? [], 'name'),
            'value_cents' => $it['value_cents'], 'purchase_date' => $it['purchase_date'] !== null ? (string)$it['purchase_date'] : null,
            'expires_on' => ($it['expires_on'] ?? null) !== null ? (string)$it['expires_on'] : null,
            'serial' => (string)$it['serial'], 'barcode' => (string)($it['barcode'] ?? '')];
}

/**
 * Apply one action to many items in a single transaction; every item gets its own history entry (via item_save /
 * the delete log). $opt: location_id / category_id (int|null), tags (string[]), tag_id (int).
 * @param int[] $ids
 * @return int number of items changed
 */
function items_bulk(array $ids, string $action, array $opt = []): int
{
    if (!in_array($action, BULK_ACTIONS, true)) throw new UserError('Unknown bulk action.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) throw new UserError('Select at least one item.');
    if (count($ids) > BULK_MAX) throw new UserError('Select at most ' . BULK_MAX . ' items at once.');
    if ($action === 'move' && ($opt['location_id'] ?? null) !== null && !tax_get('location', (int)$opt['location_id'])) throw new UserError('That location no longer exists.');
    if ($action === 'category' && ($opt['category_id'] ?? null) !== null && !tax_get('category', (int)$opt['category_id'])) throw new UserError('That category no longer exists.');
    if ($action === 'tag_add' && !($opt['tags'] ?? [])) throw new UserError('Enter at least one tag to add.');
    $files = [];
    $attFiles = [];
    $n = db_tx(function (PDO $pdo) use ($ids, $action, $opt, &$files, &$attFiles): int {
        $n = 0;
        foreach ($ids as $id) {
            $it = item_get($id);
            if (!$it) continue;
            $in = item_input($it);
            switch ($action) {
                case 'move':     $in['location_id'] = $opt['location_id'] ?? null; break;
                case 'category': $in['category_id'] = $opt['category_id'] ?? null; break;
                case 'tag_add':  $in['tags'] = tags_parse(array_merge($in['tags'], $opt['tags'])); break;
                case 'tag_remove':
                    $in['tags'] = array_column(array_filter($it['tags'], fn($t) => (int)$t['id'] !== (int)($opt['tag_id'] ?? 0)), 'name');
                    break;
                case 'delete':
                    $files = array_merge($files, array_column(photos_for($id), 'file'), [$it['image']]);
                    $attFiles = array_merge($attFiles, array_column(attachments_for($id), 'file'));
                    activity_log($id, (string)$it['name'], 'deleted', array_filter(['location' => (string)$it['location'], 'quantity' => (int)$it['quantity'], 'bulk' => true], fn($v) => $v !== ''));
                    $pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
                    $n++;
                    continue 2;
            }
            item_save($it, $in, null, false);
            $n++;
        }
        $pdo->exec('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)');
        return $n;
    });
    if (!empty($opt['keep_files'])) return $n;
    foreach (array_unique(array_filter($files)) as $f) image_delete($f);
    foreach ($attFiles as $f) attachment_file_delete($f);
    return $n;
}

/* ---------- lending ---------- */

/** Today's date (YYYY-MM-DD) in the app's time zone; lending dates are local calendar dates. */
function local_today(): string
{
    return app_now()->format('Y-m-d');
}

function is_lent(array $it): bool
{
    return ($it['lent_to'] ?? null) !== null;
}

function is_overdue(array $it, ?string $today = null): bool
{
    return is_lent($it) && ($it['lent_due'] ?? null) !== null && $it['lent_due'] < ($today ?? local_today());
}

/** Lending date from a form: YYYY-MM-DD, or null when empty. */
function parse_lend_date(string $s, string $what): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s || $s < '1900-01-01' || $s > '2999-12-31') throw new UserError("$what must be a valid date (YYYY-MM-DD).");
    return $s;
}

/** Mark an item as lent to someone (also used to change who has it / the due date). */
function item_lend(int $id, string $to, ?string $at, ?string $due): void
{
    $to = clean_line($to, 100);
    if ($to === '') throw new UserError('Enter who you lent it to.');
    $at ??= local_today();
    if ($at > local_today()) throw new UserError('The lent date cannot be in the future.');
    if ($due !== null && $due < $at) throw new UserError('The due date cannot be before the lent date.');
    db_tx(function (PDO $pdo) use ($id, $to, $at, $due): void {
        $it = item_get($id);
        if (!$it) throw new UserError('Item not found.', 404);
        $pdo->prepare('UPDATE items SET lent_to = ?, lent_at = ?, lent_due = ?, updated_at = ? WHERE id = ?')->execute([$to, $at, $due, now_utc(), $id]);
        activity_log($id, (string)$it['name'], 'lent', array_filter(['to' => $to, 'at' => $at, 'due' => $due, 'before' => $it['lent_to']], fn($v) => $v !== null));
    });
}

/** Mark a lent item as returned. */
function item_return(int $id): void
{
    db_tx(function (PDO $pdo) use ($id): void {
        $it = item_get($id);
        if (!$it) throw new UserError('Item not found.', 404);
        if (!is_lent($it)) return;
        $pdo->prepare('UPDATE items SET lent_to = NULL, lent_at = NULL, lent_due = NULL, updated_at = ? WHERE id = ?')->execute([now_utc(), $id]);
        $days = (int)(new DateTimeImmutable((string)$it['lent_at']))->diff(new DateTimeImmutable(local_today()))->format('%a');
        activity_log($id, (string)$it['name'], 'returned', ['from' => $it['lent_to'], 'days' => $days]);
    });
}

/** Items that are out, overdue first, then by due date (no due date last), then by lent date. */
function items_lent(): array
{
    $st = db()->prepare('SELECT ' . ITEM_COLS . ' ' . ITEM_FROM . ' WHERE i.lent_to IS NOT NULL
        ORDER BY CASE WHEN i.lent_due IS NOT NULL AND i.lent_due < ? THEN 0 ELSE 1 END, CASE WHEN i.lent_due IS NULL THEN 1 ELSE 0 END, i.lent_due, i.lent_at, i.id');
    $st->execute([local_today()]);
    return $st->fetchAll();
}

function lent_counts(): array
{
    $st = db()->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN lent_due IS NOT NULL AND lent_due < ? THEN 1 ELSE 0 END), 0) AS late FROM items WHERE lent_to IS NOT NULL');
    $st->execute([local_today()]);
    $r = $st->fetch();
    return ['out' => (int)$r['n'], 'overdue' => (int)$r['late']];
}

/* ---------- duplicate ---------- */

/** Copy a stored photo (full size + thumbnail) to a new random file name; returns the new name. */
function image_copy(string $file): string
{
    if (!valid_image_name($file) || !is_file(IMG_DIR . '/' . $file)) throw new UserError('A photo file is missing.');
    $new = bin2hex(random_bytes(16)) . '.jpg';
    if (!@copy(IMG_DIR . '/' . $file, IMG_DIR . '/' . $new)) throw new RuntimeException('could not copy photo');
    if (is_file(THUMB_DIR . '/' . $file) && !@copy(THUMB_DIR . '/' . $file, THUMB_DIR . '/' . $new)) {
        @unlink(IMG_DIR . '/' . $new);
        throw new RuntimeException('could not copy thumbnail');
    }
    return $new;
}

/**
 * Create a copy of an item: name + " (copy)", location, category, tags, quantity, low-stock level, notes and the custom
 * fields (value, purchase date, serial). Lending is not copied. With $photos the photo files are duplicated too.
 */
function item_duplicate(int $id, bool $photos): int
{
    $it = item_get($id);
    if (!$it) throw new UserError('Item not found.', 404);
    $in = item_input($it);
    $in['name'] = mb_substr($in['name'], 0, 193) . ' (copy)';
    $in['copied_from'] = (string)$it['name'];
    $copies = [];
    try {
        if ($photos) foreach (photos_for($id) as $p) $copies[] = image_copy($p['file']);
        return db_tx(function () use ($in, $copies): int {
            $new = item_save(null, $in, null, false);
            foreach ($copies as $f) photo_insert($new, $f);
            return $new;
        });
    } catch (Throwable $e) {
        foreach ($copies as $f) image_delete($f);
        throw $e;
    }
}

/* ---------- saved filters ---------- */


/**
 * Normalised query string of a list view: only known parameters with valid values, in a fixed order, so the same
 * combination always gives the same string ("q=drill&loc=3&sort=name"). '' = the unfiltered list.
 */
function saved_filter_query(array $src): string
{
    $q = clean_line(is_string($src['q'] ?? null) ? $src['q'] : '', 200);
    $sort = is_string($src['sort'] ?? null) && isset(SORTS[$src['sort']]) ? $src['sort'] : default_sort();
    return http_build_query(list_query($q, filters_from($src), $sort));
}

/** @return array<int,array{id:int,name:string,query:string}> */
function saved_filters(): array
{
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'query' => (string)$r['query']],
        db()->query('SELECT id, name, query FROM saved_filters ORDER BY sort_key, id')->fetchAll());
}

function saved_filter_get(int $id): ?array
{
    $st = db()->prepare('SELECT id, name, query FROM saved_filters WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ? ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'query' => (string)$r['query']] : null;
}

function saved_filter_name(string $name, ?int $except = null): string
{
    $name = clean_line($name, 60);
    if ($name === '') throw new UserError('Give the filter a name.', 422);
    $st = db()->prepare('SELECT id, name FROM saved_filters WHERE name_key = ?');
    $st->execute([fold($name)]);
    $ex = $st->fetch();
    if ($ex && (int)$ex['id'] !== $except) throw new UserError('A saved filter called “' . $ex['name'] . '” already exists.', 409);
    return $name;
}

/** Save the current list view under a name. @return int new id */
function saved_filter_create(string $name, string $query): int
{
    return db_tx(function (PDO $pdo) use ($name, $query): int {
        $name = saved_filter_name($name);
        if ($query === '') throw new UserError('Choose a search, filter or sort first – there is nothing to save.', 422);
        if ((int)$pdo->query('SELECT COUNT(*) FROM saved_filters')->fetchColumn() >= saved_filters_max()) {
            throw new UserError('You can keep up to ' . saved_filters_max() . ' saved filters. Delete one first.', 422);
        }
        return db_insert($pdo, 'INSERT INTO saved_filters (name, name_key, query, sort_key, created_at) VALUES (?,?,?,?,?)',
            [$name, fold($name), $query, natkey($name), now_utc()]);
    });
}

function saved_filter_rename(int $id, string $name): void
{
    if (!saved_filter_get($id)) throw new UserError('Saved filter not found.', 404);
    $name = saved_filter_name($name, $id);
    db()->prepare('UPDATE saved_filters SET name = ?, name_key = ?, sort_key = ? WHERE id = ?')->execute([$name, fold($name), natkey($name), $id]);
}

function saved_filter_delete(int $id): void
{
    if (!saved_filter_get($id)) throw new UserError('Saved filter not found.', 404);
    db()->prepare('DELETE FROM saved_filters WHERE id = ?')->execute([$id]);
}

/* ---------- barcodes ---------- */

const BARCODE_MAX = 64;

/** Normalise a scanned or typed barcode: no whitespace or control characters, at most BARCODE_MAX characters. */
function barcode_clean(string $code): string
{
    $code = (string)preg_replace('/[\s\x00-\x1F\x7F]+/u', '', $code);
    return mb_substr($code, 0, BARCODE_MAX);
}

/** Ids of the items with exactly this barcode (barcodes are "unique-ish": a product code can be on several items). @return int[] */
function items_by_barcode(string $code): array
{
    $code = barcode_clean($code);
    if ($code === '') return [];
    $st = db()->prepare('SELECT id FROM items WHERE barcode = ? ORDER BY id LIMIT 20');
    $st->execute([$code]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Where /barcode/{code} goes: the only item with it, the list of matches, or a new item prefilled with the code. */
function barcode_target(string $code): string
{
    $code = barcode_clean($code);
    if ($code === '') return '/barcode';
    $ids = items_by_barcode($code);
    if (count($ids) === 1) return '/item/' . $ids[0];
    if ($ids) return '/?q=' . rawurlencode($code);
    return '/new?barcode=' . rawurlencode($code);
}
