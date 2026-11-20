<?php

declare(strict_types=1);

namespace Apotheke\Auth\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\Services;

/**
 * A required filter next to OpsBackupFilter: answers OpsAccountsEndpoint's requests itself (no session, consent, host
 * list or CSRF applies to them), and lets every other request through untouched.
 */
final class OpsAccountsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $uri = $request->getServer('REQUEST_URI');
        $host = $request->getServer('HTTP_HOST');
        if (!is_string($uri) || !str_starts_with($uri, '/internal/accounts/')) {
            return null;
        }
        $headers = [];
        foreach ($request->headers() as $name => $header) {
            $headers[(string)$name] = is_array($header) ? implode(', ', array_map(static fn ($h): string => $h->getValueLine(), $header)) : $header->getValueLine();
        }
        $answer = Services::opsAccountsEndpoint()->handle($request->getMethod(), $uri, is_string($host) ? $host : '', $headers, (string)$request->getBody());
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
