<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Database\BaseConnection;

/**
 * Puts items back from an undo token, in one transaction, and uses the
 * token up (legacy undo_apply()). A deleted item comes back with its id,
 * photos, attachments and tags; an edit restores the saved fields; a bulk
 * move, category or tag change only what it changed.
 */
final class UndoApply
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly UndoTokens $tokens,
        private readonly ItemRepository $items,
        private readonly TaxonomyRepository $taxonomy,
        private readonly TagRepository $tags,
        private readonly ItemHistory $history,
    ) {
    }

    /** @return array{action:string,label:string,ids:list<int>,back:string} */
    public function apply(string $token): array
    {
        if ($this->items->hosted()) {
            // The hosted database (D1): one unit of work, so the token is used once and the rows come back together.
            return $this->items->inUnit(fn (UnitOfWork $uow): array => $this->put($token, $uow));
        }

        return Writes::transaction($this->db, fn (): array => $this->put($token, null));
    }

    /**
     * Put the token's rows back: in the open transaction ($uow null), or queued on $uow (D1), where every read
     * happens before the batch and the token's delete is the guard against a second apply.
     *
     * @return array{action:string,label:string,ids:list<int>,back:string}
     */
    private function put(string $token, ?UnitOfWork $uow): array
    {
        $gone = static fn (): UserError => new UserError('This can no longer be undone.', 410);
        $insert = function (string $table, array $row, string $step) use ($uow): void {
            if ($uow !== null) {
                $uow->insert($table, $row, $step);
            } else {
                Writes::check($this->db->table($table)->insert($row), $step);
            }
        };
        $t = $this->tokens->get($token) ?? throw $gone();
        // D1: the token's delete is the batch's first step, so a second apply fails on it (410), never on a row.
        $uow?->write('DELETE FROM undo_tokens WHERE token = ?', [$token], 'use undo token', 1, $gone);
        $payload = json_decode((string)$t['payload'], true, 512, JSON_THROW_ON_ERROR);
        $ids = [];
        $ok = fn (string $kind, mixed $v): ?int => $v !== null && $this->taxonomy->get($kind, (int)$v) !== null ? (int)$v : null;
        foreach ($payload['items'] as $s) {
            $row = $s['row'];
            $id = (int)$row['id'];
            $row['location_id'] = $ok('location', $row['location_id']);
            $row['category_id'] = $ok('category', $row['category_id']);
            if ($t['action'] === 'delete') {
                if ($this->items->get($id) !== null) {
                    continue;
                }
                $insert('items', $row, 'restore item');
                foreach ($s['photos'] as $p) {
                    $insert('item_photos', ['item_id' => $id, 'file' => $p['file'], 'position' => (int)$p['position'], 'created_at' => $p['created_at']], 'restore photo');
                }
                foreach ($s['attachments'] as $a) {
                    $insert('item_attachments', ['item_id' => $id, 'file' => $a['file'], 'name' => $a['name'], 'mime' => $a['mime'], 'size' => (int)$a['size'], 'created_at' => $a['created_at']], 'restore attachment');
                }
                $this->tags->set($id, $s['tags'], $uow);
                if ($uow !== null) {
                    $this->history->queue($uow, $id, (string)$row['name'], 'restored', ['undo' => 'delete']);
                } else {
                    $this->history->log($id, (string)$row['name'], 'restored', ['undo' => 'delete']);
                }
            } else {
                $it = $this->items->get($id);
                if ($it === null) {
                    continue;   // deleted since; nothing to put back
                }
                $in = ['name' => (string)$row['name'], 'location_id' => $row['location_id'], 'category_id' => $row['category_id'],
                    'quantity' => (int)$row['quantity'], 'min_qty' => $row['min_qty'] === null ? null : (int)$row['min_qty'], 'notes' => (string)$row['notes'],
                    'tags' => $s['tags'], 'value_cents' => $row['value_cents'] === null ? null : (int)$row['value_cents'],
                    'purchase_date' => $row['purchase_date'], 'expires_on' => $row['expires_on'], 'serial' => (string)$row['serial'], 'barcode' => (string)($row['barcode'] ?? '')];
                if ($t['action'] !== 'edit') {   // bulk move/category/tags only put back what they changed
                    $in = ['location_id' => $in['location_id'], 'category_id' => $in['category_id'], 'tags' => $in['tags']] + ItemRepository::input($it);
                }
                $in['reason'] = 'undo';
                $this->items->save($it, $in, null, false);
            }
            $ids[] = $id;
        }
        if ($uow !== null) {
            $uow->write('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)', [], 'drop unused tags');
        } else {
            Writes::check($this->db->query('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)'), 'drop unused tags');
            $this->tokens->consume($token);
        }

        return ['action' => (string)$t['action'], 'label' => (string)$t['label'], 'ids' => $ids, 'back' => (string)($payload['back'] ?? '')];
    }
}
