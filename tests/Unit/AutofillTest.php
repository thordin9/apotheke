<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/views.php';
require_once dirname(__DIR__, 2) . '/src/settings_views.php';

/** AI autofill client: settings, /v1/info validation, Test connection against the PHP echo stub. */
final class AutofillTest extends DatabaseTestCase
{
    /** @var resource|null */
    private static $stub = null;
    private static string $stubBase = '';

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        self::$stub = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", dirname(__DIR__) . '/stubs/autofill-stub.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, null,
            array_merge(getenv(), ['PHP_CLI_SERVER_WORKERS' => '2', 'STUB_SLOW_S' => '5']));
        self::$stubBase = "http://127.0.0.1:$port";
        for ($i = 0; $i < 100 && !($c = @fsockopen('127.0.0.1', $port, $e, $s, 0.1)); $i++) usleep(50000);
        if (!empty($c)) fclose($c);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$stub)) { proc_terminate(self::$stub); proc_close(self::$stub); }
    }

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

    public function testDefaultsAreOffAndEmpty(): void
    {
        $this->assertFalse(setting('autofill_enabled'));
        $this->assertSame('', setting('autofill_url'));
        $this->assertSame(30, setting('autofill_timeout'));
        $this->assertSame('', autofill_token());
        $this->assertFalse(autofill_enabled());
    }

    public function testEnabledNeedsAnAddress(): void
    {
        settings_store(['autofill_enabled' => true]);
        $this->assertFalse(autofill_enabled(), 'on without an address is still off');
        settings_store(['autofill_url' => 'http://127.0.0.1:9']);
        $this->assertTrue(autofill_enabled());
    }

    public function testTokenIsSecretAndNotInTheGenericForm(): void
    {
        $this->assertSame('secret', settings_schema()['autofill_token'][2]);
        $this->assertSame(['autofill_enabled', 'autofill_url', 'autofill_timeout'], settings_section_keys('autofill'));
        settings_store(['autofill_token' => 'abc123']);
        $this->assertArrayNotHasKey('autofill_token', settings_export()['settings']);
        $this->assertStringNotContainsString('abc123', json_encode(settings_export()));
    }

    public function testValidation(): void
    {
        $this->assertSame('http://10.0.0.2:8090', setting_validate('autofill_url', 'http://10.0.0.2:8090'));
        foreach (['ftp://x', 'javascript:alert(1)', 'nope'] as $bad) {
            try { setting_validate('autofill_url', $bad); $this->fail("accepted $bad"); } catch (UserError) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(3, setting_validate('autofill_timeout', '3'));
        $this->expectException(UserError::class);
        setting_validate('autofill_timeout', '2');
    }

    public function testOnlySettingsPermissionReachesTheSection(): void
    {
        foreach (['/settings/autofill', '/settings/autofill/token', '/settings/autofill/test'] as $p) $this->assertSame('settings', route_perm('POST', $p));
        $this->assertNotContains('settings', ROLE_DEFAULTS['editor'][1]);
        $this->assertNotContains('settings', ROLE_DEFAULTS['viewer'][1]);
    }

    public function testParseInfo(): void
    {
        $ok = autofill_parse_info('{"name":"echo","version":"1","capabilities":["name","tags","category","description","colour"],"backend":"echo","max_image_bytes":10485760,"extra":1}');
        $this->assertSame(['name' => 'echo', 'version' => '1', 'capabilities' => ['name', 'tags', 'category', 'description'], 'backend' => 'echo', 'max_image_bytes' => 10485760, 'ready' => true], $ok);
        $this->assertSame([], autofill_parse_info('{"name":"x","version":"1","capabilities":[]}')['capabilities']);
        $this->assertFalse(autofill_parse_info('{"name":"x","version":"1","capabilities":[],"ready":false}')['ready']);
        foreach (['', '{not json', '[]', '"x"', '{"version":"1","capabilities":[]}', '{"name":"x","capabilities":[]}', '{"name":"x","version":"1"}',
                  '{"name":"x","version":"1","capabilities":[1]}', '{"name":"x","version":"1","capabilities":[],"max_image_bytes":-1}',
                  '{"name":" ","version":"1","capabilities":[]}', '{"name":"x","version":"1","capabilities":[],"ready":"yes"}'] as $bad) {
            $this->assertNull(autofill_parse_info($bad), "rejects $bad");
        }
    }

    public function testParseInfoCleansHostileStrings(): void
    {
        $i = autofill_parse_info(json_encode(['name' => "<script>\n" . str_repeat('a', 200), 'version' => '1', 'capabilities' => []]));
        $this->assertLessThanOrEqual(80, mb_strlen($i['name']));
        $this->assertStringNotContainsString("\n", $i['name']);
        $html = autofill_test_result_html(['ok' => true, 'code' => 'ok', 'message' => 'Connected', 'info' => $i]);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testConnectionAgainstTheStub(): void
    {
        $r = autofill_test(self::$stubBase, 5, '');
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('echo', $r['info']['name']);
        $this->assertSame('unauthorized', autofill_test(self::$stubBase . '/t/s3cret', 5, '')['code']);
        $this->assertSame('unauthorized', autofill_test(self::$stubBase . '/t/s3cret', 5, 'wrong')['code']);
        $this->assertTrue(autofill_test(self::$stubBase . '/t/s3cret/', 5, 's3cret')['ok'], 'trailing slash is fine');
        $this->assertSame('bad_response', autofill_test(self::$stubBase . '/m/badinfo', 5, '')['code']);
        $this->assertSame('warming_up', autofill_test(self::$stubBase . '/m/warming', 5, '')['code']);
        $this->assertSame('unreachable', autofill_test('http://127.0.0.1:1', 3, '')['code']);
        $this->assertSame('not_configured', autofill_test('  ', 3, '')['code']);
        $t = microtime(true);
        $this->assertSame('timeout', autofill_test(self::$stubBase . '/m/slow', 3, '')['code']);
        $this->assertLessThan(5, microtime(true) - $t);
    }

    public function testResultMessagesNeverContainTheToken(): void
    {
        foreach (['', '/t/zzz'] as $p) {
            $r = autofill_test(self::$stubBase . $p, 5, 'tok-SECRET-123');
            $this->assertStringNotContainsString('tok-SECRET-123', json_encode($r));
            $this->assertStringNotContainsString('tok-SECRET-123', autofill_test_result_html($r));
        }
    }
}
