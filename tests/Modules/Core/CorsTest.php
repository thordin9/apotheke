<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\Cors;
use PHPUnit\Framework\TestCase;

/** API plan §2.2: exact origins only, off when the list is empty. */
final class CorsTest extends TestCase
{
    public function testOnlyExactListedOriginsAreAllowed(): void
    {
        $cors = new Cors(['https://app.example.test', 'HTTPS://B.example.test:8443/'], false);
        $this->assertTrue($cors->allows('https://app.example.test'));
        $this->assertTrue($cors->allows('https://b.example.test:8443'), 'case and a trailing slash in the list');
        $this->assertFalse($cors->allows('https://app.example.test.evil.test'));
        $this->assertFalse($cors->allows('http://app.example.test'), 'scheme counts');
        $this->assertFalse($cors->allows('https://b.example.test'), 'port counts');
        $this->assertFalse($cors->allows(''));
        $this->assertFalse($cors->allows('null'));
    }

    /** QA N-1221: one trailing dot of the host is the same origin; the answer echoes the Origin the browser sent. */
    public function testATrailingDotMatchesTheListedOrigin(): void
    {
        $cors = new Cors(['https://app.example.test', 'https://dot.example.test.:8443'], false);
        $this->assertSame(['https://app.example.test', 'https://dot.example.test:8443'], $cors->origins());
        $this->assertTrue($cors->allows('https://app.example.test.'));
        $this->assertTrue($cors->allows('https://APP.example.test.'));
        $this->assertTrue($cors->allows('https://dot.example.test:8443'), 'a dot in the list');
        $this->assertSame('https://app.example.test.', $cors->headers('https://app.example.test.', false)['Access-Control-Allow-Origin'] ?? null);
        $this->assertSame([], $cors->headers('https://other.example.test.', false), 'an unlisted origin with a dot');
        $this->assertSame([], $cors->headers('https://other.example.test.', true));
        $this->assertSame([], $cors->headers('https://app.example.test..', false), 'only one dot');
        $this->assertSame([], $cors->headers('https://app.example.tes', false));
        $this->assertSame([], $cors->headers('http://app.example.test.', false), 'scheme still counts');
    }

    public function testWildcardsNullPathsAndPlainHttpAreDropped(): void
    {
        $list = ['*', 'null', 'https://*.example.test', 'https://app.example.test/path', 'http://app.example.test', 'http://127.0.0.1:5173', 'http://localhost:5173', 'ftp://x.test', 'https://ok.example.test'];
        $this->assertSame(['https://ok.example.test'], (new Cors($list, false))->origins());
        $this->assertSame(['http://127.0.0.1:5173', 'http://localhost:5173', 'https://ok.example.test'], (new Cors($list, true))->origins(), 'loopback http in development');
        $this->assertFalse((new Cors(['*'], false))->allows('https://any.example.test'));
    }

    public function testTheHeaders(): void
    {
        $cors = new Cors(['https://app.example.test'], false);
        $this->assertSame([], $cors->headers('https://other.example.test', true));
        $this->assertSame([], (new Cors([], false))->headers('https://app.example.test', true), 'empty list: no headers');
        $this->assertSame([
            'Access-Control-Allow-Origin' => 'https://app.example.test',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Expose-Headers' => 'ETag, Location, Retry-After, Link',
        ], $cors->headers('https://app.example.test', false));
        $this->assertSame([
            'Access-Control-Allow-Origin' => 'https://app.example.test',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Expose-Headers' => 'ETag, Location, Retry-After, Link',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, PUT, DELETE',
            'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token, If-Match, Idempotency-Key',
            'Access-Control-Max-Age' => '600',
        ], $cors->headers('https://app.example.test', true));
    }
}
