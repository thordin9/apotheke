<?php

declare(strict_types=1);

namespace Tests\Layout;

use Apotheke\Core\Libraries\AssetUrl;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Layout\ErrorPage;
use Apotheke\Layout\Icons;
use Apotheke\Layout\Page;
use Apotheke\Layout\Theme;
use Apotheke\Layout\Viewer;
use Apotheke\Settings\Branding\Branding;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * The Layout module on its own. Byte parity with legacy is
 * tests/Unit/Ci4LayoutParityTest.php; this covers what that cannot reach:
 * the plain fallback page, escaping of every value, and the viewer's
 * failure mode.
 */
final class LayoutTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    public function testTheErrorPageFallsBackToThePlainPageAndLogsOnlyTheClass(): void
    {
        $logged = [];
        $page = new ErrorPage(static fn (): Page => throw new RuntimeException('SQLSTATE[08006] host=db user=secret-user'), static function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $this->assertSame(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head><body><p>a &lt;b&gt; &amp; &quot;c&quot;</p></body></html>',
            $page->html(500, 'a <b> & "c"'),
        );
        $this->assertSame(['[apotheke] error page: RuntimeException'], $logged);
    }

    public function testEveryValueIsEscapedAndNoSessionIsStartedForAnErrorPage(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $settings = $this->settings();
        $settings->store(['app_name' => '<Box> & "Co"', 'app_tagline' => "it's <i>", 'accent' => '#C8502D']);
        $created = [];
        $page = $this->page($settings, ['username' => '"><script>', 'role' => 'x"y', 'guest' => false, 'anon' => false], $created, '"><x', '/a"b');
        $html = (new ErrorPage(static fn (): Page => $page))->html(403, '<img src=x onerror=alert(1)>');
        $this->assertSame([false], $created, 'the error page asks for the token without creating a session');
        $this->assertStringContainsString('<title>Error · &lt;Box&gt; &amp; &quot;Co&quot;</title>', $html);
        $this->assertStringContainsString('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $html);
        $this->assertStringContainsString('<html lang="en" data-theme="auto">', $html, 'an unknown cookie is the default theme');
        $this->assertStringContainsString('<body class="role-xy no-view', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/theme.css?v=' . substr(md5('#C8502D'), 0, 8) . '" id="accent-css">', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('style=', $html, 'no inline style: the CSP stays legacy\'s');
        $this->assertDoesNotMatchRegularExpression('#<script>(?!</script>)#', $html, 'no inline script');

        $menu = $page->menu();
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $menu);
        $this->assertStringContainsString('name="back" value="/a&quot;b"', $menu);
        $this->assertStringContainsString('name="csrf" value="tok&quot;en"', $menu);
        $this->assertStringContainsString('<small class="menu-role">Role &lt;x&gt;</small>', $menu);
        $this->assertStringNotContainsString('id="menu-settings"', $menu, 'a role without Settings or Users');
        $this->assertStringContainsString('<div class="toast" role="status">&lt;b&gt;</div>', $page->toast('<b>'));
        $this->assertSame('', $page->toast(null));
        $this->assertSame('<div class="err" role="alert" id="e&quot;1">&lt;b&gt;</div>', $page->errorBox('<b>', 'e"1'));
        $this->assertSame('<img class="brand-logo" src="' . $this->assets()->url('icon.svg') . '" width="28" height="28" alt="" decoding="async">', $page->brandLogo(28));
        $this->assertStringContainsString('<h1 class="title-sm">&lt;T&gt;</h1>', $page->simpleHeader('<T>', '/"x'));
        $this->assertStringContainsString('href="/&quot;x"', $page->simpleHeader('<T>', '/"x'));
    }

    public function testABrokenUserReadGivesNoRoleClass(): void
    {
        $viewer = new Viewer(static fn (): ?array => throw new RuntimeException('db'), static fn (): bool => false, static fn (string $r): string => $r, ['view']);
        $this->assertSame('', $viewer->bodyClass());
        $admin = new Viewer(static fn (): ?array => ['username' => 'a', 'role' => 'admin', 'guest' => false, 'anon' => false], static fn (): bool => false, static fn (string $r): string => $r, ['view']);
        $this->assertSame('', $admin->bodyClass());
        $guest = new Viewer(static fn (): ?array => ['username' => '', 'role' => 'guest', 'guest' => true, 'anon' => true], static fn (?array $u, string $p): bool => $p === 'view', static fn (string $r): string => $r, ['view', 'edit']);
        $this->assertSame('role-guest no-edit is-guest', $guest->bodyClass());
    }

    public function testThemeCookie(): void
    {
        $this->assertSame('theme=dark; expires=' . gmdate('D, d M Y H:i:s', 1000 + Theme::COOKIE_SECONDS) . ' GMT; Max-Age=34560000; path=/; SameSite=Lax', Theme::cookie('dark', 1000));
        $this->assertSame('auto', Theme::valid(['dark']));
        $this->assertSame('auto', Theme::valid('Dark'));
        $this->assertStringStartsWith('<svg class="i"', Icons::svg('box'));
        $this->assertSame(Icons::svg(''), Icons::svg('nope'));
    }

    /**
     * @param array{username:string,role:string,guest:bool,anon:bool}|null $user
     * @param list<bool> $created
     */
    private function page(SettingsService $settings, ?array $user, array &$created, mixed $themeCookie, string $uri): Page
    {
        $viewer = new Viewer(static fn (): ?array => $user, static fn (): bool => false, static fn (string $role): string => 'Role <x>', ['view', 'edit']);

        return new Page(
            $settings,
            new Branding($settings, new LocalBrandStore($this->database?->dataDir() . '/brand'), $this->assets()),
            $this->assets(),
            $viewer,
            static function (bool $create) use (&$created): string {
                $created[] = $create;

                return 'tok"en';
            },
            static fn (): bool => true,
            $themeCookie,
            $uri,
        );
    }

    private function assets(): AssetUrl
    {
        return new AssetUrl(dirname(__DIR__, 2) . '/public/assets');
    }

    private function settings(): SettingsService
    {
        $database = $this->database;
        $this->assertNotNull($database);

        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null, new CommandLine());
    }
}
