<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Controllers;

use Apotheke\Activity\ActivityLog;
use Apotheke\Catalog\Items\FormAutofill;
use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Items\UndoApply;
use Apotheke\Catalog\RecentItems;
use Apotheke\Catalog\Views\ItemView;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\CookieConsent;
use Apotheke\Core\Http\ErrorPage;
use Apotheke\Core\Libraries\Text;
use Apotheke\Core\Security\PermissionGate;
use Apotheke\Media\ImageStore;
use Apotheke\Taxonomy\Kind;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * The item page, the add and edit forms, saving, deleting and Undo (legacy
 * public/index.php: GET /new, GET /item/{id}[/edit], POST /save,
 * POST /item/{id}/delete, POST /undo).
 */
final class ItemController extends CatalogController
{
    /** GET /new: the add form, with the defaults and a "Saved …" toast after Save & new. */
    public function create(): ResponseInterface
    {
        $get = $this->get();
        $tax = $this->taxonomy();
        $pick = function (string $kind) use ($get, $tax): ?int {
            $v = self::str($get[Kind::get($kind)['param']] ?? '');

            return ctype_digit($v) && $tax->get($kind, (int)$v) !== null ? (int)$v : $tax->defaultId($kind);
        };
        $vals = ['name' => '', 'location_id' => $pick('location'), 'new_location' => '', 'category_id' => $pick('category'), 'new_category' => '',
            'serial' => '', 'barcode' => ItemInput::barcode(self::str($get['barcode'] ?? ''))] + $this->defaults();
        $vals['af_defaults'] = array_keys(array_filter([
            'category' => !isset($get['cat']) && $vals['category_id'] !== null,
            'tags' => $vals['tags'] !== '',
            'notes' => $vals['notes'] !== '',
        ]));
        $toast = isset($get['saved']) && ($s = $this->repo()->get(self::int($get['saved']))) !== null ? 'Saved “' . $s['name'] . '”' : null;

        return $this->html($this->form(null, $vals, null, $toast));
    }

    /** GET /item/{id}: the item page; records the view for "Recently viewed". */
    public function show(string $id): ResponseInterface
    {
        $it = $this->repo()->get((int)$id);
        if ($it === null) {
            return $this->missing();
        }
        $iid = (int)$it['id'];
        // No cookie consent: no device cookie, so nothing is recorded under a scope no one can read back.
        $cookie = $this->wantsJson() || !CookieConsent::request() ? null : $this->recent()->record($iid);
        /** @var ActivityLog $log */
        $log = service('activityLog');
        $hist = $log->list($iid, 1, 10);
        $get = $this->get();
        $toast = isset($get['undone']) ? 'Undone' : null;
        $toast ??= isset($get['edited']) ? 'Photo saved as a new version' : (isset($get['reordered']) ? 'Photo order saved' : null);
        $toast ??= isset($get['merged']) ? 'Merged “' . Text::cleanLine(self::str($get['merged']), 200) . '” into this item' : null;
        $html = $this->itemView()->item($it, $this->repo()->photos($iid), max(0, self::int($get['p'] ?? 0)), array_slice($hist, 0, 10), count($hist) > 10,
            $this->repo()->attachments($iid), $toast, $this->taxonomy()->locationsById());
        $response = $this->html($html);
        if ($cookie !== null) {
            $response->setCookie($cookie);   // appended to the session's Set-Cookie, never replacing it
        }

        return $response;
    }

    /** GET /item/{id}/edit: the edit form. */
    public function edit(string $id): ResponseInterface
    {
        $it = $this->repo()->get((int)$id);
        if ($it === null) {
            return $this->missing();
        }
        $vals = ['new_location' => '', 'new_category' => '', 'tags' => implode(', ', array_column($it['tags'], 'name')),
            'value' => ItemInput::moneyInput($it['value_cents']), 'purchase_date' => (string)$it['purchase_date'], 'expires_on' => (string)($it['expires_on'] ?? ''),
            'min_qty' => $it['min_qty'] === null ? '' : (string)$it['min_qty']] + $it;

        return $this->html($this->form($it, $vals, null, isset($this->get()['copied']) ? 'Copy created – edit it here' : null));
    }

