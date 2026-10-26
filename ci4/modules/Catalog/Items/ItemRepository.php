<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Apotheke\Core\Database\Writes;
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
 * attachments_for(), item_save() and item_delete()). Every write runs in one
 * checked transaction with its history (Writes); files are removed only
 * after the commit.
 */
final class ItemRepository
{
    private const COLS = 'i.id, i.name, i.location_id, l.name AS location, i.category_id, c.name AS category, i.quantity, i.min_qty, i.expires_on, i.notes, i.image, i.value_cents, i.lent_to, i.lent_at, i.lent_due, i.purchase_date, i.serial, i.barcode, i.favourite, i.created_at, i.updated_at';

    private const FROM = 'FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id';

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
        private readonly TaxonomyRepository $taxonomy,
        private readonly TagRepository $tags,
        private readonly ImageStore $images,
        private readonly Storage $files,
        private readonly PhotoRepository $photoRows,
    ) {
    }

    /** @var list<string> files a save inside atomically() gives up; removed once the outer transaction has committed (N-126) */
    private array $dropAfterCommit = [];

    /** @return array<string,mixed>|null legacy item_get(): the row with names, typed numbers and tags */
    public function get(int $id): ?array
    {
        $row = $this->rows('SELECT ' . self::COLS . ' ' . self::FROM . ' WHERE i.id = ?', [$id])[0] ?? null;
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
        $row['tags'] = $this->tags->forItem($id);

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
        $in += ['tags' => [], 'value_cents' => null, 'purchase_date' => null, 'expires_on' => null, 'serial' => '', 'barcode' => '', 'min_qty' => null, 'copied_from' => null, 'reason' => null];
        $in['barcode'] = ItemInput::barcode((string)$in['barcode']);
        $now = $this->clock->now();
        $blob = Text::fold(implode("\n", array_merge([$in['name'], $in['notes'], $in['serial'], $in['barcode']], $in['tags'])));
        $cols = ['name' => $in['name'], 'location_id' => $in['location_id'], 'category_id' => $in['category_id'], 'quantity' => $in['quantity'], 'min_qty' => $in['min_qty'],
            'notes' => $in['notes'], 'value_cents' => $in['value_cents'], 'purchase_date' => $in['purchase_date'], 'expires_on' => $in['expires_on'], 'serial' => $in['serial'],
            'barcode' => $in['barcode'] === '' ? null : $in['barcode'], 'search' => $blob, 'sort_key' => Text::natkey($in['name'])];
        $drop = [];
        $id = Writes::transaction($this->db, function () use ($existing, $in, $newImage, $removeImage, $now, $cols, &$drop): int {
            if ($existing === null) {
                Writes::check($this->db->table('items')->insert($cols + ['image' => null, 'created_at' => $now, 'updated_at' => $now]), 'add item');
                $id = (int)$this->db->insertID();
                $this->tags->set($id, $in['tags']);
                if ($newImage !== null) {
                    $this->photoRows->insert($id, $newImage, true);
                }
                $this->history->log($id, $in['name'], 'created', array_filter([
                    'location' => $in['location_id'] !== null ? (string)($this->taxonomy->get('location', $in['location_id'])['name'] ?? '') : null,
                    'category' => $in['category_id'] !== null ? (string)($this->taxonomy->get('category', $in['category_id'])['name'] ?? '') : null,
                    'quantity' => $in['quantity'], 'min_qty' => $in['min_qty'], 'photo' => $newImage !== null ? true : null, 'tags' => $in['tags'] !== [] ? $in['tags'] : null,
                    'copied_from' => $in['copied_from'],
                ], static fn ($v): bool => $v !== null));

                return $id;
            }
            $id = (int)$existing['id'];
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
                $this->history->log($id, $in['name'], 'edited', ['changes' => $changes] + $reason);
            }
            if ((int)$old['location_id'] !== (int)$in['location_id'] || ($old['location_id'] === null) !== ($in['location_id'] === null)) {
                $this->history->log($id, $in['name'], 'moved', ['from' => (string)$old['location'], 'to' => $nameOf('location', $in['location_id'])] + $reason);
            }
            if ((int)$old['quantity'] !== $in['quantity']) {
                $this->history->log($id, $in['name'], 'quantity', ['from' => (int)$old['quantity'], 'to' => $in['quantity']]);
            }
            if ($newImage !== null || ($removeImage && $old['image'] !== null)) {
                $this->history->log($id, $in['name'], 'photo', ['change' => $newImage !== null ? ($old['image'] !== null ? 'replaced' : 'added') : 'removed']);
            }
            Writes::check($this->db->table('items')->where('id', $id)->update($cols + ['updated_at' => $now]), 'update item');
            $this->tags->set($id, $in['tags']);
            if ($newImage !== null || $removeImage) {
                $cover = $this->photos($id)[0] ?? null;
                if ($cover !== null) {
                    Writes::check($this->db->table('item_photos')->where('id', $cover['id'])->delete(), 'remove cover');
                    $drop[] = $cover['file'];
                }
                if ($newImage !== null) {
                    $this->photoRows->insert($id, $newImage, true);
                }
                $this->photoRows->renumber($id);
            }

            return $id;
        });
        if ((int)$this->db->transDepth > 0) {
            array_push($this->dropAfterCommit, ...$drop);   // inside atomically(): not before its commit
        } else {
            foreach ($drop as $f) {
                $this->images->delete($f);
            }
        }

        return $id;
    }

    /**
     * Run $fn in one checked transaction (Writes::transaction()), so a
     * caller can make its own writes part of a save.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function atomically(callable $fn): mixed
    {
        $outer = (int)$this->db->transDepth === 0;
        try {
            $r = Writes::transaction($this->db, $fn);
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
     * Delete an item (legacy item_delete()). With $keepFiles its photo and
     * attachment files stay (an undo token owns them).
     *
     * @param array<string,mixed> $item
     */
    public function delete(array $item, bool $keepFiles = false): void
    {
        $id = (int)$item['id'];
        $files = array_column($this->photos($id), 'file');
        $att = array_column($this->attachments($id), 'file');
        Writes::transaction($this->db, function () use ($item, $id): void {
            $this->history->log($id, (string)$item['name'], 'deleted', array_filter(['location' => (string)($item['location'] ?? ''), 'quantity' => (int)$item['quantity']], static fn ($v): bool => $v !== ''));
            Writes::check($this->db->table('items')->where('id', $id)->delete(), 'delete item');
            Writes::check($this->db->query('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)'), 'drop unused tags');
        });
        if ($keepFiles) {
            return;
        }
        foreach (array_unique(array_merge($files, [$item['image']])) as $f) {
            $this->images->delete(is_string($f) ? $f : null);
        }
        foreach ($att as $f) {
            $this->deleteAttachmentFile($f);
        }
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
