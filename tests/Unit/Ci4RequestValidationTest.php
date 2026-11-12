<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Web requests get their consent, sign-in and CSRF answers first: a small body that fills no form fields (JSON,
 * text) is not "too large", and only a body past post_max_size is 413. A form write from another origin (Origin,
 * else Referer, against this host or the public base) is 403 with sign-in on; without either the CSRF token decides.
 * After those checks, a form field sent as a list it does not take is a 422, and an item's name, notes, tags or
 * serial number past its limit is a 422 instead of being cut short. /upload/check (the FrankenPHP image's check
 * before a body over 64 KiB) refuses a signed-out /api request with the API's problem+json: 413 for a declared
 * length over 64 KiB, else 401. An empty 204, 304 or error answer carries no Content-Type.
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

    public function testAFormFieldSentAsAListIsA422AndSelectsNothing(): void
    {
        [$c, $token, $id] = $this->signedIn();
        [, , $other] = $this->signedIn();
        $before = $this->item($other);
        $count = $this->itemCount();
        foreach (['csrf=' . $token . '&id[]=' . $other . '&name=Changed', 'csrf=' . $token . '&id=' . $other . '&name[]=Changed',
            'csrf=' . $token . '&name[x]=New', 'csrf=' . $token . '&id=' . $other . '&name=Changed&notes[]=a&notes[]=b'] as $form) {
            $r = $c->req('POST', '/save', [], [], [], $form);
            $this->assertSame(422, $r['status'], $form);
            $this->assertStringContainsString('Each field takes one value', $r['body'], $form);
            $this->assertStringStartsWith('text/html', $r['headers']['content-type'] ?? '', $form);
            $json = $c->req('POST', '/save', [], [], ['Accept: application/json', 'X-Requested-With: fetch'], $form);
            $this->assertSame(422, $json['status'], $form);
            $this->assertFalse($json['json']['ok'] ?? null, $form);
            $this->assertStringStartsWith('Each field takes one value', (string)($json['json']['error'] ?? ''), $form);
        }
        $this->assertSame($before, $this->item($other), 'no item was changed');
        $this->assertSame($count, $this->itemCount(), 'or created');
        $this->assertSame(422, $c->req('POST', '/item/' . $id . '/fav', [], [], [], 'csrf=' . $token . '&on[]=1')['status']);
        $this->assertSame(419, $c->req('POST', '/item/' . $id . '/fav', [], [], [], 'csrf[]=' . $token)['status'], 'a token sent as a list is no token');
        $this->assertFalse($this->favourite($id));
        // The fields the forms send as lists still work, and GET filters take lists as before.
        $ask = $c->req('POST', '/bulk', [], [], [], 'csrf=' . $token . '&action=delete&ids[]=' . $id . '&ids[]=' . $other);
        $this->assertSame(200, $ask['status'], 'delete asks first');
        $this->assertSame($count, $this->itemCount());
        $this->assertSame(200, $c->get('/?loc[]=1&cat[]=none')['status']);
        // Signed out, cross-site, or without the token, those answers come first.
        $out = self::$server->client();
        $this->assertSame(303, $out->req('POST', '/save', [], [], [], 'name[]=x')['status']);
        $this->assertSame(403, $c->req('POST', '/save', [], [], ['Origin: http://other.example'], 'csrf=' . $token . '&name[]=x')['status']);
        $this->assertSame(419, $c->req('POST', '/save', [], [], [], 'csrf=wrong&name[]=x')['status']);
    }

    public function testAnOverLongNameOrNotesIsA422AndNotCutShort(): void
    {
        [$c, $token, $id] = $this->signedIn();
        $before = $this->item($id);
        $count = $this->itemCount();
        foreach ([['name' => str_repeat('n', 201)], ['name' => str_repeat('é', 201)], ['name' => 'Ok', 'notes' => str_repeat('x', 5001)],
            ['name' => 'Ok', 'tags' => str_repeat('t', 2001)], ['name' => 'Ok', 'serial' => str_repeat('s', 101)]] as $fields) {
            foreach ([0, $id] as $target) {
                $r = $c->post('/save', ['csrf' => $token, 'id' => $target] + $fields);
                $label = implode(',', array_keys($fields)) . ' ' . $target;
                $this->assertSame(422, $r['status'], $label);
                $this->assertMatchesRegularExpression('/(Name|Notes|Tags|Serial number) is too long \(at most [0-9,]+ characters\)\./', $r['body'], $label);
                $this->assertStringContainsString('<form', $r['body'], $label . ': the form again');
            }
        }
        $this->assertSame($before, $this->item($id));
        $this->assertSame($count, $this->itemCount());
        $json = $c->post('/save', ['csrf' => $token, 'name' => str_repeat('n', 201)], [], ['Accept: application/json', 'X-Requested-With: fetch']);
        $this->assertSame(422, $json['status']);
        // At the limits, and with the spaces a name loses, the values are kept whole.
        $name = str_repeat('é', 199) . 'z';
        $r = $c->post('/save', ['csrf' => $token, 'id' => $id, 'name' => '  ' . $name . '  ', 'notes' => str_repeat('x', 5000)]);
        $this->assertSame(303, $r['status']);
        $this->assertSame([$name, 5000], [$this->item($id)['name'], mb_strlen((string)$this->item($id)['notes'])]);
    }

    public function testTheUploadCheckAnswersASignedOutApiRequestWithProblemJson413(): void
    {
        $dir = tmpdir('request-validation-upload');
        $server = new Server($dir, ['APP_PASSWORD' => self::PASSWORD, 'CORS_ALLOWED_ORIGINS' => 'https://app.example.test'], 'ci4');
        try {
            $c = $server->client();
            foreach (['/api/v1/session', '/api/v1/imports?x=1', '/api', '//api/v1/session'] as $uri) {
                $r = $c->get('/upload/check', ['X-Forwarded-Uri: ' . $uri, 'X-Forwarded-Method: POST', 'X-Apotheke-Body-Length: 65537', 'Origin: https://app.example.test']);
                $this->assertSame(413, $r['status'], $uri);
                $this->assertSame('application/problem+json', $r['headers']['content-type'] ?? null, $uri);
                $this->assertSame(['type' => 'about:blank', 'title' => 'Content Too Large', 'status' => 413,
                    'detail' => 'A request body over 64 KiB needs a signed-in session.', 'code' => 'upload.too_large'], $r['json'], $uri);
                $this->assertSame('no-store', $r['headers']['cache-control'] ?? null, $uri);
                $this->assertSame("default-src 'none'; frame-ancestors 'none'; sandbox", $r['headers']['content-security-policy'] ?? null, $uri);
                $this->assertSame('https://app.example.test', $r['headers']['access-control-allow-origin'] ?? null, $uri);
                $this->assertSame('true', $r['headers']['access-control-allow-credentials'] ?? null, $uri);
                $this->assertSame('no-referrer', $r['headers']['referrer-policy'] ?? null, $uri . ': the API headers');
                $this->assertArrayNotHasKey('x-frame-options', $r['headers'], $uri);
            }
            // Without a declared length over 64 KiB (chunked, or a DELETE without a body) it is the API's 401.
            foreach (['', '65536', 'abc', '-1'] as $length) {
                $r = $c->get('/upload/check', array_merge(['X-Forwarded-Uri: /api/v1/session', 'Origin: https://app.example.test'], $length === '' ? [] : ['X-Apotheke-Body-Length: ' . $length]));
                $this->assertSame([401, 'application/problem+json', 'auth.required'], [$r['status'], $r['headers']['content-type'] ?? null, $r['json']['code'] ?? null], $length);
                $this->assertSame('https://app.example.test', $r['headers']['access-control-allow-origin'] ?? null, $length);
            }
            $other = $c->get('/upload/check', ['X-Forwarded-Uri: /api/v1/session', 'X-Apotheke-Body-Length: 300000', 'Origin: https://other.example']);
            $this->assertSame(413, $other['status']);
            $this->assertArrayNotHasKey('access-control-allow-origin', $other['headers'], 'an origin not listed gets no CORS headers');
            foreach (['', '/save', '/apix', '/backup/restore'] as $uri) {
                $r = $c->get('/upload/check', array_merge(['X-Apotheke-Body-Length: 300000'], $uri === '' ? [] : ['X-Forwarded-Uri: ' . $uri]));
                $this->assertSame([401, ''], [$r['status'], $r['body']], 'pages keep the bare 401: ' . $uri);
            }
            $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
            $this->assertSame(200, $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token])['status']);
            $this->assertSame(204, $c->get('/upload/check', ['X-Forwarded-Uri: /api/v1/imports', 'X-Apotheke-Body-Length: 300000'])['status'], 'signed in');
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    public function testAnEmptyAnswerHasNoContentType(): void
    {
        $c = self::$server->client();
        $empty = [
            'CORS preflight' => $c->req('OPTIONS', '/api/v1/session', [], [], ['Origin: http://app.example.test']),
            'upload check' => $c->get('/upload/check'),
            'signed-out photo' => $c->get('/media/full/' . str_repeat('a', 32) . '.jpg'),
        ];
        [$in, , $id] = $this->signedIn();
        $empty['missing photo'] = $in->get('/media/full/' . str_repeat('b', 32) . '.jpg');
        $token = $in->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $empty['API sign-out'] = $in->rest('DELETE', '/api/v1/session', null, ['X-CSRF-Token: ' . $token]);
        foreach ($empty as $label => $r) {
            $this->assertContains($r['status'], [204, 401, 404], $label);
            $this->assertSame('', $r['body'], $label);
            $this->assertArrayNotHasKey('content-type', $r['headers'], $label);
            $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null, $label);
        }
        // Answers with a body, and redirects, keep theirs.
        $this->assertStringStartsWith('text/html', $c->get('/robots.txt')['headers']['content-type'] ?? '');
        $this->assertStringStartsWith('text/html', $c->get('/')['headers']['content-type'] ?? '');
        $this->assertSame('application/problem+json', self::$server->client()->get('/api/v1/me')['headers']['content-type'] ?? null);
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

    /** @return array<string,mixed> */
    private function item(int $id): array
    {
        $row = testdb(self::$dir)->query('SELECT name, notes, serial, updated_at FROM items WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private function itemCount(): int
    {
        return (int)testdb(self::$dir)->query('SELECT COUNT(*) FROM items')->fetchColumn();
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
