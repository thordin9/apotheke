<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\ApiSecurityPolicy;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/** API P1: the JSON envelope, RFC 9457 problem+json and the API security headers. */
final class ApiResponseTest extends CIUnitTestCase
{
    public function testJsonIsUtf8NoStoreWithUnescapedSlashes(): void
    {
        $r = ApiResponse::json(new Response(config(App::class)), ['path' => '/a/ß'], 201);
        $this->assertSame(201, $r->getStatusCode());
        $this->assertSame('application/json; charset=utf-8', $r->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        $this->assertSame('{"path":"/a/ß"}', $r->getBody());
    }

    public function testProblemCarriesTypeTitleStatusDetailAndCode(): void
    {
        $r = ApiResponse::problem(new Response(config(App::class)), 403, 'csrf.invalid', 'The CSRF check failed.');
        $this->assertSame(403, $r->getStatusCode());
        $this->assertSame('application/problem+json', $r->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $r->getHeaderLine('Cache-Control'));
        $this->assertSame(
            ['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403, 'detail' => 'The CSRF check failed.', 'code' => 'csrf.invalid'],
            json_decode((string)$r->getBody(), true)
        );
    }

    public function testValidationProblemsListTheFields(): void
    {
        $r = ApiResponse::problem(new Response(config(App::class)), 422, 'validation.failed', 'Check the fields.', ['username' => 'Required.']);
        $body = json_decode((string)$r->getBody(), true);
        $this->assertSame('Unprocessable Content', $body['title']);
        $this->assertSame(['username' => 'Required.'], $body['errors']);
    }

    public function testRetryAfterIsSentForRateLimits(): void
    {
        $r = ApiResponse::problem(new Response(config(App::class)), 429, 'rate_limited', 'Too many attempts.', [], 60);
        $this->assertSame('60', $r->getHeaderLine('Retry-After'));
    }

    public function testOnlyPathsUnderApiAreApi(): void
    {
        foreach (['/api', '/api/', '/api/v1/session', '/api/x'] as $path) {
            $this->assertTrue(ApiResponse::isApi($path), $path);
        }
        foreach (['/', '/apiary', '/item/1', '/api-docs', ''] as $path) {
            $this->assertFalse(ApiResponse::isApi($path), $path);
        }
    }

    public function testApiHeadersLockTheResponseDown(): void
    {
        $this->assertSame([
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-site',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ], (new ApiSecurityPolicy())->headers(false));
        $this->assertSame('max-age=31536000', (new ApiSecurityPolicy())->headers(true)['Strict-Transport-Security']);
    }
}
