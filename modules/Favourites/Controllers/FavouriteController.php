<?php

declare(strict_types=1);

namespace Apotheke\Favourites\Controllers;

use Apotheke\Catalog\ItemList;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\WantsJson;
use Apotheke\Favourites\Favourites;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/** POST /item/{id}/fav (the star) and POST /recent/clear (legacy "favourites / recently viewed"). */
final class FavouriteController extends Controller
{
    public function star(string $id): ResponseInterface
    {
        $iid = (int)$id;
        /** @var ItemList $items */
        $items = service('itemList');
        $it = $items->find($iid) ?? throw new UserError('Item not found.', 404);
        $raw = $this->request->getPost('on');
        $on = $raw !== null ? self::str($raw) === '1' : !$it['favourite'];
        $this->favourites()->set($iid, $on);
        if ($this->wantsJson()) {
            return $this->response->setStatusCode(200)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
                ->setBody((string)json_encode(['ok' => true, 'favourite' => $on, 'count' => $this->favourites()->count()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $back = self::str($this->request->getPost('back') ?? '');

        return $this->redirectTo(preg_match('#^/(\?[^\r\n]*)?$|^/item/\d+$#D', $back) === 1 ? $back : '/item/' . $iid);
    }

    public function clearRecent(): ResponseInterface
    {
        $this->favourites()->clearRecent();

        return $this->redirectTo('/');
    }

    /** Legacy (string)$_POST[...]: an array reads as "Array". */
    private static function str(mixed $v): string
    {
        return is_array($v) ? 'Array' : (is_scalar($v) ? (string)$v : '');
    }

    private function wantsJson(): bool
    {
        $server = $this->request->getServer();

        return (new WantsJson())->check(is_array($server) ? $server : []);
    }

    /** Legacy redirect(): 303, or {ok, redirect} for fetch. */
    private function redirectTo(string $to): ResponseInterface
    {
        if ($this->wantsJson()) {
            return $this->response->setStatusCode(200)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
                ->setBody((string)json_encode(['ok' => true, 'redirect' => $to], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $this->response->setStatusCode(303)->setHeader('Location', $to);
    }

    private function favourites(): Favourites
    {
        /** @var Favourites $f */
        $f = service('favourites');

        return $f;
    }
}
