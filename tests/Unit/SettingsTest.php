<?php
declare(strict_types=1);

/** Control Panel settings: validation, precedence/seeding, contrast maths, SVG sanitizing, import/export, password. */
final class SettingsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
    }

    protected function tearDown(): void
    {
        settings_cache(null, true);
        parent::tearDown();
    }

    public function testEverySettingHasAValidDefault(): void
    {
        foreach (settings_schema() as $k => $d) {
            $this->assertArrayHasKey($d[0], SETTINGS_SECTIONS, "$k section");
            $def = setting_default($k);
            $this->assertSame($def, setting_validate($k, $def), "$k default validates");
        }
    }

    public function testValidationRejectsBadValues(): void
    {
        $bad = ['page_size' => '0', 'accent' => 'red', 'theme_default' => 'pink', 'timezone' => 'Mars/Base',
                'currency' => 'EUROS-LONG', 'date_format' => 'Y', 'public_url' => 'ftp://x', 'jpeg_quality' => '200',
                'default_quantity' => '-1', 'max_upload_mb' => 'abc', 'app_name' => '', 'default_sort' => 'random'];
        foreach ($bad as $k => $v) {
            try {
                setting_validate($k, $v);
                $this->fail("$k accepted " . $v);
            } catch (UserError $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testValidationNormalises(): void
    {
        $this->assertSame('#C8502D', setting_validate('accent', 'c8502d'));
        $this->assertSame('£', setting_validate('currency', ' £ '));
        $this->assertSame(25, setting_validate('page_size', ' 25 '));
        $this->assertTrue(setting_validate('overview_open', '1'));
        $this->assertFalse(setting_validate('overview_open', '0'));
        $this->assertNull(setting_validate('default_min_qty', ''));
        $this->assertSame('https://inv.example', setting_validate('public_url', 'https://inv.example/'));
    }

    public function testSaveIsAllOrNothing(): void
    {
        $err = settings_save(['page_size' => '25', 'accent' => 'nope']);
        $this->assertArrayHasKey('accent', $err);
        $this->assertSame(60, setting('page_size'), 'nothing stored when one value is invalid');
        $this->assertSame([], settings_save(['page_size' => '25', 'accent' => '#C8502D']));
        settings_cache(null, true);
        $this->assertSame(25, setting('page_size'));
        $this->assertSame('#C8502D', setting('accent'));
    }

    public function testPrecedenceConfigSeedsDatabaseWins(): void
    {
        $this->assertSame((int)cfg()['page_size'], setting('page_size'), 'unseeded → config.php default');
        settings_seed($this->pdo);
        $this->assertSame(count(settings_schema()), (int)$this->pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());
        settings_store(['page_size' => 7]);
        settings_seed($this->pdo); // seeding again never overwrites
        settings_cache(null, true);
        $this->assertSame(7, setting('page_size'), 'the database wins over config.php');
        $this->assertNotContains('db', array_keys(settings_schema()), 'bootstrap values are not DB settings');
        foreach (settings_bootstrap_info() as [$label, $value]) {
            if (str_contains($label, 'password')) $this->assertStringNotContainsString((string)cfg()['db']['password'] ?: "\0", $value, 'DB password never shown');
        }
    }

    public function testResetSection(): void
    {
        settings_save(['page_size' => '9', 'list_view' => 'compact', 'app_name' => 'Shed']);
        settings_reset_section('list');
        settings_cache(null, true);
        $this->assertSame(60, setting('page_size'));
        $this->assertSame('grid', setting('list_view'));
        $this->assertSame('Shed', setting('app_name'), 'other sections untouched');
    }

    public function testExportImportRoundTrip(): void
    {
        settings_save(['app_name' => 'Garage', 'accent' => '#0F766E']);
        user_create('admin', 'longpassword', 'admin');
        auth_turn_on();
        $ex = settings_export();
        $this->assertSame('apotheke-settings', $ex['format']);
        $this->assertArrayNotHasKey('password_hash', $ex['settings']);
        $this->assertArrayNotHasKey('auth_enabled', $ex['settings']);
        settings_reset_section('general');
        $ex['settings']['page_size'] = 'x';
        $ex['settings']['bogus'] = 1;
        $r = settings_import(json_encode($ex));
        settings_cache(null, true);
        $this->assertSame('Garage', setting('app_name'));
        $this->assertArrayHasKey('page_size', $r['errors']);
        $this->assertContains('bogus', $r['ignored']);
        $this->assertTrue(auth_enabled(), 'import never touches the password');
        $this->expectException(UserError::class);
        settings_import('{"format":"other"}');
    }

    public function testContrastMaths(): void
    {
        $this->assertEqualsWithDelta(21.0, contrast_ratio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, contrast_ratio('#777777', '#777777'), 0.01);
        $this->assertEqualsWithDelta(4.16, contrast_ratio('#c8502d', '#f5f5f7'), 0.02);
        foreach (array_merge(array_keys(ACCENT_PRESETS), ['#ffff00', '#000080', '#C8502D']) as $hex) {
            $v = accent_variants($hex);
            foreach (CONTRAST_BG_LIGHT as $bg) $this->assertGreaterThanOrEqual(4.5, contrast_ratio($v['light']['accent'], $bg), "$hex light on $bg");
            if (strtolower($hex) !== ACCENT_DEFAULT) foreach (CONTRAST_BG_DARK as $bg) $this->assertGreaterThanOrEqual(4.5, contrast_ratio($v['dark']['accent'], $bg), "$hex dark on $bg");
        }
        $this->assertSame([], accent_warnings(ACCENT_DEFAULT));
        $this->assertSame('', accent_css(ACCENT_DEFAULT), 'default accent needs no CSS');
        $this->assertNotSame([], accent_warnings('#ffff00'));
        $css = accent_css('#C8502D');
        $this->assertStringContainsString('--accent:', $css);
        $this->assertStringContainsString('prefers-color-scheme:dark', $css);
    }

    public function testSvgSanitizer(): void
    {
        $evil = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(1)</script>'
            . '<a href="javascript:alert(1)"><circle r="1"/></a><rect width="10" height="10" fill="red" style="fill:url(https://x/y)"/>'
            . '<foreignObject><div>x</div></foreignObject><image href="https://evil/x.png"/><use href="#r"/></svg>';
        $out = svg_sanitize($evil);
        foreach (['script', 'onload', 'javascript', 'foreignObject', 'https://', '<image'] as $bad) $this->assertStringNotContainsString($bad, $out, $bad);
        $this->assertStringContainsString('<rect', $out);
        $this->assertStringContainsString('href="#r"', $out);
        foreach (['<!DOCTYPE svg [<!ENTITY x "y">]><svg xmlns="http://www.w3.org/2000/svg"/>', '<html/>', 'not xml'] as $bad) {
            try { svg_sanitize($bad); $this->fail('accepted ' . $bad); } catch (UserError) { $this->addToAssertionCount(1); }
        }
    }

    public function testSignInMode(): void
    {
        $this->assertFalse(auth_enabled());
        settings_store(['auth_enabled' => true]);
        settings_cache(null, true);
        $this->assertFalse(auth_enabled(), 'the switch alone does nothing without an account');
        user_create('admin', 'correct horse', 'admin');
        $this->assertTrue(auth_enabled());
        auth_turn_off();
        settings_cache(null, true);
        $this->assertFalse(auth_enabled());
        $this->assertSame(1, users_count(), 'turning off keeps the accounts');
    }

    public function testFormattingHelpers(): void
    {
        settings_save(['date_format' => 'd.m.Y', 'money_format' => 'de']);
        settings_cache(null, true);
        $this->assertSame('15.06.2026', fmt_date('2026-06-15'));
        $this->assertSame('1.234,50', fmt_number(1234.5));
        settings_save(['default_quantity' => '3', 'default_tags' => 'home, new', 'default_expiry_months' => '24']);
        settings_cache(null, true);
        $d = new_item_defaults();
        $this->assertSame(3, (int)$d['quantity']);
        $this->assertStringContainsString('home', (string)$d['tags']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string)$d['expires_on']);
    }
}
