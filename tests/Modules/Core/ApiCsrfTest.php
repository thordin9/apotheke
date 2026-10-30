<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\ApiCsrf;
use PHPUnit\Framework\TestCase;

/** API plan §2.1: Sec-Fetch-Site, Origin, Content-Type and the X-CSRF-Token header, all on every non-safe method. */
final class ApiCsrfTest extends TestCase
{
    private const TOKEN = 'a1b2c3';

    private const OWN = ['https://api.example.test'];

    private const CORS = ['https://app.example.test'];

    /** @return array<string,string> */
    private function server(array $extra = []): array
    {
        return $extra + [
            'HTTP_ORIGIN' => 'https://api.example.test',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '2',
            'HTTP_X_CSRF_TOKEN' => self::TOKEN,
        ];
    }

    /** @param array<string,string> $server */
    private function refusal(string $method, array $server, string $token = self::TOKEN): ?array
    {
        return (new ApiCsrf())->refusal($method, $server, $token, self::OWN, self::CORS);
    }

    public function testSafeMethodsPass(): void
    {
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $this->assertNull($this->refusal($method, []), $method);
        }
    }

    public function testAGoodRequestPasses(): void
    {
        $this->assertNull($this->refusal('POST', $this->server()));
        $this->assertNull($this->refusal('PATCH', $this->server(['CONTENT_TYPE' => 'application/json; charset=utf-8'])));
        $this->assertNull($this->refusal('POST', $this->server(['CONTENT_TYPE' => 'multipart/form-data; boundary=x'])));
        $this->assertNull($this->refusal('POST', $this->server(['HTTP_ORIGIN' => 'https://app.example.test', 'HTTP_SEC_FETCH_SITE' => 'same-site'])), 'a listed origin');
        $this->assertNull($this->refusal('DELETE', $this->server(['CONTENT_TYPE' => '', 'CONTENT_LENGTH' => ''])), 'no body, no type needed');
        $noFetch = $this->server();
        unset($noFetch['HTTP_SEC_FETCH_SITE']);
        $this->assertNull($this->refusal('POST', $noFetch), 'an older browser without Sec-Fetch-Site');
    }

    public function testEachCheckRefusesOnItsOwn(): void
    {
        $csrf = [403, 'csrf.invalid'];
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(['HTTP_SEC_FETCH_SITE' => 'cross-site']))));
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(['HTTP_SEC_FETCH_SITE' => 'none']))));
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(['HTTP_ORIGIN' => 'https://evil.example.test']))));
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(['HTTP_ORIGIN' => 'null']))));
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(['HTTP_ORIGIN' => '']))), 'Origin is required');
        $this->assertSame([415, 'unsupported_media_type'], $this->pick($this->refusal('POST', $this->server(['CONTENT_TYPE' => 'application/x-www-form-urlencoded']))));
        $this->assertSame([415, 'unsupported_media_type'], $this->pick($this->refusal('POST', $this->server(['CONTENT_TYPE' => 'text/plain']))));
        $this->assertSame([415, 'unsupported_media_type'], $this->pick($this->refusal('POST', $this->server(['CONTENT_TYPE' => '']))), 'a body needs a type');
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(['HTTP_X_CSRF_TOKEN' => 'wrong']))));
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(['HTTP_X_CSRF_TOKEN' => '']))));
        $this->assertSame($csrf, $this->pick($this->refusal('POST', $this->server(), '')), 'no session token');
        $this->assertSame($csrf, $this->pick($this->refusal('PUT', $this->server(['HTTP_ORIGIN' => 'https://evil.example.test']))));
    }

    /** @param ?array{status:int,code:string,detail:string} $refusal @return array{int,string}|null */
    private function pick(?array $refusal): ?array
    {
        return $refusal === null ? null : [$refusal['status'], $refusal['code']];
    }
}
