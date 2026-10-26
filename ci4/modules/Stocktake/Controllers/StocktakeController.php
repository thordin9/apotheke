<?php

declare(strict_types=1);

namespace Apotheke\Stocktake\Controllers;

use Apotheke\Catalog\Controllers\CatalogController;
use Apotheke\Catalog\Views\ListView;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Security\PermissionGate;
use Apotheke\Stocktake\Stocktakes;
use Apotheke\Stocktake\Views\StocktakeView;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Legacy public/index.php: /stocktake (start and history) and
 * /stocktake/{id}(/check|report|finish|cancel). Looking is "view", every
 * change "stocktake"; moving the misplaced items also needs "move".
 */
final class StocktakeController extends CatalogController
{
    /** GET /stocktake, POST /stocktake (start one) */
    public function index(): ResponseInterface
    {
        if ($this->request->getMethod() === 'POST') {
            $loc = self::str($this->request->getPost('location_id') ?? '');
            if (!ctype_digit($loc)) {
                throw new UserError('Choose a location to check.', 422);
            }

            return $this->redirectTo('/stocktake/' . $this->stocktakes()->start((int)$loc));
        }
        $loc = self::str($this->request->getGet('loc') ?? '');
        $toast = self::str($this->request->getGet('done') ?? '') === 'cancelled' ? 'Stocktake cancelled' : null;

        return $this->html($this->pages()->list($this->stocktakes()->all(), $this->taxonomy()->all('location'), ctype_digit($loc) ? (int)$loc : null, $toast));
    }

    /** /stocktake/{id} and /stocktake/{id}/{act}, any method, in legacy's order of checks */
    public function take(string $id, string $act = ''): ResponseInterface
    {
        $stocktakes = $this->stocktakes();
        $s = $stocktakes->get((int)$id) ?? throw new UserError('Stocktake not found.', 404);
        $post = $this->request->getMethod() === 'POST';
        if (!$post && ($act === '' || $act === 'report')) {
            if ($act === '' && $s['status'] === 'open') {
                return $this->html($this->pages()->take($s));
            }
            $finished = $this->request->getGet('finished');
            $toast = $finished !== null ? 'Stocktake finished' . (self::int($finished) > 0 ? ' · ' . ListView::itemNoun(self::int($finished)) . ' moved' : '') : null;

            return $this->html($this->pages()->report($s, $toast));
        }
        if (!$post || $act === '' || $act === 'report') {
            throw new UserError('Page not found.', 404);
        }
        if ($act === 'cancel') {
            $stocktakes->cancel($s['id']);

            return $this->redirectTo('/stocktake?done=cancelled');
        }
        if ($act === 'finish') {
            $moves = $this->request->getPost('moves') === '1';
            if ($moves) {
                /** @var PermissionGate $gate */
                $gate = service('permissionGate');
                $gate->require('move');
            }

            return $this->redirectTo('/stocktake/' . $s['id'] . '/report?finished=' . $stocktakes->finish($s['id'], $moves));
        }
        // check: by item id (tick in the list) or by a scanned / typed code
        $found = $this->request->getPost('found') ?? '1';
        $found = $found !== '0';
        try {
            $item = self::str($this->request->getPost('item_id') ?? '');
            $iid = ctype_digit($item) ? (int)$item : $stocktakes->resolveCode(self::str($this->request->getPost('code') ?? ''));
            $r = $stocktakes->check($s['id'], $iid, $found);
        } catch (UserError $e) {
            $code = (int)$e->getCode();
            if ($this->wantsJson()) {
                return $this->json(['ok' => false, 'error' => $e->getMessage()], $code !== 0 ? $code : 422);
            }

            return $this->html($this->pages()->take($s, $e->getMessage()), $code >= 400 ? $code : 422);
        }
        $msg = match ($r['state']) {
            'found' => 'Found “' . $r['name'] . '”', 'missing' => 'Unticked “' . $r['name'] . '”',
            'misplaced' => '“' . $r['name'] . '” belongs in ' . ($r['home'] !== '' ? $r['home'] : 'no location') . ' – marked as misplaced',
            default => 'Removed “' . $r['name'] . '”',
        };
        if ($this->wantsJson()) {
            $now = $stocktakes->get($s['id']) ?? $s;

            return $this->json(['ok' => true, 'state' => $r['state'], 'message' => $msg, 'html' => $this->pages()->body($now)]);
        }

        return $this->redirectTo('/stocktake/' . $s['id']);
    }

    private function stocktakes(): Stocktakes
    {
        /** @var Stocktakes $s */
        $s = service('stocktakes');

        return $s;
    }

    private function pages(): StocktakeView
    {
        /** @var StocktakeView $v */
        $v = service('stocktakeView');

        return $v;
    }
}
