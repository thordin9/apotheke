<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Web requests get their consent, sign-in and CSRF answers first: a small body that fills no form fields (JSON,
 * text) is not "too large", and only a body past post_max_size is 413. A form write from another origin (Origin,
 * else Referer, against this host or the public base) is 403 with sign-in on; without either the CSRF token decides.
 */
final class Ci4RequestValidationTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('request-validation');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4', null, ['post_max_size' => '1M']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    public function testASmallBodyWithoutFormFieldsGetsTheUsualAnswerNot413(): void
    {
        $c = self::$server->client();
        $fetch = ['Accept: application/json', 'X-Requested-With: fetch'];
        foreach (['application/json' => '{"a":1}', 'text/plain' => 'hello', 'application/x-www-form-urlencoded' => ''] as $type => $body) {
            $page = $c->req('POST', '/login', [], [], ['Content-Type: ' . $type], $body);
            $this->assertSame(419, $page['status'], $type . ': the sign-in form asks for its token');
            $this->assertStringNotContainsString('too large', $page['body'], $type);
            $json = $c->req('POST', '/login', [], [], array_merge(['Content-Type: ' . $type], $fetch), $body);
            $this->assertSame(419, $json['status'], $type);
            $this->assertFalse($json['json']['ok'] ?? null, $type);
            foreach (['/item/1/fav', '/save'] as $path) {
                $this->assertSame(303, $c->req('POST', $path, [], [], ['Content-Type: ' . $type], $body)['status'], $type . ' ' . $path . ': signed out');
                $r = $c->req('POST', $path, [], [], array_merge(['Content-Type: ' . $type], $fetch), $body);
                $this->assertSame([401, 'Not signed in'], [$r['status'], $r['json']['error'] ?? null], $type . ' ' . $path);
            }
            $this->assertSame(403, $c->req('POST', '/consent', [], [], ['Content-Type: ' . $type], $body)['status'], $type . ': /consent answers as before');
        }
    }

    public function testABodyPastPostMaxSizeIs413(): void
    {
        $c = self::$server->client();
        foreach (['/login', '/save', '/item/1/fav'] as $path) {
            $r = $c->req('POST', $path, [], [], ['Content-Type: application/x-www-form-urlencoded'], 'a=' . str_repeat('x', 1100000));
            $this->assertSame(413, $r['status'], $path);
            $this->assertStringContainsString('Upload too large (max 1M).', $r['body'], $path);
            $r = $c->req('POST', $path, [], [], ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: fetch'], str_repeat('x', 1100000));
            $this->assertSame([413, false], [$r['status'], $r['json']['ok'] ?? null], $path);
        }
    }

    public function testAFormWriteFromAnotherOriginIsRefusedWithAValidToken(): void
    {
        [$c, $token, $id] = $this->signedIn();
        $fetch = ['Accept: application/json', 'X-Requested-With: fetch'];
        foreach ([['Origin: http://other.example'], ['Origin: null'], ['Referer: http://other.example/page'], ['Sec-Fetch-Site: cross-site'],
            ['Sec-Fetch-Site: same-site'], ['Origin: ' . str_replace('http://', 'https://', $c->base)]] as $headers) {
            $r = $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], $headers);
            $this->assertSame(403, $r['status'], implode(' ', $headers));
            $this->assertStringContainsString('came from another site', $r['body'], implode(' ', $headers));
            $json = $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], array_merge($headers, $fetch));
            $this->assertSame([403, false], [$json['status'], $json['json']['ok'] ?? null], implode(' ', $headers));
        }
        $this->assertSame(403, $c->post('/save', ['csrf' => $token, 'name' => 'Elsewhere'], [], ['Origin: http://other.example'])['status']);
        $this->assertSame(403, $c->post('/logout', ['csrf' => $token], [], ['Origin: http://other.example'])['status'], 'sign-out too');
        $this->assertFalse($this->favourite($id), 'nothing changed');
    }

    public function testASameOriginFormWriteOrOneWithoutOriginGoesToTheTokenCheck(): void
    {
        [$c, $token, $id] = $this->signedIn();
        $fetch = ['Accept: application/json', 'X-Requested-With: fetch'];
        $want = true;
        foreach ([['Origin: ' . $c->base], ['Referer: ' . $c->base . '/item/' . $id], ['Sec-Fetch-Site: same-origin'], []] as $headers) {
            $r = $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], array_merge($headers, $fetch));
            $this->assertSame([200, $want], [$r['status'], $r['json']['favourite'] ?? null], implode(' ', $headers));
            $want = !$want;
            $this->assertSame(419, $c->post('/item/' . $id . '/fav', ['csrf' => 'wrong'], [], array_merge($headers, $fetch))['status'], implode(' ', $headers) . ': the token still counts');
        }
    }

    public function testBehindATrustedProxyTheForwardedSchemeAndThePublicBaseAreSameOrigin(): void
    {
        [$c, $token, $id] = $this->signedIn();
        $https = str_replace('http://', 'https://', $c->base);
        $proxied = ['Origin: ' . $https, 'X-Forwarded-Proto: https', 'Accept: application/json', 'X-Requested-With: fetch'];
        $pdo = testdb(self::$dir);
        try {
            $this->assertSame(403, $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], $proxied)['status'], 'the forwarded scheme is not trusted');
            $this->setting($pdo, 'trust_proxy', 'true');
            $this->assertSame(200, $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], $proxied)['status'], 'trusted proxy');
            $this->assertSame(403, $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], ['Origin: https://other.example', 'X-Forwarded-Proto: https'])['status']);
        } finally {
            $this->setting($pdo, 'trust_proxy', 'false');
        }

        $dir = tmpdir('request-validation-base');
        $server = new Server($dir, ['APP_PASSWORD' => self::PASSWORD, 'PUBLIC_URL' => 'https://shop.example'], 'ci4');
        try {
            [$c, $token, $id] = $this->signedIn($server, $dir);
            $json = ['Accept: application/json', 'X-Requested-With: fetch'];
            $this->assertSame(200, $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], array_merge(['Origin: https://shop.example'], $json))['status'], 'the public base');
            $this->assertSame(200, $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], array_merge(['Referer: https://shop.example/item/' . $id], $json))['status'], 'a Referer on the public base');
            $this->assertSame(200, $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], array_merge(['Origin: ' . $c->base], $json))['status'], 'the address in use');
            foreach (['Origin: https://shop.example.other', 'Origin: http://shop.example', 'Referer: https://other.example/'] as $header) {
                $this->assertSame(403, $c->post('/item/' . $id . '/fav', ['csrf' => $token], [], [$header])['status'], $header);
            }
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /** @return array{0:Client,1:string,2:int} a signed-in client, its CSRF token and a new item's id */
    private function signedIn(?Server $server = null, ?string $dir = null): array
    {
        $dir ??= self::$dir;
        $c = ($server ?? self::$server)->client();
        $login = $c->post('/login', ['csrf' => $c->csrf(), 'username' => 'admin', 'password' => self::PASSWORD]);
        $this->assertSame(303, $login['status']);
        $token = $c->csrf();
        $this->assertSame(303, $c->post('/save', ['csrf' => $token, 'name' => 'Lamp ' . bin2hex(random_bytes(3))])['status']);
        $id = (int)testdb($dir)->query('SELECT MAX(id) FROM items')->fetchColumn();
        $this->assertGreaterThan(0, $id);

        return [$c, $token, $id];
    }

    private function favourite(int $id): bool
    {
        return (int)testdb(self::$dir)->query('SELECT favourite FROM items WHERE id = ' . $id)->fetchColumn() === 1;
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, '2026-01-01']);
    }
}
