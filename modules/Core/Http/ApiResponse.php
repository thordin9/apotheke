<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * The /api/v1 answers (API plan §3): JSON in UTF-8, and errors as RFC 9457
 * application/problem+json with a stable machine `code` (`auth.required`,
 * `csrf.invalid`, `permission.denied`, `validation.failed`, `not_found`, …)
 * and, for 422, an `errors` field map. Never cached.
 */
final class ApiResponse
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** The API's paths: /api and everything under /api/. */
    public static function isApi(string $path): bool
    {
        return $path === '/api' || str_starts_with($path, '/api/');
    }

    public static function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        return $response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($data, self::FLAGS));
    }

    /** @param array<string,string> $errors field => message, for 422 */
    public static function problem(ResponseInterface $response, int $status, string $code, string $detail, array $errors = [], ?int $retryAfter = null): ResponseInterface
    {
        $response->setStatusCode($status);
        $body = ['type' => 'about:blank', 'title' => $response->getReasonPhrase(), 'status' => $status, 'detail' => $detail, 'code' => $code];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        if ($retryAfter !== null) {
            $response->setHeader('Retry-After', (string)$retryAfter);
        }

        return $response->setHeader('Content-Type', 'application/problem+json')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($body, self::FLAGS));
    }
}
