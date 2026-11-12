<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\ApiSecurityPolicy;
use Apotheke\Core\Http\Cors;
use Apotheke\Core\Security\PermissionGate;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * GET /upload/check: the probe the FrankenPHP image asks before it reads a request body over 64 KiB, or one
 * without a length (docker/frankenphp/Caddyfile, M-1420). 204 when the request is signed in, or sign-in is off;
 * 401 when it is not. Which permission the upload itself needs is the target route's business, so a signed-in
 * role without "backup" still gets 204. Starts no session and has no body, like /backup/restore/check.
 * For an /api request (X-Forwarded-Uri, set by forward_auth) the refusal is the API's problem+json, with the
 * API headers and, for a listed origin, the CORS headers: 413 `upload.too_large` when the declared length
 * (X-Apotheke-Body-Length, set by the Caddyfile) is over 64 KiB, else 401 `auth.required` (a body without a
 * length). Caddy passes it on as is.
 */
final class UploadCheckController extends Controller
{
    public const API_DETAIL = 'A request body over 64 KiB needs a signed-in session.';

    public const SMALL_BODY = 65536;

    public function check(): ResponseInterface
    {
        /** @var PermissionGate $gate */
        $gate = service('permissionGate');
        try {
            $gate->require('backup');
            $status = 204;
        } catch (UserError $e) {
            $status = $e->getMessage() === 'Not signed in' ? 401 : 204;
        }
        $path = '/' . ltrim(explode('?', $this->request->getHeaderLine('X-Forwarded-Uri'), 2)[0], '/');
        if ($status === 401 && ApiResponse::isApi($path)) {
            $length = $this->request->getHeaderLine('X-Apotheke-Body-Length');
            $response = preg_match('/^[0-9]{1,15}$/', $length) === 1 && (int)$length > self::SMALL_BODY
                ? ApiResponse::problem($this->response, 413, 'upload.too_large', self::API_DETAIL)
                : ApiResponse::problem($this->response, 401, 'auth.required', 'Sign in first.');
            $https = str_starts_with(strtolower((string)Services::settings()->get('public_url')), 'https://');
            $response->removeHeader('X-Frame-Options');
            foreach ((new ApiSecurityPolicy())->headers($https) as $name => $value) {
                $response->removeHeader($name)->setHeader($name, $value);
            }
            $cors = new Cors(Services::apothekeConfig()->corsAllowedOrigins(), ENVIRONMENT === 'development');
            if ($cors->origins() !== []) {
                $response->setHeader('Vary', 'Origin');
                foreach ($cors->headers($this->request->getHeaderLine('Origin'), false) as $name => $value) {
                    $response->setHeader($name, $value);
                }
            }

            return $response;
        }

        return $this->response->setStatusCode($status)->setBody('')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
    }
}
