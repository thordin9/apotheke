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
        $this->assertSame(50, setting('autofill_timeout'));
        $this->assertSame('', autofill_token());
        $this->assertFalse(autofill_enabled());
    }

    public function testMigration0024MovesOnlyTheOldDefaultTimeout(): void
    {
        $run = require migrations_dir() . '/0024_autofill_timeout.php';
        foreach ([30 => 50, 20 => 20, 45 => 45, 60 => 60, 50 => 50] as $stored => $want) {
            $this->pdo->prepare("UPDATE settings SET value = ? WHERE name = 'autofill_timeout'")->execute([json_encode($stored)]);
            $run($this->pdo, $this->driver);
            settings_cache(null, true);
            $this->assertSame($want, setting('autofill_timeout'), "stored $stored");
        }
        $this->assertArrayHasKey('0024', migrations_applied($this->pdo, $this->driver), 'part of the normal migration run');
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
        foreach (['/settings/autofill', '/settings/autofill/token', '/settings/autofill/test', '/settings/autofill/test-photo'] as $p) $this->assertSame('settings', route_perm('POST', $p));
        $this->assertNotContains('settings', ROLE_DEFAULTS['editor'][1]);
        $this->assertNotContains('settings', ROLE_DEFAULTS['viewer'][1]);
    }

    public function testParseInfo(): void
    {
        $ok = autofill_parse_info('{"name":"echo","version":"1","capabilities":["name","tags","category","description","colour"],"backend":"echo","max_image_bytes":10485760,"extra":1}');
        $this->assertSame(['name' => 'echo', 'version' => '1', 'capabilities' => ['name', 'tags', 'category', 'description'], 'backend' => 'echo', 'max_image_bytes' => 10485760, 'ready' => true, 'avg_latency_ms' => null], $ok);
        $this->assertSame([], autofill_parse_info('{"name":"x","version":"1","capabilities":[]}')['capabilities']);
        $this->assertFalse(autofill_parse_info('{"name":"x","version":"1","capabilities":[],"ready":false}')['ready']);
        foreach (['', '{not json', '[]', '"x"', '{"version":"1","capabilities":[]}', '{"name":"x","capabilities":[]}', '{"name":"x","version":"1"}',
                  '{"name":"x","version":"1","capabilities":[1]}', '{"name":"x","version":"1","capabilities":[],"max_image_bytes":-1}',
                  '{"name":" ","version":"1","capabilities":[]}', '{"name":"x","version":"1","capabilities":[],"ready":"yes"}'] as $bad) {
            $this->assertNull(autofill_parse_info($bad), "rejects $bad");
        }
    }

    public function testParseInfoAvgLatencyIsOptional(): void
    {
        $base = '{"name":"x","version":"1","capabilities":[]';
        $this->assertSame(13500, autofill_parse_info($base . ',"avg_latency_ms":13500}')['avg_latency_ms']);
        $this->assertSame(0, autofill_parse_info($base . ',"avg_latency_ms":0}')['avg_latency_ms']);
        foreach (['-1', '13.5', '"13500"', 'null', 'true', '[1]'] as $odd) {
            $i = autofill_parse_info($base . ',"avg_latency_ms":' . $odd . '}');
            $this->assertNotNull($i, "an odd avg_latency_ms ($odd) does not reject the answer");
            $this->assertNull($i['avg_latency_ms'], "… it is ignored ($odd)");
        }
        $this->assertSame(3_600_000, autofill_parse_info($base . ',"avg_latency_ms":99999999}')['avg_latency_ms'], 'capped at an hour');
    }

    public function testExpectedTimeComesFromTheCachedInfo(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase]);
        @unlink(DATA_DIR . '/autofill-info.json');
        $this->assertNull(autofill_expected_ms(), 'nothing cached: unknown');
        autofill_info_cache(['name' => 'x', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => null, 'ready' => true, 'avg_latency_ms' => 13500]);
        $this->assertSame(13500, autofill_expected_ms());
        autofill_info_cache(['name' => 'x', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => null, 'ready' => true]);
        $this->assertNull(autofill_expected_ms(), 'an info without the field (older cache): unknown');
        settings_store(['autofill_url' => self::$stubBase . '/x']);
        $this->assertNull(autofill_expected_ms(), 'cached for another address: unknown');
    }

    public function testSuccessfulSuggestRefreshesAStaleInfo(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . '/l/4200', 'autofill_timeout' => 5]);
        @unlink(DATA_DIR . '/autofill-info.json');
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(4200, $r['avg_latency_ms'], 'the answer carries the fresh average');
        $this->assertSame(4200, autofill_expected_ms(), '… and it is cached for the next page');
        $this->assertArrayNotHasKey('confidence', $r);
        // a failed suggestion does not contact /v1/info
        @unlink(DATA_DIR . '/autofill-info.json');
        settings_store(['autofill_url' => self::$stubBase . '/m/error/l/4200']);
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertSame('busy', $r['code']);
        $this->assertNull($r['avg_latency_ms']);
        $this->assertNull(autofill_info_cache());
    }

    public function testRetryAfterIsPassedThrough(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . '/m/busy/r/17', 'autofill_timeout' => 5]);
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertSame(['busy', 17], [$r['code'], $r['retry_after'] ?? null]);
        $this->assertStringContainsString('warming up', $r['message']);
        settings_store(['autofill_url' => self::$stubBase . '/m/error']);
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertSame('busy', $r['code']);
        $this->assertArrayNotHasKey('retry_after', $r, 'a 503 without Retry-After is not worth a retry');
        // cold: the first call is refused with Retry-After, the next one is answered
        settings_store(['autofill_url' => self::$stubBase . '/m/cold/r/2']);
        @unlink(sys_get_temp_dir() . '/af-stub-' . parse_url(self::$stubBase, PHP_URL_PORT) . '.cold');
        $this->assertSame(2, autofill_suggest($this->jpeg(40, 40))['retry_after'] ?? null);
        $this->assertTrue(autofill_suggest($this->jpeg(40, 40))['ok']);
    }

    public function testParseRetryAfter(): void
    {
        $this->assertSame(15, autofill_parse_retry_after(" 15\r\n"));
        $this->assertSame(0, autofill_parse_retry_after('0'));
        $this->assertSame(86400, autofill_parse_retry_after('999999'));
        $this->assertNull(autofill_parse_retry_after(''));
        $this->assertNull(autofill_parse_retry_after('soon'));
        $this->assertNull(autofill_parse_retry_after('-5'));
        $in = autofill_parse_retry_after(gmdate('D, d M Y H:i:s', time() + 30) . ' GMT');
        $this->assertGreaterThanOrEqual(28, $in);
        $this->assertLessThanOrEqual(30, $in);
        $this->assertSame(0, autofill_parse_retry_after(gmdate('D, d M Y H:i:s', time() - 60) . ' GMT'), 'a date in the past: now');
    }

    public function testParseConfidence(): void
    {
        $this->assertSame(['name' => 0.82, 'category' => null, 'description' => 1.0],
            autofill_parse_confidence('{"name":{"value":"x","confidence":0.8234},"category":{"value":"y","confidence":7},"description":{"value":"z","confidence":1}}'));
        $this->assertSame(['name' => null, 'category' => null, 'description' => null], autofill_parse_confidence('{not json'));
    }

    public function testPhotoTestAgainstTheStub(): void
    {
        $this->assertFileExists(autofill_sample_path());
        $this->assertLessThan(100 * 1024, filesize(autofill_sample_path()), 'the sample photo stays small');
        $r = autofill_test_photo(self::$stubBase . '/l/1500', 5, '');
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame(['reachable' => true, 'token' => true, 'ready' => true], $r['checks']);
        $this->assertMatchesRegularExpression('/^Echo item [0-9a-f]{8}$/', $r['suggestions']['name']);
        $this->assertStringStartsWith('JPEG image, ', $r['suggestions']['description']);
        $this->assertSame(0.5, $r['confidence']['name']);
        $this->assertIsInt($r['elapsed_ms']);
        $this->assertSame(1500, $r['info']['avg_latency_ms']);
        $html = autofill_test_result_html($r);
        foreach (['id="af-checks"', 'data-check="ready" data-state="ok"', 'id="af-s-name"', '50 %', 'id="af-s-elapsed"', 'id="af-latency"', '1.5 s'] as $want) {
            $this->assertStringContainsString($want, $html);
        }
    }

    public function testPhotoTestFailures(): void
    {
        $r = autofill_test_photo('http://127.0.0.1:1', 3, '');
        $this->assertSame(['unreachable', ['reachable' => false, 'token' => null, 'ready' => null]], [$r['code'], $r['checks']]);
        $this->assertStringContainsString('not checked', autofill_test_result_html($r));
        $r = autofill_test_photo(self::$stubBase . '/t/right', 5, 'wrong-SECRET-tok');
        $this->assertSame(['unauthorized', ['reachable' => true, 'token' => false, 'ready' => null]], [$r['code'], $r['checks']]);
        $this->assertStringNotContainsString('wrong-SECRET-tok', json_encode($r) . autofill_test_result_html($r), 'never echoes the token');
        $this->assertTrue(autofill_test_photo(self::$stubBase . '/t/right', 5, 'right')['ok']);
        $r = autofill_test_photo(self::$stubBase . '/m/warming', 5, '');
        $this->assertSame(['warming_up', ['reachable' => true, 'token' => true, 'ready' => false]], [$r['code'], $r['checks']]);
        $r = autofill_test_photo(self::$stubBase . '/m/busy/r/20', 5, '');
        $this->assertSame(['busy', false, 20], [$r['code'], $r['checks']['ready'], $r['retry_after'] ?? null]);
        $this->assertStringStartsWith('The sample photo got no suggestions:', $r['message']);
        $r = autofill_test_photo(self::$stubBase . '/m/empty', 5, '');
        $this->assertSame('empty', $r['code']);
        $this->assertTrue($r['ok']);
        // the whole run stays within the timeout: a slow model is cut off at the configured 3 s
        $t = microtime(true);
        $r = autofill_test_photo(self::$stubBase . '/d/8000', 3, '');
        $this->assertSame('timeout', $r['code']);
        $this->assertLessThan(4.5, microtime(true) - $t);
        $this->assertTrue($r['checks']['ready'], 'the checks passed before the photo timed out');
        $this->assertSame('not_configured', autofill_test_photo(' ', 3, '')['code']);
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

    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($im);
        return (string)ob_get_clean();
    }

    public function testPrepareImageDownscalesToJpeg1024(): void
    {
        $out = autofill_prepare_image($this->jpeg(2048, 1536));
        $this->assertTrue($out['converted']);
        $this->assertSame('image/jpeg', $out['mime']);
        $i = getimagesizefromstring($out['data']);
        $this->assertSame([1024, 768, IMAGETYPE_JPEG], [$i[0], $i[1], $i[2]]);
        $small = getimagesizefromstring(autofill_prepare_image($this->jpeg(300, 500))['data']);
        $this->assertSame([300, 500], [$small[0], $small[1]], 'small photos are not enlarged');
        $odd = autofill_prepare_image("\x00\x00\x00\x18ftypheic....");
        $this->assertFalse($odd['converted'], 'unreadable images pass through unchanged');
        $this->assertSame("\x00\x00\x00\x18ftypheic....", $odd['data']);
        $this->assertSame('image/heic', $odd['mime']);
        $this->assertNull(autofill_prepare_image("%PDF-1.7\n%\xE2\xE3\n1 0 obj"), 'a PDF is not sent');
        $this->assertNull(autofill_prepare_image('just some text'), 'random bytes are not sent');
        $this->assertNull(autofill_prepare_image("\xFF\xD8\xFF\xE0broken jpeg"), 'a broken JPEG is not sent');
        $this->assertNull(autofill_prepare_image("\x00\x00\x00\x18ftypisom...."), 'an MP4 (non-HEIF ftyp) is not sent');
    }

    public function testParseSuggestion(): void
    {
        $s = autofill_parse_suggestion('{"name":{"value":"Cordless drill","confidence":0.82},"category":{"value":"Tools"},"tags":[{"value":"bosch","confidence":0.6},{"value":"Bosch"},{"value":"red"}],"description":{"value":"Red Bosch\n18V drill","confidence":1},"colour":"red"}');
        $this->assertSame(['name' => 'Cordless drill', 'category' => 'Tools', 'tags' => ['bosch', 'red'], 'description' => "Red Bosch\n18V drill"], $s);
        $this->assertSame(['name' => null, 'category' => null, 'tags' => [], 'description' => null], autofill_parse_suggestion('{}'));
        foreach (['{not json', '', '[]', '"x"', 'null'] as $bad) $this->assertNull(autofill_parse_suggestion($bad), "malformed: $bad");
        // wrong-shaped fields are dropped one by one
        $s = autofill_parse_suggestion('{"name":"plain string","category":{"value":"X","confidence":7},"tags":"a,b","description":{"value":"   "}}');
        $this->assertSame(['name' => null, 'category' => null, 'tags' => [], 'description' => null], $s);
        // caps: long values cut, at most 8 tags, control characters removed
        $s = autofill_parse_suggestion(json_encode(['name' => ['value' => str_repeat('n', 500) . "\t"], 'tags' => array_map(fn($i) => ['value' => "t$i"], range(1, 20)),
            'description' => ['value' => "a\x07b" . str_repeat('d', 3000)]]));
        $this->assertSame(200, mb_strlen($s['name']));
        $this->assertCount(8, $s['tags']);
        $this->assertSame(2000, mb_strlen($s['description']));
        $this->assertStringStartsWith('abd', $s['description']);
    }

    public function testSuggestIsOffWithoutTouchingTheNetwork(): void
    {
        settings_store(['autofill_url' => 'http://127.0.0.1:1']);
        $this->assertSame('off', autofill_suggest($this->jpeg(10, 10))['code']);
    }

    public function testSuggestAgainstTheStub(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase, 'autofill_timeout' => 5]);
        $r = autofill_suggest($this->jpeg(1600, 1200));
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('Misc', $r['suggestions']['category'], 'no categories yet: the echo default');
        $this->assertSame(['echo', 'sample'], $r['suggestions']['tags']);
        $this->assertMatchesRegularExpression('/^JPEG image, \d+ bytes$/', $r['suggestions']['description']);
        $map = ['/m/error' => 'busy', '/m/malformed' => 'bad_response', '/m/toolarge' => 'too_large', '/m/unsupported' => 'unsupported', '/t/x' => 'unauthorized', '/m/empty' => 'empty'];
        foreach ($map as $p => $code) {
            settings_store(['autofill_url' => self::$stubBase . $p]);
            $this->assertSame($code, autofill_suggest($this->jpeg(20, 20))['code'], $p);
        }
        settings_store(['autofill_url' => 'http://127.0.0.1:1']);
        $this->assertSame('unreachable', autofill_suggest($this->jpeg(20, 20))['code']);
    }

    public function testSuggestRespectsTheAdvertisedLimit(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase]);
        autofill_info_cache(['name' => 'tiny', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => 10, 'ready' => true]);
        $this->assertSame('too_large', autofill_suggest($this->jpeg(100, 100))['code']);
        autofill_info_cache(['name' => 'big', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => null, 'ready' => true]);
    }

    public function testSuggestRouteNeedsCreate(): void
    {
        $this->assertSame('create', route_perm('POST', '/autofill/suggest'));
        $this->assertNotContains('create', ROLE_DEFAULTS['viewer'][1]);
    }

    public function testTestConnectionSendsTheStoredTokenOnlyToTheSavedAddress(): void
    {
        settings_store(['autofill_url' => 'http://10.0.0.2:8090', 'autofill_token' => 'stored']);
        $this->assertSame('stored', autofill_test_token('http://10.0.0.2:8090'));
        $this->assertSame('stored', autofill_test_token(' HTTP://10.0.0.2:8090/ '));
        foreach (['http://10.0.0.3:8090', 'http://10.0.0.2:8091', 'https://10.0.0.2:8090', 'http://10.0.0.2:8090/x', 'http://evil.example', ''] as $u) {
            $this->assertSame('', autofill_test_token($u), "no token for $u");
        }
        $this->assertSame('typed', autofill_test_token('http://evil.example', ' typed '));
        settings_store(['autofill_url' => '']);
        $this->assertSame('', autofill_test_token(''), 'nothing saved: never the stored token');
    }

    public function testImageLimitInMib(): void
    {
        $this->assertSame('10 MiB', autofill_mib(10485760));
        $this->assertSame('1.5 MiB', autofill_mib(1572864));
        $this->assertSame(60, settings_schema()['autofill_timeout'][5]['max']);
    }
}
