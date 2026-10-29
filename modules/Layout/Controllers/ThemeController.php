<?php

declare(strict_types=1);

namespace Apotheke\Layout\Controllers;

use Apotheke\Core\Http\WantsJson;
use Apotheke\Layout\Theme;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * POST /theme (legacy public/index.php): the menu's Auto / Light / Dark. Sets
 * the `theme` cookie, then answers {ok, theme} to fetch or sends the browser
 * back to a same-site path. CSRF is checked by the global filter.
 */
final class ThemeController extends Controller
{
    public function save(): ResponseInterface
    {
        $theme = Theme::valid($this->request->getPost('theme') ?? 'auto');
        $this->response->setHeader('Set-Cookie', Theme::cookie($theme, time()));
        $server = $this->request->getServer();
        if ((new WantsJson())->check(is_array($server) ? $server : [])) {
            return $this->response->setStatusCode(200)
                ->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
                ->setBody((string)json_encode(['ok' => true, 'theme' => $theme], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $back = $this->request->getPost('back');

        return $this->response->setStatusCode(303)->setHeader('Location', Theme::safeBack(is_string($back) ? $back : '/'));
    }
}
