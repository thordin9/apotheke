<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Libraries\Version;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/** /healthz and /health. Bytes match the legacy front controller. */
final class HealthController extends Controller
{
    public function healthz(): ResponseInterface
    {
        service('apothekeDb');
        $response = service('response');
        $response->setStatusCode(200);
        $response->removeHeader('Cache-Control');
        $response->setHeader('Content-Type', 'text/plain');
        $response->setHeader('X-Apotheke-Version', Version::current());
        $response->setBody('ok');

        return $response;
    }

    public function health(): ResponseInterface
    {
        service('apothekeDb');
        $version = Version::current();
        $json = json_encode(['status' => 'ok', 'version' => $version]);
        $response = service('response');
        $response->setStatusCode(200);
        $response->removeHeader('Cache-Control');
        $response->setHeader('Content-Type', 'application/json');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setBody(is_string($json) ? $json : '{"status":"ok"}');

        return $response;
    }
}
