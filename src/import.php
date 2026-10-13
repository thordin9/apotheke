<?php
declare(strict_types=1);

/*
 * CSV import: upload → map columns → preview (a dry run inside a transaction that is rolled back, so the preview shows
 * exactly what the real run would do, including every validation error) → import (the same run, committed in one
 * transaction). The files of the CSV export import as they are: same headers, "id" updates the item with that id.
 * The uploaded file waits in DATA_DIR/tmp/import-<id>.csv; the `imports` table (migration 0020) keeps the history.
 */

const IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const IMPORT_MAX_ROWS = 5000;
const IMPORT_PREVIEW_ROWS = 50;

/** Target fields: key => [label, aliases (folded header names that map automatically)]. */
function import_fields(): array
{
    return [
        'id'            => ['ID (update that item)', ['id', 'item id']],
        'name'          => ['Name', ['name', 'item', 'title', 'όνομα', 'ονομα']],
        'location'      => ['Location', ['location', 'place', 'where', 'location path', 'θεση', 'τοποθεσια']],
        'category'      => ['Category', ['category', 'type', 'κατηγορια']],
        'quantity'      => ['Quantity', ['quantity', 'qty', 'count', 'amount', 'ποσοτητα']],
        'notes'         => ['Notes', ['notes', 'note', 'description', 'comment', 'σημειωσεις']],
        'tags'          => ['Tags', ['tags', 'tag', 'labels']],
        'value'         => ['Value', ['value', 'price', 'cost', 'τιμη']],
        'purchase_date' => ['Purchase date', ['purchase_date', 'purchase date', 'purchased', 'bought']],
        'serial'        => ['Serial number', ['serial', 'serial number', 'sn']],
        'barcode'       => ['Barcode', ['barcode', 'ean', 'upc', 'gtin']],
        'min_qty'       => ['Low-stock level', ['min_qty', 'min qty', 'minimum', 'low stock']],
        'expires_on'    => ['Expires on', ['expires_on', 'expires', 'expiry', 'best before', 'warranty until']],
        'lent_to'       => ['Lent to', ['lent_to', 'lent to', 'borrower']],
        'lent_due'      => ['Due back', ['lent_due', 'due', 'due back']],
    ];
}

function import_file(int $id): string
{
    return cfg()['data_dir'] . '/tmp/import-' . $id . '.csv';
}

/** Read CSV text into [header, rows]; detects , ; or tab, strips a BOM, converts Windows-1252 to UTF-8. */
function import_parse(string $csv): array
{
    if (str_starts_with($csv, "\xEF\xBB\xBF")) $csv = substr($csv, 3);
    if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
    $csv = str_replace(["\r\n", "\r"], "\n", $csv);
    $first = strtok($csv, "\n") ?: '';
    $counts = [',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")];
    arsort($counts);
    $delim = (string)array_key_first($counts);
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $csv);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, null, $delim, '"', '')) !== false) {
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
        $rows[] = array_map(fn($v) => (string)$v, $r);
        if (count($rows) > IMPORT_MAX_ROWS + 1) throw new UserError('That file has more than ' . number_format(IMPORT_MAX_ROWS) . ' rows. Split it into smaller files.', 422);
    }
    fclose($fh);
    if (count($rows) < 2) throw new UserError('The file needs a header row and at least one item.', 422);
    $header = array_map(fn($h) => clean_line($h, 80), array_shift($rows));
    return [$header, $rows, $delim];
}

