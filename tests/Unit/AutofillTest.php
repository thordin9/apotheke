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
        $this->assertSame(['autofill_enabled', 'autofill_auto', 'autofill_url', 'autofill_timeout', 'autofill_model', 'autofill_image_size'], settings_section_keys('autofill'));
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
        $this->assertSame(['name' => 'echo', 'version' => '1', 'capabilities' => ['name', 'tags', 'category', 'description'], 'backend' => 'echo', 'max_image_bytes' => 10485760, 'ready' => true, 'avg_latency_ms' => null, 'models' => null, 'image_sizes' => null], $ok);
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

    public function testTestingAnUnsavedAddressDoesNotTouchTheCache(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . '/l/8000']);
        @unlink(DATA_DIR . '/autofill-info.json');
        $this->assertTrue(autofill_test(self::$stubBase . '/l/8000/', 5, '')['ok'], 'saved address (normalised)');
        $this->assertSame(8000, autofill_expected_ms());
        foreach (['autofill_test', 'autofill_test_photo'] as $fn) {
            $this->assertTrue($fn(self::$stubBase . '/l/20000', 5, '')['ok'], "$fn: unsaved address B");
            $this->assertSame(8000, autofill_expected_ms(), "$fn: B's info is not cached under the saved address");
        }
        @unlink(DATA_DIR . '/autofill-info.json');
        autofill_test_photo(self::$stubBase . '/l/20000', 5, '');
        $this->assertNull(autofill_info_cache(), 'nothing cached for an unsaved address');
        autofill_test_photo(strtoupper('http://') . substr(self::$stubBase, 7) . '/l/8000', 5, '');
        $this->assertSame(8000, autofill_expected_ms(), 'the photo test of the saved address caches it');
    }

    public function testPrimeInfoAfterSaving(): void
    {
        @unlink(DATA_DIR . '/autofill-info.json');
        settings_store(['autofill_enabled' => false, 'autofill_url' => self::$stubBase . '/l/9000']);
        $this->assertFalse(autofill_prime_info(), 'off: no request');
        $this->assertNull(autofill_info_cache());
        settings_store(['autofill_enabled' => true]);
        $this->assertTrue(autofill_prime_info());
        $this->assertSame(9000, autofill_expected_ms(), 'the first photo gets an estimate without a Test click');
        // failures are silent and bounded: down, bad answer, wrong token, slow (min(timeout, 5 s))
        foreach (['http://127.0.0.1:1', self::$stubBase . '/m/badinfo', self::$stubBase . '/t/x'] as $u) {
            @unlink(DATA_DIR . '/autofill-info.json');
            settings_store(['autofill_url' => $u]);
            $this->assertFalse(autofill_prime_info(), $u);
            $this->assertNull(autofill_info_cache(), $u);
        }
        settings_store(['autofill_url' => self::$stubBase . '/m/slow', 'autofill_timeout' => 3]);
        $t = microtime(true);
        $this->assertFalse(autofill_prime_info());
        $this->assertLessThan(4.5, microtime(true) - $t, 'bounded by the timeout when it is under 5 s');
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
        $this->assertSame(['busy', 17, 'busy'], [$r['code'], $r['retry_after'] ?? null, $r['retry_reason'] ?? null]);
        $this->assertSame('No suggestions: the service is busy. Try again in 17 s.', $r['message'], 'a full queue is busy, not warming up');
        settings_store(['autofill_url' => self::$stubBase . '/m/warmup/r/15']);
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertSame([15, 'warming'], [$r['retry_after'] ?? null, $r['retry_reason'] ?? null]);
        $this->assertSame('No suggestions: the model is warming up. Try again in 15 s.', $r['message']);
        settings_store(['autofill_url' => self::$stubBase . '/m/error']);
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertSame('busy', $r['code']);
        $this->assertArrayNotHasKey('retry_after', $r, 'a 503 without Retry-After is not worth a retry');
        $this->assertStringContainsString('busy or not ready', $r['message'], 'backend_unavailable: busy or not ready');
        // backend_error: the service failed on this photo – a matching message, no retry (even with Retry-After)
        foreach (['/m/fault', '/m/fault/r/5'] as $p) {
            settings_store(['autofill_url' => self::$stubBase . $p]);
            $r = autofill_suggest($this->jpeg(40, 40));
            $this->assertSame('backend_error', $r['code'], $p);
            $this->assertSame('No suggestions: the service had a problem with this photo. Try again, or fill in the fields yourself.', $r['message'], $p);
            $this->assertArrayNotHasKey('retry_after', $r, $p);
        }
        $r = autofill_test_photo(self::$stubBase . '/m/fault', 5, '');
        $this->assertSame(['backend_error', true], [$r['code'], $r['checks']['ready']]);
        $this->assertStringStartsWith('The sample photo got no suggestions: the service had a problem', $r['message']);
        // cold: the first call is refused with Retry-After, the next one is answered
        settings_store(['autofill_url' => self::$stubBase . '/m/cold/r/2']);
        @unlink(sys_get_temp_dir() . '/af-stub-' . parse_url(self::$stubBase, PHP_URL_PORT) . '.cold');
        $this->assertSame(2, autofill_suggest($this->jpeg(40, 40))['retry_after'] ?? null);
        $this->assertTrue(autofill_suggest($this->jpeg(40, 40))['ok']);
    }

    public function testRetryReasonFromTheServiceMessage(): void
    {
        foreach (["model 'qwen2.5vl-jetson:3b' is loading", "model 'moondream' is warming up", 'Model loading', 'backend starting'] as $m) {
            $this->assertSame('warming', autofill_retry_reason($m), $m);
        }
        foreach (['backend busy, queue full', 'backend busy, a queued request would time out', '', 'try later', 'unloaded'] as $m) {
            $this->assertSame('busy', autofill_retry_reason($m), "busy by default: '$m'");
        }
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
        $html = autofill_test_result_html($r);
        foreach (['Not reachable', 'Token: not checked', 'Model: not checked'] as $t) $this->assertStringContainsString("<span>$t</span>", $html);
        $this->assertStringNotContainsString('>Reachable<', $html);
        $r = autofill_test_photo(self::$stubBase . '/t/right', 5, 'wrong-SECRET-tok');
        $this->assertSame(['unauthorized', ['reachable' => true, 'token' => false, 'ready' => null]], [$r['code'], $r['checks']]);
        $this->assertStringNotContainsString('wrong-SECRET-tok', json_encode($r) . autofill_test_result_html($r), 'never echoes the token');
        $html = autofill_test_result_html($r);
        foreach (['Reachable', 'Token not accepted', 'Model: not checked'] as $t) $this->assertStringContainsString("<span>$t</span>", $html, 'the state is in the text');
        $this->assertStringNotContainsString('<span>Token accepted</span>', $html, 'a refused token never reads as accepted');
        $this->assertTrue(autofill_test_photo(self::$stubBase . '/t/right', 5, 'right')['ok']);
        $r = autofill_test_photo(self::$stubBase . '/m/warming', 5, '');
        $this->assertSame(['warming_up', ['reachable' => true, 'token' => true, 'ready' => false]], [$r['code'], $r['checks']]);
        $this->assertStringContainsString('<span>Model not ready</span>', autofill_test_result_html($r));
        $r = autofill_test_photo(self::$stubBase . '/m/busy/r/20', 5, '');
        $this->assertSame(['busy', true, 20], [$r['code'], $r['checks']['ready'], $r['retry_after'] ?? null], 'a full queue: the model is ready');
        $r = autofill_test_photo(self::$stubBase . '/m/warmup/r/20', 5, '');
        $this->assertSame(['busy', false], [$r['code'], $r['checks']['ready']], 'warming up: the model is not ready');
        $this->assertStringContainsString('warming up', $r['message']);
        $this->assertStringStartsWith('The sample photo got no suggestions:', $r['message']);
        $r = autofill_test_photo(self::$stubBase . '/m/empty', 5, '');
        $this->assertSame('empty', $r['code']);
        $this->assertTrue($r['ok']);
        // the whole run stays within the timeout: a slow model is cut off at the configured 3 s
        $t = microtime(true);
        $r = autofill_test_photo(self::$stubBase . '/d/8000', 3, '');
        $this->assertSame('unavailable', $r['code']);
        $this->assertStringContainsString('unavailable', $r['message']);
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
        $map = ['/m/error' => 'busy', '/m/malformed' => 'unavailable', '/m/toolarge' => 'too_large', '/m/unsupported' => 'unsupported', '/t/x' => 'unauthorized', '/m/empty' => 'empty'];
        foreach ($map as $p => $code) {
            settings_store(['autofill_url' => self::$stubBase . $p]);
            $this->assertSame($code, autofill_suggest($this->jpeg(20, 20))['code'], $p);
        }
        settings_store(['autofill_url' => 'http://127.0.0.1:1']);
        $r = autofill_suggest($this->jpeg(20, 20));
        $this->assertSame('unavailable', $r['code']);
        $this->assertStringContainsString('unavailable', $r['message']);
        $this->assertArrayNotHasKey('retry_after', $r);
    }

    public function testSuggestRespectsTheAdvertisedLimit(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase]);
        autofill_info_cache(['name' => 'tiny', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => 10, 'ready' => true]);
        $r = autofill_suggest($this->jpeg(100, 100));
        $this->assertSame('too_large', $r['code']);
        $this->assertStringContainsString('10 bytes', $r['message'], 'the message states the advertised limit');
        autofill_info_cache(['name' => 'big', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => null, 'ready' => true]);
    }

    public function testTooLargeMessageStatesTheShippedServiceLimit(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . '/m/toolarge', 'autofill_timeout' => 5]);
        @unlink(DATA_DIR . '/autofill-info.json');
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertSame('too_large', $r['code']);
        $this->assertStringContainsString('0.9 MiB', $r['message'], 'MiB, the fallback until /v1/info is cached');
        $this->assertStringContainsString('983040', $r['message'], 'the same limit in bytes');
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

    /* ---------------- 2.4: on demand, model and image size ---------------- */

    public function testOnDemandDefaults(): void
    {
        $this->assertFalse(setting('autofill_auto'), 'auto-analyze is off on a new install');
        $this->assertSame('', setting('autofill_model'), 'no model chosen: the service default');
        $this->assertSame(0, setting('autofill_image_size'), 'no size chosen: the recommended one');
        $this->assertArrayHasKey('autofill_auto', settings_export()['settings'], 'the new settings are exported like the others');
        $this->assertArrayHasKey('autofill_model', settings_export()['settings']);
    }

    public function testModelAndSizeValidation(): void
    {
        $this->assertSame(0, setting_validate('autofill_image_size', ''));
        $this->assertSame(0, setting_validate('autofill_image_size', '0'));
        $this->assertSame(672, setting_validate('autofill_image_size', ' 672 '));
        foreach (['10', '63', '4097', 'abc', '-448', '1e3'] as $bad) {
            try { setting_validate('autofill_image_size', $bad); $this->fail("size $bad accepted"); } catch (UserError) { $this->addToAssertionCount(1); }
        }
        $this->assertSame('qwen2.5vl-jetson:3b', setting_validate('autofill_model', " qwen2.5vl-jetson:3b\n"));
        $this->assertSame('', setting_validate('autofill_model', ''));
        try { setting_validate('autofill_model', str_repeat('m', 121)); $this->fail('a 121-character model accepted'); } catch (UserError) { $this->addToAssertionCount(1); }
        $this->assertSame([], settings_save(['autofill_auto' => '1', 'autofill_model' => 'b', 'autofill_image_size' => '448']));
        $this->assertSame([true, 'b', 448], [setting('autofill_auto'), setting('autofill_model'), setting('autofill_image_size')]);
    }

    /** A 2.3 install (autofill on, analyzing every photo on upload) upgraded to 2.4: auto-analyze is off afterwards. */
    public function testMigration0025TurnsAutoAnalyzeOffOnExistingInstalls(): void
    {
        $run = require migrations_dir() . '/0025_autofill_on_demand.php';
        // as 2.3 left it: autofill switched on, none of the 2.4 rows
        $this->pdo->exec("DELETE FROM settings WHERE name IN ('autofill_auto', 'autofill_model', 'autofill_image_size')");
        settings_store(['autofill_enabled' => true, 'autofill_url' => 'http://10.0.0.9:8080', 'autofill_timeout' => 45]);
        $run($this->pdo, $this->driver);
        settings_cache(null, true);
        $rows = $this->pdo->query("SELECT name, value FROM settings WHERE name IN ('autofill_auto', 'autofill_model', 'autofill_image_size') ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame(['autofill_auto' => 'false', 'autofill_image_size' => '0', 'autofill_model' => '""'], $rows, 'rows written, auto-analyze off');
        $this->assertSame([true, 'http://10.0.0.9:8080', 45], [setting('autofill_enabled'), setting('autofill_url'), setting('autofill_timeout')], 'the rest is kept');
        // even a stray "on" (e.g. a pre-release) is turned off: off on every install
        settings_store(['autofill_auto' => true]);
        $run($this->pdo, $this->driver);
        settings_cache(null, true);
        $this->assertFalse(setting('autofill_auto'));
        $this->assertArrayHasKey('0025', migrations_applied($this->pdo, $this->driver), 'part of the normal migration run');
    }

    public function testParseInfoModelsAndImageSizes(): void
    {
        $base = '{"name":"x","version":"1.2","capabilities":[]';
        $i = autofill_parse_info($base . ',"models":[{"id":"qwen2.5vl-jetson:3b","default":true,"loaded":true,"avg_latency_ms":14000},{"id":"qwen3-vl:2b"},'
            . '{"id":"qwen2.5vl-jetson:3b"},{"id":""},{"name":"no id"},"plain",{"id":"c","default":true,"loaded":"yes","avg_latency_ms":-5}],'
            . '"image_sizes":[{"long_edge":1024},{"long_edge":448},{"long_edge":672,"recommended":true},{"long_edge":672},{"long_edge":32},{"long_edge":"448"},{"long_edge":9000},{"long_edge":1024,"recommended":true}]}');
        $this->assertSame([
            ['id' => 'qwen2.5vl-jetson:3b', 'default' => true, 'loaded' => true, 'avg_latency_ms' => 14000],
            ['id' => 'qwen3-vl:2b', 'default' => false, 'loaded' => null, 'avg_latency_ms' => null],
            ['id' => 'c', 'default' => false, 'loaded' => null, 'avg_latency_ms' => null],
        ], $i['models'], 'bad entries skipped, duplicates merged, one default');
        $this->assertSame([['long_edge' => 448, 'recommended' => false], ['long_edge' => 672, 'recommended' => true], ['long_edge' => 1024, 'recommended' => false]],
            $i['image_sizes'], 'sorted, deduplicated, in range, one recommended');
        foreach (['"models":[]', '"models":{"a":1}', '"models":"qwen"', '"models":[{"id":5}]', '"image_sizes":[]', '"image_sizes":[{"long_edge":1}]', '"image_sizes":"448"'] as $odd) {
            $j = autofill_parse_info($base . ',' . $odd . '}');
            $this->assertNotNull($j, "an odd field ($odd) never rejects the answer");
            $this->assertNull(str_contains($odd, 'models') ? $j['models'] : $j['image_sizes'], "… it is ignored ($odd)");
        }
        $this->assertNull(autofill_parse_info($base . '}')['models'], 'an older service: no models');
    }

    public function testChosenModelAndImageSide(): void
    {
        $v12 = ['models' => [['id' => 'a', 'default' => false, 'loaded' => null, 'avg_latency_ms' => null], ['id' => 'b', 'default' => true, 'loaded' => null, 'avg_latency_ms' => null]],
                'image_sizes' => [['long_edge' => 448, 'recommended' => false], ['long_edge' => 672, 'recommended' => true], ['long_edge' => 1024, 'recommended' => false]]];
        $old = ['name' => 'x', 'avg_latency_ms' => 5000];
        $this->assertSame('b', autofill_default_model($v12));
        $this->assertNull(autofill_default_model($old));
        $this->assertSame('a', autofill_chosen_model($v12, 'a'));
        $this->assertSame('zzz', autofill_chosen_model($v12, 'zzz'), 'an unlisted model is still asked for: the service falls back to its default');
        $this->assertNull(autofill_chosen_model($v12, ''), 'no choice: nothing sent, the service uses its default');
        $this->assertNull(autofill_chosen_model($old, 'a'), 'a service without models never gets a model');
        $this->assertNull(autofill_chosen_model(null, 'a'), 'nothing known about the service: nothing sent');
        $this->assertSame(672, autofill_image_side($v12, 0), 'no choice: the recommended size');
        $this->assertSame(448, autofill_image_side($v12, 448));
        $this->assertSame(1024, autofill_image_side($old, 448), 'a service without image_sizes: 1024 px as before, whatever is stored');
        $this->assertSame(1024, autofill_image_side(null, 448));
        $this->assertSame(1024, autofill_recommended_side([['long_edge' => 448, 'recommended' => false], ['long_edge' => 1024, 'recommended' => false], ['long_edge' => 1536, 'recommended' => false]]), 'none marked: the largest up to 1024');
        $this->assertSame(1536, autofill_recommended_side([['long_edge' => 1536, 'recommended' => false]]), '… else the smallest');
    }

    public function testExpectedTimeIsPerModel(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . '/per-model']);
        $info = ['name' => 'x', 'version' => '1.2', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => null, 'ready' => true, 'avg_latency_ms' => 9000,
                 'models' => [['id' => 'a', 'default' => true, 'loaded' => true, 'avg_latency_ms' => 14000], ['id' => 'b', 'default' => false, 'loaded' => false, 'avg_latency_ms' => 6000],
                              ['id' => 'c', 'default' => false, 'loaded' => false, 'avg_latency_ms' => null]], 'image_sizes' => null];
        autofill_info_cache($info);
        $this->assertSame(14000, autofill_expected_ms(), 'no choice: the default model\'s own average');
        settings_store(['autofill_model' => 'b']);
        $this->assertSame(6000, autofill_expected_ms(), 'the chosen model\'s average');
        settings_store(['autofill_model' => 'c']);
        $this->assertSame(9000, autofill_expected_ms(), 'a model without its own average: the service-wide one');
        settings_store(['autofill_model' => 'zzz']);
        $this->assertSame(9000, autofill_expected_ms(), 'an unknown model: the service-wide one');
    }

    /** @return list<array> what the stub received, oldest first (GET /_stub/uploads) */
    private function uploads(): array
    {
        return json_decode((string)file_get_contents(self::$stubBase . '/_stub/uploads'), true);
    }

    private function clearUploads(): void
    {
        file_get_contents(self::$stubBase . '/_stub/uploads', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    }

    public function testSuggestSendsTheChosenModelAndSize(): void
    {
        $url = self::$stubBase . '/n/a*=3000,b=8000/z/448,672*,1024';
        settings_store(['autofill_enabled' => true, 'autofill_url' => $url, 'autofill_timeout' => 5]);
        $this->assertTrue(autofill_test($url, 5)['ok'], 'Test connection caches the info');
        $this->clearUploads();
        $r = autofill_suggest($this->jpeg(2000, 1500));
        $this->assertTrue($r['ok'], $r['message']);
        $u = $this->uploads();
        $this->assertArrayNotHasKey('model', $u[0]['context'], 'no choice: no model sent');
        $this->assertSame([672, 504], [$u[0]['width'], $u[0]['height']], 'the recommended size');
        $this->assertSame('a', $r['model_used']);
        $this->assertSame(['type' => 'image/jpeg', 'bytes' => $u[0]['bytes'], 'width' => 672, 'height' => 504], $r['image'], 'what was sent (the debug hook)');
        $this->assertSame(3000, $r['avg_latency_ms']);
        settings_store(['autofill_model' => 'b', 'autofill_image_size' => 448]);
        $r = autofill_suggest($this->jpeg(2000, 1500));
        $u = $this->uploads();
        $this->assertSame('b', end($u)['context']['model'], 'the chosen model goes along as context.model');
        $this->assertSame([448, 336], [end($u)['width'], end($u)['height']]);
        $this->assertSame(['b', 8000], [$r['model_used'], $r['avg_latency_ms']], 'model_used and that model\'s average');
        // an unknown model falls back to the default on the service side
        settings_store(['autofill_model' => 'zzz']);
        $this->assertSame('a', autofill_suggest($this->jpeg(20, 20))['model_used']);
        // the info cache expires after an hour: a known service still gets its chosen model and size
        $f = DATA_DIR . '/autofill-info.json';
        $d = json_decode((string)file_get_contents($f), true);
        $d['at'] = time() - 7200;
        file_put_contents($f, json_encode($d));
        settings_store(['autofill_model' => 'b']);
        $this->clearUploads();
        autofill_suggest($this->jpeg(2000, 1500));
        $this->assertSame(['b', 448], [$this->uploads()[0]['context']['model'] ?? null, $this->uploads()[0]['width']]);
    }

    public function testAnOlderServiceGetsWhatItGotBefore(): void
    {
        // the plain stub is an API v1.0 service: no models, no image sizes in /v1/info, no model_used
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase, 'autofill_timeout' => 5, 'autofill_model' => 'b', 'autofill_image_size' => 448]);
        $this->assertTrue(autofill_test(self::$stubBase, 5)['ok']);
        $this->clearUploads();
        $r = autofill_suggest($this->jpeg(2000, 1500));
        $u = $this->uploads()[0];
        $this->assertArrayNotHasKey('model', $u['context'], 'no models listed: no model sent, even with one stored');
        $this->assertSame([1024, 768], [$u['width'], $u['height']], 'no sizes listed: 1024 px as before');
        $this->assertArrayNotHasKey('model_used', array_filter($r, fn($v) => $v !== null), 'nothing reported');
    }

    public function testAModelSwitchIsAColdStart(): void
    {
        $url = self::$stubBase . '/m/switch/r/1/n/a*,b';
        settings_store(['autofill_enabled' => true, 'autofill_url' => $url, 'autofill_timeout' => 5, 'autofill_model' => 'b']);
        $this->clearUploads();
        $this->assertTrue(autofill_test($url, 5)['ok']);
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertSame(['busy', 1, 'warming'], [$r['code'], $r['retry_after'] ?? null, $r['retry_reason'] ?? null], 'the switch answers 503 + Retry-After: the browser waits and retries once');
        $r = autofill_suggest($this->jpeg(40, 40));
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('b', $r['model_used'], 'the retry gets the switched model');
        $this->assertTrue(autofill_suggest($this->jpeg(40, 40))['ok'], 'loaded now: no more cold start');
        settings_store(['autofill_model' => '']);
        $this->assertSame('busy', autofill_suggest($this->jpeg(40, 40))['code'], 'back to the default: another switch');
        $this->clearUploads();
    }

    public function testPhotoTestRetriesAColdModelOnce(): void
    {
        $this->clearUploads();
        $t = microtime(true);
        $r = autofill_test_photo(self::$stubBase . '/m/switch/r/1/n/a*,b', 5, '', 'b');
        $this->assertLessThan(4.5, microtime(true) - $t, 'the one-second wait stays well inside the timeout');
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame('b', $r['model_used'] ?? null, 'the retry gets the model that was loading');
        $this->assertCount(2, $this->uploads(), 'exactly one retry');

        $this->clearUploads();
        $t = microtime(true);
        $r = autofill_test_photo(self::$stubBase . '/m/warmup/r/1', 5, '');
        $this->assertSame('busy', $r['code'], 'a second warming answer is not retried again');
        $this->assertFalse($r['checks']['ready']);
        $this->assertCount(2, $this->uploads(), 'still exactly one retry when the second try fails');
        $this->assertGreaterThan(0.8, microtime(true) - $t);

        $this->clearUploads();
        $t = microtime(true);
        $r = autofill_test_photo(self::$stubBase . '/m/busy/r/40', 5, '');
        $this->assertSame(['busy', true, 40], [$r['code'], $r['checks']['ready'], $r['retry_after'] ?? null]);
        $this->assertCount(1, $this->uploads(), 'Retry-After longer than the timeout: no retry');
        $this->assertLessThan(3.0, microtime(true) - $t, 'does not sleep out a wait past the timeout');
        $this->clearUploads();
    }

    private function exifJpeg(int $w, int $h, int $orientation): string
    {
        $jpeg = $this->jpeg($w, $h);
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        return "\xFF\xD8" . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    public function testPrepareImageAtTheChosenSize(): void
    {
        $i = getimagesizefromstring(autofill_prepare_image($this->jpeg(2000, 1000), 448)['data']);
        $this->assertSame([448, 224], [$i[0], $i[1]]);
        $out = autofill_prepare_image($this->jpeg(300, 200), 672);
        $this->assertSame([300, 200], [$out['width'], $out['height']], 'never enlarged');
        if (function_exists('exif_read_data')) {
            $out = autofill_prepare_image($this->exifJpeg(800, 400, 6), 448);
            $this->assertSame([224, 448], [$out['width'], $out['height']], 'EXIF orientation 6: a sideways phone photo is sent upright');
        }
    }

    public function testPrepareImageKeepsTransparency(): void
    {
        $im = imagecreatetruecolor(1600, 800);
        imagesavealpha($im, true);
        imagealphablending($im, false);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, 400, 200, 1200, 600, imagecolorallocatealpha($im, 200, 20, 20, 0));
        ob_start(); imagepng($im); $png = (string)ob_get_clean();
        $out = autofill_prepare_image($png, 448);
        $this->assertSame('image/png', $out['mime'], 'a transparent PNG stays a PNG');
        $back = imagecreatefromstring($out['data']);
        $this->assertSame([448, 224], [imagesx($back), imagesy($back)]);
        $this->assertSame(127, (imagecolorat($back, 2, 2) >> 24) & 0x7F, 'the transparent corner is still transparent');
        $this->assertSame(0, (imagecolorat($back, 224, 112) >> 24) & 0x7F, 'the opaque middle is opaque');
        $opaque = imagecreatetruecolor(100, 100);
        ob_start(); imagepng($opaque); $flat = (string)ob_get_clean();
        $this->assertSame('image/jpeg', autofill_prepare_image($flat)['mime'], 'a PNG without transparency is sent as JPEG');
    }

    public function testPhotoTestUsesTheChosenModelAndSize(): void
    {
        $url = self::$stubBase . '/n/a*,b/z/448*,672';
        $this->clearUploads();
        $r = autofill_test_photo($url, 5, '', 'b', 672);
        $this->assertTrue($r['ok'], $r['message']);
        $u = $this->uploads()[0];
        $this->assertSame('b', $u['context']['model']);
        $this->assertLessThanOrEqual(672, max($u['width'], $u['height']), 'at most the chosen size (never enlarged)');
        $this->assertSame(['b', 'b', 672], [$r['model_used'], $r['model_sent'], $r['image_side']]);
        $html = autofill_test_result_html($r);
        foreach (['id="af-s-model"', '>b<', 'id="af-s-size"', '672 px', 'id="af-models"', 'a (default), b', 'id="af-sizes"', '448 px (recommended)'] as $want) {
            $this->assertStringContainsString($want, $html);
        }
        $r = autofill_test_photo($url, 5, '', 'zzz', 0);
        $this->assertStringContainsString('(asked for zzz)', autofill_test_result_html($r), 'a fallback is visible');
        $r = autofill_test_photo(self::$stubBase, 5, '', 'b', 448);
        $this->assertNull($r['model_sent'], 'an older service: no model, no size row');
        $this->assertStringNotContainsString('af-s-model', autofill_test_result_html($r));
        $this->clearUploads();
    }

    public function testDropdownsOnlyWhenTheServiceOffersThem(): void
    {
        settings_store(['autofill_url' => 'http://10.0.0.5:8080', 'autofill_model' => 'b', 'autofill_image_size' => 448]);
        @unlink(DATA_DIR . '/autofill-info.json');
        autofill_form_info(null, true);
        foreach (['autofill_model' => 'value="b"', 'autofill_image_size' => 'value="448"'] as $k => $v) {
            $html = setting_field_html($k, setting($k), null);
            $this->assertStringNotContainsString('<select', $html, "$k hidden without the list");
            $this->assertStringContainsString('type="hidden"', $html, "… but the stored value is kept on save ($k)");
            $this->assertStringContainsString($v, $html);
        }
        autofill_info_cache(['name' => 'x', 'version' => '1.2', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => null, 'ready' => true, 'avg_latency_ms' => null,
            'models' => [['id' => 'a', 'default' => true, 'loaded' => true, 'avg_latency_ms' => 4000], ['id' => 'b', 'default' => false, 'loaded' => false, 'avg_latency_ms' => null]],
            'image_sizes' => [['long_edge' => 448, 'recommended' => false], ['long_edge' => 672, 'recommended' => true]]]);
        $m = setting_field_html('autofill_model', 'b', null);
        foreach (['<select name="autofill_model" id="s-autofill_model"', '<option value="">Service default (a)</option>', 'a · loaded · about 4.0 s', '<option value="b" selected>b</option>'] as $want) {
            $this->assertStringContainsString($want, $m);
        }
        $z = setting_field_html('autofill_image_size', 0, null);
        foreach (['<select name="autofill_image_size"', '<option value="0" selected>Recommended (672 px)</option>', '448 px', '672 px (recommended)'] as $want) {
            $this->assertStringContainsString($want, $z);
        }
        $this->assertStringContainsString('gone (not offered by the service: it uses its default)', setting_field_html('autofill_model', 'gone', null), 'a stored choice the service dropped stays visible');
        $this->assertStringNotContainsString('ALLOWED_MODELS', $m, 'two models: no hint');
        $this->assertStringNotContainsString('disabled', $m, 'two models: enabled');
        // one model (ALLOWED_MODELS unset on the service): shown, disabled, with a hint; a hidden input keeps the value
        autofill_info_cache(['name' => 'x', 'version' => '1.2', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => null, 'ready' => true, 'avg_latency_ms' => null,
            'models' => [['id' => 'a', 'default' => true, 'loaded' => true, 'avg_latency_ms' => null]], 'image_sizes' => []]);
        $one = setting_field_html('autofill_model', '', null);
        foreach (['<input type="hidden" name="autofill_model" value="">', '<select disabled data-af-single="1" id="s-autofill_model"', '<option value="a" selected>a · loaded</option>', 'More models appear when ALLOWED_MODELS is set on the service.'] as $want) {
            $this->assertStringContainsString($want, $one);
        }
        $this->assertSame(1, substr_count($one, '<option'), 'only the single entry');
        @unlink(DATA_DIR . '/autofill-info.json');
    }

    public function testImageLimitInMib(): void
    {
        $this->assertSame('10 MiB', autofill_mib(10485760));
        $this->assertSame('0.9 MiB', autofill_mib(983040));
        $this->assertSame('1.5 MiB', autofill_mib(1572864));
        $this->assertSame(60, settings_schema()['autofill_timeout'][5]['max']);
        $this->assertSame(983040, AUTOFILL_SERVICE_IMAGE_BYTES);
    }

    public function testAnalysisCopyIsAStrippedJpeg(): void
    {
        $plain = $this->scene(4000, 3000, false);
        $out = autofill_analysis_jpeg($plain);
        $this->assertNotNull($out);
        $info = getimagesizefromstring($out);
        $this->assertSame([1600, 1200, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);
        $this->assertLessThanOrEqual(983040, strlen($out));
        $this->assertSame($this->jpegDqt($this->referenceJpeg(80)), $this->jpegDqt($out), 'JPEG quality about 80');
        $this->assertStringNotContainsString('GPSLatitude', $out);
        $this->assertStringNotContainsString("\xFF\xE1", $out);

        $png = $this->png(4000, 3000);
        $asJpeg = autofill_analysis_jpeg($png);
        $this->assertNotNull($asJpeg);
        $pi = getimagesizefromstring($asJpeg);
        $this->assertSame([1600, 1200, IMAGETYPE_JPEG], [$pi[0], $pi[1], $pi[2]]);
        $part = autofill_multipart_body($asJpeg, '{}');
        $this->assertLessThanOrEqual(983040, strlen($part['body']));
        $this->assertGreaterThan(strlen($asJpeg), strlen($part['body']));

        $small = getimagesizefromstring(autofill_analysis_jpeg($this->jpeg(300, 200)));
        $this->assertSame([300, 200], [$small[0], $small[1]], 'never enlarged');

        if (function_exists('exif_read_data')) {
            $turned = autofill_analysis_jpeg($this->scene(4000, 3000, true));
            $ti = getimagesizefromstring($turned);
            $this->assertSame([1200, 1600], [$ti[0], $ti[1]], 'orientation 6 is applied before the resize');
            $im = imagecreatefromstring($turned);
            $top = imagecolorat($im, 600, 8);
            $this->assertGreaterThan(150, ($top >> 16) & 255, 'the red left edge becomes the top edge');
            $this->assertLessThan(80, $top & 255);
        }
    }

    public function testFallbackLimitIsUsedUntilInfoIsCached(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase, 'autofill_timeout' => 5]);
        @unlink(DATA_DIR . '/autofill-info.json');
        $this->assertSame(983040, autofill_service_image_limit());
        autofill_info_cache(['name' => 'echo', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => 10485760, 'ready' => true]);
        $this->assertSame(10485760, autofill_service_image_limit());
    }

    public function testResizeFailureSendsNothingAndLeavesTheStoredPhoto(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase, 'autofill_timeout' => 5]);
        $bytes = $this->jpeg(80, 60);
        $name = $this->storePhoto($bytes);
        $orig = image_original_path($name);
        $origHash = hash_file('sha256', $orig);
        $servedHash = hash_file('sha256', IMG_DIR . '/' . $name);
        $this->clearUploads();
        $r = autofill_suggest_path($orig, []);
        $this->assertSame('unsupported', $r['code']);
        $this->assertStringContainsString('cannot be read as a photo', $r['message']);
        $this->assertSame([], $this->uploads());
        $this->assertSame($origHash, hash_file('sha256', image_original_path($name)));
        $this->assertSame($servedHash, hash_file('sha256', IMG_DIR . '/' . $name));
        $this->assertSame([], glob(DATA_DIR . '/tmp/autofill-*.jpg') ?: []);
        $heic = "\x00\x00\x00\x18ftypheic" . str_repeat('x', 300);
        $this->clearUploads();
        $r = autofill_suggest($heic, ['gd']);
        $this->assertSame('unsupported', $r['code']);
        $this->assertSame([], $this->uploads());
        $this->assertSame([], glob(DATA_DIR . '/tmp/autofill-*.jpg') ?: []);
    }

    public function testOverLimitBodyIsNotSent(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase, 'autofill_timeout' => 5]);
        autofill_info_cache(['name' => 'tiny', 'version' => '1', 'capabilities' => [], 'backend' => null, 'max_image_bytes' => 200, 'ready' => true]);
        $this->clearUploads();
        $r = autofill_suggest($this->jpeg(400, 300));
        $this->assertSame('too_large', $r['code']);
        $this->assertSame([], $this->uploads(), 'no HTTP request once every quality is still over the limit');
        $this->assertSame([], glob(DATA_DIR . '/tmp/autofill-*.jpg') ?: []);
    }

    /**
     * A service that advertises max_image_bytes 524288 and no image_sizes. The analysis copy is still built with the
     * existing q80, then q70, then q60 step-down. The whole multipart body has to land on or under that limit.
     * A miss fails the test. Absence of Imagick is not a size skip: that engine is simply not in the list.
     */
    public function testNoisy1024AnalysisMultipartFitsHalfMebibyte(): void
    {
        $prevQuality = getenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
        $prevSide = getenv('AUTOFILL_UPLOAD_MAX_SIDE');
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
        putenv('AUTOFILL_UPLOAD_MAX_SIDE');
        $paths = [];
        try {
            $jpeg = $this->noisyJpeg1024();
            $this->assertSame($jpeg, $this->noisyJpeg1024(), 'seeded noise is deterministic');
            $dim = getimagesizefromstring($jpeg);
            $this->assertSame([1024, 1024, IMAGETYPE_JPEG], [$dim[0], $dim[1], $dim[2]]);
            $this->assertGreaterThan(524288, strlen($jpeg), 'the fixture itself is over the advertised limit');

            $parsed = autofill_parse_info('{"name":"limit","version":"1","capabilities":[],"max_image_bytes":524288,"ready":true}');
            $this->assertIsArray($parsed);
            $this->assertNull($parsed['image_sizes']);
            $this->assertSame(524288, $parsed['max_image_bytes']);
            autofill_info_cache($parsed);
            $this->assertSame(524288, autofill_service_image_limit());
            $known = autofill_known_info();
            $this->assertSame(1024, autofill_image_side($known));
            $this->assertSame([80, 70, 60], autofill_jpeg_qualities());
            $side = min(autofill_image_side($known), autofill_upload_max_side());
            $this->assertSame(1024, $side);
            $ctx = json_encode(autofill_context(autofill_chosen_model($known)), JSON_UNESCAPED_UNICODE);
            $this->assertIsString($ctx);

            $engines = [];
            if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) $engines[] = 'gd';
            if (class_exists('Imagick')) $engines[] = 'imagick';
            $this->assertNotSame([], $engines, 'GD or Imagick is required');

            $reports = [];
            foreach ($engines as $engine) {
                $sizes = [];
                foreach ([80, 70, 60] as $q) {
                    $out = autofill_analysis_jpeg($jpeg, $side, $q, [$engine]);
                    $this->assertNotNull($out, "$engine quality $q produced no JPEG");
                    $this->assertStringStartsWith("\xFF\xD8", $out);
                    $sizes[$q] = strlen(autofill_multipart_body($out, $ctx)['body']);
                }
                $reports[] = $engine . ' q80=' . $sizes[80] . ' q70=' . $sizes[70] . ' q60=' . $sizes[60];
                $this->assertLessThanOrEqual(524288, $sizes[60], $engine . ' analysis multipart is still over 524288 at q60 (' . implode('; ', $reports) . ')');
                $opened = autofill_open_analysis($jpeg, $side, $ctx, [$engine]);
                if (!empty($opened['path'])) $paths[] = $opened['path'];
                $this->assertTrue($opened['ok'], $engine . ' ' . ($opened['message'] ?? '') . ' (' . implode('; ', $reports) . ')');
                $this->assertNotSame('too_large', $opened['code'] ?? null, implode('; ', $reports));
                $this->assertLessThanOrEqual(524288, strlen($opened['body']), implode('; ', $reports));
                $fit = null;
                foreach ([80, 70, 60] as $q) {
                    if ($sizes[$q] <= 524288) { $fit = $q; break; }
                }
                $this->assertNotNull($fit);
                $this->assertSame($sizes[$fit], strlen($opened['body']), $engine . ' stopped at q' . $fit);
                $built = getimagesizefromstring($opened['jpeg']);
                $this->assertSame([1024, 1024, IMAGETYPE_JPEG], [$built[0], $built[1], $built[2]]);
            }
        } finally {
            foreach ($paths as $path) autofill_unlink_analysis($path);
            if ($prevQuality === false) putenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
            else putenv('AUTOFILL_UPLOAD_JPEG_QUALITY=' . $prevQuality);
            if ($prevSide === false) putenv('AUTOFILL_UPLOAD_MAX_SIDE');
            else putenv('AUTOFILL_UPLOAD_MAX_SIDE=' . $prevSide);
            @unlink(DATA_DIR . '/autofill-info.json');
        }
    }

    /**
     * Imagick must not keep a 4:4:4 JPEG's chroma. The analysis copy is 4:2:0 and the multipart body fits 524288.
     * Skip only when the Imagick extension is not loaded. A size miss or a 4:4:4 result fails.
     */
    public function testImagickEncodes444JpegAs420WithinAnalysisLimit(): void
    {
        if (!class_exists('Imagick')) $this->markTestSkipped('Imagick is not available');
        $prevQuality = getenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
        $prevSide = getenv('AUTOFILL_UPLOAD_MAX_SIDE');
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
        putenv('AUTOFILL_UPLOAD_MAX_SIDE');
        $paths = [];
        try {
            $source = $this->jpegForcedChroma($this->noisyJpeg1024(), '1x1,1x1,1x1', 95);
            $this->assertSame('4:4:4', $this->jpegChroma($source), 'the fixture must be a 4:4:4 JPEG');
            $dim = getimagesizefromstring($source);
            $this->assertSame([1024, 1024, IMAGETYPE_JPEG], [$dim[0], $dim[1], $dim[2]]);

            $parsed = autofill_parse_info('{"name":"limit","version":"1","capabilities":[],"max_image_bytes":524288,"ready":true}');
            $this->assertIsArray($parsed);
            autofill_info_cache($parsed);
            $known = autofill_known_info();
            $side = min(autofill_image_side($known), autofill_upload_max_side());
            $ctx = json_encode(autofill_context(autofill_chosen_model($known)), JSON_UNESCAPED_UNICODE);
            $this->assertIsString($ctx);

            foreach ([80, 70, 60] as $q) {
                $out = autofill_analysis_jpeg($source, $side, $q, ['imagick']);
                $this->assertNotNull($out, "imagick quality $q produced no JPEG");
                $this->assertSame('4:2:0', $this->jpegChroma($out), "imagick quality $q kept 4:4:4");
            }
            if (function_exists('imagejpeg')) {
                $gd = autofill_analysis_jpeg($source, $side, 80, ['gd']);
                $this->assertNotNull($gd);
                $plain = imagecreatetruecolor(8, 8);
                ob_start();
                imagejpeg($plain, null, 80);
                $native = (string)ob_get_clean();
                $this->assertSame($this->jpegChroma($native), $this->jpegChroma($gd), 'GD analysis chroma stays on the GD encoder');
            }
            $opened = autofill_open_analysis($source, $side, $ctx, ['imagick']);
            if (!empty($opened['path'])) $paths[] = $opened['path'];
            $this->assertTrue($opened['ok'] ?? false, $opened['message'] ?? 'analysis copy was not built');
            $this->assertNotSame('too_large', $opened['code'] ?? null);
            $this->assertLessThanOrEqual(524288, strlen($opened['body']));
            $this->assertSame('4:2:0', $this->jpegChroma($opened['jpeg']));
            $built = getimagesizefromstring($opened['jpeg']);
            $this->assertSame([1024, 1024, IMAGETYPE_JPEG], [$built[0], $built[1], $built[2]]);
        } finally {
            foreach ($paths as $path) autofill_unlink_analysis($path);
            if ($prevQuality === false) putenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
            else putenv('AUTOFILL_UPLOAD_JPEG_QUALITY=' . $prevQuality);
            if ($prevSide === false) putenv('AUTOFILL_UPLOAD_MAX_SIDE');
            else putenv('AUTOFILL_UPLOAD_MAX_SIDE=' . $prevSide);
            @unlink(DATA_DIR . '/autofill-info.json');
        }
    }

    public function testUnknownJsonObjectIsEmptyNotUnavailable(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . '/m/hello', 'autofill_timeout' => 5]);
        $r = autofill_suggest($this->jpeg(30, 20));
        $this->assertTrue($r['ok'], $r['message']);
        $this->assertSame('empty', $r['code']);
    }

    public function testRetryAfterOverAMinuteIsBusyAndDoesNotRetry(): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . '/m/busy/r/90', 'autofill_timeout' => 60]);
        $this->clearUploads();
        $t = microtime(true);
        $r = autofill_suggest($this->jpeg(40, 30));
        $this->assertLessThan(5, microtime(true) - $t);
        $this->assertSame('busy', $r['code']);
        $this->assertStringContainsString('busy', $r['message']);
        $this->assertArrayNotHasKey('retry_after', $r);
        $this->assertArrayNotHasKey('suggestions', $r);
        $this->assertSame(1, $this->suggestCount());
        $name = $this->storePhoto($this->jpeg(40, 30));
        $id = item_save(null, ['name' => 'Saved while busy', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $name, false);
        $this->assertSame('Saved while busy', item_get($id)['name']);
        $this->assertSame('', (string)item_get($id)['notes']);
    }

    public function testShortRetryResendsTheAnalysisCopyNotTheOriginal(): void
    {
        $this->clearUploads();
        $sample = hash_file('sha256', autofill_sample_path());
        $r = autofill_test_photo(self::$stubBase . '/m/cold/r/1', 10, '');
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $ups = $this->uploads();
        $this->assertCount(2, $ups);
        $this->assertSame($ups[0]['sha256'], $ups[1]['sha256']);
        $this->assertNotSame($sample, $ups[0]['sha256']);
        $this->assertSame('JPEG', $ups[0]['type']);
        $this->assertSame('JPEG', $ups[1]['type']);
    }

    public function testBadServiceAnswersSaveTheItemWithoutSuggestions(): void
    {
        $cases = [
            '/m/html' => 'html 502',
            '/m/htmlfail' => 'html 503',
            '/m/text' => 'plain text 200',
            '/m/truncated' => 'truncated json',
            '/m/jsonerr' => 'json 5xx',
        ];
        foreach ($cases as $path => $label) $this->assertUnavailableSuggest($path, $label, 5);
        $this->assertUnavailableSuggest('/d/8000', 'suggest timeout', 3);
    }

    private function assertUnavailableSuggest(string $path, string $label, int $timeout): void
    {
        settings_store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase . $path, 'autofill_timeout' => $timeout]);
        $name = $this->storePhoto($this->jpeg(48, 36));
        $this->clearUploads();
        $t = microtime(true);
        $r = autofill_suggest_path((string)image_original_path($name));
        $this->assertLessThan($timeout + 3, microtime(true) - $t, $label);
        $this->assertFalse($r['ok'], $label);
        $this->assertSame('unavailable', $r['code'], $label . ' ' . ($r['message'] ?? ''));
        $this->assertStringContainsString('unavailable', $r['message'], $label);
        $this->assertArrayNotHasKey('retry_after', $r, $label);
        $this->assertArrayNotHasKey('suggestions', $r, $label);
        $id = item_save(null, ['name' => 'Saved after ' . $label, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $name, false);
        $item = item_get($id);
        $this->assertSame('Saved after ' . $label, $item['name'], $label);
        $this->assertSame('', (string)$item['notes'], $label);
        $this->assertSame([], tags_for($id), $label);
        $this->assertSame(1, $this->suggestCount(), $label);
        $this->assertSame([], glob(DATA_DIR . '/tmp/autofill-*.jpg') ?: [], $label);
        $this->assertSame([], glob(DATA_DIR . '/tmp/autofill-src-*') ?: [], $label);
    }

    private function suggestCount(): int
    {
        $n = json_decode((string)file_get_contents(self::$stubBase . '/_stub/count'), true);
        return (int)($n['n'] ?? 0);
    }

    private function storePhoto(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'af');
        file_put_contents($tmp, $bytes);
        try {
            return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => 'p.jpg']);
        } finally {
            @unlink($tmp);
        }
    }

    /** 1024×1024 JPEG of seeded noise plus a fine XOR texture. High quality so the detail survives the first decode. */
    private function noisyJpeg1024(): string
    {
        $side = 1024;
        $im = imagecreatetruecolor($side, $side);
        $s = 20260320;
        for ($y = 0; $y < $side; $y++) {
            for ($x = 0; $x < $side; $x++) {
                $s = (1103515245 * $s + 12345) & 0x7fffffff;
                $n = $s & 255;
                $fine = (($x * 17) ^ ($y * 13)) & 63;
                $r = ($n + $fine) & 255;
                $g = (($n >> 1) ^ ($x & 255)) & 255;
                $b = (($s >> 8) + ($y & 15) * 8) & 255;
                imagesetpixel($im, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }
        ob_start();
        imagejpeg($im, null, 95);
        return (string)ob_get_clean();
    }

    /** Solid blocks so a 4000×3000 frame stays small. $turned adds EXIF orientation 6 and a red left edge. */
    private function scene(int $w, int $h, bool $turned): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 200));
        if ($turned) imagefilledrectangle($im, 0, 0, 200, $h - 1, imagecolorallocate($im, 220, 0, 0));
        ob_start();
        imagejpeg($im, null, 85);
        $jpeg = (string)ob_get_clean();
        if (!$turned) return $jpeg;
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', 6) . "\x00\x00" . "\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff . 'GPSLatitude';
        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 160, 40));
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    private function referenceJpeg(int $quality): string
    {
        $im = imagecreatetruecolor(8, 8);
        ob_start();
        imagejpeg($im, null, $quality);
        return (string)ob_get_clean();
    }

    /** Re-encode with Imagick at an explicit jpeg:sampling-factor (for example 1x1,1x1,1x1). */
    private function jpegForcedChroma(string $jpeg, string $factors, int $quality): string
    {
        $im = new Imagick();
        $im->readImageBlob($jpeg);
        $im->setImageFormat('jpeg');
        $im->setOption('jpeg:sampling-factor', $factors);
        if (method_exists($im, 'setImageProperty')) $im->setImageProperty('jpeg:sampling-factor', $factors);
        $im->setImageCompressionQuality($quality);
        $im->stripImage();
        $out = $im->getImageBlob();
        $im->clear();
        $this->assertIsString($out);
        $this->assertStringStartsWith("\xFF\xD8", $out);
        return $out;
    }

    /** 4:2:0 / 4:4:4 / 4:2:2 from the SOF component sampling factors, or the raw factor list. */
    private function jpegChroma(string $jpeg): string
    {
        return match ($this->jpegSamplingFactors($jpeg)) {
            '2x2,1x1,1x1' => '4:2:0',
            '1x1,1x1,1x1' => '4:4:4',
            '2x1,1x1,1x1' => '4:2:2',
            default => $this->jpegSamplingFactors($jpeg),
        };
    }

    private function jpegSamplingFactors(string $jpeg): string
    {
        $i = 2;
        $n = strlen($jpeg);
        while ($i + 3 < $n) {
            if ($jpeg[$i] !== "\xFF") { $i++; continue; }
            $marker = ord($jpeg[$i + 1]);
            while ($marker === 0xFF && $i + 2 < $n) { $i++; $marker = ord($jpeg[$i + 1]); }
            if ($marker === 0xD8 || $marker === 0xD9 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { $i += 2; continue; }
            if ($i + 4 > $n) break;
            $len = unpack('n', substr($jpeg, $i + 2, 2))[1];
            if ($len < 2) break;
            if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) {
                $comps = ord($jpeg[$i + 9]);
                $parts = [];
                $p = $i + 10;
                for ($c = 0; $c < $comps; $c++) {
                    if ($p + 1 >= $n) break;
                    $sf = ord($jpeg[$p + 1]);
                    $parts[] = ($sf >> 4) . 'x' . ($sf & 0x0F);
                    $p += 3;
                }
                return implode(',', $parts);
            }
            if ($marker === 0xDA) break;
            $i += 2 + $len;
        }
        return '';
    }

    private function jpegDqt(string $jpeg): string
    {
        $out = '';
        $i = 2;
        $n = strlen($jpeg);
        while ($i + 4 < $n && $jpeg[$i] === "\xFF") {
            $marker = ord($jpeg[$i + 1]);
            if ($marker === 0xD8 || $marker === 0xD9 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { $i += 2; continue; }
            $len = unpack('n', substr($jpeg, $i + 2, 2))[1];
            if ($marker === 0xDB) $out .= substr($jpeg, $i + 4, $len - 2);
            if ($marker === 0xDA) break;
            $i += 2 + $len;
        }
        return $out;
    }
}
