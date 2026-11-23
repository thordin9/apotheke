<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy\Controllers\Api;

use Apotheke\Core\Http\ApiResponse;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /api/v1/locations and GET /api/v1/categories (API plan §3, Catalog row; the read half): what the Manage pages
 * and the list's filters show, for the view permission, exactly as /locations and /categories show it. Locations come
 * in tree order with their parent, depth, path and total (their own items plus every entry below them); categories
 * are flat. Each answer also says how many items have none ("No location" / "No category" on the page).
 */
final class TaxonomyController extends Controller
{
    /** GET /api/v1/locations: the tree, parents first. */
    public function locations(): ResponseInterface
    {
        return $this->answer('location');
    }

    /** GET /api/v1/categories: flat, in the page's order. */
    public function categories(): ResponseInterface
    {
        return $this->answer('category');
    }

    private function answer(string $kind): ResponseInterface
    {
        /** @var TaxonomyRepository $repo */
        $repo = service('taxonomy');
        $out = [];
        foreach ($repo->all($kind) as $e) {
            $entry = ['id' => $e['id'], 'name' => $e['name'], 'is_default' => $e['is_default'] === 1, 'items' => $e['n']];
            if ($kind === 'location') {
                $entry += ['parent_id' => $e['parent_id'], 'depth' => $e['depth'], 'path' => $e['path'], 'total' => $e['total']];
            }
            $out[] = $entry;
        }

        return ApiResponse::json($this->response, ['data' => $out, 'items_without_' . $kind => $repo->noneCount($kind)]);
    }
}
