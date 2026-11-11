<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge;

use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Core\Libraries\Text;
use Apotheke\Media\ImageStore;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;

/**
 * One action on many items in one transaction (legacy items_bulk()): move,
 * category, tag_add, tag_remove, delete. Every item gets its own history
 * entry; deleted items' files go after the commit, unless an undo token
 * keeps them.
 */
final class BulkService
{
    public const MAX = 500;

    public const ACTIONS = ['move', 'category', 'tag_add', 'tag_remove', 'delete'];

    /** Legacy bulk_perm(): the permission an action needs on top of "bulk". */
    public static function permission(string $action): string
    {
        return match ($action) {
            'labels' => 'labels',
            'delete' => 'delete',
            'move' => 'move',
            default => 'edit',
        };
    }

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ItemRepository $items,
        private readonly TaxonomyRepository $taxonomy,
        private readonly ItemHistory $history,
        private readonly ImageStore $images,
    ) {
    }

    /** The hosted database (the D1 driver): the selection runs as one unit of work. */
    public function hosted(): bool
    {
        return Writes::onD1($this->db);
    }

    /**
     * @param list<int> $ids
     * @param array{location_id?:?int,category_id?:?int,tags?:list<string>,tag_id?:int,keep_files?:bool} $opt
     * @param (callable(UnitOfWork, int):void)|null $after hosted: queued in the same unit after the item writes
     * @return int the number of items changed
     */
    public function run(array $ids, string $action, array $opt = [], ?callable $after = null): int
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new UserError('Unknown bulk action.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            throw new UserError('Select at least one item.');
        }
        if (count($ids) > self::MAX) {
            throw new UserError('Select at most ' . self::MAX . ' items at once.');
        }
        if ($action === 'move' && ($opt['location_id'] ?? null) !== null && $this->taxonomy->get('location', (int)$opt['location_id']) === null) {
            throw new UserError('That location no longer exists.');
        }
        if ($action === 'category' && ($opt['category_id'] ?? null) !== null && $this->taxonomy->get('category', (int)$opt['category_id']) === null) {
            throw new UserError('That category no longer exists.');
        }
        if ($action === 'tag_add' && ($opt['tags'] ?? []) === []) {
            throw new UserError('Enter at least one tag to add.');
        }
        $files = [];
        $attFiles = [];
        $apply = function (?UnitOfWork $uow) use ($ids, $action, $opt, &$files, &$attFiles): int {
            $n = 0;
            foreach ($ids as $id) {
                $it = $this->items->get($id);
                if ($it === null) {
                    continue;
                }
                $in = ItemRepository::input($it);
                switch ($action) {
                    case 'move':
                        $in['location_id'] = $opt['location_id'] ?? null;
                        break;
                    case 'category':
                        $in['category_id'] = $opt['category_id'] ?? null;
                        break;
                    case 'tag_add':
                        $in['tags'] = Text::tags(array_merge($in['tags'], $opt['tags'] ?? []));
                        break;
                    case 'tag_remove':
                        $in['tags'] = array_column(array_filter($it['tags'], static fn (array $t): bool => (int)$t['id'] !== (int)($opt['tag_id'] ?? 0)), 'name');
                        break;
                    default:   // delete
                        $files = array_merge($files, array_column($this->items->photos($id), 'file'), [$it['image']]);
                        $attFiles = array_merge($attFiles, array_column($this->items->attachments($id), 'file'));
                        $details = array_filter(['location' => (string)$it['location'], 'quantity' => (int)$it['quantity'], 'bulk' => true], static fn ($v): bool => $v !== '');
                        if ($uow !== null) {
                            $this->history->queue($uow, $id, (string)$it['name'], 'deleted', $details);
                            $uow->write('DELETE FROM items WHERE id = ?', [$id], 'delete item', 1, self::changed(...));
                        } else {
                            $this->history->log($id, (string)$it['name'], 'deleted', $details);
                            Writes::check($this->db->table('items')->where('id', $id)->delete(), 'delete item');
                        }
                        $n++;
                        continue 2;
                }
                $this->items->save($it, $in, null, false);
                $n++;
            }
            $drop = 'DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)';
            $uow !== null ? $uow->write($drop, [], 'drop unused tags') : Writes::check($this->db->query($drop), 'drop unused tags');

            return $n;
        };
        // On the hosted database the whole selection is one unit of work, one batch on the Installation object
        // (ADR-002: all or nothing there); each item's save joins it. A failed batch wrote nothing, so the same
        // request again simply resumes from the current rows. Elsewhere it is one transaction, as before.
        $n = $this->hosted()
            ? $this->items->inUnit(static function (UnitOfWork $uow) use ($apply, $after): int {
                $n = $apply($uow);
                if ($after !== null) {
                    $after($uow, $n);
                }

                return $n;
            })
            : Writes::transaction($this->db, static fn (): int => $apply(null));
        if (!empty($opt['keep_files'])) {
            return $n;
        }
        foreach (array_unique(array_filter($files)) as $f) {
            $this->images->delete(is_string($f) ? $f : null);
        }
        foreach ($attFiles as $f) {
            $this->items->deleteAttachmentFile($f);
        }

        return $n;
    }

    /** A selected item changed or went while the batch was built: nothing was written. */
    private static function changed(): UserError
    {
        return new UserError('The selection changed while it was being edited. Try again.', 409);
    }
}
