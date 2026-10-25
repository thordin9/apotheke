<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge;

use Apotheke\Catalog\ItemList;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Core\Libraries\Text;
use Apotheke\Taxonomy\TagRepository;
use CodeIgniter\Database\BaseConnection;

/**
 * Merging duplicates (legacy src/merge.php): everything of the source moves
 * into the target in one transaction, then the source is gone. Its files now
 * belong to the target, so none are removed.
 */
final class MergeService
{
    public const QTY_MAX = 1000000;

    public const NOTES_MAX = 5000;

    private const FILLS = ['location_id' => 'Location', 'category_id' => 'Category', 'min_qty' => 'Running low at', 'value_cents' => 'Purchase price',
        'purchase_date' => 'Purchase date', 'expires_on' => 'Expiry / warranty', 'serial' => 'Serial number', 'barcode' => 'Barcode'];

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
        private readonly ItemRepository $items,
        private readonly TagRepository $tags,
        private readonly ItemList $list,
        private readonly string $defaultSort,
    ) {
    }

    /**
     * What a merge would produce, changing nothing (legacy merge_preview()).
     *
     * @return array{target:array<string,mixed>,source:array<string,mixed>,result:array<string,mixed>,fills:array<string,string>,photos:int,attachments:int,history:int,lending:string}
     */
    public function preview(int $sourceId, int $targetId): array
    {
        if ($sourceId === $targetId) {
            throw new UserError('Pick a different item to merge into.', 422);
        }
        $src = $this->items->get($sourceId);
        $dst = $this->items->get($targetId);
        if ($src === null || $dst === null) {
            throw new UserError('Item not found.', 404);
        }
        $in = ItemRepository::input($dst);
        $from = ItemRepository::input($src);
        $fills = [];
        $in['quantity'] = min(self::QTY_MAX, (int)$dst['quantity'] + (int)$src['quantity']);
        $empty = static fn (mixed $v): bool => $v === null || $v === '';
        foreach (self::FILLS as $k => $label) {
            if ($empty($in[$k]) && !$empty($from[$k])) {
                $in[$k] = $from[$k];
                $fills[$k] = $label;
            }
        }
        $in['tags'] = array_values(array_unique(array_merge($in['tags'], $from['tags'])));
        $sn = trim((string)$src['notes']);
        if ($sn !== '' && !str_contains((string)$dst['notes'], $sn)) {
            $in['notes'] = mb_substr(trim(trim((string)$dst['notes']) . "\n\n" . $sn), 0, self::NOTES_MAX);
            $fills['notes'] = 'Notes (appended)';
        }
        $lending = '';
        if ($src['lent_to'] !== null) {
            $lending = $dst['lent_to'] === null ? 'copied' : 'kept';
        }
        $count = fn (string $table): int => (int)(Writes::rows($this->db, "SELECT COUNT(*) AS n FROM $table WHERE item_id = ?", [$sourceId])[0]['n'] ?? 0);

        return ['target' => $dst, 'source' => $src, 'result' => $in, 'fills' => $fills,
            'photos' => $count('item_photos'), 'attachments' => $count('item_attachments'), 'history' => $count('activity'), 'lending' => $lending];
    }

    /**
     * Merge $sourceId into $targetId (legacy item_merge()): quantities add up,
     * photos (after the target's), attachments, tags and history move over,
     * empty fields are filled, notes appended, a loan taken over when the
     * target isn't lent, the star kept if either had it.
     *
     * @return array<string,mixed> the preview that was applied
     */
    public function merge(int $sourceId, int $targetId): array
    {
        return Writes::transaction($this->db, function () use ($sourceId, $targetId): array {
            $p = $this->preview($sourceId, $targetId);
            $src = $p['source'];
            $dst = $p['target'];
            $in = $p['result'];
            $base = (int)(Writes::rows($this->db, 'SELECT COALESCE(MAX(position), -1) AS p FROM item_photos WHERE item_id = ?', [$targetId])[0]['p'] ?? -1) + 1;
            Writes::check($this->db->query('UPDATE item_photos SET item_id = ?, position = position + ? WHERE item_id = ?', [$targetId, $base, $sourceId]), 'merge photos');
            Writes::check($this->db->query('UPDATE item_attachments SET item_id = ? WHERE item_id = ?', [$targetId, $sourceId]), 'merge attachments');
            Writes::check($this->db->query('UPDATE activity SET item_id = ? WHERE item_id = ?', [$targetId, $sourceId]), 'merge history');
            Writes::check($this->db->query('DELETE FROM recent_views WHERE item_id = ?', [$sourceId]), 'merge recent');
            Writes::check($this->db->query('DELETE FROM items WHERE id = ?', [$sourceId]), 'merge source');
            $cover = Writes::rows($this->db, 'SELECT file FROM item_photos WHERE item_id = ? ORDER BY position, id LIMIT 1', [$targetId])[0]['file'] ?? null;
            $lend = $p['lending'] === 'copied' ? [$src['lent_to'], $src['lent_at'], $src['lent_due']] : [$dst['lent_to'], $dst['lent_at'], $dst['lent_due']];
            $barcode = (string)$in['barcode'];
            $blob = Text::fold(implode("\n", array_merge([(string)$in['name'], (string)$in['notes'], (string)$in['serial'], $barcode], $in['tags'])));
            Writes::check($this->db->query('UPDATE items SET location_id=?, category_id=?, quantity=?, min_qty=?, notes=?, value_cents=?, purchase_date=?, expires_on=?, serial=?, barcode=?,
                       image=?, lent_to=?, lent_at=?, lent_due=?, favourite=?, search=?, updated_at=? WHERE id=?', [
                $in['location_id'], $in['category_id'], $in['quantity'], $in['min_qty'], $in['notes'], $in['value_cents'], $in['purchase_date'], $in['expires_on'],
                $in['serial'], $barcode === '' ? null : $barcode, $cover === null || $cover === '' ? null : $cover, ...$lend,
                (int)(!empty($dst['favourite']) || !empty($src['favourite'])), $blob, $this->clock->now(), $targetId,
            ]), 'merge target');
            $this->tags->set($targetId, $in['tags']);
            Writes::check($this->db->query('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)'), 'drop unused tags');
            $this->history->log($targetId, (string)$dst['name'], 'merged', array_filter([
                'from' => (string)$src['name'], 'from_id' => $sourceId,
                'quantity' => [(int)$dst['quantity'], (int)$in['quantity']],
                'photos' => $p['photos'] !== 0 ? $p['photos'] : null, 'attachments' => $p['attachments'] !== 0 ? $p['attachments'] : null,
                'filled' => $p['fills'] !== [] ? array_values($p['fills']) : null, 'lending' => $p['lending'] !== '' ? $p['lending'] : null,
            ], static fn ($v): bool => $v !== null));

            return $p;
        });
    }

    /**
     * Likely duplicates of $it (legacy merge_candidates()): the same name
     * ignoring case and accents first, then the same barcode, then similar
     * names; or the search for $q.
     *
     * @param array<string,mixed> $it
     * @return list<array<string,mixed>>
     */
    public function candidates(array $it, string $q = '', int $limit = 30): array
    {
        $id = (int)$it['id'];
        if ($q !== '') {
            $rows = $this->list->search($q, [], 'name', $this->defaultSort, 1, $limit + 1)['items'];
        } else {
            $words = array_slice(preg_split('/\s+/u', Text::fold((string)$it['name']), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2);
            $rows = $words !== [] ? $this->list->search(implode(' ', $words), [], 'name', $this->defaultSort, 1, $limit + 1)['items'] : [];
            if ((string)($it['barcode'] ?? '') !== '') {
                $rows = array_merge(Writes::rows($this->db, "SELECT i.id, i.name, i.location_id, COALESCE(l.name, '') AS location, i.category_id, COALESCE(c.name, '') AS category, i.quantity, i.min_qty, i.image, i.lent_to, i.lent_due, i.expires_on, i.favourite "
                    . 'FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id WHERE i.barcode = ? AND i.id <> ? LIMIT ' . $limit, [(string)$it['barcode'], $id]), $rows);
            }
        }
        $seen = [$id => true];
        $out = [];
        $key = Text::fold((string)$it['name']);
        foreach ($rows as $r) {
            if (isset($seen[(int)$r['id']])) {
                continue;
            }
            $seen[(int)$r['id']] = true;
            $out[] = $r;
        }
        usort($out, static fn (array $a, array $b): int => (Text::fold((string)$b['name']) === $key) <=> (Text::fold((string)$a['name']) === $key));

        return array_slice($out, 0, $limit);
    }
}