    /** POST /save: create or update; a UserError shows the form again (422). */
    public function save(): ResponseInterface
    {
        $post = $this->request->getPost();
        $post = is_array($post) ? $post : [];
        $id = self::int($post['id'] ?? 0);
        $existing = $id > 0 ? $this->repo()->get($id) : null;
        if ($id > 0 && $existing === null) {
            throw new UserError('Item not found.', 404);
        }
        $qty = filter_var($post['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
        $vals = [
            'name' => Text::cleanLine(self::str($post['name'] ?? ''), 200),
            'quantity' => $qty === false ? 1 : $qty,
            'notes' => mb_substr(trim(str_replace("\r\n", "\n", self::str($post['notes'] ?? ''))), 0, 5000),
            'tags' => mb_substr(self::str($post['tags'] ?? ''), 0, 2000),
            'value' => Text::cleanLine(self::str($post['value'] ?? ''), 32),
            'purchase_date' => Text::cleanLine(self::str($post['purchase_date'] ?? ''), 10),
            'expires_on' => Text::cleanLine(self::str($post['expires_on'] ?? ''), 10),
            'serial' => Text::cleanLine(self::str($post['serial'] ?? ''), 100),
            'barcode' => ItemInput::barcode(self::str($post['barcode'] ?? '')),
            'min_qty' => Text::cleanLine(self::str($post['min_qty'] ?? ''), 12),
        ];
        $raw = [];
        foreach (Kind::KINDS as $kind) {
            $col = Kind::get($kind)['col'];
            $raw[$kind] = self::str($post[$col] ?? '');
            $vals[$col] = ctype_digit($raw[$kind]) ? (int)$raw[$kind] : null;
            $vals['new_' . $kind] = Text::cleanLine(self::str($post['new_' . $kind] ?? ''), 120);
            if ($vals['new_' . $kind] === '') {
                $vals['new_' . $kind] = Text::cleanLine(self::str($post['new_' . $kind . '_ns'] ?? ''), 120);
            }
        }
        // changing the location of an existing item (or adding a new location on the way) needs "move"
        if ($existing !== null && ((array_key_exists('location_id', $post) && $vals['location_id'] !== $existing['location_id']) || $vals['new_location'] !== '')) {
            /** @var PermissionGate $gate */
            $gate = service('permissionGate');
            $gate->require('move');
        }
        $tax = $this->taxonomy();
        /** @var ImageStore $images */
        $images = service('imageStore');
        $undo = $this->undo();
        $before = $existing !== null ? $undo->snapshot([(int)$existing['id']]) : [];
        $newImage = null;
        $savedId = null;
        $fields = [];
        try {
            if ($vals['name'] === '') {
                throw new UserError('Please give the item a name.');
            }
            if ($qty === false) {
                throw new UserError('Quantity must be a whole number between 0 and 1,000,000.');
            }
            $fields = ['tags' => Text::tags($vals['tags']), 'value_cents' => ItemInput::money($vals['value']),
                'purchase_date' => ItemInput::purchaseDate($vals['purchase_date'], $this->calendar()->now()->modify('+86400 seconds')->format('Y-m-d')),
                'expires_on' => ItemInput::expiryDate($vals['expires_on']), 'serial' => $vals['serial'], 'barcode' => $vals['barcode'], 'min_qty' => ItemInput::minQty($vals['min_qty'])];
            foreach (Kind::KINDS as $kind) {
                $t = Kind::get($kind);
                if ($vals['new_' . $kind] === '' && $raw[$kind] === '__new') {
                    throw new UserError('Type a name for the new ' . $t['one'] . '.');
                }
                if ($vals[$t['col']] !== null && $tax->get($kind, $vals[$t['col']]) === null) {
                    throw new UserError('That ' . $t['one'] . ' no longer exists. Please pick another.');
                }
            }
        } catch (UserError $e) {
            $savedId = $e;
        }
        if ($savedId === null) {
            // The photo is stored and decoded before the transaction, so a write that commits meanwhile
            // cannot make the save fail (M-91). It stays held until the save commits (L-81).
            $storeError = null;
            try {
                $file = $this->upload(['photo', 'photo2']);
                if ($file !== null) {
                    $newImage = $images->store($file->getTempName(), (int)$file->getSize(), $file->getError());
                    $images->hold($newImage);
                }
            } catch (UserError $e) {
                $storeError = $e;
            }
            // One transaction: a failed save also drops the new location or category, and the photo's files (L-81).
            // A UserError before the save still keeps them, as on legacy, and shows the form again.
            try {
                $savedId = $this->repo()->atomically(function () use (&$vals, $fields, $existing, $post, $tax, $newImage, $storeError) {
                    try {
                        foreach (Kind::KINDS as $kind) {
                            if ($vals['new_' . $kind] !== '') {
                                $vals[Kind::get($kind)['col']] = (int)$tax->create($kind, $vals['new_' . $kind])['id'];
                                $vals['new_' . $kind] = '';
                            }
                        }
                    } catch (UserError $e) {
                        return $e;
                    }

                    return $storeError ?? $this->repo()->save($existing, $fields + $vals, $newImage, !empty($post['remove_image']));
                });
            } catch (Throwable $e) {
                if ($newImage !== null) {
                    $images->delete($newImage);
                    $images->release($newImage);
                }
                throw $e;
            }
            if ($newImage !== null) {
                if ($savedId instanceof UserError) {
                    $images->delete($newImage);
                }
                $images->release($newImage);
            }
        }
        if ($savedId instanceof UserError) {
            $e = $savedId;
            if ($this->wantsJson()) {
                return $this->json(['ok' => false, 'error' => $e->getMessage()], 422);
            }

            // Legacy's form page does not call no_store(): PHP's session header stays (parity gate, QA L-471).
            return $this->html($this->form($existing, $vals, $e->getMessage()), 422)
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate');
        }
        $token = null;
        if ($before !== []) {
            $strip = static fn (array $s): array => [array_diff_key($s['row'], ['updated_at' => 1, 'search' => 1, 'image' => 1]), $s['tags']];
            $now = $undo->snapshot([$savedId]);
            if ($now !== [] && $strip($now[0]) !== $strip($before[0])) {
                $token = $undo->create('edit', 'Saved “' . $vals['name'] . '”', $before);
            }
        }
        if (self::str($post['after'] ?? 'view') === 'another') {
            $qs = ['loc' => $vals['location_id'], 'cat' => $vals['category_id'], 'saved' => $savedId];

            return $this->redirectTo('/new?' . http_build_query(array_filter($qs, static fn ($v): bool => $v !== null)));
        }

        return $this->redirectTo($existing !== null ? '/item/' . $savedId . ($token !== null ? '?undo=' . $token : '') : '/?saved=' . $savedId);
    }

    /** POST /item/{id}/delete: delete it; with Undo on, its files wait for the token. */
    public function delete(string $id): ResponseInterface
    {
        $it = $this->repo()->get((int)$id) ?? throw new UserError('Item not found.', 404);
        $undo = $this->undo();
        $snap = $undo->enabled() ? $undo->snapshot([(int)$it['id']]) : [];
        $this->repo()->delete($it, $snap !== []);
        $token = $undo->create('delete', 'Deleted “' . $it['name'] . '”', $snap, [], UndoTokens::files($snap));

        return $this->redirectTo('/?deleted=1' . ($token !== null ? '&undo=' . $token : ''));
    }

    /** POST /undo: put the items back. */
    public function undoAction(): ResponseInterface
    {
        $this->undo()->prune();
        /** @var UndoApply $apply */
        $apply = service('undoApply');
        $r = $apply->apply(self::str($this->request->getPost('token') ?? ''));
        $n = count($r['ids']);
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'action' => $r['action'], 'ids' => $r['ids']]);
        }
        if ($r['back'] !== '' && preg_match('#^/\?[^\r\n]*$#D', $r['back']) === 1) {
            return $this->redirectTo($r['back'] . '&undone=' . $n);
        }

