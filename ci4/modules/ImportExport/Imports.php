<?php

declare(strict_types=1);

namespace Apotheke\ImportExport;

use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\Text;
use Apotheke\Core\Storage\Storage;
use Apotheke\Lending\Loans;
use Apotheke\Taxonomy\TaxonomyRepository;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * CSV import (legacy src/import.php): upload → map columns → preview (a dry
 * run inside a transaction that is rolled back, so it shows exactly what the
 * real run would do, every validation error included) → import (the same run,
 * committed in one transaction). The files of the CSV export import as they
 * are: same headers, "id" updates the item with that id. The upload waits in
 * DATA_DIR/tmp/import-<id>.csv; the `imports` table keeps the history.
 *
 * A run takes the import's row first, so on SQLite it holds the write lock
 * before its first read, and two imports of one file run one after the
 * other. The import is marked done in the transaction that imports it, so a
 * second submit of the same file finds it done and imports nothing.
 *
 * @phpstan-type Import array{id:int,file_name:string,status:string,total_rows:int,mapping:array<int|string,mixed>,options:array<string,mixed>,result:?array<string,mixed>,created_at:string,finished_at:?string,actor:?string}
 * @phpstan-type Made array{location:list<string>,category:list<string>,tag:list<string>}
 * @phpstan-type Run array{rows:list<array{line:int,state:string,name:string,error:string}>,created:int,updated:int,unchanged:int,errors:int,skipped:int,made:Made,committed:bool,done_before:bool}
 */
