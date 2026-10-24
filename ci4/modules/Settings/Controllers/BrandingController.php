<?php

declare(strict_types=1);

namespace Apotheke\Settings\Controllers;

use Apotheke\Settings\Branding\Branding;
use Apotheke\Settings\BrandStore;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Public branding routes (the sign-in page needs them too): /theme.css,
 * /labels.css, /manifest.webmanifest, /favicon.ico and /brand/{file}.
 * Bytes and headers match the legacy front controller. No session.
 */
final class BrandingController extends Controller
{
    private const IMMUTABLE = 'public, max-age=31536000, immutable';

    /** Own CSP for brand files, so an SVG opened directly can never run anything. */
    public const BRAND_CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    public function theme(): ResponseInterface
    {
        return $this->css(service('branding')->themeCss());
    }

    public function labels(): ResponseInterface
    {
        return $this->css(service('branding')->labelsCss());
    }

    public function manifest(): ResponseInterface
    {
        $json = json_encode(service('branding')->manifest(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $this->plain(200, 'application/manifest+json', 'no-cache', is_string($json) ? $json : '');
    }

    public function favicon(): ResponseInterface
    {
        /** @var Branding $branding */
        $branding = service('branding');
        $bytes = $branding->favicon();
        $response = $this->plain(200, 'image/vnd.microsoft.icon', 'public, max-age=86400', $bytes);
        $response->setHeader('Content-Length', (string)strlen($bytes));

        return $response;
    }

    public function brand(string $name = ''): ResponseInterface
    {
        /** @var BrandStore $store */
        $store = service('brandStore');
        $bytes = preg_match('#^[a-z0-9.\-]+$#D', $name) && isset(BrandStore::FILES[$name]) ? $store->read($name) : null;
        if ($bytes === null) {
            $response = service('response');
            $response->setStatusCode(404);
            $response->removeHeader('Cache-Control');
            $response->removeHeader('Content-Type');
            $response->setBody('');

            return $response;
        }
        $response = $this->plain(200, BrandStore::FILES[$name], self::IMMUTABLE, $bytes);
        $response->setHeader('Content-Length', (string)strlen($bytes));
        $response->setHeader('Content-Security-Policy', self::BRAND_CSP);

        return $response;
    }

    private function css(string $body): ResponseInterface
    {
        $versioned = $this->request->getGet('v') !== null;

        return $this->plain(200, 'text/css; charset=utf-8', $versioned ? self::IMMUTABLE : 'no-cache', $body);
    }

    private function plain(int $status, string $type, string $cache, string $body): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode($status);
        $response->removeHeader('Cache-Control');
        $response->setHeader('Content-Type', $type);
        $response->setHeader('Cache-Control', $cache);
        $response->setBody($body);

        return $response;
    }
}
