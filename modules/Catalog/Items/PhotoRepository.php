<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\HostedLimits;
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
 * change is one unit of work with its history (ADR-002); a file is removed
 * only after the unit runs, and a new one stays held for cleanup until then.
 */
final class PhotoRepository
{
    public const ROTATIONS = [0, 90, 180, 270];

    private readonly UnitsOfWork $units;

    private readonly HostedLimits $limits;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     * @param HostedLimits|null $limits the hosted plan's photo limit; none when null (self-hosted)
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
        private readonly ImageStore $images,
        private readonly UndoTokens $undo,
        ?UnitsOfWork $units = null,
        ?HostedLimits $limits = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
        $this->limits = $limits ?? HostedLimits::off();
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

    /** Legacy photo_add(): a stored photo joins the item; released from cleanup once the unit ran. */
    public function add(int $itemId, string $file, int $max): int
    {
        $this->images->hold($file);
        // L-1773: position from COALESCE(MAX)+1 inside the write so parallel batch adds get 0,1,2.
        $this->units->run('items', function (UnitOfWork $uow) use ($itemId, $file, $max): void {
            // Unit lock first (no row lock): the guard re-checks the count so two adds at once
            // cannot pass the limit together (P6).
            $uow->assert('(SELECT COUNT(*) FROM item_photos WHERE item_id = ?) < ?', [$itemId, $max], 'photo limit',
                static fn (): UserError => new UserError('An item can have at most ' . $max . ' photos.', 422));
            $this->limits->guard($uow, 'photos');   // hosted: the plan's photos in all
            $now = $this->clock->now();
            $before = (int)(Writes::rows($this->db, 'SELECT COUNT(*) AS n FROM item_photos WHERE item_id = ?', [$itemId])[0]['n'] ?? 0);
            $uow->write(
                'INSERT INTO item_photos (item_id, file, position, created_at)'
                . ' SELECT ?, ?, COALESCE((SELECT MAX(position) FROM item_photos WHERE item_id = ?), -1) + 1, ?',
                [$itemId, $file, $itemId, $now],
                'add photo',
                1,
            );
            $uow->write(
                'UPDATE items SET image = (SELECT file FROM item_photos WHERE item_id = ? ORDER BY position, id LIMIT 1),'
                . ' updated_at = ? WHERE id = ?',
                [$itemId, $now, $itemId],
                'cover photo',
            );
            $this->history->queue($uow, $itemId, $this->name($itemId), 'photo', ['change' => 'added', 'count' => $before + 1]);
        }, [(string)$itemId, ...$this->limits->lockKeys('photos')]);
        $this->images->release($file);

        return (int)(Writes::rows($this->db, 'SELECT id FROM item_photos WHERE item_id = ? AND file = ? ORDER BY id DESC', [$itemId, $file])[0]['id'] ?? 0);
    }

