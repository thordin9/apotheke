<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

require_once dirname(__DIR__, 2) . '/modules/Core/Exceptions/UserError.php';
require_once dirname(__DIR__, 2) . '/modules/Core/Libraries/Text.php';
require_once dirname(__DIR__, 2) . '/modules/Settings/SettingsSchema.php';

use Apotheke\Settings\SettingsSchema;

/**
 * Golden: Apotheke\Settings\SettingsSchema against legacy src/settings.php
 * for every key. Same definitions, same defaults, and the same value or the
 * same UserError message and code for every value of a shared corpus.
 */
final class Ci4SettingsSchemaTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh(['seed' => false]);
        roles(true);
        settings_cache(null, true);
    }

    protected function tearDown(): void
    {
        roles(true);
        settings_cache(null, true);
        parent::tearDown();
    }

    public function testDefinitionsMatchLegacyForEveryKey(): void
    {
        $schema = new SettingsSchema();
        $this->assertSame(settings_schema(), $schema->definitions());
        $this->assertSame(array_keys(settings_schema()), $schema->keys());
        $this->assertSame(SETTINGS_SECTIONS, SettingsSchema::SECTIONS);
        foreach (['ACCENT_DEFAULT', 'MONEY_FORMATS', 'DATE_FORMATS', 'TIME_FORMATS', 'SORTS', 'ATTACH_TYPE_SETS', 'AUTOFILL_SIDE_MIN'] as $const) {
            $this->assertSame(constant($const), constant(SettingsSchema::class . '::' . $const), $const);
        }
        foreach (array_keys(SETTINGS_SECTIONS) as $section) {
            $this->assertSame(settings_section_keys($section), $schema->sectionKeys($section), $section);
        }
    }

    public function testDefaultsMatchLegacyForEveryKey(): void
    {
        $schema = new SettingsSchema();
        foreach (array_keys(settings_schema()) as $key) {
            $legacy = setting_default($key);
            $ci4 = $schema->default($key, cfg());
            if ($key === 'password_hash' && $legacy !== '') {
                $this->assertTrue(password_verify(cfg()['app_password'], (string)$ci4), $key);
                continue;
            }
            $this->assertSame($legacy, $ci4, $key);
        }
    }

    public function testConfigBackedDefaultsReadTheGivenConfig(): void
    {
        $schema = new SettingsSchema();
        $cfg = ['public_url' => 'http://192.0.2.10:9091/', 'currency' => 'CHF', 'timezone' => 'Europe/Athens',
            'page_size' => 25, 'expiry_soon_days' => 14, 'uploads' => ['max_mb' => 8, 'full_max' => 2000, 'thumb_max' => 300],
            'app_password' => 'placeholder-password'];
        $this->assertSame('http://192.0.2.10:9091', $schema->default('public_url', $cfg));
        $this->assertSame('CHF', $schema->default('currency', $cfg));
        $this->assertSame('Europe/Athens', $schema->default('timezone', $cfg));
        $this->assertSame(25, $schema->default('page_size', $cfg));
        $this->assertSame(14, $schema->default('expiry_soon_days', $cfg));
        $this->assertSame(8, $schema->default('max_upload_mb', $cfg));
        $this->assertSame(2000, $schema->default('image_full_max', $cfg));
        $this->assertSame(300, $schema->default('image_thumb_max', $cfg));
        $this->assertTrue($schema->default('auth_enabled', $cfg));
        $this->assertTrue(password_verify('placeholder-password', (string)$schema->default('password_hash', $cfg)));
        // An invalid config value falls back to null, like legacy.
        $this->assertNull($schema->default('timezone', ['timezone' => 'Mars/Base']));
        $this->assertNull($schema->default('page_size', ['page_size' => 9000]));
        $this->assertFalse($schema->default('auth_enabled', []));
        $this->assertSame('', $schema->default('password_hash', []));
        // A literal default never calls the config closure.
        $this->assertSame('Apotheke', $schema->default('app_name', static fn (): array => throw new LogicException('called')));
        $this->assertNull($schema->default('no_such_key', []));
    }

    public function testValidateMatchesLegacyForEveryKeyAndTheCorpus(): void
    {
        $schema = new SettingsSchema();
        $checked = 0;
        foreach (array_keys(settings_schema()) as $key) {
            foreach (self::corpus() as $i => $raw) {
                $this->assertSame($this->outcome(fn () => setting_validate($key, $raw)), $this->outcome(fn () => $schema->validate($key, $raw)), "$key corpus #$i");
                $checked++;
            }
        }
        $this->assertGreaterThan(62 * 60, $checked);
        $this->assertSame($this->outcome(fn () => setting_validate('nope', 'x')), $this->outcome(fn () => $schema->validate('nope', 'x')));
    }

    public function testRoleSettingFollowsTheAccountRoles(): void
    {
        $schema = (new SettingsSchema())->withAccountRoles(['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer', 'helper' => 'Helper']);
        $this->assertSame('helper', $schema->validate('auth_register_role', 'helper'));
        $this->expectExceptionMessage('Role for new registrations: choose a role other than admin.');
        (new SettingsSchema())->validate('auth_register_role', 'helper');
    }

    public function testAutofillMaxImageBytes(): void
    {
        $schema = new SettingsSchema();
        $this->assertTrue($schema->has('autofill_max_image_bytes'), 'migration 0026 key is missing from the schema copy');
        $this->assertSame('autofill', $schema->definitions()['autofill_max_image_bytes'][0]);
        $this->assertSame('auto', $schema->default('autofill_max_image_bytes', []));
        foreach (['', 'auto', 'AUTO', ' Auto ', null] as $raw) {
            $this->assertSame('auto', $schema->validate('autofill_max_image_bytes', $raw));
        }
        $this->assertSame(65536, $schema->validate('autofill_max_image_bytes', '65536'));
        $this->assertSame(10485760, $schema->validate('autofill_max_image_bytes', 10485760));
        foreach (['65535', '10485761', '1e6', '-1', 'big', ['x']] as $raw) {
            try {
                $schema->validate('autofill_max_image_bytes', $raw);
                $this->fail('accepted ' . json_encode($raw));
            } catch (Apotheke\Core\Exceptions\UserError $e) {
                $this->assertSame('Maximum image size (bytes): auto, or a whole number of bytes from 65536 to 10485760.', $e->getMessage());
            }
        }
    }

    /** @return array{0:string,1:mixed,2?:int} */
    private function outcome(callable $fn): array
    {
        try {
            return ['ok', $fn()];
        } catch (UserError | Apotheke\Core\Exceptions\UserError $e) {
            return ['error', $e->getMessage(), $e->getCode()];
        }
    }

    /** @return list<mixed> */
    private static function corpus(): array
    {
        return [
            null, '', ' ', '0', '1', '-1', '  7 ', '42', '-0', '01', '1000000000', '999999999', '1.5', 3.5, 0, 1, 42, -3, true, false,
            'yes', 'no', 'on', 'off', 'true', 'false', 'maybe', 'abc', ['a'], [],
            '#abc', 'abc', 'ABCDEF', '#4F46E5', '#4f46e5', '4f46e5', '#c8502d', '#12345', 'red',
            'Europe/Athens', 'UTC', 'Mars/Base', 'europe/athens',
            'http://192.0.2.10:9091', 'HTTP://Example.com/', 'https://autofill.example/', 'ftp://x', 'https://a b', 'http://[::1]:8080/v1',
            'https://' . str_repeat('x', 200),
            'viewer', 'editor', 'admin', 'guest', 'svg', 'png', 'auto', 'AUTO', '65536', '10485761',
            'en', 'de', 'grid', 'compact', 'recent', 'value', 'pdf_images', 'device', 'install', 'after', 'before', 'dark', 'Y-m-d', 'g:i A',
            "Home, #New\nnew; Café", implode(',', array_map(static fn (int $i): string => "t$i", range(1, 21))), str_repeat('ά', 41),
            str_repeat('x', 501), "  a\tb  ", '  Apotheke  ', str_repeat('y', 81), 'EUR', 'EUROS-LONG', '£',
            'llava:7b', str_repeat('m', 121), '64', '63', '4096', '4097', '00064',
        ];
    }
}
