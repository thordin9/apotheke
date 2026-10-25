<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Http\LegacyPathRouter;
use Apotheke\Core\Http\PublicBase;
use Apotheke\Core\Http\RequestScheme;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use Config\App;
use Config\Services;
use Throwable;

/**
 * CI4's base URL for this request, the way legacy public_url() on main builds
 * it (#76 L-2): the public_url setting (PUBLIC_URL when it is empty in the
 * Control Panel), otherwise this request's scheme with trust_proxy and a Host
 * that passed the allow-list, else localhost. Config\App only has a fallback
 * that reads no database; this sets the request's App config and URI before
 * any controller runs, so base_url(), site_url() and redirect()->to() follow
 * the setting. /healthz is skipped: it builds no URLs.
 */
final class PublicBaseFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        if (LegacyPathRouter::isHealth($request)) {
            return null;
        }
        $server = $request->getServer();
        $server = is_array($server) ? $server : [];
        try {
            $settings = Services::settings();
            $trustProxy = (bool)$settings->get('trust_proxy');
            $publicUrl = (string)$settings->get('public_url');
        } catch (Throwable) {
            $trustProxy = false;
            $publicUrl = ApothekeConfig::fromEnvironment()->publicUrl();
        }
        $check = Services::hostCheck(false);
        $base = (new RequestScheme($trustProxy))->publicBase($server, static fn (string $host): bool => $check->passes($host), $publicUrl);
        $uri = $request->getUri();
        (new PublicBase())->apply(config(App::class), $uri instanceof SiteURI ? $uri : null, $base);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