    /** Legacy photo_action(): cover, left, right or delete. Returns the photo's new index (-1 when deleted). */
    public function action(int $itemId, int $photoId, string $action): int
    {
        $drop = null;
        $new = $this->units->run('items', function (UnitOfWork $uow) use ($itemId, $photoId, $action, &$drop): int {
            $uow->write('UPDATE items SET updated_at = ? WHERE id = ?', [$this->clock->now(), $itemId], 'item updated');
            $list = $this->list($itemId);
            $p = null;
            foreach ($list as $i => $row) {
                if ($row['id'] === $photoId) {
                    $p = $row + ['index' => $i];
                    break;
                }
            }
            if ($p === null) {
                throw new UserError('Photo not found.', 404);
            }
            $ids = array_column($list, 'id');
            $files = array_column($list, 'file');
            $i = $p['index'];
            switch ($action) {
                case 'cover':
                    array_splice($ids, $i, 1);
                    array_unshift($ids, $photoId);
                    array_splice($files, $i, 1);
                    array_unshift($files, $p['file']);
                    $new = 0;
                    break;
                case 'left':
                case 'right':
                    $new = $action === 'left' ? max(0, $i - 1) : min(count($ids) - 1, $i + 1);
                    [$ids[$i], $ids[$new]] = [$ids[$new], $ids[$i]];
                    [$files[$i], $files[$new]] = [$files[$new], $files[$i]];
                    break;
                case 'delete':
                    $uow->write('DELETE FROM item_photos WHERE id = ?', [$photoId], 'delete photo');
                    array_splice($ids, $i, 1);
                    array_splice($files, $i, 1);
                    $drop = $p['file'];
                    $new = -1;
                    break;
                default:
                    throw new UserError('Unknown photo action.', 404);
            }
            $this->queueRenumber($uow, $itemId, $ids, $files);
            if ($new !== $i || $action === 'delete') {
                $this->history->queue($uow, $itemId, $this->name($itemId), 'photo',
                    ['change' => ['cover' => 'cover', 'left' => 'reordered', 'right' => 'reordered', 'delete' => 'deleted'][$action], 'count' => count($ids)]);
            }

            return $new;
        }, (string)$itemId);
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
        $this->units->run('items', function (UnitOfWork $uow) use ($itemId, $ids): void {
            $uow->write('UPDATE items SET updated_at = ? WHERE id = ?', [$this->clock->now(), $itemId], 'item updated');
            $list = $this->list($itemId);
            $have = array_column($list, 'id');
            $byId = [];
            foreach ($list as $row) {
                $byId[$row['id']] = $row['file'];
            }
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
            $files = array_map(static fn (int $id): string => $byId[$id], $ids);
            $this->queueRenumber($uow, $itemId, $ids, $files);
            $this->history->queue($uow, $itemId, $this->name($itemId), 'photo', ['change' => $ids[0] !== $have[0] ? 'cover' : 'reordered', 'count' => count($ids)]);
        }, (string)$itemId);
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
        $old = $p['file'];
        try {
            $new = $this->images->edit($old, $rotate, $x, $y, $w, $h);
        } catch (UserError $e) {
            $this->stillCurrent($itemId, $photoId, $old);
            throw $e;
        }
        $name = $new['name'];
        try {
            $version = $this->units->run('items', function (UnitOfWork $uow) use ($itemId, $photoId, $name, $old, $rotate, $new): int {
                $now = $this->clock->now();
                $uow->write('UPDATE items SET updated_at = ? WHERE id = ?', [$now, $itemId], 'item updated');
                $list = $this->list($itemId);
                $cur = null;
                foreach ($list as $row) {
                    if ($row['id'] === $photoId) {
                        $cur = $row;
                        break;
                    }
                }
                if ($cur === null || $cur['file'] !== $old) {
                    $this->stillCurrent($itemId, $photoId, $old);
                    throw new UserError('Could not read that photo.', 500);
                }
                $before = (int)(Writes::rows($this->db, 'SELECT version FROM item_photos WHERE id = ?', [$photoId])[0]['version'] ?? 0);
                // Compare-and-swap (M-90): only the request that read the current file replaces it. The other
                // one of a double submit changes nothing and drops its own new files below.
                $uow->write(
                    'UPDATE item_photos SET file = ?, version = version + 1, edited_at = ? WHERE id = ? AND item_id = ? AND file = ?',
                    [$name, $now, $photoId, $itemId, $old],
                    'edit photo',
                    1,
                    static fn (): UserError => new UserError('Could not read that photo.', 500),
                );
                $files = array_map(static fn (array $r): string => $r['id'] === $photoId ? $name : $r['file'], $list);
                $this->queueRenumber($uow, $itemId, array_column($list, 'id'), $files);
                $this->history->queue($uow, $itemId, $this->name($itemId), 'photo',
                    array_filter(['change' => 'edited', 'rotate' => $rotate ?: null, 'size' => $new['width'] . '×' . $new['height']]));

                return $before + 1;
            }, (string)$itemId);
        } catch (Throwable $e) {
            $this->images->delete($name);
            $this->images->release($name);
            throw $e;
        }
        $this->images->release($name);
        $used = Writes::rows($this->db, 'SELECT 1 AS x FROM item_photos WHERE file = ? UNION ALL SELECT 1 AS x FROM items WHERE image = ?', [$old, $old]);
        if ($used === [] && !$this->undo->holds($old)) {
            $this->images->delete($old);
        }

        return ['id' => $photoId, 'file' => $name, 'version' => $version];
    }

    /**
     * The photo still shows $file. Otherwise answer as legacy does for a photo
     * that changed or went away while it was read: 404 when it is gone, else
     * "Could not read that photo." (500).
     */
    private function stillCurrent(int $itemId, int $photoId, string $file): void
    {
        if ($this->find($itemId, $photoId)['file'] !== $file) {
            throw new UserError('Could not read that photo.', 500);
        }
    }

    /**
     * Queue position 0..n-1 and the cover mirrored into items.image. $ids may
     * hold Refs for a photo inserted in this unit; $files is the same length.
     *
     * @param list<int|Ref> $ids
     * @param list<string> $files
     */
    private function queueRenumber(UnitOfWork $uow, int $itemId, array $ids, array $files): void
    {
        foreach ($ids as $i => $pid) {
            $uow->write('UPDATE item_photos SET position = ? WHERE id = ? AND item_id = ?', [$i, $pid, $itemId], 'photo order');
        }
        $uow->write('UPDATE items SET image = ? WHERE id = ?', [$files[0] ?? null, $itemId], 'cover photo');
    }

    private function name(int $itemId): string
    {
        return (string)(Writes::rows($this->db, 'SELECT name FROM items WHERE id = ?', [$itemId])[0]['name'] ?? '');
    }
}