final class Imports
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const PREVIEW_ROWS = 50;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param Storage $files DATA_DIR
     * @param Closure(): ?string $actor legacy current_actor()
     * @param bool $createMissing the import_create_missing setting
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly Calendar $calendar,
        private readonly Storage $files,
        private readonly ItemRepository $items,
        private readonly TaxonomyRepository $taxonomy,
        private readonly Loans $loans,
        private readonly Closure $actor,
        private readonly bool $createMissing,
    ) {
    }

    /**
     * Target fields: key => [label, aliases (folded header names that map automatically)].
     *
     * @return array<string,array{0:string,1:list<string>}>
     */
    public static function fields(): array
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

    private static function key(int $id): string
    {
        return 'tmp/import-' . $id . '.csv';
    }

    /**
     * Store an upload and register it (legacy import_upload()).
     *
     * @param array{error:int,size:int,tmp_name:string,name:string}|null $file null: none, or not one file
     */
    public function upload(?array $file): int
    {
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            throw new UserError('Choose a CSV file to import.', 422);
        }
        if ($file['size'] > self::MAX_BYTES) {
            throw new UserError('That file is larger than 5 MB.', 422);
        }
        $csv = (string)@file_get_contents($file['tmp_name']);
        if (str_contains(substr($csv, 0, 4096), "\0")) {
            throw new UserError('That does not look like a CSV file.', 422);
        }
        [$header, $rows] = CsvCells::parse($csv);
        $name = Text::cleanLine($file['name'], 120) ?: 'import.csv';
        Writes::check($this->db->table('imports')->insert([
            'file_name' => $name, 'status' => 'uploaded', 'total_rows' => count($rows), 'mapping' => (string)json_encode(self::autoMap($header)),
            'options' => (string)json_encode(['create' => $this->createMissing, 'skip_errors' => false]), 'created_at' => $this->clock->now(), 'actor' => ($this->actor)(),
        ]), 'add import');
        $id = (int)$this->db->insertID();
        $this->files->write(self::key($id), $csv);

        return $id;
    }

    /**
     * Column index => field key ('' = ignore), guessed from the header names.
     *
     * @param list<string> $header
     * @return list<string>
     */
    public static function autoMap(array $header): array
    {
        $map = [];
        $used = [];
        foreach ($header as $h) {
            $k = '';
            $f = Text::fold(trim($h));
            foreach (self::fields() as $key => [, $aliases]) {
                if (!isset($used[$key]) && in_array($f, $aliases, true)) {
                    $k = $key;
                    break;
                }
            }
            if ($k !== '') {
                $used[$k] = true;
            }
            $map[] = $k;
        }

        return $map;
    }

    /** @return Import|null */
    public function get(int $id): ?array
    {
        $r = Writes::rows($this->db, 'SELECT * FROM imports WHERE id = ?', [$id])[0] ?? null;
        if ($r === null) {
            return null;
        }
        $json = static fn (mixed $v): mixed => json_decode(is_scalar($v) ? (string)$v : '', true);
        $mapping = $json($r['mapping']);
        $options = $json($r['options']);
        $result = $r['result'] ? $json($r['result']) : null;

        /** @var Import */
        return [
            'id' => (int)$r['id'], 'file_name' => (string)$r['file_name'], 'status' => (string)$r['status'], 'total_rows' => (int)$r['total_rows'],
            'mapping' => is_array($mapping) ? $mapping : [], 'options' => is_array($options) ? $options : [], 'result' => is_array($result) ? $result : null,
            'created_at' => (string)$r['created_at'], 'finished_at' => $r['finished_at'] === null ? null : (string)$r['finished_at'], 'actor' => $r['actor'] === null ? null : (string)$r['actor'],
        ];
    }

    /** @return list<array<string,mixed>> the latest uploads, newest first */
    public function recent(int $n = 10): array
    {
        return Writes::rows($this->db, 'SELECT id, file_name, status, total_rows, created_at, finished_at, actor, result FROM imports ORDER BY id DESC LIMIT ' . $n);
    }

    /**
     * The stored file of an import.
     *
     * @param array{id:int} $imp
     * @return array{0:list<string>,1:list<list<string>>} header, rows
     */
    public function rows(array $imp): array
    {
        $csv = $this->files->read(self::key($imp['id']));
        if ($csv === null) {
            throw new UserError('The uploaded file is gone – upload it again.', 410);
        }
        [$header, $rows] = CsvCells::parse($csv);

        return [$header, $rows];
    }

    /**
     * Save the mapping and options chosen in the form.
     *
     * @param list<string> $header
     * @param array<string,mixed> $post
     * @return array{0:list<string>,1:array{create:bool,skip_errors:bool}}
     */
    public function setMapping(int $id, array $header, array $post): array
    {
        $fields = self::fields();
        $cols = $post['col'] ?? null;
        $map = [];
        $seen = [];
        foreach (array_keys($header) as $i) {
            // Legacy (string)($post['col'][$i] ?? ''): a string "col" gives one character, never a field.
            $v = is_array($cols) ? ($cols[$i] ?? '') : (is_string($cols) ? ($cols[$i] ?? '') : '');
            $k = is_scalar($v) ? (string)$v : 'Array';
            if ($k !== '' && !isset($fields[$k])) {
                $k = '';
            }
            if ($k !== '' && isset($seen[$k])) {
                throw new UserError('“' . $fields[$k][0] . '” is chosen for two columns.', 422);
            }
            if ($k !== '') {
                $seen[$k] = true;
            }
            $map[] = $k;
        }
        if (!isset($seen['name'])) {
            throw new UserError('Choose which column holds the item name.', 422);
        }
        $opt = ['create' => !empty($post['create']), 'skip_errors' => !empty($post['skip_errors'])];
        Writes::check($this->db->query('UPDATE imports SET mapping = ?, options = ? WHERE id = ?', [(string)json_encode($map), (string)json_encode($opt), $id]), 'save mapping');

        return [$map, $opt];
    }

    /**
     * A location by name or path ("Garage › Shelf 2", also "Garage > Shelf 2"
     * or "Garage / Shelf 2"); creates it when allowed.
     *
     * @param Made $made
     */
    private function location(string $v, bool $create, array &$made): ?int
    {
        $parts = array_values(array_filter(array_map(static fn (string $p): string => Text::cleanLine($p, 120), preg_split('/\s*(?:›|>|\/)\s*/u', $v) ?: []), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return null;
        }
        $last = $parts[count($parts) - 1];
        $ex = $this->taxonomy->find('location', $last);
        if ($ex !== null) {
            return $ex['id'];
        }
        if (!$create) {
            throw new UserError('Unknown location “' . $last . '”.');
        }
        $parent = null;
        foreach ($parts as $p) {
            $r = $this->taxonomy->create('location', $p);
            if ($r['created']) {
                $made['location'][] = $r['name'];
                if ($parent !== null) {
                    $this->taxonomy->setParent($r['id'], $parent);
                }
            }
            $parent = $r['id'];
        }

        return $parent;
    }

    /**
     * Run the import (legacy import_run()). $commit false: everything happens
     * inside a transaction that is rolled back (the preview). A commit marks
     * the import done in the same transaction; done_before says another run
     * did that first (nothing is imported or previewed; N-373).
     *
     * @param Import $imp
     * @return Run
     */
    public function run(array $imp, bool $commit): array
    {
        $map = $imp['mapping'];
        $create = !empty($imp['options']['create']);
        $skip = !empty($imp['options']['skip_errors']);
        $col = [];
        foreach ($map as $i => $k) {
            if (is_string($k) && $k !== '') {
                $col[$k] = (int)$i;
            }
        }
        $out = ['rows' => [], 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'skipped' => 0, 'made' => ['location' => [], 'category' => [], 'tag' => []], 'committed' => false, 'done_before' => false];
        $db = $this->db;
        $db->resetTransStatus();
        if (!$db->transBegin()) {
            throw new WriteFailed('Database write failed: begin');
        }
        try {
            $this->hold($imp['id']);
            // A preview or a submit that waited for another submit finds the import done (and its file gone).
            if ((Writes::rows($db, 'SELECT status FROM imports WHERE id = ?', [$imp['id']])[0]['status'] ?? '') === 'done') {
                $out['done_before'] = true;
                $db->transRollback();

                return $out;
            }
            // Read after the lock and the status: the file is the one this run may import.
            [, $rows] = $this->rows($imp);
            $knownTags = [];
            foreach (Writes::rows($db, 'SELECT name FROM tags') as $t) {
                $knownTags[Text::fold((string)$t['name'])] = true;
            }
            $tomorrow = $this->calendar->now()->modify('+86400 seconds')->format('Y-m-d');
            foreach ($rows as $n => $r) {
                $get = static fn (string $k): string => isset($col[$k]) ? trim($r[$col[$k]] ?? '') : '';
                $line = $n + 2;
                $name = '';
                Writes::check($db->query('SAVEPOINT imp_row'), 'savepoint');
                try {
                    $id = $get('id');
                    $existing = null;
                    if ($id !== '') {
                        if (!ctype_digit($id)) {
                            throw new UserError('ID must be a number.');
                        }
                        $existing = $this->items->get((int)$id);   // unknown id: a new item
                    }
                    $name = Text::cleanLine($get('name'), 200);
                    if ($name === '') {
                        throw new UserError('Name is empty.');
                    }
                    $qtyRaw = $get('quantity');
                    $qty = $qtyRaw === '' ? ($existing !== null ? (int)$existing['quantity'] : 1) : filter_var($qtyRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
                    if ($qty === false) {
                        throw new UserError('Quantity “' . $qtyRaw . '” is not a whole number from 0 to 1,000,000.');
                    }
                    $keep = static fn (string $k, mixed $fromExisting, callable $parse): mixed => isset($col[$k]) ? $parse($get($k)) : ($existing !== null ? $fromExisting : $parse(''));
                    $in = [
                        'name' => $name, 'quantity' => $qty,
                        'notes' => $keep('notes', (string)($existing['notes'] ?? ''), static fn (string $v): string => mb_substr($v, 0, 5000)),
                        'value_cents' => $keep('value', $existing['value_cents'] ?? null, static fn (string $v): ?int => ItemInput::money($v)),
                        'purchase_date' => $keep('purchase_date', $existing['purchase_date'] ?? null, static fn (string $v): ?string => ItemInput::purchaseDate($v, $tomorrow)),
                        'expires_on' => $keep('expires_on', $existing['expires_on'] ?? null, static fn (string $v): ?string => ItemInput::expiryDate($v)),
                        'serial' => $keep('serial', (string)($existing['serial'] ?? ''), static fn (string $v): string => Text::cleanLine($v, 100)),
                        'barcode' => $keep('barcode', (string)($existing['barcode'] ?? ''), static fn (string $v): string => ItemInput::barcode($v)),
                        'min_qty' => $keep('min_qty', $existing['min_qty'] ?? null, static fn (string $v): ?int => ItemInput::minQty($v)),
                        'tags' => $keep('tags', array_column($existing['tags'] ?? [], 'name'), static fn (string $v): array => Text::tags($v)),
                    ];
                    foreach ($in['tags'] as $t) {
                        if (isset($knownTags[Text::fold($t)])) {
                            continue;
                        }
                        if (!$create) {
                            throw new UserError('Unknown tag “' . $t . '”.');
                        }
                        $knownTags[Text::fold($t)] = true;
                        $out['made']['tag'][] = $t;
                    }
                    $in['location_id'] = isset($col['location']) ? $this->location($get('location'), $create, $out['made'])
                        : ($existing !== null && $existing['location_id'] !== null ? (int)$existing['location_id'] : null);
                    if (isset($col['category'])) {
                        $c = Text::cleanLine($get('category'), 120);
                        $ex = $c === '' ? null : $this->taxonomy->find('category', $c);
                        if ($c === '') {
                            $in['category_id'] = null;
                        } elseif ($ex !== null) {
                            $in['category_id'] = $ex['id'];
                        } elseif ($create) {
                            $made = $this->taxonomy->create('category', $c);
                            $in['category_id'] = $made['id'];
                            $out['made']['category'][] = $made['name'];
                        } else {
                            throw new UserError('Unknown category “' . $c . '”.');
                        }
                    } else {
                        $in['category_id'] = $existing !== null && $existing['category_id'] !== null ? (int)$existing['category_id'] : null;
                    }
                    $lentTo = Text::cleanLine($get('lent_to'), 100);
                    $due = isset($col['lent_due']) ? ItemInput::expiryDate($get('lent_due')) : null;
                    $lend = $lentTo !== '' && $lentTo !== (string)($existing['lent_to'] ?? '');
                    if ($existing !== null && !$lend && self::same($existing, $in)) {
                        $state = 'same';      // nothing to change: no save, no history entry, updated_at kept
                        $out['unchanged']++;
                    } else {
                        /** @var array{name:string,location_id:?int,category_id:?int,quantity:int,notes:string,tags:list<string>,value_cents:?int,purchase_date:?string,expires_on:?string,serial:string,barcode:string,min_qty:?int} $in */
                        $savedId = $this->items->save($existing, $in, null, false);
                        if ($lend) {
                            $this->loans->lend($savedId, $lentTo, null, $due);
                        }
                        $state = $existing !== null ? 'update' : 'new';
                        $out[$existing !== null ? 'updated' : 'created']++;
                    }
                    Writes::check($db->query('RELEASE SAVEPOINT imp_row'), 'savepoint');
                    $msg = '';
                } catch (UserError $e) {
                    Writes::check($db->query('ROLLBACK TO SAVEPOINT imp_row'), 'savepoint');
                    $state = 'error';
                    $msg = $e->getMessage();
                    $out['errors']++;
                }
                $out['rows'][] = ['line' => $line, 'state' => $state, 'name' => $name, 'error' => $msg];
            }
            foreach ($out['made'] as $k => $list) {
                $out['made'][$k] = array_values(array_unique($list));
            }
            if ($commit && ($out['errors'] === 0 || $skip)) {
                $out['skipped'] = $out['errors'];
                $summary = ['created' => $out['created'], 'updated' => $out['updated'], 'unchanged' => $out['unchanged'], 'skipped' => $out['skipped'], 'made' => $out['made']];
                Writes::check($db->query("UPDATE imports SET status = 'done', finished_at = ?, result = ? WHERE id = ?", [$this->clock->now(), (string)json_encode($summary, JSON_UNESCAPED_UNICODE), $imp['id']]), 'finish import');
                if (!$db->transStatus()) {
                    throw new WriteFailed('Database write failed: a statement in the transaction');
                }
                try {
                    $committed = $db->transCommit();
                } catch (Throwable) {
                    $committed = false;
                }
                if (!$committed) {
                    throw new WriteFailed('Database write failed: commit');
                }
                $out['committed'] = true;
            } else {
                $db->transRollback();
                $db->resetTransStatus();
            }
        } catch (Throwable $e) {
            if ((int)$db->transDepth > 0) {
                $db->transRollback();
            }
            $db->resetTransStatus();
            throw $e;
        }

        return $out;
    }

    /**
     * Inside the run's transaction, before its first read: the import's row
     * locked for it (as Loans and UserRepository do). PostgreSQL locks it
     * without writing; SQLite takes its one write lock with a no-op write.
     */
    private function hold(int $id): void
    {
        if ($this->db->DBDriver === 'Postgre') {
            Writes::rows($this->db, 'SELECT id FROM imports WHERE id = ? FOR UPDATE', [$id]);

            return;
        }
        Writes::check($this->db->query('UPDATE imports SET status = status WHERE id = ?', [$id]), 'lock import');
    }

    /**
     * Would saving $in change nothing on $existing?
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $in
     */
    private static function same(array $existing, array $in): bool
    {
        $norm = static fn (mixed $v): string => is_scalar($v) ? (string)$v : '';
        foreach (['name', 'quantity', 'notes', 'value_cents', 'purchase_date', 'expires_on', 'serial', 'min_qty', 'location_id', 'category_id'] as $k) {
            if ($norm($existing[$k] ?? null) !== $norm($in[$k] ?? null)) {
                return false;
            }
        }
        if ($norm($existing['barcode'] ?? null) !== ItemInput::barcode($norm($in['barcode']))) {
            return false;
        }
        $t = static function (array $l): array {
            $a = array_map(static fn (mixed $v): string => Text::fold(is_scalar($v) ? (string)$v : ''), $l);
            sort($a);

            return $a;
        };
        $have = is_array($existing['tags'] ?? null) ? array_column($existing['tags'], 'name') : [];

        return $t($have) === $t(is_array($in['tags']) ? $in['tags'] : []);
    }

    /**
     * The real import, after a preview (legacy import_commit()). Records the
     * result and deletes the stored file. Null: another submit imported it first.
     *
     * @param Import $imp
     * @return array<string,mixed>|null
     */
    public function commit(array $imp): ?array
    {
        if ($imp['status'] === 'done') {
            throw new UserError('This file was imported already.', 409);
        }
        $r = $this->run($imp, true);
        if ($r['done_before']) {
            return null;
        }
        if (!$r['committed']) {
            throw new UserError($r['errors'] . ' ' . ($r['errors'] === 1 ? 'row has' : 'rows have') . ' errors. Fix the file, or tick “Skip rows with errors”.', 422);
        }
        $this->files->delete(self::key($imp['id']));

        return ['created' => $r['created'], 'updated' => $r['updated'], 'unchanged' => $r['unchanged'], 'skipped' => $r['skipped'], 'made' => $r['made']];
    }

    /** @param array<string,mixed> $s */
    public static function summaryText(array $s): string
    {
        $int = static fn (mixed $v): int => is_scalar($v) ? (int)$v : 0;
        $p = [];
        $p[] = $int($s['created'] ?? 0) . ' added';
        $p[] = $int($s['updated'] ?? 0) . ' updated';
        if (!empty($s['unchanged'])) {
            $p[] = $int($s['unchanged']) . ' unchanged';
        }
        if (!empty($s['skipped'])) {
            $p[] = $int($s['skipped']) . ' skipped';
        }
        $m = is_array($s['made'] ?? null) ? $s['made'] : [];
        $count = static fn (mixed $l): int => is_array($l) ? count($l) : 0;
        $made = array_filter(['location' => $count($m['location'] ?? []), 'category' => $count($m['category'] ?? []), 'tag' => $count($m['tag'] ?? [])]);
        foreach ($made as $k => $n) {
            $p[] = $n . ' new ' . ($k === 'category' ? ($n === 1 ? 'category' : 'categories') : $k . ($n === 1 ? '' : 's'));
        }

        return implode(' · ', $p);
    }
}