/** Store an upload and register it. @return int import id */
function import_upload(array $file): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new UserError('Choose a CSV file to import.', 422);
    if ((int)$file['size'] > IMPORT_MAX_BYTES) throw new UserError('That file is larger than 5 MB.', 422);
    $csv = (string)file_get_contents((string)$file['tmp_name']);
    if (str_contains(substr($csv, 0, 4096), "\0")) throw new UserError('That does not look like a CSV file.', 422);
    [$header, $rows] = import_parse($csv);
    $name = clean_line((string)($file['name'] ?? 'import.csv'), 120) ?: 'import.csv';
    $id = db_insert(db(), 'INSERT INTO imports (file_name, status, total_rows, mapping, options, created_at, actor) VALUES (?,?,?,?,?,?,?)',
        [$name, 'uploaded', count($rows), json_encode(import_auto_map($header)), json_encode(['create' => (bool)setting('import_create_missing'), 'skip_errors' => false]), now_utc(), current_actor()]);
    @mkdir(dirname(import_file($id)), 0775, true);
    file_put_contents(import_file($id), $csv);
    return $id;
}

/** Column index => field key ('' = ignore), guessed from the header names. */
function import_auto_map(array $header): array
{
    $map = [];
    $used = [];
    foreach ($header as $i => $h) {
        $k = '';
        $f = fold(trim($h));
        foreach (import_fields() as $key => [, $aliases]) {
            if (!isset($used[$key]) && in_array($f, $aliases, true)) { $k = $key; break; }
        }
        if ($k !== '') $used[$k] = true;
        $map[$i] = $k;
    }
    return $map;
}

function import_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM imports WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['id'] = (int)$r['id'];
    $r['mapping'] = json_decode((string)$r['mapping'], true) ?: [];
    $r['options'] = json_decode((string)$r['options'], true) ?: [];
    $r['result'] = $r['result'] ? json_decode((string)$r['result'], true) : null;
    return $r;
}

function imports_recent(int $n = 10): array
{
    return db()->query('SELECT id, file_name, status, total_rows, created_at, finished_at, actor, result FROM imports ORDER BY id DESC LIMIT ' . $n)->fetchAll();
}

/** Read the stored file of an import. @return array{0:array,1:array} header, rows */
function import_rows(array $imp): array
{
    $f = import_file($imp['id']);
    if (!is_file($f)) throw new UserError('The uploaded file is gone – upload it again.', 410);
    [$header, $rows] = import_parse((string)file_get_contents($f));
    return [$header, $rows];
}

/** Save the mapping + options chosen in the form. */
function import_set_mapping(int $id, array $header, array $post): array
{
    $fields = import_fields();
    $map = [];
    $seen = [];
    foreach (array_keys($header) as $i) {
        $k = (string)($post['col'][$i] ?? '');
        if ($k !== '' && !isset($fields[$k])) $k = '';
        if ($k !== '' && isset($seen[$k])) throw new UserError('“' . $fields[$k][0] . '” is chosen for two columns.', 422);
        if ($k !== '') $seen[$k] = true;
        $map[$i] = $k;
    }
    if (!isset($seen['name'])) throw new UserError('Choose which column holds the item name.', 422);
    $opt = ['create' => !empty($post['create']), 'skip_errors' => !empty($post['skip_errors'])];
    db()->prepare('UPDATE imports SET mapping = ?, options = ? WHERE id = ?')->execute([json_encode($map), json_encode($opt), $id]);
    return [$map, $opt];
}

/** A location by name or path ("Garage › Shelf 2", also "Garage > Shelf 2" / "Garage / Shelf 2"); creates it when allowed. */
function import_location(string $v, bool $create, array &$made): ?int
{
    $parts = array_values(array_filter(array_map(fn($p) => clean_line($p, 120), preg_split('/\s*(?:›|>|\/)\s*/u', $v) ?: []), fn($p) => $p !== ''));
    if (!$parts) return null;
    if ($ex = tax_find('location', end($parts))) return (int)$ex['id'];
    if (!$create) throw new UserError('Unknown location “' . end($parts) . '”.');
    $parent = null;
    foreach ($parts as $p) {
        $r = tax_create('location', $p);
        if ($r['created']) {
            $made['location'][] = $r['name'];
            if ($parent !== null) location_set_parent($r['id'], $parent);
        }
        $parent = $r['id'];
    }
    return $parent;
}

