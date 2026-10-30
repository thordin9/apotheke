<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Security\PermissionGate;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /upload/check: the probe the FrankenPHP image asks before it reads a request body over 64 KiB, or one
 * without a length (docker/frankenphp/Caddyfile, M-1420). 204 when the request is signed in, or sign-in is off;
 * 401 when it is not. Which permission the upload itself needs is the target route's business, so a signed-in
 * role without "backup" still gets 204. Starts no session and has no body, like /backup/restore/check.
 */
final class UploadCheckController extends Controller
{
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

        return $this->response->setStatusCode($status)->setBody('')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
    }
}
