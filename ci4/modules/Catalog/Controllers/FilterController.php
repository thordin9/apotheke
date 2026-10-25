<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Controllers;

use Apotheke\Catalog\ListFilters;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Taxonomy\Kind;
use CodeIgniter\HTTP\ResponseInterface;

/** /filters: the saved filters page, save the list's view, rename and delete (legacy "saved filters"). */
final class FilterController extends CatalogController
{
    public function index(): ResponseInterface
    {
        $done = $this->request->getGet('done');
        $toast = match (is_string($done) ? $done : '') {
            'renamed' => 'Filter renamed', 'deleted' => 'Filter deleted', default => null,
        };

        return $this->html($this->listPage(null, $toast));
    }

    public function create(): ResponseInterface
    {
        $post = $this->request->getPost();
        $post = is_array($post) ? $post : [];
        $query = ListFilters::savedQuery($post, (string)$this->settings()->get('default_sort'));
        try {
            $sid = $this->saved()->create(self::str($post['name'] ?? ''), $query);
        } catch (UserError $e) {
            if ($this->wantsJson()) {
                return $this->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
            }
            throw $e;
        }
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'id' => $sid, 'html' => $this->view()->savedChips($this->saved()->all(), $query)]);
        }

        return $this->redirectTo('/?' . ($query !== '' ? $query . '&' : '') . 'filter_saved=' . $sid);
    }

    public function act(string $id, string $act): ResponseInterface
    {
        $sid = (int)$id;
        try {
            $act === 'rename' ? $this->saved()->rename($sid, self::str($this->request->getPost('name') ?? '')) : $this->saved()->delete($sid);
        } catch (UserError $e) {
            if ($e->getCode() === 404) {
                throw $e;
            }

            return $this->html($this->listPage($e->getMessage(), null), $e->getCode() ?: 422);
        }

        return $this->redirectTo('/filters?done=' . ($act === 'rename' ? 'renamed' : 'deleted'));
    }

    private function listPage(?string $error, ?string $toast): string
    {
        return $this->view()->savedFiltersPage($this->saved()->all(), $this->describe(...), $error, $toast);
    }

    /** "“drill” · Garage › Shelf · Tools · #winter · Running low · sorted by Name" (legacy saved_filter_describe()). */
    private function describe(string $query): string
    {
        parse_str($query, $p);
        $f = ListFilters::from($p);
        $parts = [];
        if (($p['q'] ?? '') !== '') {
            $parts[] = '“' . self::str($p['q']) . '”';
        }
        foreach (['loc' => 'location', 'cat' => 'category'] as $k => $kind) {
            if ($f[$k] === 'none') {
                $parts[] = Kind::get($kind)['none'];
            } elseif ($f[$k] !== '') {
                $parts[] = $kind === 'location' ? ($this->taxonomy()->locationPath((int)$f[$k]) ?: '?') : ($this->taxonomy()->get($kind, (int)$f[$k])['name'] ?? '?');
            }
        }
        if ($f['tag'] !== '') {
            foreach ($this->tags()->inUse() as $t) {
                if ((string)$t['id'] === $f['tag']) {
                    $parts[] = '#' . $t['name'];
                }
            }
        }
        foreach (ListFilters::DEFS as $k => $d) {
            if ($f[$k] !== '') {
                $parts[] = $d['opts'][$f[$k]];
            }
        }
        if (isset($p['sort']) && is_string($p['sort']) && isset(ListFilters::SORTS[$p['sort']])) {
            $parts[] = 'sorted by ' . ListFilters::SORTS[$p['sort']];
        }

        return implode(' · ', $parts);
    }
}
