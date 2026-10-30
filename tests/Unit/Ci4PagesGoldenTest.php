<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * L-961: the item list, the item page, its edit form and the main JSON answers, pinned to
 * what CI4 gave at the cutover (recorded twice, identical), in the style of
 * Ci4BrandingHttpTest: status, a few headers, and the body's sha1 (HTML) or
 * the decoded JSON (its "html" part as a sha1). The CSRF token, asset
 * cache busters and VERSION are masked; the clock is fixed.
 *
 * Re-record after an intended change: APOTHEKE_GOLDEN_RECORD=/path.json,
 * run this test twice, diff the two files, then paste one into GOLDEN.
 */
final class Ci4PagesGoldenTest extends TestCase
{
    private const HEADERS = ['content-type', 'cache-control', 'x-content-type-options', 'x-frame-options'];

    /** Recorded at the cutover head 029188d, twice, identical; the edit form at f1b4535 (twice on SQLite, once on PostgreSQL, identical); GET / again with VERSION masked (twice on SQLite, identical). */
    private const GOLDEN = [
        'GET /' => [
            'status' => 200,
            'sha1' => 'f58221024d5e0cf5c542dc39923f5b63f120f9cf',
            'content-type' => 'text/html; charset=UTF-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'GET /item/1' => [
            'status' => 200,
            'sha1' => 'bb90af88f6a33fade380768d7e8f2bf7aa688219',
            'content-type' => 'text/html; charset=UTF-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'GET /item/1/edit' => [
            'status' => 200,
            'sha1' => '1d6d390668fa6d26570481c8525fc8bebc7cb56e',
            'content-type' => 'text/html; charset=UTF-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'GET /item/999' => [
            'status' => 404,
            'sha1' => 'aca4c0124cbca4ee6705e4871a1556c448c74d11',
            'content-type' => 'text/html; charset=UTF-8',
            'cache-control' => 'no-store, max-age=0, no-cache',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'JSON /' => [
            'status' => 200,
            'json' => [
                'ok' => true,
                'html' => 'sha1:f87bf266da6bbe1dec1682ea456874de3d3eb429',
                'total' => 3,
                'count' => '3 items',
                'more' => false,
                'page' => 1,
                'chips' => '',
                'nfilters' => 0,
                'query' => '',
            ],
            'content-type' => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'JSON /?q=drill' => [
            'status' => 200,
            'json' => [
                'ok' => true,
                'html' => 'sha1:9a3a818ce313d0574e37290e1ff78c71c6ff75d7',
                'total' => 1,
                'count' => '1 item found',
                'more' => false,
                'page' => 1,
                'chips' => '',
                'nfilters' => 0,
                'query' => 'q=drill',
            ],
            'content-type' => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'JSON /?q=nothing-matches' => [
            'status' => 200,
            'json' => [
                'ok' => true,
                'html' => 'sha1:da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'total' => 0,
                'count' => '0 items found',
                'more' => false,
                'page' => 1,
                'chips' => '',
                'nfilters' => 0,
                'query' => 'q=nothing-matches',
            ],
            'content-type' => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'JSON /?sort=name&page=2' => [
            'status' => 200,
            'json' => [
                'ok' => true,
                'html' => 'sha1:da39a3ee5e6b4b0d3255bfef95601890afd80709',
                'total' => 3,
                'count' => '3 items',
                'more' => false,
                'page' => 2,
                'chips' => '<a class="achip" href="/" data-clear="sort" aria-label="Reset sort">Sort: Name<svg class="i" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6 6 18"/></svg></a><a class="achip achip-clear" href="/" data-clear="all">Clear all</a>',
                'nfilters' => 1,
                'query' => 'sort=name',
            ],
            'content-type' => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'JSON POST /save (no name)' => [
            'status' => 422,
            'json' => [
                'ok' => false,
                'error' => 'Please give the item a name.',
            ],
            'content-type' => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'JSON POST /save (no CSRF)' => [
            'status' => 419,
            'json' => [
                'ok' => false,
                'error' => 'Your session expired. Reload the page and try again.',
            ],
            'content-type' => 'application/json; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
        'GET /healthz' => [
            'status' => 200,
            'sha1' => '7a85f4764bbd6daf1c3545efbbf0f279a6dc0beb',
            'content-type' => 'text/plain;charset=UTF-8',
            'cache-control' => null,
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'SAMEORIGIN',
        ],
    ];

    public function testKeyPagesAndJsonMatchTheGoldens(): void
    {
        $dir = tmpdir('ci4-golden');
        $server = new Server($dir, ['APOTHEKE_CLOCK' => '2020-06-15 12:00:00', 'TZ' => 'UTC'], 'ci4');
        try {
            $client = $server->client();
            $csrf = $client->csrf();
            $this->assertNotSame('', $csrf);
            foreach ([['Cordless drill', "18 V, two batteries\nShelf B"], ['Ladder', ''], ['Δράπανο κρουστικό', 'Κουτί 5']] as [$name, $notes]) {
                $saved = $client->post('/save', ['csrf' => $csrf, 'name' => $name, 'notes' => $notes, 'quantity' => '2', 'new_location' => 'Garage', 'new_category' => 'Tools', 'tags' => 'power, home']);
                $this->assertSame(303, $saved['status'], $saved['body']);
            }
            $seen = [
                'GET /' => $this->page($client->get('/')),
                'GET /item/1' => $this->page($client->get('/item/1')),
                'GET /item/1/edit' => $this->page($client->get('/item/1/edit')),
                'GET /item/999' => $this->page($client->get('/item/999')),
                'JSON /' => $this->json($client->json('/')),
                'JSON /?q=drill' => $this->json($client->json('/?q=drill')),
                'JSON /?q=nothing-matches' => $this->json($client->json('/?q=nothing-matches')),
                'JSON /?sort=name&page=2' => $this->json($client->json('/?sort=name&page=2')),
                'JSON POST /save (no name)' => $this->json($client->api('/save', ['csrf' => $csrf, 'name' => ''])),
                'JSON POST /save (no CSRF)' => $this->json($client->api('/save', ['name' => 'x'])),
                'GET /healthz' => $this->page($client->get('/healthz')),
            ];
            $record = getenv('APOTHEKE_GOLDEN_RECORD');
            if (is_string($record) && $record !== '') {
                file_put_contents($record, var_export($seen, true) . "\n");
                $this->markTestSkipped('recorded to ' . $record);
            }
            $this->assertSame(self::GOLDEN, $seen);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /** @param array{status:int,headers:array<string,string>,body:string} $r @return array<string,mixed> */
    private function page(array $r): array
    {
        return ['status' => $r['status'], 'sha1' => sha1(self::mask($r['body']))] + $this->headers($r);
    }

    /** @param array{status:int,headers:array<string,string>,body:string,json:mixed} $r @return array<string,mixed> */
    private function json(array $r): array
    {
        $this->assertIsArray($r['json'], $r['body']);
        $body = $r['json'];
        if (isset($body['html']) && is_string($body['html'])) {
            $body['html'] = 'sha1:' . sha1(self::mask($body['html']));
        }

        return ['status' => $r['status'], 'json' => $body] + $this->headers($r);
    }

    /** @param array{headers:array<string,string>} $r @return array<string,?string> */
    private function headers(array $r): array
    {
        $out = [];
        foreach (self::HEADERS as $name) {
            $out[$name] = $r['headers'][$name] ?? null;
        }

        return $out;
    }

    private static function mask(string $body): string
    {
        $body = (string)preg_replace('/(name="csrf" (?:content|value)=")[a-f0-9]+/', '$1CSRF', $body);
        // The list page shows VERSION, which every release commit bumps.
        $version = trim((string)file_get_contents(dirname(__DIR__, 2) . '/VERSION'));
        if ($version !== '') {
            $body = str_replace($version, 'VERSION', $body);
        }

        return (string)preg_replace('#\?v=[0-9a-f]{8,}#', '?v=V', $body);
    }
}
