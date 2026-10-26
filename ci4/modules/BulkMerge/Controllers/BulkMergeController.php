<?php

declare(strict_types=1);

namespace Apotheke\BulkMerge\Controllers;

use Apotheke\BulkMerge\BulkService;
use Apotheke\BulkMerge\Duplicate;
use Apotheke\BulkMerge\MergeService;
use Apotheke\BulkMerge\QuantityAdjust;
use Apotheke\BulkMerge\Views\BulkMergeView;
use Apotheke\Catalog\Controllers\CatalogController;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Views\ListView;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Text;
use Apotheke\Core\Security\PermissionGate;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Legacy public/index.php: POST /item/{id}/qty, POST /item/{id}/duplicate,
 * GET and POST /item/{id}/merge, and POST /bulk (the list's selection mode).
 */
final class BulkMergeController extends CatalogController
{
    /** Legacy UNDO_ACTIONS: what Undo can take back. */
    private const UNDO_ACTIONS = ['edit', 'move', 'category', 'tag_add', 'tag_remove', 'delete'];

    /** POST /item/{id}/qty: delta -1000..1000; the quantity never goes below 0. */
    public function qty(string $id): ResponseInterface
    {
        $delta = max(-1000, min(1000, self::int($this->request->getPost('delta') ?? 0)));
        /** @var QuantityAdjust $adjust */
        $adjust = service('quantityAdjust');
        $q = $adjust->adjust((int)$id, $delta) ?? throw new UserError('Item not found.', 404);
        if ($this->wantsJson()) {
            $row = $this->repo()->get((int)$id);

            return $this->json(['ok' => true, 'quantity' => $q, 'low' => $row !== null && $row['min_qty'] !== null && (int)$row['quantity'] <= (int)$row['min_qty']]);
        }

        return $this->redirectTo('/item/' . (int)$id);
    }

    /** POST /item/{id}/duplicate: the copy's edit form; photos=1 copies the photos too. */
    public function duplicate(string $id): ResponseInterface
    {
        /** @var Duplicate $dup */
        $dup = service('duplicate');
        $new = $dup->copy((int)$id, $this->request->getPost('photos') === '1');

        return $this->redirectTo('/item/' . $new . '/edit?copied=1');
    }

    /** GET /item/{id}/merge: pick the item to keep, or (?into=) confirm. */
    public function mergePage(string $id): ResponseInterface
    {
        $it = $this->repo()->get((int)$id) ?? throw new UserError('Item not found.', 404);
        $into = self::str($this->request->getGet('into') ?? '');
        if (ctype_digit($into)) {
            return $this->html($this->view11b()->mergeConfirm($this->merger()->preview((int)$it['id'], (int)$into)));
        }
        $q = Text::cleanLine(self::str($this->request->getGet('q') ?? ''), 200);

        return $this->html($this->view11b()->mergePick($it, $this->merger()->candidates($it, $q), $q));
    }

    /** POST /item/{id}/merge: without confirm=1 back to the confirm page. */
    public function merge(string $id): ResponseInterface
    {
        $it = $this->repo()->get((int)$id) ?? throw new UserError('Item not found.', 404);
        $into = self::int($this->request->getPost('into') ?? 0);
        if (self::str($this->request->getPost('confirm') ?? '') !== '1') {
            return $this->redirectTo('/item/' . (int)$it['id'] . '/merge?into=' . $into);
        }
        $p = $this->merger()->merge((int)$it['id'], $into);
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $into, 'quantity' => (int)$p['result']['quantity']]);
        }

        return $this->redirectTo('/item/' . $into . '?merged=' . rawurlencode((string)$it['name']));
    }

    /** POST /bulk: one action on the selected items; delete asks first. */
    public function bulk(): ResponseInterface
    {
        $post = $this->request->getPost();
        $post = is_array($post) ? $post : [];
        $raw = $post['ids'] ?? [];
        $ids = array_slice(array_map(static fn (mixed $v): int => self::int($v), is_array($raw) ? array_values($raw) : [$raw]), 0, BulkService::MAX + 1);
        $action = self::str($post['action'] ?? '');
        $back = self::str($post['back'] ?? '');
        if (preg_match('#^/\?[^\r\n]*$#D', $back) !== 1) {
            $back = '/?select=1';
        }
        $back = (string)preg_replace('/&?(?:bulk_(?:done|act)|undo|undone)=[^&]*/', '', $back);
        $taxId = static function (string $k) use ($post): ?int {
            $v = self::str($post[$k] ?? '');

            return ctype_digit($v) ? (int)$v : null;
        };
        /** @var PermissionGate $gate */
        $gate = service('permissionGate');
        $gate->require(BulkService::permission($action));
        $positive = static fn (array $ids): array => array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
        if ($action === 'labels') {
            $ids = $positive($ids);
            if ($ids === []) {
                throw new UserError('Select at least one item.', 422);
            }
            if (count($ids) > BulkService::MAX) {
                throw new UserError('Select at most ' . BulkService::MAX . ' items at once.', 422);
            }

            return $this->redirectTo('/labels?ids=' . implode(',', $ids));
        }
        /** @var UndoTokens $undo */
        $undo = service('undoTokens');
        try {
            if ($action === 'delete' && self::str($post['confirm'] ?? '') !== '1') {
                $items = array_values(array_filter(array_map(fn (int $i): ?array => $this->repo()->get($i), $positive($ids))));
                if ($items === []) {
                    throw new UserError('Select at least one item.');
                }
                if (count($items) > BulkService::MAX) {
                    throw new UserError('Select at most ' . BulkService::MAX . ' items at once.');
                }

                return $this->html($this->view11b()->bulkDelete($items, $back));
            }
            $snap = $undo->enabled() && in_array($action, self::UNDO_ACTIONS, true) && count($ids) <= BulkService::MAX ? $undo->snapshot($positive($ids)) : [];
            /** @var BulkService $bulk */
            $bulk = service('bulkService');
            $n = $bulk->run($ids, $action, ['location_id' => $taxId('location_id'), 'category_id' => $taxId('category_id'),
                'tags' => Text::tags(self::str($post['tags'] ?? '')), 'tag_id' => self::int($post['tag_id'] ?? 0), 'keep_files' => $action === 'delete' && $snap !== []]);
        } catch (UserError $e) {
            throw new UserError($e->getMessage(), 422);
        }
        $token = $n !== 0 ? $undo->create($action === 'delete' ? 'delete' : $action, ListView::bulkToast($n, $action), $snap, ['back' => $back], $action === 'delete' ? UndoTokens::files($snap) : []) : null;

        return $this->redirectTo($back . (str_contains($back, '?') ? '&' : '?') . 'bulk_done=' . $n . '&bulk_act=' . rawurlencode($action) . ($token !== null ? '&undo=' . $token : ''));
    }

    private function repo(): ItemRepository
    {
        /** @var ItemRepository $r */
        $r = service('itemRepository');

        return $r;
    }

    private function merger(): MergeService
    {
        /** @var MergeService $m */
        $m = service('mergeService');

        return $m;
    }

    private function view11b(): BulkMergeView
    {
        /** @var BulkMergeView $v */
        $v = service('bulkMergeView');

        return $v;
    }
}
