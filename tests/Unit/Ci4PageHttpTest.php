<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * The CI4 dev server with a test-only HTML page (APOTHEKE_TEST_HTML_PAGE):
 * an HTML answer never starts CI4's own session beside the native one
 * (#76 L-1), and CI4's base URL and the token form's 303 follow the public
 * address, never the request's Host (#76 L-2).
 */
final class Ci4PageHttpTest extends TestCase
{
    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    /**
     * #76 L-1: after the native session is released, an HTML answer used to
     * make storePreviousURL() start CI4's FileHandler session for the same id:
     * a second file, and a new id once its 300 s regeneration time had passed.
     */
    public function testAnHtmlPageKeepsTheNativeSessionOnly(): void
    {
        $this->withServer(['APOTHEKE_TEST_SESSION_ON_DEMAND' => '1', 'APOTHEKE_TEST_HTML_PAGE' => '1'], function (Server $server, string $dir): void {
            $client = $server->client();
            $first = $client->get('/test-html-page');
            $this->assertSame(200, $first['status'], $first['body']);
            $this->assertStringContainsString('text/html', $first['headers']['content-type'] ?? '');
            $this->assertStringContainsString('apothekesid=', $first['headers']['set-cookie'] ?? '');
            $id = (string)$client->cookies['apothekesid'];
            $file = $dir . '/sessions/sess_' . $id;
            $this->assertSame(['sess_' . $id], $this->sessionFiles($dir), 'one native session file, nothing from CI4');
            // An old CI4 regeneration stamp: CI4's own session would change the id now.
            file_put_contents($file, (string)file_get_contents($file) . '__ci_last_regenerate|i:' . (time() - 3600) . ';');
            $csrf = $this->csrf($file);
            foreach ([1, 2] as $round) {
                $page = $client->get('/test-html-page');
                $this->assertSame(200, $page['status'], (string)$round);
                $this->assertArrayNotHasKey('set-cookie', $page['headers'], 'no new cookie, round ' . $round);
                $this->assertSame($id, $client->cookies['apothekesid']);
                $this->assertSame(['sess_' . $id], $this->sessionFiles($dir), 'round ' . $round);
                $this->assertSame($csrf, $this->csrf($file), 'the CSRF token stays, round ' . $round);
                $this->assertStringNotContainsString('_ci_previous_url', (string)file_get_contents($file));
            }
        });
    }

    /**
     * #76 L-2: the address set only in the Control Panel is CI4's base URL, and
     * the token form's 303 sends a relative Location, as legacy does.
     */
    public function testTheControlPanelAddressIsTheBaseAndTheFormRedirectIsRelative(): void
    {
        $this->withServer(['APOTHEKE_TEST_SESSION_ON_DEMAND' => '1', 'APOTHEKE_TEST_HTML_PAGE' => '1'], function (Server $server, string $dir): void {
            $client = $server->client();
            $this->assertStringContainsString(htmlspecialchars($server->base . '/labels'), $client->get('/test-html-page')['body'], 'no address: this request\'s allowed Host');
            $this->setting($dir, 'public_url', '"https://shelf.example"');
            foreach (['shelf.example', parse_url($server->base, PHP_URL_HOST) . ':' . parse_url($server->base, PHP_URL_PORT), 'localhost'] as $host) {
                $page = $client->get('/test-html-page', ['Host: ' . $host]);
                $this->assertSame(200, $page['status'], $host);
                $this->assertStringContainsString('https://shelf.example/labels', $page['body'], $host);
            }
            $csrf = $this->csrf($dir . '/sessions/sess_' . $client->cookies['apothekesid']);
            foreach ([['token' => 'form-token-placeholder'], ['action' => 'clear']] as $i => $fields) {
                $done = $i === 0 ? 'token-saved' : 'token-cleared';
                $saved = $client->post('/settings/autofill/token', $fields + ['csrf' => $csrf], [], ['Host: shelf.example']);
                $this->assertSame(303, $saved['status'], $saved['body']);
                $this->assertSame('/settings/autofill?done=' . $done, $saved['headers']['location'] ?? '');
                $this->assertStringContainsString('no-store', $saved['headers']['cache-control'] ?? '');
                $this->assertStringNotContainsString('form-token-placeholder', implode("\n", $saved['raw']) . $saved['body']);
            }
            $this->assertSame(['sess_' . $client->cookies['apothekesid']], $this->sessionFiles($dir));
        });
    }

    /** #76 L-2: without an address, the scheme follows trust_proxy (legacy request_is_https()). */
    public function testWithoutAnAddressTheSchemeFollowsTrustProxy(): void
    {
        $this->withServer(['APOTHEKE_TEST_HTML_PAGE' => '1'], function (Server $server, string $dir): void {
            $client = $server->client();
            $host = (string)parse_url($server->base, PHP_URL_HOST) . ':' . (string)parse_url($server->base, PHP_URL_PORT);
            $this->assertStringContainsString('http://' . $host . '/labels', $client->get('/test-html-page', ['X-Forwarded-Proto: https'])['body'], 'trust_proxy off');
            $this->setting($dir, 'trust_proxy', 'true');
            $this->assertStringContainsString('https://' . $host . '/labels', $client->get('/test-html-page', ['X-Forwarded-Proto: https'])['body'], 'trust_proxy on');
            $this->assertStringContainsString('http://localhost/labels', $client->get('/test-html-page', ['Host: localhost'])['body']);
        });
    }

    private function setting(string $dir, string $name, string $json): void
    {
        $pdo = testdb($dir);
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare("INSERT INTO settings (name, value, updated_at) VALUES (?, ?, '2026-01-01')")->execute([$name, $json]);
    }

    /** @return list<string> */
    private function sessionFiles(string $dir): array
    {
        $files = array_map('basename', glob($dir . '/sessions/*') ?: []);
        sort($files);

        return $files;
    }

    private function csrf(string $file): string
    {
        $this->assertMatchesRegularExpression('/csrf\|s:64:"([0-9a-f]{64})"/', (string)file_get_contents($file));
        preg_match('/csrf\|s:64:"([0-9a-f]{64})"/', (string)file_get_contents($file), $m);

        return $m[1];
    }

    /**
     * @param array<string,string|null> $env
     * @param Closure(Server,string):void $test
     */
    private function withServer(array $env, Closure $test): void
    {
        $dir = tmpdir('ci4-page');
        $server = new Server($dir, $env, 'ci4');
        try {
            $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
            $test($server, $dir);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}
