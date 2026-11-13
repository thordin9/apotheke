<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\OpsBackupEndpoint;
use Apotheke\Backup\OpsBackups;
use Apotheke\Backup\StoredBackups;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Security\OpsSignature;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\TestDatabase;

/**
 * POST /internal/backup through the app (operator console C2): answered first, before the host list, sessions and
 * CSRF, only on ops.internal of a hosted installation; on the public host, or self-hosted, the path is not found.
 */
final class OpsBackupHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private ?TestDatabase $database = null;

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        Services::reset(true);
        parent::tearDown();
    }

    /** @return array{0:string,1:array<string,string>} body and headers of a signed request */
    private function wire(bool $hosted): array
    {
        $d = $this->database = TestDatabase::create('sqlite');
        Services::reset(true);
        Services::injectMock('apothekeConfig', $d->config);
        Services::injectMock('apothekeDb', $d->db);
        $appKey = 'base64:' . base64_encode(random_bytes(32));
        $settings = new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($d->dataDir())), new Clock(),
            $d->config->values(), static fn (string $m): null => null, new CommandLine());
        $stored = new StoredBackups(new LocalStorage($d->dataDir()), new Backups($d->db, new Clock(), $settings, $d->dataDir(), $d->config->originalsDir()), new Clock(), 5);
        Services::injectMock('opsBackupEndpoint', new OpsBackupEndpoint($hosted, OpsSignature::fromAppKey($appKey),
            static fn (): OpsBackups => new OpsBackups($d->db, $stored, new Clock()), new Clock()));
        $body = (string)json_encode(['job_id' => 'job-1']);
        $sig = OpsSignature::fromAppKey($appKey) ?? throw new \LogicException('key');

        return [$body, ['Content-Type' => 'application/json', OpsSignature::HEADER => $sig->header('POST', OpsBackupEndpoint::PATH, $body, (new Clock())->unix())]];
    }

    private function post(string $host, string $body, array $headers): \CodeIgniter\Test\TestResponse
    {
        // The request reads its server values from here.
        service('superglobals')->setServer('HTTP_HOST', $host);
        service('superglobals')->setServer('REQUEST_URI', '/internal/backup');
        try {
            return $this->withHeaders($headers)->withBody($body)->call('POST', 'internal/backup');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            return new \CodeIgniter\Test\TestResponse((new \CodeIgniter\HTTP\Response(config(\Config\App::class)))->setStatusCode(404));
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testHostedOpsHostGetsTheBackup(): void
    {
        [$body, $headers] = $this->wire(true);
        $r = $this->post('ops.internal', $body, $headers);
        $r->assertStatus(200);
        $this->assertSame('no-store', $r->response()->getHeaderLine('Cache-Control'));
        $json = json_decode((string)$r->response()->getBody(), true);
        $this->assertTrue($json['ok']);
        $this->assertStringStartsWith('backups/', $json['backup']['key']);
        $this->assertSame('', $r->response()->getHeaderLine('Set-Cookie'));
        $unsigned = $this->post('ops.internal', $body, ['Content-Type' => 'application/json']);
        $unsigned->assertStatus(401);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testThePublicHostDoesNotHaveIt(): void
    {
        [$body, $headers] = $this->wire(true);
        $r = $this->post('localhost', $body, $headers);
        $this->assertContains($r->response()->getStatusCode(), [404, 421]);
        $this->assertStringNotContainsString('"ok"', (string)$r->response()->getBody());
        $this->assertSame([], glob($this->database?->dataDir() . '/backups/*') ?: []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSelfHostedDoesNotHaveIt(): void
    {
        [$body, $headers] = $this->wire(false);
        $r = $this->post('ops.internal', $body, $headers);
        $this->assertContains($r->response()->getStatusCode(), [404, 421]);
        $this->assertSame([], glob($this->database?->dataDir() . '/backups/*') ?: []);
    }
}