/**
 * Run the import. $commit = false: everything happens inside a transaction that is rolled back (the preview).
 * @return array{rows: array<int,array>, created:int, updated:int, errors:int, skipped:int, made: array, committed: bool}
 */
function import_run(array $imp, bool $commit): array
{
    [$header, $rows] = import_rows($imp);
    $map = $imp['mapping'];
    $create = !empty($imp['options']['create']);
    $skip = !empty($imp['options']['skip_errors']);
    $col = array_flip(array_filter($map, fn($k) => $k !== ''));
    $pdo = db();
    $out = ['rows' => [], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'skipped' => 0, 'made' => ['location' => [], 'category' => [], 'tag' => []], 'committed' => false];
    $pdo->beginTransaction();
    try {
        $knownTags = array_flip(array_map('fold', $pdo->query('SELECT name FROM tags')->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($rows as $n => $r) {
            $get = fn(string $k): string => isset($col[$k]) ? trim((string)($r[$col[$k]] ?? '')) : '';
            $line = $n + 2;
            $pdo->exec('SAVEPOINT imp_row');
            try {
                $id = $get('id');
                $existing = null;
                if ($id !== '') {
                    if (!ctype_digit($id)) throw new UserError('ID must be a number.');
                    $existing = item_get((int)$id);   // unknown id: a new item
                }
                $name = clean_line($get('name'), 200);
                if ($name === '') throw new UserError('Name is empty.');
                $qtyRaw = $get('quantity');
                $qty = $qtyRaw === '' ? ($existing ? (int)$existing['quantity'] : 1) : filter_var($qtyRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
                if ($qty === false) throw new UserError('Quantity “' . $qtyRaw . '” is not a whole number from 0 to 1,000,000.');
                $keep = fn(string $k, mixed $fromExisting, callable $parse) => isset($col[$k]) ? $parse($get($k)) : ($existing ? $fromExisting : $parse(''));
                $in = [
                    'name' => $name, 'quantity' => $qty,
                    'notes' => $keep('notes', (string)($existing['notes'] ?? ''), fn($v) => mb_substr($v, 0, 5000)),
                    'value_cents' => $keep('value', $existing['value_cents'] ?? null, fn($v) => parse_money($v)),
                    'purchase_date' => $keep('purchase_date', $existing['purchase_date'] ?? null, fn($v) => parse_date_ymd($v)),
                    'expires_on' => $keep('expires_on', $existing['expires_on'] ?? null, fn($v) => parse_expiry_date($v)),
                    'serial' => $keep('serial', (string)($existing['serial'] ?? ''), fn($v) => clean_line($v, 100)),
                    'barcode' => $keep('barcode', (string)($existing['barcode'] ?? ''), fn($v) => barcode_clean($v)),
                    'min_qty' => $keep('min_qty', $existing['min_qty'] ?? null, fn($v) => parse_min_qty($v)),
                    'tags' => $keep('tags', array_column($existing['tags'] ?? [], 'name'), fn($v) => tags_parse($v)),
                ];
                foreach ($in['tags'] as $t) {
                    if (isset($knownTags[fold($t)])) continue;
                    if (!$create) throw new UserError('Unknown tag “' . $t . '”.');
                    $knownTags[fold($t)] = true;
                    $out['made']['tag'][] = $t;
                }
                $in['location_id'] = isset($col['location']) ? import_location($get('location'), $create, $out['made'])
                    : ($existing ? ($existing['location_id'] === null ? null : (int)$existing['location_id']) : null);
                if (isset($col['category'])) {
                    $c = clean_line($get('category'), 120);
                    if ($c === '') {
                        $in['category_id'] = null;
                    } elseif ($ex = tax_find('category', $c)) {
                        $in['category_id'] = (int)$ex['id'];
                    } elseif ($create) {
                        $made = tax_create('category', $c);
                        $in['category_id'] = $made['id'];
                        $out['made']['category'][] = $made['name'];
                    } else {
                        throw new UserError('Unknown category “' . $c . '”.');
                    }
                } else {
                    $in['category_id'] = $existing && $existing['category_id'] !== null ? (int)$existing['category_id'] : null;
                }
                $lentTo = clean_line($get('lent_to'), 100);
                $due = isset($col['lent_due']) ? parse_expiry_date($get('lent_due')) : null;
                $lend = $lentTo !== '' && $lentTo !== (string)($existing['lent_to'] ?? '');
                if ($existing && !$lend && import_same($existing, $in)) {
                    $state = 'same';      // nothing to change: no save, no history entry, updated_at kept
                    $out['unchanged']++;
                } else {
                    $savedId = item_save($existing, $in, null, false);
                    if ($lend) item_lend($savedId, $lentTo, null, $due);
                    $state = $existing ? 'update' : 'new';
                    $out[$existing ? 'updated' : 'created']++;
                }
                $pdo->exec('RELEASE SAVEPOINT imp_row');
                $msg = '';
            } catch (UserError $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT imp_row');
                $state = 'error';
                $msg = $e->getMessage();
                $out['errors']++;
                $name = $name ?? '';
            }
            $out['rows'][] = ['line' => $line, 'state' => $state, 'name' => $name ?? '', 'error' => $msg];
            unset($name);
        }
        foreach ($out['made'] as &$list) $list = array_values(array_unique($list));
        unset($list);
        if ($commit && ($out['errors'] === 0 || $skip)) {
            $out['skipped'] = $out['errors'];
            $pdo->commit();
            $out['committed'] = true;
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $out;
}

/** Would saving $in change nothing on $existing? */
function import_same(array $existing, array $in): bool
{
    $norm = fn($v) => $v === null ? '' : (string)$v;
    foreach (['name', 'quantity', 'notes', 'value_cents', 'purchase_date', 'expires_on', 'serial', 'min_qty', 'location_id', 'category_id'] as $k) {
        if ($norm($existing[$k] ?? null) !== $norm($in[$k] ?? null)) return false;
    }
    if ($norm($existing['barcode'] ?? null) !== barcode_clean((string)$in['barcode'])) return false;
    $t = fn(array $l) => (function ($a) { sort($a); return $a; })(array_map('fold', $l));
    return $t(array_column($existing['tags'] ?? [], 'name')) === $t($in['tags']);
}

/** The real import (after a preview). Records the result; deletes the stored file. */
function import_commit(array $imp): array
{
    if ($imp['status'] === 'done') throw new UserError('This file was imported already.', 409);
    $r = import_run($imp, true);
    if (!$r['committed']) throw new UserError($r['errors'] . ' ' . ($r['errors'] === 1 ? 'row has' : 'rows have') . ' errors. Fix the file, or tick “Skip rows with errors”.', 422);
    $summary = ['created' => $r['created'], 'updated' => $r['updated'], 'unchanged' => $r['unchanged'], 'skipped' => $r['skipped'], 'made' => $r['made']];
    db()->prepare("UPDATE imports SET status = 'done', finished_at = ?, result = ? WHERE id = ?")->execute([now_utc(), json_encode($summary, JSON_UNESCAPED_UNICODE), $imp['id']]);
    @unlink(import_file($imp['id']));
    return $summary;
}

function import_summary_text(array $s): string
{
    $p = [];
    $p[] = $s['created'] . ' added';
    $p[] = $s['updated'] . ' updated';
    if (!empty($s['unchanged'])) $p[] = $s['unchanged'] . ' unchanged';
    if (!empty($s['skipped'])) $p[] = $s['skipped'] . ' skipped';
    $m = $s['made'] ?? [];
    $made = array_filter(['location' => count($m['location'] ?? []), 'category' => count($m['category'] ?? []), 'tag' => count($m['tag'] ?? [])]);
    foreach ($made as $k => $n) $p[] = $n . ' new ' . ($k === 'category' ? ($n === 1 ? 'category' : 'categories') : $k . ($n === 1 ? '' : 's'));
    return implode(' · ', $p);
}
