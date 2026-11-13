<?php

declare(strict_types=1);

namespace Apotheke\Backup\Filters;

use Apotheke\Backup\OpsBackupEndpoint;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Services;

/**
 * The first required filter: answers OpsBackupEndpoint's requests itself (no session, consent, host list or CSRF
 * applies to them), and lets every other request through untouched.
 */
final class OpsBackupFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $uri = $request->getServer('REQUEST_URI');
        if ($uri !== OpsBackupEndpoint::PATH) {
            return null;
        }
        $host = $request->getServer('HTTP_HOST');
        $headers = [];
        foreach ($request->headers() as $name => $header) {
            $headers[(string)$name] = is_array($header) ? implode(', ', array_map(static fn ($h): string => $h->getValueLine(), $header)) : $header->getValueLine();
        }
        $host = is_string($host) ? $host : '';
        $endpoint = Services::opsBackupEndpoint();
        if (!$endpoint->applies($uri, $host, $headers)) {
            return null;
        }
        @ignore_user_abort(true);   // a backup that has started finishes and is recorded, even if the caller gives up
        @set_time_limit(900);
        $answer = $endpoint->handle($request->getMethod(), $uri, $host, $headers, (string)$request->getBody());
        if ($answer === null) {
            return null;
        }
        $response = new Response(config(App::class));
        $response->setStatusCode($answer['status']);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->removeHeader('Cache-Control');
        $response->setHeader('Cache-Control', 'no-store');
        if (isset($answer['allow'])) {
            $response->setHeader('Allow', $answer['allow']);
        }

        return $response->setBody((string)json_encode($answer['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
