<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Media\ImageStore;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * An item's photos (legacy photos_for(), photo_insert(), photos_renumber(),
 * photo_add(), photo_find(), photo_action(), photo_reorder() and
 * photo_edit()). Position 0 is the cover, mirrored into items.image. Every
 * write runs in one checked transaction with its history; a file is removed
 * only after the commit, and a new one stays held for cleanup until then.
 */
final class PhotoRepository
{
    public const ROTATIONS = [0, 90, 180, 270];

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
        private readonly ImageStore $images,
        private readonly UndoTokens $undo,
    ) {
    }

    /** @return list<array{id:int,file:string,position:int}> cover first */
    public function list(int $itemId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'file' => (string)$r['file'], 'position' => (int)$r['position']],
            Writes::rows($this->db, 'SELECT id, file, position FROM item_photos WHERE item_id = ? ORDER BY position, id', [$itemId]));
    }

    /** @return array{id:int,file:string,position:int,index:int} */
    public function find(int $itemId, int $photoId): array
    {
        foreach ($this->list($itemId) as $i => $p) {
            if ($p['id'] === $photoId) {
                return $p + ['index' => $i];
            }
        }
        throw new UserError('Photo not found.', 404);
    }

    /** Add $file as the last photo, or as the cover; then renumber. */
    public function insert(int $itemId, string $file, bool $asCover = false): int
    {
        $max = (int)(Writes::rows($this->db, 'SELECT COALESCE(MAX(position), -1) AS p FROM item_photos WHERE item_id = ?', [$itemId])[0]['p'] ?? -1);
        Writes::check($this->db->table('item_photos')->insert(['item_id' => $itemId, 'file' => $file, 'position' => $asCover ? -1 : $max + 1, 'created_at' => $this->clock->now()]), 'add photo');
        $id = (int)$this->db->insertID();
        $this->renumber($itemId);

        return $id;
    }

    /**
     * Positions 0..n-1 in the current order (or in $order, photo ids), and the
     * cover mirrored into items.image.
     *
     * @param array<int,int>|null $order
     */
    public function renumber(int $itemId, ?array $order = null): void
    {
        foreach (array_values($order ?? array_column($this->list($itemId), 'id')) as $i => $pid) {
            Writes::check($this->db->table('item_photos')->where('id', $pid)->where('item_id', $itemId)->update(['position' => $i]), 'photo order');
        }
        Writes::check($this->db->table('items')->where('id', $itemId)->update(['image' => $this->list($itemId)[0]['file'] ?? null]), 'cover photo');
    }

    /** Legacy photo_add(): a stored photo joins the item; released from cleanup once committed. */
    public function add(int $itemId, string $file, int $max): int
    {
        $this->images->hold($file);
        $pid = Writes::transaction($this->db, function () use ($itemId, $file, $max): int {
            if (count($this->list($itemId)) >= $max) {
                throw new UserError('An item can have at most ' . $max . ' photos.');
            }
            $pid = $this->insert($itemId, $file);
            $this->touch($itemId);
            $this->history->log($itemId, $this->name($itemId), 'photo', ['change' => 'added', 'count' => count($this->list($itemId))]);

            return $pid;
        });
        $this->images->release($file);

        return $pid;
    }

    /** Legacy photo_action(): cover, left, right or delete. Returns the photo's new index (-1 when deleted). */
    public function action(int $itemId, int $photoId, string $action): int
    {
        $drop = null;
        $new = Writes::transaction($this->db, function () use ($itemId, $photoId, $action, &$drop): int {
            $p = $this->find($itemId, $photoId);
            $ids = array_column($this->list($itemId), 'id');
            $i = $p['index'];
            switch ($action) {
                case 'cover':
                    array_splice($ids, $i, 1);
                    array_unshift($ids, $photoId);
                    $new = 0;
                    break;
                case 'left':
                case 'right':
                    $new = $action === 'left' ? max(0, $i - 1) : min(count($ids) - 1, $i + 1);
                    [$ids[$i], $ids[$new]] = [$ids[$new], $ids[$i]];
                    break;
                case 'delete':
                    Writes::check($this->db->table('item_photos')->where('id', $photoId)->delete(), 'delete photo');
                    array_splice($ids, $i, 1);
                    $drop = $p['file'];
                    $new = -1;
                    break;
                default:
                    throw new UserError('Unknown photo action.', 404);
            }
            $this->renumber($itemId, $ids);
            $this->touch($itemId);
            if ($new !== $i || $action === 'delete') {
                $this->history->log($itemId, $this->name($itemId), 'photo',
                    ['change' => ['cover' => 'cover', 'left' => 'reordered', 'right' => 'reordered', 'delete' => 'deleted'][$action], 'count' => count($ids)]);
            }

            return $new;
        });
        if ($drop !== null) {
            $this->images->delete($drop);
        }

        return $new;
    }

    /**
     * Legacy photo_reorder(): all of the item's photo ids, each once; the
     * first becomes the cover.
     *
     * @param array<mixed> $ids
     */
    public function reorder(int $itemId, array $ids): void
    {
        Writes::transaction($this->db, function () use ($itemId, $ids): void {
            $have = array_column($this->list($itemId), 'id');
            $ids = array_values(array_map('intval', $ids));
            $a = $ids;
            sort($a);
            $b = $have;
            sort($b);
            if ($a !== $b) {
                throw new UserError('That is not the list of this item’s photos.', 422);
            }
            if ($ids === $have) {
                return;
            }
            $this->renumber($itemId, $ids);
            $this->touch($itemId);
            $this->history->log($itemId, $this->name($itemId), 'photo', ['change' => $ids[0] !== $have[0] ? 'cover' : 'reordered', 'count' => count($ids)]);
        });
    }

    /**
     * Legacy photo_edit(): rotate (clockwise, in 90° steps) then crop to
     * [x, y, w, h], fractions of the rotated photo. The result is a new
     * served name; the old one goes unless a row or a pending undo still
     * needs it.
     *
     * @param array{0:float,1:float,2:float,3:float} $crop
     * @return array{id:int,file:string,version:int}
     */
    public function edit(int $itemId, int $photoId, int $rotate, array $crop): array
    {
        if (!in_array($rotate, self::ROTATIONS, true)) {
            throw new UserError('Rotate in steps of 90°.', 422);
        }
        [$x, $y, $w, $h] = $crop;
        foreach ($crop as $v) {
            if (!is_finite($v) || $v < 0 || $v > 1) {
                throw new UserError('The crop area is outside the photo.', 422);
            }
        }
        if ($x + $w > 1.0001 || $y + $h > 1.0001) {
            throw new UserError('The crop area is outside the photo.', 422);
        }
        if ($w < 0.02 || $h < 0.02) {
            throw new UserError('The crop area is too small.', 422);
        }
        $p = $this->find($itemId, $photoId);
        if ($rotate === 0 && $x <= 0.0001 && $y <= 0.0001 && $w >= 0.9999 && $h >= 0.9999) {
            throw new UserError('Nothing to change: rotate or crop the photo first.', 422);
        }
        $new = $this->images->edit($p['file'], $rotate, $x, $y, $w, $h);
        $name = $new['name'];
        try {
            $version = Writes::transaction($this->db, function () use ($itemId, $photoId, $name, $rotate, $new): int {
                $now = $this->clock->now();
                Writes::check($this->db->query('UPDATE item_photos SET file = ?, version = version + 1, edited_at = ? WHERE id = ? AND item_id = ?', [$name, $now, $photoId, $itemId]), 'edit photo');
                $this->renumber($itemId);
                $this->touch($itemId);
                $this->history->log($itemId, $this->name($itemId), 'photo',
                    array_filter(['change' => 'edited', 'rotate' => $rotate ?: null, 'size' => $new['width'] . '×' . $new['height']]));

                return (int)(Writes::rows($this->db, 'SELECT version FROM item_photos WHERE id = ?', [$photoId])[0]['version'] ?? 0);
            });
        } catch (Throwable $e) {
            $this->images->delete($name);
            $this->images->release($name);
            throw $e;
        }
        $this->images->release($name);
        $used = Writes::rows($this->db, 'SELECT 1 AS x FROM item_photos WHERE file = ? UNION ALL SELECT 1 AS x FROM items WHERE image = ?', [$p['file'], $p['file']]);
        if ($used === [] && !$this->undo->holds($p['file'])) {
            $this->images->delete($p['file']);
        }

        return ['id' => $photoId, 'file' => $name, 'version' => $version];
    }

    private function touch(int $itemId): void
    {
        Writes::check($this->db->table('items')->where('id', $itemId)->update(['updated_at' => $this->clock->now()]), 'item updated');
    }

    private function name(int $itemId): string
    {
        return (string)(Writes::rows($this->db, 'SELECT name FROM items WHERE id = ?', [$itemId])[0]['name'] ?? '');
    }
}
