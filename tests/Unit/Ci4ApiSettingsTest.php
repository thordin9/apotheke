<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * API P2: GET /api/v1/settings and GET /api/v1/settings/{section} (API plan §3, Control Panel row): the values the
 * Control Panel's forms show, with their schema, for the settings permission. Write-only and internal settings
 * (the autofill access token, the old password hash, the logo) are never in an answer: autofill says only whether
 * a token is set.
 */
final class Ci4ApiSettingsTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private const TOKEN = 'api-settings-token-placeholder';

    private const SECTIONS = ['general', 'appearance', 'locale', 'list', 'items', 'limits', 'labels', 'uploads', 'autofill', 'data'];

    /**
     * Every key each section returns, pinned (L-1240): a write-only or internal key whose type became a readable one
     * would show up here first.
     */
    private const KEYS = [
        'general' => ['app_name', 'app_tagline', 'public_url'],
        'appearance' => ['accent', 'theme_default', 'icon_background'],
        'locale' => ['currency', 'money_format', 'currency_position', 'date_format', 'time_format', 'timezone'],
        'list' => ['page_size', 'default_sort', 'list_view', 'recent_limit', 'recent_scope', 'search_typos', 'overview_open'],
        'items' => ['default_quantity', 'default_min_qty', 'default_tags', 'default_notes', 'default_purchase_today', 'default_expiry_months'],
        'limits' => ['expiry_soon_days', 'lend_due_days', 'saved_filters_max', 'undo_seconds', 'stocktake_apply_moves', 'max_photos'],
        'labels' => ['label_width_mm', 'label_height_mm', 'label_columns', 'label_gap_mm', 'label_show_sub', 'label_show_url', 'label_border'],
        'uploads' => ['max_upload_mb', 'image_full_max', 'image_thumb_max', 'jpeg_quality', 'attach_types', 'attach_max_mb', 'attach_max_per_item'],
        'autofill' => ['autofill_enabled', 'autofill_auto', 'autofill_url', 'autofill_timeout', 'autofill_model', 'autofill_image_size', 'autofill_max_image_bytes'],
        'data' => ['import_create_missing'],
    ];

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-settings');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
        $pdo = testdb(self::$dir);
        $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['vera', 'vera', password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'viewer', 'active', '2026-01-01 00:00:00']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    public function testSignedOutIs401AndAViewerIs403(): void
    {
        $anon = $this->client();
        foreach (['/api/v1/settings', '/api/v1/settings/general'] as $path) {
            $r = $anon->rest('GET', $path);
            $this->assertSame([401, 'auth.required'], [$r['status'], $r['json']['code'] ?? null], $path);
            ApiContract::assertMatches('GET', $path, $r);
        }
        $vera = $this->signedIn('vera');
        foreach (['/api/v1/settings', '/api/v1/settings/autofill'] as $path) {
            $r = $vera->rest('GET', $path);
            $this->assertSame([403, 'permission.denied'], [$r['status'], $r['json']['code'] ?? null], $path);
            ApiContract::assertMatches('GET', $path, $r);
            $this->assertStringNotContainsString('autofill_url', $r['body']);
        }
    }

    public function testEverySectionWithItsSchema(): void
    {
        $admin = $this->signedIn('admin');
        $r = $admin->rest('GET', '/api/v1/settings');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/settings', $r);
        $this->assertSame('no-store', $r['headers']['cache-control']);
        $sections = $r['json']['sections'];
        $this->assertSame(self::SECTIONS, array_column($sections, 'section'), 'security (users permission) and system (not settings) are not here');
        $general = $sections[0];
        $this->assertSame('General', $general['title']);
        $this->assertSame(['app_name', 'app_tagline', 'public_url'], array_keys($general['values']));
        $this->assertSame('Apotheke', $general['values']['app_name']);
        $this->assertSame(['key' => 'app_name', 'label' => 'App name', 'type' => 'string', 'default' => 'Apotheke',
            'help' => 'Shown in the header, the window title and on the home screen.', 'rules' => ['max' => 40, 'required' => true]], $general['fields'][0]);
        $list = $sections[3];
        $this->assertSame(['min' => 1, 'max' => 500], $list['fields'][0]['rules'], 'page_size');
        $this->assertSame(['grid' => 'Cards with photos', 'compact' => 'Compact rows'], $list['fields'][2]['rules']['options'], 'list_view');
        $this->assertIsInt($list['values']['page_size']);
        $this->assertIsBool($list['values']['search_typos']);
        $this->assertSame([], array_filter($sections, static fn (array $s): bool => $s['section'] !== 'autofill' && array_key_exists('token_set', $s)));
        $this->assertNotContains('logo', array_keys($sections[1]['values']), 'internal');
        $raw = json_decode($r['body']);
        $this->assertSame('accent', $raw->sections[1]->fields[0]->key);
        $this->assertEquals(new stdClass(), $raw->sections[1]->fields[0]->rules, 'no rules is an empty object, not a list');
        $this->assertSame(self::KEYS, array_combine(array_column($sections, 'section'), array_map(static fn (array $s): array => array_keys($s['values']), $sections)),
            'every section returns exactly its pinned keys');
        foreach ($sections as $s) {
            $this->assertMatchesRegularExpression('/^"[a-f0-9]{64}"$/', $s['etag']);
            $this->assertSame(array_keys($s['values']), array_column($s['fields'], 'key'));
        }
    }

    #[DataProvider('sections')]
    public function testOneSectionMatchesItsPartOfTheWhole(string $section): void
    {
        $admin = $this->signedIn('admin');
        $all = $admin->rest('GET', '/api/v1/settings')['json']['sections'];
        $r = $admin->rest('GET', '/api/v1/settings/' . $section);
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/settings/' . $section, $r);
        $this->assertSame($all[array_search($section, self::SECTIONS, true)], $r['json']);
        $this->assertSame($r['json']['etag'], $r['headers']['etag']);
    }

    /** @return iterable<string,array{0:string}> */
    public static function sections(): iterable
    {
        foreach (self::SECTIONS as $s) {
            yield $s => [$s];
        }
    }

    public function testTheTokenIsNeverReadBack(): void
    {
        $admin = $this->signedIn('admin');
        $before = $admin->rest('GET', '/api/v1/settings/autofill');
        $this->assertFalse($before['json']['token_set']);
        $this->assertArrayNotHasKey('autofill_token', $before['json']['values']);
        $web = $this->client();
        $web->post('/login', ['csrf' => $web->csrf(), 'username' => 'admin', 'password' => self::PASSWORD]);
        $saved = $web->post('/settings/autofill/token', ['csrf' => $web->csrf(), 'token' => self::TOKEN]);
        $this->assertContains($saved['status'], [200, 302, 303], $saved['body']);
        $after = $admin->rest('GET', '/api/v1/settings/autofill');
        $this->assertTrue($after['json']['token_set']);
        $this->assertNotSame($before['json']['etag'], $after['json']['etag'], 'setting a token changes the ETag (token_set)');
        $other = $web->post('/settings/autofill/token', ['csrf' => $web->csrf(), 'token' => self::TOKEN . '-other']);
        $this->assertContains($other['status'], [200, 302, 303], $other['body']);
        $changed = $admin->rest('GET', '/api/v1/settings/autofill');
        $this->assertTrue($changed['json']['token_set']);
        $this->assertSame($after['json']['etag'], $changed['json']['etag'], 'another token keeps the ETag: it says nothing about the value (N-1242)');
        $this->assertStringNotContainsString(self::TOKEN, $changed['body']);
        foreach (['/api/v1/settings', '/api/v1/settings/autofill'] as $path) {
            $body = $admin->rest('GET', $path)['body'];
            $this->assertStringNotContainsString(self::TOKEN, $body);
            $this->assertStringNotContainsString('autofill_token', $body);
            $this->assertStringNotContainsString('password_hash', $body);
            $this->assertStringNotContainsString('$2y$', $body);
        }
    }

    public function testIfNoneMatch(): void
    {
        $admin = $this->signedIn('admin');
        $r = $admin->rest('GET', '/api/v1/settings/labels');
        $etag = $r['headers']['etag'];
        $same = $admin->rest('GET', '/api/v1/settings/labels', null, ['If-None-Match: ' . $etag]);
        $this->assertSame([304, ''], [$same['status'], $same['body']]);
        $this->assertSame($etag, $same['headers']['etag']);
        ApiContract::assertMatches('GET', '/api/v1/settings/labels', $same);
        $listed = $admin->rest('GET', '/api/v1/settings/labels', null, ['If-None-Match: "other", W/' . $etag]);
        $this->assertSame(304, $listed['status'], 'a list, and a weak match');
        $this->assertSame(304, $admin->rest('GET', '/api/v1/settings/labels', null, ['If-None-Match: *'])['status']);
        $stale = $admin->rest('GET', '/api/v1/settings/labels', null, ['If-None-Match: "' . str_repeat('0', 64) . '"']);
        $this->assertSame(200, $stale['status']);
        $other = $admin->rest('GET', '/api/v1/settings/general', null, ['If-None-Match: ' . $etag]);
        $this->assertSame(200, $other['status'], 'one section\'s ETag is not another\'s');
    }

    public function testOtherSectionsAreNotFound(): void
    {
        $admin = $this->signedIn('admin');
        foreach (['security', 'system', 'nope', 'general/extra'] as $section) {
            $r = $admin->rest('GET', '/api/v1/settings/' . $section);
            $this->assertSame([404, 'not_found'], [$r['status'], $r['json']['code'] ?? null], $section);
        }
    }

    private function client(): Client
    {
        $this->assertNotNull(self::$server);

        return self::$server->client();
    }

    private function signedIn(string $user): Client
    {
        $c = $this->client();
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $in = $c->rest('POST', '/api/v1/session', ['username' => $user, 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);

        return $c;
    }
}
