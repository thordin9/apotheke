<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Controllers;

use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\ItemList;
use Apotheke\Catalog\SavedFilters;
use Apotheke\Catalog\Stats;
use Apotheke\Catalog\Views\ListView;
use Apotheke\Core\Http\WantsJson;
use Apotheke\Core\Libraries\Version;
use Apotheke\Layout\Page;
use Apotheke\Settings\SettingsService;
use Apotheke\Taxonomy\TagRepository;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared answers of the Catalog and Favourites routes: legacy's no-store
 * pages, json_out() and redirect() (303, or {ok, redirect} for fetch), and
 * legacy's casts of request values.
 */
abstract class CatalogController extends Controller
{
    /** Legacy (string)$_GET/$_POST[...]: an array reads as "Array". */
    protected static function str(mixed $v): string
    {
        return is_array($v) ? 'Array' : (is_scalar($v) ? (string)$v : '');
    }

    /** Legacy (int)$_GET/$_POST[...]: an array is 0 when empty, else 1. */
    protected static function int(mixed $v): int
    {
        return is_array($v) ? ($v === [] ? 0 : 1) : (is_scalar($v) ? (int)$v : 0);
    }

    protected function wantsJson(): bool
    {
        $server = $this->request->getServer();

        return (new WantsJson())->check(is_array($server) ? $server : []);
    }

    protected function redirectTo(string $to): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'redirect' => $to]);
        }

        return $this->response->setStatusCode(303)->setHeader('Location', $to);
    }

    /** @param string $type legacy's pages keep PHP's default header; /stats sends its own */
    protected function html(string $html, int $status = 200, string $type = 'text/html; charset=UTF-8'): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Content-Type', $type)
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody($html);
    }

    /** @param array<string,mixed> $data */
    protected function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function view(): ListView
    {
        $s = $this->settings();
        $keys = ['default_sort', 'list_view', 'currency', 'currency_position', 'money_format', 'app_name'];
        $values = array_combine($keys, array_map(static fn (string $k): string => (string)$s->get($k), $keys));
        /** @var Page $page */
        $page = service('layout');

        return new ListView($page, $this->calendar(), $values, Version::current(), $s->signInEnabled());
    }

    protected function settings(): SettingsService
    {
        /** @var SettingsService $s */
        $s = service('settings');

        return $s;
    }

    protected function calendar(): Calendar
    {
        /** @var Calendar $c */
        $c = service('calendar');

        return $c;
    }

    protected function items(): ItemList
    {
        /** @var ItemList $i */
        $i = service('itemList');

        return $i;
    }

    protected function catalogStats(): Stats
    {
        /** @var Stats $s */
        $s = service('catalogStats');

        return $s;
    }

    protected function saved(): SavedFilters
    {
        /** @var SavedFilters $s */
        $s = service('savedFilters');

        return $s;
    }

    protected function taxonomy(): TaxonomyRepository
    {
        /** @var TaxonomyRepository $t */
        $t = service('taxonomy');

        return $t;
    }

    protected function tags(): TagRepository
    {
        /** @var TagRepository $t */
        $t = service('tags');

        return $t;
    }
}
