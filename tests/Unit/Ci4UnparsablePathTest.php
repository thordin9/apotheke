<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * #323: a path PHP's URL parser rejects (a colon followed by digits, such as /stats:1) is a 404 like any other
 * unknown path, never the site root; under /api it is the same JSON 404 as an unknown API path, with the API
 * headers and CORS. Colons in the query string, and an encoded colon in the path, behave as before. Sign-in is
 * off on this server, so / is the list page.
 */
final class Ci4UnparsablePathTest extends TestCase
{
    private const APP = 'https://app.example.test';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('unparsable-path');
        self::$server = new Server(self::$dir, ['CORS_ALLOWED_ORIGINS' => self::APP], 'ci4');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    private function client(): Client
    {
        $this->assertNotNull(self::$server);

        return self::$server->client();
    }

    public function testTheRootAndColonsInTheQueryStillAnswer(): void
    {
        $root = $this->client()->get('/');
        $this->assertSame(200, $root['status'], substr($root['body'], 0, 200));
        foreach (['/?q=a:1', '/?next=/stats:1', '/stats?x=a:1', '/stats'] as $path) {
            $r = $this->client()->get($path);
            $this->assertSame(200, $r['status'], $path . ': ' . substr($r['body'], 0, 200));
        }
        $api = $this->client()->get('/api/v1/session?q=a:1');
        $this->assertSame(200, $api['status'], 'a colon in an API query string routes the path');
    }

    public function testAnUnparsablePathIsThe404(): void
    {
        $root = $this->client()->get('/');
        foreach (['/stats:1', '/stats:1?x=1', '/a:1/b', '/:1', '/stats:65536', '/stats%3A1', '/stats%3a1', '///stats', '/index.php/stats:1'] as $path) {
            foreach (['GET', 'POST'] as $method) {
                $r = $this->client()->req($method, $path);
                $label = $method . ' ' . $path;
                $this->assertSame(404, $r['status'], $label . ': ' . substr($r['body'], 0, 200));
                $this->assertArrayNotHasKey('location', $r['headers'], $label);
                $this->assertStringStartsWith('text/html', $r['headers']['content-type'] ?? '', $label);
                $this->assertStringContainsString('Page not found.', $r['body'], $label);
                $this->assertNotSame($root['body'], $r['body'], $label);
            }
        }
    }

    /** A camera original is refused before the framework runs, in this spelling too. */
    public function testAnUnparsableOriginalsPathIsStillRefused(): void
    {
        $r = $this->client()->get('/originals/a:1');
        $this->assertSame([404, 'Not found'], [$r['status'], $r['body']]);
    }

    public function testAnUnparsableApiPathIsTheApiJson404(): void
    {
        $want = $this->client()->get('/api/v1/nothing-here', ['Origin: ' . self::APP]);
        $this->assertSame(404, $want['status']);
        foreach (['/api/a:1', '/api/v1/a:1', '/api/v1/items:1', '/api/v1/items/1:2?x=1', '/api/v1/a%3A1'] as $path) {
            foreach (['GET', 'POST', 'DELETE'] as $method) {
                $r = $this->client()->req($method, $path, [], [], ['Origin: ' . self::APP, 'Content-Type: application/json']);
                $label = $method . ' ' . $path . ': ' . substr($r['body'], 0, 200);
                $this->assertSame(404, $r['status'], $label);
                $this->assertSame($want['json'], $r['json'], $label);
                foreach (['content-type', 'content-security-policy', 'x-content-type-options', 'referrer-policy', 'cross-origin-resource-policy', 'cache-control', 'access-control-allow-origin', 'access-control-allow-credentials', 'access-control-expose-headers'] as $name) {
                    $this->assertSame($want['headers'][$name] ?? null, $r['headers'][$name] ?? null, $label . ' ' . $name);
                }
                $this->assertArrayNotHasKey('set-cookie', $r['headers'], $label);
                $this->assertArrayNotHasKey('x-frame-options', $r['headers'], $label);
            }
        }
    }
}
