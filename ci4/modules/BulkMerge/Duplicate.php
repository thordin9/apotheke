<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge;

use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Items\PhotoRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Media\ImageStore;
use Throwable;

/**
 * A copy of an item (legacy item_duplicate()): name + " (copy)", the fields,
 * tags and low-stock level; no loan. With $photos every photo is copied
 * (served copy, thumbnail and original). A failed copy or save leaves no
 * new files.
 */
final class Duplicate
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly PhotoRepository $photos,
        private readonly ImageStore $images,
    ) {
    }

    /** The new item's id. */
    public function copy(int $id, bool $photos): int
    {
        $it = $this->items->get($id) ?? throw new UserError('Item not found.', 404);
        $in = ItemRepository::input($it);
        $in['name'] = mb_substr((string)$in['name'], 0, 193) . ' (copy)';
        $in['copied_from'] = (string)$it['name'];
        $copies = [];
        try {
            if ($photos) {
                foreach ($this->items->photos($id) as $p) {
                    $copies[] = $new = $this->images->copy($p['file']);
                    $this->images->hold($new);   // removed if the request dies before the commit
                }
            }
            $newId = $this->items->atomically(function () use ($in, $copies): int {
                $new = $this->items->save(null, $in, null, false);
                foreach ($copies as $f) {
                    $this->photos->insert($new, $f);
                }

                return $new;
            });
        } catch (Throwable $e) {
            foreach ($copies as $f) {
                $this->images->delete($f);
                $this->images->release($f);
            }
            throw $e;
        }
        foreach ($copies as $f) {
            $this->images->release($f);
        }

        return $newId;
    }
}
