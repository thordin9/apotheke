<?php

declare(strict_types=1);

namespace Tests\Autofill;

use Apotheke\Autofill\AnalysisCopyBuilder;
use Apotheke\Autofill\AutofillClient;
use Apotheke\Autofill\AutofillService;
use Apotheke\Autofill\InfoCache;
use Apotheke\Autofill\LogRedactor;
use Apotheke\Autofill\ResponseClassifier;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Media\ImageStore;
use Apotheke\Media\Storage\ImagickDecoder;
use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Closure;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * CI4 autofill client. The legacy AutofillTest cases for the cap, the single
 * 413 retry, warn-once, quality steps and the byte-identical original are
 * the spec. The service under test is a PHP stub on 127.0.0.1, or a
 * scripted transport. No real host and no real token.
 */
final class AutofillTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const TOKEN = 'sekret-token-xyz';

    /** @var resource|null */
    private static $stub = null;

    private static string $stubBase = '';

    private ?TestDatabase $database = null;

    private ?LocalStorage $storage = null;

    private ?SettingsService $settings = null;

    private ?ImageStore $images = null;

    /** @var list<string> */
    private array $logged = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($sock);
        $port = (int)substr((string)strrchr((string)stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__, 3) . '/tests/stubs/autofill-stub.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
            $pipes,
            null,
            array_merge(getenv() ?: [], ['PHP_CLI_SERVER_WORKERS' => '2', 'STUB_SLOW_S' => '5']),
        );
        self::assertNotFalse($proc);
        self::$stub = $proc;
        self::$stubBase = 'http://127.0.0.1:' . $port;
        $up = false;
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.1);
            if ($c !== false) {
                fclose($c);
                $up = true;
                break;
            }
            usleep(50000);
        }
        self::assertTrue($up, 'autofill stub did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$stub)) {
            proc_terminate(self::$stub);
            proc_close(self::$stub);
        }
        parent::tearDownAfterClass();
    }

    /**
     * A stored session holding $csrf, named by the request's apothekesid cookie,
     * as a browser that loaded the form sends it. SessionCookie loads it.
     */
    private function liveSession(string $csrf): void
    {
        $save = (string)getenv('DATA_DIR') . '/sessions';
        if (!is_dir($save)) {
            mkdir($save, 0775, true);
        }
        $id = bin2hex(random_bytes(16));
        file_put_contents($save . '/sess_' . $id, 'csrf|s:' . strlen($csrf) . ':"' . $csrf . '";last_activity|i:' . time() . ';');
        $_COOKIE['apothekesid'] = $id;
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unset($_COOKIE['apothekesid']);
        $this->database?->drop();
        $this->database = null;
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
        putenv('AUTOFILL_UPLOAD_MAX_SIDE');
        putenv('AUTOFILL_MAX_IMAGE_BYTES');
        Services::reset(true);
        parent::tearDown();
    }

    public function testMibAndKbDisplay(): void
    {
        $limit = $this->stack()->limits();
        $this->assertSame('10 MiB', $limit->mib(10485760));
        $this->assertSame('0.9 MiB', $limit->mib(983040));
        $this->assertSame('1.5 MiB', $limit->mib(1572864));
        $this->assertSame('1 KB', $limit->mib(1000));
        $this->assertSame('0.01 KB', $limit->mib(10));
        $this->assertStringContainsString('max 1 KB / 1000 bytes', $limit->tooLargeMessage(1000));
        $this->assertStringNotContainsString('0 MiB', $limit->tooLargeMessage(1000));
    }

    public function testJpegQualityStepsDownByTenAndIncludesSixty(): void
    {
        $analysis = $this->stack()->analysis();
        $this->assertSame([80, 70, 60], $analysis->qualities());
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY=95');
        $this->assertSame([95, 85, 75, 65, 60], $analysis->qualities());
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY=90');
        $this->assertSame([90, 80, 70, 60], $analysis->qualities());
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY=65');
        $this->assertSame([65, 60], $analysis->qualities());
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY=40');
        $this->assertSame([40], $analysis->qualities());
    }

    public function testExplicitByteCapStaysInsideTheSettingsBounds(): void
    {
        $settings = $this->stack()->limits();
        unset($settings);
        $err = $this->settings->save(['autofill_max_image_bytes' => '65535']);
        $this->assertArrayHasKey('autofill_max_image_bytes', $err);
        $this->assertStringContainsString('65536', $err['autofill_max_image_bytes']);
        $this->assertStringContainsString('10485760', $err['autofill_max_image_bytes']);
        $this->assertSame([], $this->settings->save(['autofill_max_image_bytes' => '65536']));
        $this->assertSame(65536, $this->settings->get('autofill_max_image_bytes'));
        $this->assertSame([], $this->settings->save(['autofill_max_image_bytes' => '10485760']));
        $this->assertNotSame([], $this->settings->save(['autofill_max_image_bytes' => '10485761']));
        $this->assertSame('auto', $this->settings->save(['autofill_max_image_bytes' => 'auto']) ? 'no' : $this->settings->get('autofill_max_image_bytes'));
    }

    public function testEnforcedCapBeatsTheConfiguredMaximumAndAuto(): void
    {
        $af = $this->stack();
        $this->settings->store(['autofill_url' => 'http://autofill.test', 'autofill_max_image_bytes' => 2097152, 'autofill_token' => self::TOKEN]);
        $af->rememberInfo($this->info(10485760, 2031616));
        $this->assertSame(2031616, $af->limits()->target(true));
        $this->assertSame(2031616, $af->limits()->target(false));
        $text = implode("\n", $this->logged);
        $this->assertStringContainsString('2097152', $text);
        $this->assertStringContainsString('2031616', $text);
        $this->assertStringContainsString('enforced_image_bytes', $text);
        $this->assertStringNotContainsString(self::TOKEN, $text);
        $af->rememberInfo($this->info(10485760, 4000000));
        $this->assertSame(2097152, $af->limits()->target(false), 'a higher enforced cap does not raise the target');
        $this->settings->store(['autofill_max_image_bytes' => 'auto']);
        $af->rememberInfo($this->info(10485760, 2031616));
        $this->assertSame(2031616, $af->limits()->target(false), 'auto is clamped to enforced_image_bytes too');
    }

    public function testExplicitCapClampsToAdvertisedMaxWhenEnforcedIsAbsent(): void
    {
        $af = $this->stack();
        $this->settings->store(['autofill_url' => 'http://autofill.test', 'autofill_max_image_bytes' => 2097152, 'autofill_token' => self::TOKEN]);
        $af->rememberInfo($this->info(1048576, null));
        $this->assertSame(1048576, $af->limits()->target(true));
        $text = implode("\n", $this->logged);
        $this->assertStringContainsString('2097152', $text);
        $this->assertStringContainsString('1048576', $text);
        $this->assertStringContainsString('max_image_bytes', $text);
        $this->assertStringNotContainsString(self::TOKEN, $text);
        $this->assertStringNotContainsString('Bearer', $text);
    }

    public function testExplicitCapIsUsedAsStoredWhenTheServiceHasNotSaid(): void
    {
        $af = $this->stack();
        $this->settings->store(['autofill_url' => 'http://autofill.test', 'autofill_max_image_bytes' => 524288]);
        $this->assertSame(524288, $af->limits()->target(true));
        $this->assertSame([], $this->logged);
    }

    public function testAutoUsesCachedMaxOrTheFallbackAndIgnoresTheEnvironment(): void
    {
        $af = $this->stack();
        $this->settings->store(['autofill_url' => 'http://autofill.test', 'autofill_max_image_bytes' => 'auto']);
        putenv('AUTOFILL_MAX_IMAGE_BYTES=65536');
        $this->assertSame(983040, $af->limits()->target(false));
        $af->rememberInfo($this->info(10485760, null));
        $this->assertSame(10485760, $af->limits()->target(false));
        $af->rememberInfo($this->info(null, null));
        $this->assertSame(983040, $af->limits()->target(false));
        $odd = (new ResponseClassifier(static fn (): int => time()))->parseInfo('{"name":"x","version":"1","capabilities":[],"enforced_image_bytes":"2031616"}');
        $this->assertIsArray($odd);
        $this->assertNull($odd['enforced_image_bytes']);
    }

    public function testWarnOnceIsStoredInTheFileAndNotOnTheInstance(): void
    {
        $af = $this->stack();
        $this->settings->store(['autofill_url' => 'http://autofill.test', 'autofill_max_image_bytes' => 2097152, 'autofill_token' => self::TOKEN]);
        $af->rememberInfo($this->info(10485760, 2031616));
        $this->assertSame(2031616, $af->limits()->target(true));
        $this->assertSame(2031616, $af->limits()->target(true));
        $this->assertSame(1, $this->clampLines(), implode("\n", $this->logged));
        $again = $this->anotherService();
        $this->assertSame(2031616, $again->limits()->target(true));
        $this->assertSame(1, $this->clampLines(), 'a new instance must not log a pair the file already has');
        $raw = (string)$this->storage->read(InfoCache::KEY);
        $this->assertStringContainsString('"from":2097152', $raw);
        $this->assertStringContainsString('"to":2031616', $raw);
        $this->storage->delete(InfoCache::KEY);
        $fresh = $this->anotherService();
        $fresh->rememberInfo($this->info(10485760, 2031616));
        $this->assertSame(2031616, $fresh->limits()->target(true));
        $this->assertSame(2, $this->clampLines(), 'deleting the file makes the next pair warn again');
        $this->settings->store(['autofill_max_image_bytes' => 4000000]);
        $this->assertSame(2031616, $fresh->limits()->target(true));
        $this->assertSame(3, $this->clampLines());
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $this->logged));
    }

    public function testTokenInAResponseBodyIsRedacted(): void
    {
        $calls = [];
        $af = $this->stack($this->script($calls, [
            ['status' => 200, 'body' => 'plain ' . self::TOKEN . ' Bearer ' . self::TOKEN, 'error' => null, 'retry_after' => null, 'content_type' => 'text/plain'],
        ]));
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => 'http://autofill.test',
            'autofill_timeout' => 5,
            'autofill_token' => self::TOKEN,
            'autofill_max_image_bytes' => 1048576,
        ]);
        $r = $af->suggest($this->jpeg(40, 30), ['gd']);
        $this->assertSame('unavailable', $r['code']);
        $text = implode("\n", $this->logged);
        $this->assertStringNotContainsString(self::TOKEN, $text);
        $this->assertStringNotContainsString('Bearer ' . self::TOKEN, $text);
        $this->assertStringContainsString('[redacted]', $text);
        $this->assertLessThanOrEqual(80, strlen((string)preg_replace('/^apotheke autofill: suggest: unexpected body \(/', '', $this->logged[0] ?? '')));
    }

    public function testExactFitJpegIsPostedOnce(): void
    {
        $posted = [];
        $af = $this->ready($posted, function (int $n, string $jpeg): array {
            return $this->okSuggest();
        });
        $src = $this->noisy(480);
        $q80 = $af->analysis()->jpeg($src, 1024, 80, ['gd']);
        $this->assertIsString($q80);
        $this->settings->store(['autofill_max_image_bytes' => 'auto']);
        $af->rememberInfo($this->info(strlen($q80), null));
        $r = $af->suggest($src, ['gd']);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertCount(1, $posted);
        $this->assertSame($q80, $posted[0], 'the first POST is the JPEG that fills the cap');
        $this->assertSame(strlen($q80), $r['image']['bytes']);
        $this->assertSame([], glob($this->database->dataDir() . '/autofill/tmp/*') ?: []);
    }

    public function testHttp413RefetchesOnceAndRetriesAnExactFitJpeg(): void
    {
        $posts = [];
        $gets = 0;
        $q80 = '';
        $q70 = '';
        $af = $this->stack(function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$posts, &$gets, &$q80, &$q70): array {
            $this->assertSame(self::TOKEN, $token);
            if ($method === 'GET' && str_ends_with($url, '/v1/info')) {
                $gets++;

                return $this->http(200, json_encode($this->info(strlen($q80), strlen($q70)), JSON_THROW_ON_ERROR));
            }
            if ($method === 'POST') {
                $posts[] = $this->jpegIn((string)$body);

                return count($posts) === 1
                    ? $this->http(413, '{"error":{"code":"payload_too_large","message":"image too large ' . self::TOKEN . '"}}')
                    : $this->okSuggest();
            }
            $this->fail('unexpected ' . $method . ' ' . $url);
        });
        $src = $this->noisy(480);
        $built80 = $af->analysis()->jpeg($src, 1024, 80, ['gd']);
        $built70 = $af->analysis()->jpeg($src, 1024, 70, ['gd']);
        $this->assertIsString($built80);
        $this->assertIsString($built70);
        $this->assertLessThan(strlen($built80), strlen($built70), 'q70 must be smaller so the retry is a different JPEG');
        $q80 = $built80;
        $q70 = $built70;
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => 'http://autofill.test',
            'autofill_timeout' => 5,
            'autofill_token' => self::TOKEN,
            'autofill_max_image_bytes' => 'auto',
        ]);
        $af->rememberInfo($this->info(strlen($q80), null));
        $r = $af->suggest($src, ['gd']);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertArrayNotHasKey('http_413', $r);
        $this->assertSame(1, $gets, 'one refetch, no loop');
        $this->assertCount(2, $posts, 'one retry, then stop');
        $this->assertSame($q80, $posts[0], 'the first POST is the JPEG that filled the old cap');
        $this->assertSame($q70, $posts[1], 'the retry POST is the JPEG that fills the new cap');
        $this->assertLessThanOrEqual(strlen($q70), strlen($posts[1]));
        $this->assertSame(strlen($q70), $af->limits()->target(false));
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $this->logged));
        $this->assertStringNotContainsString('Bearer', implode("\n", $this->logged));
        $this->assertSame([], glob($this->database->dataDir() . '/autofill/tmp/*') ?: []);
    }

    public function testASecond413IsNotRetried(): void
    {
        $posts = 0;
        $gets = 0;
        $af = $this->stack(function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$posts, &$gets): array {
            if ($method === 'GET') {
                $gets++;

                return $this->http(200, json_encode($this->info(100000, 50000), JSON_THROW_ON_ERROR));
            }
            $posts++;
            if ($posts > 2) {
                return $this->http(500, 'retried');
            }

            return $this->http(413, '{"error":{"code":"payload_too_large","message":"image too large"}}');
        });
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => 'http://autofill.test',
            'autofill_timeout' => 5,
            'autofill_max_image_bytes' => 1048576,
        ]);
        $r = $af->suggest($this->jpeg(80, 60), ['gd']);
        $this->assertSame('too_large', $r['code']);
        $this->assertArrayNotHasKey('http_413', $r);
        $this->assertSame(2, $posts);
        $this->assertSame(1, $gets);
    }

    public function testSixtyIsTriedBeforeTheLongEdgeShrinks(): void
    {
        putenv('AUTOFILL_UPLOAD_JPEG_QUALITY=95');
        $af = $this->stack();
        $src = $this->noisy(1024);
        $at60 = $af->analysis()->jpeg($src, 1024, 60, ['gd']);
        $at65 = $af->analysis()->jpeg($src, 1024, 65, ['gd']);
        $this->assertIsString($at60);
        $this->assertIsString($at65);
        $this->assertGreaterThan(strlen($at60), strlen($at65));
        $this->assertGreaterThanOrEqual(65536, strlen($at60));
        $this->settings->store(['autofill_url' => 'http://autofill.test', 'autofill_max_image_bytes' => strlen($at60)]);
        $opened = $af->analysis()->open($src, 1024, '{}', ['gd']);
        $this->assertTrue($opened['ok'], $opened['message'] ?? '');
        $dim = getimagesizefromstring($opened['jpeg']);
        $this->assertSame([1024, 1024], [$dim[0], $dim[1]], '60 fits, so the long edge must not shrink');
        $this->assertSame($at60, $opened['jpeg']);
        $this->assertLessThanOrEqual(strlen($at60), strlen($opened['jpeg']));
        $this->assertGreaterThan(strlen($at60), strlen($opened['body']), 'the multipart wrapper is over the cap and still accepted');
        $af->releaseAnalysis($opened['path']);
    }

    public function testCapCountsTheJpegNotTheMultipartWrapper(): void
    {
        $posts = 0;
        $af = $this->stack(function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$posts): array {
            $posts++;

            return $this->okSuggest();
        });
        $src = $this->noisy(480);
        $jpeg = $af->analysis()->jpeg($src, 1024, 80, ['gd']);
        $this->assertIsString($jpeg);
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => 'http://autofill.test',
            'autofill_timeout' => 5,
            'autofill_max_image_bytes' => 'auto',
        ]);
        $af->rememberInfo($this->info(strlen($jpeg), null));
        $opened = $af->analysis()->open($src, 1024, '{}', ['gd']);
        $this->assertTrue($opened['ok'], $opened['message'] ?? '');
        $this->assertSame(strlen($jpeg), strlen($opened['jpeg']));
        $this->assertGreaterThan(strlen($jpeg), strlen($opened['body']));
        $sent = $af->suggestAt('http://autofill.test', '', 5, $opened['body'], $opened['content_type'], strlen($opened['jpeg']));
        $this->assertNotSame('too_large', $sent['code']);
        $this->assertSame(1, $posts);
        $af->releaseAnalysis($opened['path']);
    }

    public function testOffSuggestDoesNotTouchTheNetwork(): void
    {
        $af = $this->stack(function (): array {
            $this->fail('a request was made while autofill is off');
        });
        $r = $af->suggest($this->jpeg(20, 20), ['gd']);
        $this->assertSame(['ok' => false, 'code' => 'off', 'message' => 'AI autofill is off.'], $r);
    }

    public function testStoredTokenIsSentOnlyToTheSavedAddress(): void
    {
        $af = $this->stack();
        $this->settings->store(['autofill_url' => 'http://10.0.0.2:8090', 'autofill_token' => 'stored']);
        $tokens = $af->tokens();
        $this->assertSame('stored', $tokens->forTest('http://10.0.0.2:8090'));
        $this->assertSame('stored', $tokens->forTest(' HTTP://10.0.0.2:8090/ '));
        foreach (['http://10.0.0.3:8090', 'http://10.0.0.2:8091', 'https://10.0.0.2:8090', 'http://10.0.0.2:8090/x', 'http://evil.example', ''] as $url) {
            $this->assertSame('', $tokens->forTest($url), $url);
        }
        $this->assertSame('typed', $tokens->forTest('http://evil.example', ' typed '));
    }

    public function testSuggestAgainstTheStubSendsOneJpeg(): void
    {
        $this->clearStub();
        $af = $this->stack();
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => self::$stubBase,
            'autofill_timeout' => 5,
            'autofill_max_image_bytes' => 'auto',
        ]);
        $src = $this->jpeg(120, 80);
        $r = $af->suggest($src, ['gd']);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertSame('ok', $r['code']);
        $this->assertSame(1, $this->suggestCount());
        $ups = $this->uploads();
        $this->assertCount(1, $ups);
        $this->assertSame('JPEG', $ups[0]['type']);
        $this->assertNotSame(hash('sha256', $src), $ups[0]['sha256']);
        $this->assertLessThanOrEqual(983040, $ups[0]['bytes']);
        $this->assertSame([], glob($this->database->dataDir() . '/autofill/tmp/*') ?: []);
    }

    public function testParseInfoModelsAndAHostileName(): void
    {
        $p = new ResponseClassifier(static fn (): int => 1_700_000_000);
        $ok = $p->parseInfo('{"name":"echo","version":"1","capabilities":["name","tags","category","description","colour"],"backend":"echo","max_image_bytes":10485760,"extra":1}');
        $this->assertSame([
            'name' => 'echo', 'version' => '1', 'capabilities' => ['name', 'tags', 'category', 'description'],
            'backend' => 'echo', 'max_image_bytes' => 10485760, 'enforced_image_bytes' => null, 'ready' => true,
            'avg_latency_ms' => null, 'models' => null, 'image_sizes' => null,
        ], $ok);
        $this->assertNull($p->parseInfo('{"name":"x","version":"1","capabilities":[1]}'));
        $this->assertSame(3_600_000, $p->parseInfo('{"name":"x","version":"1","capabilities":[],"avg_latency_ms":99999999}')['avg_latency_ms']);
        $hostile = $p->parseInfo('{"name":"  <b>n</b>  ","version":"1","capabilities":[]}');
        $this->assertIsArray($hostile);
        $this->assertSame('<b>n</b>', $hostile['name']);
        $this->assertSame(1, $p->retryAfter('1'));
        $this->assertNull($p->retryAfter(''));
        $this->assertSame('busy', $p->retryReason('backend busy, queue full'));
        $this->assertSame('warming', $p->retryReason("model 'x' is warming up"));
    }

    /** @return iterable<string,array{string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    /** @param string $driver */
    #[\PHPUnit\Framework\Attributes\DataProvider('drivers')]
    public function testOriginalStaysByteIdenticalAndOnlyTheCopyIsSent(string $driver): void
    {
        $this->clearStub();
        $af = $this->stack(null, $driver);
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => self::$stubBase,
            'autofill_timeout' => 5,
            'autofill_max_image_bytes' => 524288,
            'autofill_token' => self::TOKEN,
        ]);
        $db = $this->database->db;
        $db->table('categories')->insert(['name' => 'Tools', 'name_key' => 'tools', 'is_default' => 0, 'created_at' => '2020-01-01 00:00:00']);
        $db->table('items')->insert([
            'name' => 'Hammer', 'quantity' => 1, 'notes' => '', 'search' => 'hammer',
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]);
        $itemId = (int)$db->insertID();
        $db->table('tags')->insert(['name' => 'Handy', 'name_key' => 'handy', 'sort_key' => 'handy', 'created_at' => '2020-01-01 00:00:00']);
        $db->table('item_tags')->insert(['item_id' => $itemId, 'tag_id' => (int)$db->insertID()]);
        $name = str_repeat('a', 32) . '.jpg';
        $served = $this->jpeg(30, 20);
        $original = $this->noisy(640);
        $this->assertNotSame($served, $original);
        $images = $this->database->dataDir() . '/images';
        $originals = $this->database->config->originalsDir();
        mkdir($images, 0775, true);
        mkdir($originals . '/map', 0775, true);
        file_put_contents($images . '/' . $name, $served);
        $base = str_repeat('b', 64) . '.jpg';
        file_put_contents($originals . '/' . $base, $original);
        file_put_contents($originals . '/map/' . substr($name, 0, 32), $base);
        $db->table('item_photos')->insert([
            'item_id' => $itemId, 'file' => $name, 'position' => 0, 'created_at' => '2020-01-01 00:00:00',
        ]);
        $photoId = (int)$db->insertID();
        Services::injectMock('settings', $this->settings);
        Services::injectMock('autofill', $af);
        // The token CsrfFilter checks comes from a live session that SessionCookie loads.
        $this->liveSession('csrf-test-token');
        $response = $this->withSession(['csrf' => 'csrf-test-token'])->post('autofill/suggest', [
            'photo_id' => (string)$photoId,
            'csrf' => 'csrf-test-token',
        ]);
        $response->assertStatus(200);
        $body = json_decode($response->response()->getBody(), true);
        $this->assertTrue($body['ok'], $body['message'] ?? $response->response()->getBody());
        $this->assertSame($original, (string)file_get_contents($originals . '/' . $base));
        $this->assertSame($served, (string)file_get_contents($images . '/' . $name));
        $this->assertSame(hash('sha256', $original), hash_file('sha256', $originals . '/' . $base));
        $ups = $this->uploads();
        $this->assertCount(1, $ups);
        $this->assertSame('JPEG', $ups[0]['type']);
        $this->assertNotSame(hash('sha256', $original), $ups[0]['sha256']);
        $this->assertNotSame(hash('sha256', $served), $ups[0]['sha256']);
        $this->assertLessThanOrEqual(524288, $ups[0]['bytes']);
        $this->assertContains('Tools', $ups[0]['context']['categories']);
        $this->assertContains('Handy', $ups[0]['context']['tags']);
        $this->assertSame('Bearer ' . self::TOKEN, $ups[0]['auth']);
        $this->assertSame([], glob($this->database->dataDir() . '/autofill/tmp/*') ?: []);
        $this->assertStringNotContainsString(self::TOKEN, $response->response()->getBody());
        $writable = dirname(__DIR__, 2) . '/writable';
        $this->assertSame([], glob($writable . '/autofill*') ?: []);
        $this->assertSame([], glob($writable . '/*/autofill*') ?: []);
    }

    /**
     * PLAN step 12: a stored photo is read through Media (ImageStore), as
     * legacy `image_original_path() ?? images/`: the kept original, else the
     * served JPEG, else no_image without a request. Scripted transport only.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('drivers')]
    public function testStoredPhotoIsReadThroughMedia(string $driver): void
    {
        $posted = [];
        $af = $this->stack(function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$posted): array {
            $this->assertSame('POST', $method);
            $this->assertSame('http://autofill.test/v1/suggest', $url);
            $posted[] = $this->jpegIn((string)$body);

            return $this->okSuggest();
        }, $driver);
        $this->settings->store(['autofill_enabled' => true, 'autofill_url' => 'http://autofill.test', 'autofill_timeout' => 5, 'autofill_max_image_bytes' => 1048576]);
        $af->rememberInfo($this->info(null, null));
        $db = $this->database->db;
        $this->assertTrue($db->table('items')->insert([
            'name' => 'Hammer', 'quantity' => 1, 'notes' => '', 'search' => 'hammer',
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]));
        $itemId = (int)$db->insertID();
        $original = $this->noisy(640);
        $tmp = (string)tempnam(sys_get_temp_dir(), 'af-up');
        file_put_contents($tmp, $original);
        $name = $this->images->store($tmp, strlen($original));
        unlink($tmp);
        $this->assertTrue($db->table('item_photos')->insert(['item_id' => $itemId, 'file' => $name, 'position' => 0, 'created_at' => '2020-01-01 00:00:00']));
        $photoId = (string)$db->insertID();
        $served = (string)$this->storage->read('images/' . $name);
        $this->assertNotSame($original, $served);
        $originals = new LocalStorage($this->database->config->originalsDir());
        $base = trim((string)$originals->read('map/' . substr($name, 0, 32)));

        $r = $af->suggestPhotoId($photoId);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertCount(1, $posted);
        $this->assertSame($af->analysis()->jpeg($original, AutofillService::IMAGE_SIDE, 80), $posted[0], 'the copy is made from the kept original');
        $this->assertSame($original, $originals->read($base), 'the original is not rewritten');
        $this->assertSame($served, $this->storage->read('images/' . $name), 'the served copy is not rewritten');

        $originals->delete('map/' . substr($name, 0, 32));
        $r = $af->suggestPhotoId($photoId);
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertCount(2, $posted);
        $this->assertSame($af->analysis()->jpeg($served, AutofillService::IMAGE_SIDE, 80), $posted[1], 'without an original, the served JPEG');

        $this->storage->delete('images/' . $name);
        foreach ([$photoId, '999999', 'abc', ''] as $id) {
            $r = $af->suggestPhotoId($id);
            $this->assertSame('no_image', $r['code'], $id);
            $this->assertSame('No suggestions: that photo no longer exists.', $r['message']);
        }
        $this->assertCount(2, $posted, 'a missing photo sends nothing');
        $this->assertSame([], glob($this->database->dataDir() . '/autofill/tmp/*') ?: []);
    }

    /**
     * L-270: a stored photo with an EXIF Orientation goes out upright, as
     * legacy sends it from the file path, even with allow_url_fopen off. CI
     * runs the CI4 Autofill and Media suites again under
     * `php -d allow_url_fopen=0`. N-280: the photo has a colour per quadrant,
     * so each orientation 2-8 must put a known colour in each corner.
     */
    public function testRotatedStoredPhotoGoesOutUprightWithoutUrlFopen(): void
    {
        $posted = [];
        $af = $this->stack(function (string $method, string $url, ?string $body) use (&$posted): array {
            $posted[] = $this->jpegIn((string)$body);

            return $this->okSuggest();
        });
        $this->settings->store(['autofill_enabled' => true, 'autofill_url' => 'http://autofill.test', 'autofill_timeout' => 5, 'autofill_max_image_bytes' => 1048576]);
        $af->rememberInfo($this->info(null, null));
        $db = $this->database->db;
        $this->assertTrue($db->table('items')->insert([
            'name' => 'Hammer', 'quantity' => 1, 'notes' => '', 'search' => 'hammer',
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]));
        $itemId = (int)$db->insertID();
        // The stored pixels' corners (top-left, top-right, bottom-left, bottom-right), and for each
        // orientation which of them the upright copy shows at its own TL, TR, BL and BR (EXIF 2.32, 4.6.4).
        $upright = [1 => 'rgby', 2 => 'gryb', 3 => 'ybgr', 4 => 'byrg', 5 => 'rbgy', 6 => 'bryg', 7 => 'ygbr', 8 => 'gyrb'];
        $fopen = 'allow_url_fopen=' . var_export(ini_get('allow_url_fopen'), true);
        foreach ($upright as $orientation => $corners) {
            $rotated = $this->withOrientation($this->quadrants(1536, 1024), $orientation);
            $tmp = (string)tempnam(sys_get_temp_dir(), 'af-up');
            file_put_contents($tmp, $rotated);
            $name = $this->images->store($tmp, strlen($rotated));
            $this->assertTrue($db->table('item_photos')->insert(['item_id' => $itemId, 'file' => $name, 'position' => $orientation, 'created_at' => '2020-01-01 00:00:00']));
            try {
                $r = $af->suggestPhotoId((string)$db->insertID());
                $this->assertTrue($r['ok'], $r['message'] ?? '');
                $sent = (string)array_pop($posted);
                $size = getimagesizefromstring($sent);
                $this->assertSame($orientation >= 5 ? [683, 1024] : [1024, 683], [$size[0] ?? 0, $size[1] ?? 0], "orientation $orientation, $fopen");
                $this->assertSame($corners, $this->corners($sent), "orientation $orientation, $fopen");
                $this->assertSame($af->analysis()->jpeg($rotated, AutofillService::IMAGE_SIDE, 80, null, $tmp), $sent, "orientation $orientation: legacy reads EXIF from the path");
            } finally {
                unlink($tmp);
            }
        }
        $this->assertSame([], $posted);
        $this->assertSame([], glob($this->database->dataDir() . '/autofill/tmp/*') ?: []);
    }

    /**
     * A browser form (no fetch, no Accept: application/json) saves and clears
     * the token with 303. The redirect must not start CI4's session beside
     * the native one.
     */
    public function testPlainFormSavesAndClearsTheTokenWith303(): void
    {
        $af = $this->stack();
        Services::injectMock('settings', $this->settings);
        Services::injectMock('autofill', $af);
        $this->liveSession('csrf-test-token');
        // SessionCookie sets this save path, then CI4's FileHandler ini_sets it
        // while that native session is still active.
        $sessions = $this->database->dataDir() . '/sessions';
        mkdir($sessions, 0775, true);
        config(\Config\Session::class)->savePath = $sessions;
        \CodeIgniter\Config\Services::resetSingle('session');
        $saved = $this->withSession(['csrf' => 'csrf-test-token'])->post('settings/autofill/token', [
            'token' => 'form-token-placeholder',
            'csrf' => 'csrf-test-token',
        ]);
        $saved->assertStatus(303);
        // A relative Location, as legacy sends (#76 L-2).
        $this->assertSame('/settings/autofill?done=token-saved', $saved->response()->getHeaderLine('Location'));
        $this->assertStringContainsString('no-store', $saved->response()->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('form-token-placeholder', $saved->response()->getBody());
        $this->assertArrayNotHasKey('_ci_previous_url', $_SESSION);
        $this->assertSame('form-token-placeholder', $this->settings->autofillToken());

        \CodeIgniter\Config\Services::resetSingle('session');
        $this->liveSession('csrf-test-token');
        $cleared = $this->withSession(['csrf' => 'csrf-test-token'])->post('settings/autofill/token', [
            'action' => 'clear',
            'csrf' => 'csrf-test-token',
        ]);
        $cleared->assertStatus(303);
        $this->assertSame('/settings/autofill?done=token-cleared', $cleared->response()->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('_ci_previous_url', $_SESSION);
        $this->assertSame('', $this->settings->autofillToken());
    }

    public function testTypedPhotoTokenIsRedactedFromServerErrorLogs(): void
    {
        $typed = 'typed-photo-token';
        foreach ([500, 503] as $status) {
            $this->database?->drop();
            $calls = [];
            $info = (string)json_encode($this->info(10485760, null));
            $af = $this->stack($this->script($calls, [
                $this->http(200, 'ok', 'text/plain'),
                $this->http(200, $info),
                $this->http($status, 'boom ' . $typed . ' Bearer ' . $typed, 'text/plain'),
            ]));
            $this->settings->store([
                'autofill_enabled' => true,
                'autofill_url' => 'http://autofill.test',
                'autofill_timeout' => 8,
                'autofill_token' => self::TOKEN,
                'autofill_max_image_bytes' => 1048576,
            ]);
            $r = $af->testPhoto('http://autofill.test', 8, $typed);
            $this->assertFalse($r['ok'], 'HTTP ' . $status);
            $text = implode("\n", $this->logged);
            $this->assertStringNotContainsString($typed, $text, 'HTTP ' . $status);
            $this->assertStringNotContainsString(self::TOKEN, $text, 'HTTP ' . $status);
            $this->assertStringContainsString('[redacted]', $text, 'HTTP ' . $status);
        }
    }

    public function testAFailed413RebuildDoesNotPostAgain(): void
    {
        $posts = 0;
        $af = $this->stack(function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$posts): array {
            if ($method === 'GET') {
                return $this->http(200, (string)json_encode($this->info(1048576, null)));
            }
            $posts++;

            return $this->http(413, '{"error":{"code":"payload_too_large","message":"too big"}}');
        });
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => 'http://autofill.test',
            'autofill_timeout' => 5,
            'autofill_max_image_bytes' => 1048576,
            'autofill_token' => self::TOKEN,
        ]);
        $opened = [
            'ok' => true,
            'body' => 'not-a-multipart',
            'content_type' => 'multipart/form-data; boundary=x',
            'jpeg' => 'jj',
            'path' => 'autofill/tmp/autofill-0123456789abcdef.jpg',
        ];
        [$r] = $af->postAnalysis('http://autofill.test', self::TOKEN, 5, $opened, static fn (): array => [
            'ok' => false,
            'code' => 'unsupported',
            'message' => 'could not rebuild',
        ]);
        $this->assertSame(1, $posts);
        $this->assertFalse($r['ok']);
        $this->assertSame('unsupported', $r['code']);
        $this->assertSame('could not rebuild', $r['message']);
    }

    public function testA413RetryReleasesTheFirstTempWhenRefetchFails(): void
    {
        $posts = 0;
        $af = $this->stack(function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$posts): array {
            if ($method === 'GET') {
                return $this->http(503, 'info down', 'text/plain');
            }
            $posts++;

            return $posts === 1 ? $this->http(413, '{}') : $this->okSuggest();
        });
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => 'http://autofill.test',
            'autofill_timeout' => 5,
            'autofill_max_image_bytes' => 1048576,
            'autofill_token' => self::TOKEN,
        ]);
        $old = 'autofill/tmp/autofill-0123456789abcdef.jpg';
        $new = 'autofill/tmp/autofill-fedcba9876543210.jpg';
        $this->storage->write($old, 'old-jpeg');
        $this->storage->write($new, 'new-jpeg');
        [$r] = $af->postAnalysis('http://autofill.test', self::TOKEN, 5, [
            'ok' => true,
            'body' => 'b1',
            'content_type' => 'multipart/form-data',
            'jpeg' => 'jj',
            'path' => $old,
        ], static fn (): array => [
            'ok' => true,
            'body' => 'b2',
            'content_type' => 'multipart/form-data',
            'jpeg' => 'kk',
            'path' => $new,
        ]);
        $this->assertSame(2, $posts, 'a failed refetch still retries once');
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $this->assertNull($this->storage->read($old));
        $this->assertSame('new-jpeg', $this->storage->read($new));
        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $this->logged));
    }

    public function testAutofillSourcesDoNotNameWritable(): void
    {
        $dir = dirname(__DIR__, 2) . '/modules/Autofill';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $this->assertStringNotContainsString('writable/', (string)file_get_contents($file->getPathname()), $file->getFilename());
        }
    }

    public function testConnectionAndPhotoTestAgainstTheStub(): void
    {
        $this->clearStub();
        $af = $this->stack();
        $this->settings->store(['autofill_enabled' => true, 'autofill_url' => self::$stubBase, 'autofill_timeout' => 5]);
        $c = $af->test(self::$stubBase, 5, '');
        $this->assertTrue($c['ok'], $c['message']);
        $this->assertSame('echo', $c['info']['name']);
        $photo = $af->testPhoto(self::$stubBase, 5, '');
        $this->assertTrue($photo['ok'], $photo['message'] ?? '');
        $this->assertSame(['reachable' => true, 'token' => true, 'ready' => true], $photo['checks']);
        $this->assertStringNotContainsString('sekret', json_encode($photo) ?: '');
    }

    /** @param array<string,mixed> $info */
    private function info(?int $max, ?int $enforced): array
    {
        return [
            'name' => 'echo', 'version' => '1', 'capabilities' => [], 'backend' => null,
            'max_image_bytes' => $max, 'enforced_image_bytes' => $enforced, 'ready' => true,
            'avg_latency_ms' => null, 'models' => null, 'image_sizes' => null,
        ];
    }

    /** @return array{status:int,body:string,error:?string,retry_after:?int,content_type:string} */
    private function http(int $status, string $body, string $type = 'application/json'): array
    {
        return ['status' => $status, 'body' => $body, 'error' => null, 'retry_after' => null, 'content_type' => $type];
    }

    /** @return array{status:int,body:string,error:?string,retry_after:?int,content_type:string} */
    private function okSuggest(): array
    {
        return $this->http(200, (string)json_encode([
            'name' => ['value' => 'Hammer', 'confidence' => 0.5],
            'category' => ['value' => 'Tools', 'confidence' => 0.5],
            'tags' => [['value' => 'echo', 'confidence' => 0.5]],
            'description' => ['value' => 'A hammer', 'confidence' => 0.5],
        ]));
    }

    /**
     * @param list<array{status:int,body:string,error:?string,retry_after:?int,content_type:string}> $answers
     * @return Closure(string,string,?string,string,int,string):array{status:int,body:string,error:?string,retry_after:?int,content_type:string}
     */
    private function script(array &$calls, array $answers): Closure
    {
        return function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$calls, $answers): array {
            $calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'token' => $token];
            $answer = $answers[count($calls) - 1] ?? null;
            $this->assertNotNull($answer, 'unexpected ' . $method . ' ' . $url);

            return $answer;
        };
    }

    /**
     * @param list<string> $posted
     * @param Closure(int,string):array{status:int,body:string,error:?string,retry_after:?int,content_type:string} $onPost
     */
    private function ready(array &$posted, Closure $onPost): AutofillService
    {
        $af = $this->stack(function (string $method, string $url, ?string $body, string $token, int $timeout, string $type) use (&$posted, $onPost): array {
            $this->assertSame('POST', $method);
            $jpeg = $this->jpegIn((string)$body);
            $posted[] = $jpeg;

            return $onPost(count($posted), $jpeg);
        });
        $this->settings->store([
            'autofill_enabled' => true,
            'autofill_url' => 'http://autofill.test',
            'autofill_timeout' => 5,
            'autofill_token' => self::TOKEN,
        ]);

        return $af;
    }

    private function jpegIn(string $body): string
    {
        $start = strpos($body, "\xFF\xD8");
        $this->assertNotFalse($start);
        $end = strpos($body, "\r\n--", $start);
        $this->assertNotFalse($end);

        return substr($body, $start, $end - $start);
    }

    private function stack(?Closure $transport = null, string $driver = 'sqlite'): AutofillService
    {
        $this->database = TestDatabase::create($driver);
        $this->storage = new LocalStorage($this->database->dataDir());
        $this->logged = [];
        $logged = &$this->logged;
        $settings = new SettingsService(
            new SettingsRepository($this->database->db),
            new SettingsSchema(),
            new LocalBrandStore($this->database->dataDir() . '/brand'),
            new Clock(),
            $this->database->config->values(),
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            },
        );
        $this->settings = $settings;
        $this->images = new ImageStore(
            $settings,
            new OriginalStore(new LocalStorage($this->database->config->originalsDir(), 0640)),
            new ServedImageWriter($this->storage),
            new ImagickDecoder(),
            new RequestCleanup(),
        );
        $now = static fn (): int => time();
        $log = new LogRedactor(
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            },
            static fn (): string => $settings->autofillToken(),
        );

        return new AutofillService(
            $settings,
            $this->storage,
            new AutofillClient($now, $transport),
            $log,
            $this->database->db,
            $this->images,
            ApothekeConfig::repoRoot() . '/public/assets/autofill-sample.jpg',
            static function (int $seconds): void {
            },
            $now,
        );
    }

    /** A second client on the same database, storage and log, so warn-once cannot hide in an instance property. */
    private function anotherService(): AutofillService
    {
        $settings = $this->settings;
        $logged = &$this->logged;
        $now = static fn (): int => time();
        $log = new LogRedactor(
            static function (string $message) use (&$logged): void {
                $logged[] = $message;
            },
            static fn (): string => $settings->autofillToken(),
        );

        return new AutofillService(
            $settings,
            $this->storage,
            new AutofillClient($now),
            $log,
            $this->database->db,
            $this->images,
            ApothekeConfig::repoRoot() . '/public/assets/autofill-sample.jpg',
            static function (int $seconds): void {
            },
            $now,
        );
    }

    private function clampLines(): int
    {
        return substr_count(implode("\n", $this->logged), 'image limit clamped');
    }

    private function jpeg(int $w, int $h, int $quality = 85): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 80, 160));
        ob_start();
        imagejpeg($im, null, $quality);

        return (string)ob_get_clean();
    }

    /** A JPEG with one colour per quadrant: red, green (top), blue, yellow (bottom). */
    private function quadrants(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        foreach ([[0, 0, 255, 0, 0], [1, 0, 0, 255, 0], [0, 1, 0, 0, 255], [1, 1, 255, 255, 0]] as [$x, $y, $r, $g, $b]) {
            imagefilledrectangle($im, $x * intdiv($w, 2), $y * intdiv($h, 2), ($x + 1) * intdiv($w, 2) - 1, ($y + 1) * intdiv($h, 2) - 1, (int)imagecolorallocate($im, $r, $g, $b));
        }
        ob_start();
        imagejpeg($im, null, 90);

        return (string)ob_get_clean();
    }

    /** The quadrant colour (r, g, b or y) near each corner: top-left, top-right, bottom-left, bottom-right. */
    private function corners(string $jpeg): string
    {
        $im = imagecreatefromstring($jpeg);
        $this->assertInstanceOf(\GdImage::class, $im);
        $w = imagesx($im);
        $h = imagesy($im);
        $out = '';
        foreach ([[10, 10], [$w - 11, 10], [10, $h - 11], [$w - 11, $h - 11]] as [$x, $y]) {
            $c = imagecolorat($im, $x, $y);
            $rgb = [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
            $out .= match (true) {
                $rgb[0] > 160 && $rgb[1] > 160 && $rgb[2] < 100 => 'y',
                $rgb[0] > 160 && $rgb[1] < 100 && $rgb[2] < 100 => 'r',
                $rgb[0] < 100 && $rgb[1] > 160 && $rgb[2] < 100 => 'g',
                $rgb[0] < 100 && $rgb[1] < 100 && $rgb[2] > 160 => 'b',
                default => '?',
            };
        }

        return $out;
    }

    /** $jpeg with a big-endian EXIF APP1 holding only Orientation. */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', 1) . pack('nnNnn', 0x0112, 3, 1, $orientation, 0) . pack('N', 0);
        $app1 = "Exif\x00\x00" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /** Seeded noise. High quality so a later re-encode at 80 and 70 differs in size. */
    private function noisy(int $side): string
    {
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

    private function clearStub(): void
    {
        @file_get_contents(self::$stubBase . '/_stub/uploads', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    }

    private function suggestCount(): int
    {
        $n = json_decode((string)file_get_contents(self::$stubBase . '/_stub/count'), true);

        return (int)($n['n'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    private function uploads(): array
    {
        $rows = json_decode((string)file_get_contents(self::$stubBase . '/_stub/uploads'), true);

        return is_array($rows) ? $rows : [];
    }
}