        return $this->redirectTo($n === 1 ? '/item/' . $r['ids'][0] . '?undone=1' : '/?undone=' . $n);
    }

    /**
     * @param array<string,mixed>|null $it
     * @param array<string,mixed> $vals
     */
    private function form(?array $it, array $vals, ?string $error, ?string $toast = null): string
    {
        /** @var FormAutofill $af */
        $af = service('formAutofill');
        $attrs = $af->attrs();
        if ($attrs !== null) {
            $attrs['photos'] = $it === null ? [] : $this->repo()->photos((int)$it['id']);
        }
        $tax = $this->taxonomy();

        return $this->itemView()->form($it, $vals, $tax->all('location'), $tax->all('category'), $this->tags()->inUse(), $attrs, $error, $toast);
    }

    /** @return array<string,mixed> legacy new_item_defaults() */
    private function defaults(): array
    {
        $s = $this->settings();
        $today = $this->calendar()->today();
        $months = $s->get('default_expiry_months');

        return [
            'quantity' => (int)$s->get('default_quantity'),
            'min_qty' => $s->get('default_min_qty') === null ? '' : (string)$s->get('default_min_qty'),
            'tags' => (string)$s->get('default_tags'),
            'notes' => (string)$s->get('default_notes'),
            'value' => '',
            'purchase_date' => $s->get('default_purchase_today') ? $today : '',
            'expires_on' => $months === null ? '' : (new \DateTimeImmutable($today))->modify('+' . (int)$months . ' months')->format('Y-m-d'),
        ];
    }

    /** @param list<string> $fields legacy pick_upload(): the first single file sent */
    private function upload(array $fields): ?UploadedFile
    {
        foreach ($fields as $f) {
            $file = $this->request->getFile($f);
            if ($file instanceof UploadedFile && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                return $file;
            }
        }

        return null;
    }

    /** Legacy view_error(404, 'Item not found.'): the error page even for a fetch. */
    private function missing(): ResponseInterface
    {
        /** @var ErrorPage $page */
        $page = service('errorPage');

        return $this->response->setStatusCode(404)->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody($page->html(404, 'Item not found.'));
    }

    /** @return array<string,mixed> */
    private function get(): array
    {
        $get = $this->request->getGet();

        return is_array($get) ? $get : [];
    }

    private function repo(): ItemRepository
    {
        /** @var ItemRepository $r */
        $r = service('itemRepository');

        return $r;
    }

    private function undo(): UndoTokens
    {
        /** @var UndoTokens $u */
        $u = service('undoTokens');

        return $u;
    }

    private function recent(): RecentItems
    {
        /** @var RecentItems $r */
        $r = service('recentItems');

        return $r;
    }

    private function itemView(): ItemView
    {
        /** @var ItemView $v */
        $v = service('itemView');

        return $v;
    }
}
