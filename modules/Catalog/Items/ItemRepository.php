<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\ReadCache;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Core\Libraries\Text;
use Apotheke\Core\Storage\Storage;
use Apotheke\Media\ImageStore;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;

/**
 * One item, its photos and attachments (legacy item_get(), photos_for(),
 * attachments_for(), item_save() and item_delete()). Writes run in one unit
 * of work with their history (ADR-002); files are removed only after the
 * unit commits.
 */
final class ItemRepository
{
    private const COLS = 'i.id, i.name, i.location_id, l.name AS location, i.category_id, c.name AS category, i.quantity, i.min_qty, i.expires_on, i.notes, i.image, i.value_cents, i.lent_to, i.lent_at, i.lent_due, i.purchase_date, i.serial, i.barcode, i.favourite, i.created_at, i.updated_at';

    private const FROM = 'FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id';

    private readonly UnitsOfWork $units;

    private readonly ReadCache $cache;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
        private readonly TaxonomyRepository $taxonomy,
        private readonly TagRepository $tags,
        private readonly ImageStore $images,
        private readonly Storage $files,
        private readonly PhotoRepository $photoRows,
        ?UnitsOfWork $units = null,
        ?ReadCache $cache = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
        $this->cache = $cache ?? ReadCache::off();
    }

    /** @var list<string> files a save inside atomically() gives up; removed once the outer transaction has committed (N-126) */
    private array $dropAfterCommit = [];

    /** @return array<string,mixed>|null legacy item_get(): the row with names, typed numbers and tags */
    public function get(int $id): ?array
    {
        // Remembered for the request until a write to one of the tables it reads (never inside a transaction).
        return $this->cache->remember($this->db, 'items.get.' . $id, ['items', 'item_tags', 'tags', 'locations', 'categories'],
            fn (): ?array => $this->read($id), static fn (?array $row): bool => $row !== null);
    }

    /** @return array<string,mixed>|null */
    private function read(int $id): ?array
    {
        $item = ['SELECT ' . self::COLS . ' ' . self::FROM . ' WHERE i.id = ?', [$id]];
        $tags = null;
        if (Writes::batches($this->db)) {
            // The row and its tags in one round trip (Writes::rowsMany()).
            [$rows, $tags] = Writes::rowsMany($this->db, [$item, TagRepository::forItemQuery($id)]);
        } else {
            $rows = $this->rows(...$item);
        }
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['location_id'] = $row['location_id'] === null ? null : (int)$row['location_id'];
        $row['category_id'] = $row['category_id'] === null ? null : (int)$row['category_id'];
        $row['quantity'] = (int)$row['quantity'];
        $row['location'] = (string)($row['location'] ?? '');
        $row['category'] = (string)($row['category'] ?? '');
        $row['notes'] = (string)$row['notes'];
        $row['value_cents'] = $row['value_cents'] === null ? null : (int)$row['value_cents'];
        $row['serial'] = (string)($row['serial'] ?? '');
        $row['barcode'] = (string)($row['barcode'] ?? '');
        $row['min_qty'] = $row['min_qty'] === null ? null : (int)$row['min_qty'];
        $row['favourite'] = (int)$row['favourite'];
        $row['tags'] = $tags !== null ? TagRepository::forItemRows($tags) : $this->tags->forItem($id);

        return $row;
    }

    /** @return list<array{id:int,file:string,position:int}> cover first */
    public function photos(int $itemId): array
    {
        return $this->photoRows->list($itemId);
    }

    /** @return list<array{id:int,name:string,mime:string,size:int,file:string}> */
    public function attachments(int $itemId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'mime' => (string)$r['mime'], 'size' => (int)$r['size'], 'file' => (string)$r['file']],
            $this->rows('SELECT id, file, name, mime, size FROM item_attachments WHERE item_id = ? ORDER BY id', [$itemId]));
    }

    /**
     * Create or update an item (legacy item_save()). $newImage replaces the
     * cover photo; $removeImage removes it (the next photo becomes the cover).
     *
     * @param array<string,mixed>|null $existing
     * @param array{name:string,location_id:?int,category_id:?int,quantity:int,notes:string,tags?:list<string>,value_cents?:?int,purchase_date?:?string,expires_on?:?string,serial?:string,barcode?:string,min_qty?:?int,copied_from?:?string,reason?:?string} $in
     */
    public function save(?array $existing, array $in, ?string $newImage, bool $removeImage): int
    {
        $result = $this->saveRef($existing, $in, $newImage, $removeImage);

        return $result instanceof Ref ? $result->id() : $result;
    }

    /**
     * save(), but a new item saved inside a caller's unit of work answers its Ref (the id is known once that unit
     * ran), so the caller's later steps in the same unit can refer to it (a CSV import's row on the hosted database).
     *
     * @param array<string,mixed>|null $existing
     * @param array{name:string,location_id:?int,category_id:?int,quantity:int,notes:string,tags?:list<string>,value_cents?:?int,purchase_date?:?string,expires_on?:?string,serial?:string,barcode?:string,min_qty?:?int,copied_from?:?string,reason?:?string} $in
     */
    public function saveRef(?array $existing, array $in, ?string $newImage, bool $removeImage): int|Ref
    {
        $in += ['tags' => [], 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => '', 'min_qty' => null, 'copied_from' => null, 'reason' => null];
        $in['barcode'] = ItemInput::barcode((string)$in['barcode']);
        $now = $this->clock->now();
        $blob = Text::fold(implode("\n", array_merge([$in['name'], $in['notes'], $in['serial'], $in['barcode']], $in['tags'])));
        $cols = ['name' => $in['name'], 'location_id' => $in['location_id'], 'category_id' => $in['category_id'], 'quantity' => $in['quantity'], 'min_qty' => $in['min_qty'],
            'notes' => $in['notes'], 'value_cents' => $in['value_cents'], 'purchase_date' => $in['purchase_date'], 'expires_on' => $in['expires_on'], 'serial' => $in['serial'],
            'barcode' => $in['barcode'] === '' ? null : $in['barcode'], 'search' => $blob, 'sort_key' => Text::natkey($in['name'])];
        $drop = [];
        $key = $existing === null ? '' : (string)(int)$existing['id'];
        // One unit of work (ADR-002, Ref + guards): tags and the cover photo join it; history is queued.
        $result = $this->units->run('items', function (UnitOfWork $uow) use ($existing, $in, $newImage, $removeImage, $now, $cols, &$drop): int|Ref {
            if ($existing === null) {
                $ref = $uow->insert('items', $cols + ['image' => $newImage, 'created_at' => $now, 'updated_at' => $now], 'add item');
                $this->tags->set($ref, $in['tags'], $uow);
                if ($newImage !== null) {
                    $uow->insert('item_photos', ['item_id' => $ref, 'file' => $newImage, 'position' => 0, 'created_at' => $now], 'add cover');
                }
                $this->history->queue($uow, $ref, $in['name'], 'created', array_filter([
                    'location' => $in['location_id'] !== null ? (string)($this->taxonomy->get('location', $in['location_id'])['name'] ?? '') : null,
                    'category' => $in['category_id'] !== null ? (string)($this->taxonomy->get('category', $in['category_id'])['name'] ?? '') : null,
                    'quantity' => $in['quantity'], 'min_qty' => $in['min_qty'], 'photo' => $newImage !== null ? true : null, 'tags' => $in['tags'] !== [] ? $in['tags'] : null,
                    'copied_from' => $in['copied_from'],
                ], static fn ($v): bool => $v !== null));

                return $ref;
            }
            $id = (int)$existing['id'];
            $uow->assert('EXISTS (SELECT 1 FROM items WHERE id = ?)', [$id], 'item still there',
                static fn (): UserError => new UserError('Item not found.', 404));
            $old = $this->get($id) ?? $existing;
            $nameOf = fn (string $k, ?int $v): string => $v === null ? '' : (string)($this->taxonomy->get($k, $v)['name'] ?? '');
            $money = static fn (?int $c): string => $c === null ? '' : number_format($c / 100, 2, '.', '');
            $cmp = [
                'name' => [$old['name'], $in['name']],
                'category' => [(string)$old['category'], $nameOf('category', $in['category_id'])],
                'notes' => [self::short((string)$old['notes']), self::short($in['notes'])],
                'value' => [$money($old['value_cents'] ?? null), $money($in['value_cents'])],
                'purchase_date' => [(string)($old['purchase_date'] ?? ''), (string)($in['purchase_date'] ?? '')],
                'expires_on' => [(string)($old['expires_on'] ?? ''), (string)($in['expires_on'] ?? '')],
                'serial' => [(string)($old['serial'] ?? ''), $in['serial']],
                'barcode' => [(string)($old['barcode'] ?? ''), $in['barcode']],
                'tags' => [implode(', ', array_column($old['tags'] ?? [], 'name')), implode(', ', $in['tags'])],
                'min_qty' => [($old['min_qty'] ?? null) === null ? '' : (string)$old['min_qty'], $in['min_qty'] === null ? '' : (string)$in['min_qty']],
            ];
            $changes = [];
            foreach ($cmp as $k => [$a, $b]) {
                if ($a !== $b) {
                    $changes[$k] = [$a, $b];
                }
            }
            $reason = array_filter(['reason' => $in['reason']]);
            if ($changes !== []) {
                $this->history->queue($uow, $id, $in['name'], 'edited', ['changes' => $changes] + $reason);
            }
            if ((int)$old['location_id'] !== (int)$in['location_id'] || ($old['location_id'] === null) !== ($in['location_id'] === null)) {
                $this->history->queue($uow, $id, $in['name'], 'moved', ['from' => (string)$old['location'], 'to' => $nameOf('location', $in['location_id'])] + $reason);
            }
            if ((int)$old['quantity'] !== $in['quantity']) {
                $this->history->queue($uow, $id, $in['name'], 'quantity', ['from' => (int)$old['quantity'], 'to' => $in['quantity']]);
            }
            if ($newImage !== null || ($removeImage && $old['image'] !== null)) {
                $this->history->queue($uow, $id, $in['name'], 'photo', ['change' => $newImage !== null ? ($old['image'] !== null ? 'replaced' : 'added') : 'removed']);
            }
            $uow->update('items', $cols + ['updated_at' => $now], ['id' => $id], 'update item');
            $this->tags->set($id, $in['tags'], $uow);
            if ($newImage !== null || $removeImage) {
                $cover = $this->photos($id)[0] ?? null;
                if ($cover !== null) {
                    $uow->write('DELETE FROM item_photos WHERE id = ?', [$cover['id']], 'remove cover');
                    $drop[] = $cover['file'];
                }
                if ($newImage !== null) {
                    $uow->insert('item_photos', ['item_id' => $id, 'file' => $newImage, 'position' => -1, 'created_at' => $now], 'add cover');
                }
                // Renumber remaining photos 0..n-1 and mirror the cover into items.image (as PhotoRepository::renumber).
                $rest = array_values(array_filter($this->photos($id), static fn (array $p): bool => $cover === null || (int)$p['id'] !== (int)$cover['id']));
                if ($newImage !== null) {
                    $uow->write('UPDATE item_photos SET position = 0 WHERE item_id = ? AND file = ?', [$id, $newImage], 'cover position');
                    foreach ($rest as $i => $p) {
                        $uow->write('UPDATE item_photos SET position = ? WHERE id = ? AND item_id = ?', [$i + 1, $p['id'], $id], 'photo order');
                    }
                    $uow->update('items', ['image' => $newImage], ['id' => $id], 'cover photo');
                } else {
                    foreach ($rest as $i => $p) {
                        $uow->write('UPDATE item_photos SET position = ? WHERE id = ? AND item_id = ?', [$i, $p['id'], $id], 'photo order');
                    }
                    $uow->update('items', ['image' => $rest[0]['file'] ?? null], ['id' => $id], 'cover photo');
                }
            }

            return $id;
        }, $key);
        if ($this->units->isOpen() || (int)$this->db->transDepth > 0) {
            array_push($this->dropAfterCommit, ...$drop);   // inside atomically(): not before its commit
        } else {
            foreach ($drop as $f) {
                $this->images->delete($f);
            }
        }

        return $result;
    }

    /**
     * Run $fn in one checked transaction (Writes::atomic()), so a
     * caller can make its own writes part of a save. A unit of work opened
     * inside joins it (ADR-002). On the hosted database (D1) $fn may run one
     * unit of work (one save) and nothing else, so it is still one batch.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function atomically(callable $fn): mixed
    {
        $outer = (int)$this->db->transDepth === 0;
        try {
            $r = Writes::atomic($this->db, $fn);
        } finally {
            $drop = $outer ? $this->dropAfterCommit : [];
            if ($outer) {
                $this->dropAfterCommit = [];
            }
        }
        foreach ($drop as $f) {
            $this->images->delete($f);
        }

        return $r;
    }

    /**
     * Run $fn in this repository's unit of work on items: a save() inside joins it, so the caller's own steps and
     * the save are one batch (undo on the hosted database).
     *
     * @template T
     * @param callable(UnitOfWork):T $fn
     * @return T
     */
    public function inUnit(callable $fn): mixed
    {
        return $this->units->run('items', $fn);
    }

    /** Whether this repository writes to the hosted database (the D1 driver). */
    public function hosted(): bool
    {
        return $this->db instanceof \Apotheke\Core\Database\D1\Connection;
    }

    /**
     * Delete an item (legacy item_delete()). With $keepFiles its photo and
     * attachment files stay (an undo token owns them). False, with nothing
     * written or removed, when the item is already gone (L-1667: a
     * concurrent delete of the same item wrote it first).
     *
     * @param array<string,mixed> $item
     */
    public function delete(array $item, bool $keepFiles = false): bool
    {
        $id = (int)$item['id'];
        $files = array_column($this->photos($id), 'file');
        $att = array_column($this->attachments($id), 'file');
        $gone = new UserError('Item not found.', 404);
        try {
            // One unit of work per item (ADR-002, batch): the history row, the item (its rows cascade) and unused tags.
            $deleted = $this->units->run('items', function (UnitOfWork $uow) use ($item, $id, $gone): bool {
                if ($this->rows('SELECT 1 AS ok FROM items WHERE id = ?', [$id]) === []) {
                    return false;   // re-read under the unit's lock: deleted already
                }
                $uow->assert('EXISTS (SELECT 1 FROM items WHERE id = ?)', [$id], 'item still there', static fn (): UserError => $gone);   // the batch's re-check
                $this->history->queue($uow, $id, (string)$item['name'], 'deleted', array_filter(['location' => (string)($item['location'] ?? ''), 'quantity' => (int)$item['quantity']], static fn ($v): bool => $v !== ''));
                $uow->write('DELETE FROM items WHERE id = ?', [$id], 'delete item');
                $uow->write('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)', [], 'drop unused tags');

                return true;
            }, (string)$id);
        } catch (UserError $e) {
            if ($e !== $gone) {
                throw $e;
            }
            $deleted = false;
        }
        if (!$deleted || $keepFiles) {
            return $deleted;
        }
        foreach (array_unique(array_merge($files, [$item['image']])) as $f) {
            $this->images->delete(is_string($f) ? $f : null);
        }
        foreach ($att as $f) {
            $this->deleteAttachmentFile($f);
        }

        return true;
    }

    /** Legacy attachment_file_delete(): only a stored attachment name. */
    public function deleteAttachmentFile(?string $file): void
    {
        if (is_string($file) && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/D', $file) === 1) {
            $this->files->delete('files/' . $file);
        }
    }

    /**
     * The item's fields as item_save() takes them (legacy item_input()).
     *
     * @param array<string,mixed> $it
     * @return array<string,mixed>
     */
    public static function input(array $it): array
    {
        return ['name' => (string)$it['name'], 'location_id' => $it['location_id'], 'category_id' => $it['category_id'], 'quantity' => (int)$it['quantity'],
            'min_qty' => $it['min_qty'], 'notes' => (string)$it['notes'], 'tags' => array_column($it['tags'], 'name'), 'value_cents' => $it['value_cents'],
            'purchase_date' => $it['purchase_date'], 'expires_on' => $it['expires_on'], 'serial' => (string)$it['serial'], 'barcode' => (string)$it['barcode']];
    }

    private static function short(string $s): string
    {
        return mb_strlen($s) > 200 ? mb_substr($s, 0, 199) . '…' : $s;
    }

    /**
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db, $sql, $binds);
    }
}
