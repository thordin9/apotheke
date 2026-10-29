<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Public branding routes on CI4 against the legacy front controller:
 * /theme.css, /labels.css, /manifest.webmanifest, /favicon.ico and
 * /brand/{file}. Body bytes and the relevant headers must be identical.
 */
final class Ci4BrandingHttpTest extends TestCase
{
    private const HEADERS = ['content-type', 'cache-control', 'content-length', 'content-security-policy',
        'x-content-type-options', 'x-frame-options', 'referrer-policy'];

    public function testBrandingRoutesMatchLegacy(): void
    {
        if (!is_file(ROOT . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $leftDir = tmpdir('brand-legacy');
        $rightDir = tmpdir('brand-ci4');
        $legacy = new Server($leftDir, [], 'legacy');
        $ci4 = new Server($rightDir, [], 'ci4');
        try {
            $paths = ['/theme.css', '/theme.css?v=1', '/theme.css?v', '/labels.css', '/labels.css?v=abc', '/manifest.webmanifest',
                '/favicon.ico', '/brand/logo.svg', '/brand/logo.png', '/brand/icon-192.png', '/brand/favicon.ico', '/brand/evil.php'];
            $this->compare($legacy, $ci4, $paths, 'defaults');

            $settings = ['accent' => '"#C8502D"', 'app_name' => '"Αποθήκη του σπιτιού μας"', 'app_tagline' => '"τα πράγματά μας"',
                'label_width_mm' => '50', 'label_height_mm' => '40', 'label_columns' => '4', 'label_gap_mm' => '0',
                'label_show_sub' => 'false', 'label_show_url' => 'true', 'label_border' => 'false', 'icon_background' => '"#112233"'];
            $svg = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 10 10\"><rect width=\"10\" height=\"10\"/></svg>\n";
            foreach ([$leftDir, $rightDir] as $dir) {
                $this->store($dir, $settings + ['logo' => '"svg"']);
                $this->brandFile($dir, 'logo.svg', $svg);
            }
            $this->compare($legacy, $ci4, $paths, 'svg logo');
            $logo = $ci4->client()->get('/brand/logo.svg');
            $this->assertSame($svg, $logo['body']);
            $this->assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $logo['headers']['content-security-policy']);

            $png = img_png(64, 64);
            $ico = "\0\0\1\0" . str_repeat('i', 40);
            foreach ([$leftDir, $rightDir] as $dir) {
                @unlink($dir . '/brand/logo.svg');
                $this->store($dir, ['logo' => '"png"', 'accent' => '"#0f766e"']);
                foreach (['logo.png', 'icon-32.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-maskable-512.png'] as $name) {
                    $this->brandFile($dir, $name, $png);
                }
                $this->brandFile($dir, 'favicon.ico', $ico);
            }
            $this->compare($legacy, $ci4, $paths, 'png logo');
            $this->assertSame($ico, $ci4->client()->get('/favicon.ico')['body']);
            $manifest = $ci4->client()->get('/manifest.webmanifest')['json'];
            $this->assertStringStartsWith('/brand/icon-192.png?v=', $manifest['icons'][0]['src']);

            // HEAD is answered like GET (headers only).
            $head = $ci4->client()->req('HEAD', '/labels.css');
            $this->assertSame(200, $head['status']);
            $this->assertSame('text/css; charset=utf-8', $head['headers']['content-type']);
            $this->assertSame('', $head['body']);
            // Names outside the legacy pattern are not branding routes: an ordinary 404 page with a session.
            $upper = $ci4->client()->get('/brand/Logo.svg');
            $this->assertSame(404, $upper['status']);
            $this->assertSame(404, $legacy->client()->get('/brand/Logo.svg')['status']);
        } finally {
            $legacy->stop();
            $ci4->stop();
            testdb_drop($leftDir);
            testdb_drop($rightDir);
            rrmdir($leftDir);
            rrmdir($rightDir);
            @unlink($leftDir . '.server.log');
            @unlink($rightDir . '.server.log');
        }
    }

    /** @param list<string> $paths */
    private function compare(Server $legacy, Server $ci4, array $paths, string $label): void
    {
        foreach ($paths as $path) {
            $left = $legacy->client()->get($path);
            $right = $ci4->client()->get($path);
            $this->assertSame($left['status'], $right['status'], "$label $path status " . (string)@file_get_contents($ci4->log));
            // The bundled assets' cache busters come from each tree's file times (legacy asset()):
            // CI4 serves its copy under public/assets, so only their values may differ.
            $busters = static fn (string $body): string => (string)preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $body);
            $this->assertSame($busters($left['body']), $busters($right['body']), "$label $path body");
            foreach (self::HEADERS as $name) {
                $this->assertSame($left['headers'][$name] ?? null, $right['headers'][$name] ?? null, "$label $path $name");
            }
            $this->assertArrayNotHasKey('set-cookie', $left['headers'], "$label $path legacy cookie");
            $this->assertArrayNotHasKey('set-cookie', $right['headers'], "$label $path cookie");
        }
    }

    /** @param array<string,string> $values name => JSON */
    private function store(string $dir, array $values): void
    {
        $db = testdb($dir);
        $st = $db->prepare('UPDATE settings SET value = ? WHERE name = ?');
        foreach ($values as $name => $json) {
            $st->execute([$json, $name]);
        }
    }

    /** Same bytes and the same mtime on both sides, so the ?v= cache buster matches. */
    private function brandFile(string $dir, string $name, string $bytes): void
    {
        @mkdir($dir . '/brand', 0775, true);
        file_put_contents($dir . '/brand/' . $name, $bytes);
        touch($dir . '/brand/' . $name, 1700000000);
    }
}
