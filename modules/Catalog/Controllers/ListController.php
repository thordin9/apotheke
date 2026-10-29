<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Controllers;

use Apotheke\Catalog\ItemList;
use Apotheke\Catalog\ListFilters;
use Apotheke\Catalog\RecentItems;
use Apotheke\Catalog\Views\ListView;
use Apotheke\Core\Libraries\Text;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET / (the list and search, legacy "list / search") and GET /stats (the
 * overview's body, loaded when the panel opens). A fetch gets legacy's JSON
 * page of cards.
 */
final class ListController extends CatalogController
{
    public function index(): ResponseInterface
    {
        $get = $this->request->getGet();
        $get = is_array($get) ? $get : [];
        $default = (string)$this->settings()->get('default_sort');
        $rawQ = is_string($get['q'] ?? null) ? $get['q'] : '';
        $q = Text::cleanLine(self::str($get['q'] ?? ''), 200);
        $f = ListFilters::from($get);
        $sort = ListFilters::sort($get['sort'] ?? null, $default);
        $page = ItemList::clampPage(self::int($get['page'] ?? 1));
        $items = $this->items();
        $res = $items->search($q, $f, $sort, $default, $page, (int)$this->settings()->get('page_size'));
        $view = $this->view();
        $select = ($get['select'] ?? '') === '1';
        $locs = $this->taxonomy()->all('location');
        $cats = $this->taxonomy()->all('category');
        $tags = $this->tags()->inUse();
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'html' => $view->cards($res['items'], $select), 'total' => $res['total'],
                'count' => ListView::countText($res['total'], $q !== '' || ListFilters::active($f)) . ListView::fixedNote($res['fixed']), 'more' => $res['more'], 'page' => $page,
                'chips' => $view->activeChips($f, $sort, $locs, $cats, $tags, $rawQ), 'nfilters' => ListFilters::activeCount($f, $sort, $default), 'query' => ListFilters::savedQuery($get, $default)]);
        }
        $toast = null;
        if (isset($get['saved']) && ($s = $items->find(self::int($get['saved']))) !== null) {
            $toast = 'Saved “' . $s['name'] . '”';
        }
        if (isset($get['deleted'])) {
            $toast = 'Item deleted';
        }
        if (isset($get['undone'])) {
            $toast = 'Undone';
        }
        if (isset($get['bulk_done'])) {
            $toast = ListView::bulkToast(self::int($get['bulk_done']), self::str($get['bulk_act'] ?? ''));
        }
        if (isset($get['filter_saved']) && ($sf = $this->saved()->get(self::int($get['filter_saved']))) !== null) {
            $toast = 'Saved filter “' . $sf['name'] . '”';
        }
        $cookie = $_COOKIE['stats_open'] ?? ($this->settings()->get('overview_open') ? '1' : '0');
        /** @var RecentItems $recent */
        $recent = service('recentItems');

        return $this->html($view->page($q, $rawQ, $f, $sort, $res, $locs, $cats, $items->stats(), $this->catalogStats(), $tags, $items->favouriteCount(),
            $this->saved()->all(), $recent->items(), $toast, $cookie !== '0', $select, ($get['all'] ?? '') === '1'));
    }

    public function stats(): ResponseInterface
    {
        return $this->html($this->view()->statsBody($this->catalogStats()), 200, 'text/html; charset=utf-8');
    }
}
