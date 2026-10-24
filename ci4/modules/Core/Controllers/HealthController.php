<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Exceptions\DatabaseUnavailable;
use Apotheke\Core\Libraries\Version;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/** /healthz and /health. Bytes match the legacy front controller. */
final class HealthController extends Controller
{
    public function healthz(): ResponseInterface
    {
        $response = service('response');
        $response->removeHeader('Cache-Control');
        $response->setHeader('Content-Type', 'text/plain');
        $response->setHeader('X-Apotheke-Version', Version::current());
        if (!$this->databaseUp()) {
            return $this->databaseDown($response);
        }
        $response->setStatusCode(200);
        $response->setBody('ok');

        return $response;
    }

    public function health(): ResponseInterface
    {
        $response = service('response');
        $response->removeHeader('Cache-Control');
        $response->setHeader('Content-Type', 'application/json');
        $response->setHeader('Cache-Control', 'no-store');
        if (!$this->databaseUp()) {
            return $this->databaseDown($response);
        }
        $version = Version::current();
        $json = json_encode(['status' => 'ok', 'version' => $version]);
        $response->setStatusCode(200);
        $response->setBody(is_string($json) ? $json : '{"status":"ok"}');

        return $response;
    }

    /** A refused connect logs only the allowlisted line, as legacy db() does. */
    private function databaseUp(): bool
    {
        try {
            service('apothekeDb');
        } catch (DatabaseUnavailable $e) {
            error_log($e->getMessage());

            return false;
        }

        return true;
    }

    /** Legacy answers 500 with an empty body and the headers it had already sent. */
    private function databaseDown(ResponseInterface $response): ResponseInterface
    {
        $response->setStatusCode(500);
        $response->setBody('');

        return $response;
    }
}
