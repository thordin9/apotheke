<?php

declare(strict_types=1);

namespace Apotheke\Labels\Controllers;

use Apotheke\Catalog\Controllers\CatalogController;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Views\ListView;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Labels\Views\LabelsView;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Legacy public/index.php: GET /labels (all locations, one location, its
 * sub-locations or its items, or ?ids= from the list's selection) and
 * GET /item/{id}/label.
 */
final class LabelsController extends CatalogController
{
    /** Legacy BULK_MAX. */
    private const MAX = 500;

    /** GET /labels */
    public function index(): ResponseInterface
    {
        $locs = $this->taxonomy()->all('location');
        $locLabel = static fn (array $l): array => ['title' => (string)$l['name'], 'sub' => ListView::itemNoun((int)($l['total'] ?? $l['n'])), 'path' => '/?loc=' . (int)$l['id']];
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', self::str($this->request->getGet('ids') ?? ''))), static fn (int $i): bool => $i > 0)));
        if ($ids !== []) {
            if (count($ids) > self::MAX) {
                throw new UserError('Print at most ' . self::MAX . ' labels at once.', 422);
            }
            $labels = [];
            foreach ($ids as $id) {
                $it = $this->repo()->get($id);
                if ($it !== null) {
                    $labels[] = ['title' => (string)$it['name'], 'sub' => (string)$it['location'], 'path' => '/item/' . $id];
                }
            }

            return $this->html($this->labelsView()->labels(ListView::itemNoun(count($labels)) . ' selected', $labels, '/?select=1'));
        }
        $loc = $this->request->getGet('loc') ?? '';
        $loc = is_string($loc) && ($loc === 'none' || ctype_digit($loc)) ? $loc : '';
        if (ctype_digit($loc)) {
            $l = $this->taxonomy()->get('location', (int)$loc) ?? throw new UserError('Location not found.', 404);
            $byId = [];
            foreach ($locs as $r) {
                $byId[$r['id']] = $r;
            }
            $base = '/labels?loc=' . $l['id'];
            $labels = [];
            if ($this->request->getGet('items') === '1') {
                foreach ($this->items()->search('', ['loc' => $loc, 'cat' => ''], 'name', '', 1, 1000)['items'] as $it) {
                    $labels[] = ['title' => (string)$it['name'], 'sub' => (string)$it['location'], 'path' => '/item/' . (int)$it['id']];
                }
            } elseif ($this->request->getGet('sub') === '1') {
                $sub = array_flip($this->taxonomy()->subtreeIds($l['id']));
                foreach ($locs as $r) {
                    if (isset($sub[$r['id']])) {
                        $labels[] = $locLabel($r);
                    }
                }
            } else {
                $labels[] = $locLabel($byId[$l['id']] ?? $l);
            }
            $opts = [$base => 'Location label'];
            if (count($this->taxonomy()->subtreeIds($l['id'])) > 1) {
                $opts[$base . '&sub=1'] = 'Location + sub-locations';
            }
            $opts += [$base . '&items=1' => 'One label per item', '/labels' => 'All locations'];

            return $this->html($this->labelsView()->labels($l['name'], $labels, '/locations', $opts));
        }

        return $this->html($this->labelsView()->labels('All locations', array_map($locLabel, $locs), '/locations'));
    }

    /** GET /item/{id}/label */
    public function item(string $id): ResponseInterface
    {
        $it = $this->repo()->get((int)$id) ?? throw new UserError('Item not found.', 404);

        return $this->html($this->labelsView()->labels((string)$it['name'], [['title' => (string)$it['name'], 'sub' => (string)$it['location'], 'path' => '/item/' . (int)$it['id']]], '/item/' . (int)$it['id']));
    }

    private function repo(): ItemRepository
    {
        /** @var ItemRepository $r */
        $r = service('itemRepository');

        return $r;
    }

    private function labelsView(): LabelsView
    {
        /** @var LabelsView $v */
        $v = service('labelsView');

        return $v;
    }
}
