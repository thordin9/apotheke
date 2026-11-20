<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\FirstAdminClaim;
use Apotheke\Auth\OpsAccountsEndpoint;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Hosted\HostedLimits;
use Apotheke\Core\Hosted\PlanLimits;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\OpsSignature;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\TestDatabase;

/**
 * POST /internal/accounts/claim and /internal/accounts/reset-admins through the app (operator console): answered
 * first, only on ops.internal of a hosted installation, signed like /internal/backup with the key of the shared test
 * vector (_support/ops-signature-vector.json). Elsewhere, or self-hosted, the path is not found
 * (404, or 421 from the host list).
 */
final class OpsAccountsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private ?TestDatabase $database = null;

    private ?OpsSignature $sig = null;

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        Services::reset(true);
        parent::tearDown();
    }

    private function wire(bool $hosted): void
    {
        $d = $this->database = TestDatabase::create('sqlite');
        Services::reset(true);
        Services::injectMock('apothekeConfig', $d->config);
        Services::injectMock('apothekeDb', $d->db);
        Services::injectMock('hostedLimits', $hosted
            ? HostedLimits::hosted(static fn (): PlanLimits => PlanLimits::fromBridge(['ok' => true, 'plan' => 't', 'limits' => []]) ?? throw new \LogicException('fixture'))
            : HostedLimits::off());
        $v = json_decode((string)file_get_contents(dirname(__DIR__) . '/_support/ops-signature-vector.json'), true);
        $this->assertIsArray($v);
        $this->sig = OpsSignature::fromAppKey('base64:' . base64_encode(implode('', array_map('chr', range(1, 32)))));
        $this->assertNotNull($this->sig);
        // The same key as the edge's vector.
        $this->assertSame($v['header'], $this->sig->header($v['method'], $v['path'], $v['body'], $v['t']));
        Services::injectMock('opsAccountsEndpoint', new OpsAccountsEndpoint($hosted, $this->sig,
            static fn (): FirstAdminClaim => Services::firstAdminClaim(false), static fn () => Services::hostedAdminReset(false), new Clock()));
    }

    /** @return array<string,string> */
    private function signed(string $path, string $body = '{}', ?int $t = null, string $as = ''): array
    {
        $this->assertNotNull($this->sig);

        return ['Content-Type' => 'application/json', OpsSignature::HEADER => $this->sig->header('POST', $as !== '' ? $as : $path, $body, $t ?? (new Clock())->unix())];
    }

    /** @param array<string,string> $headers */
    private function post(string $host, string $path, array $headers, string $body = '{}'): TestResponse
    {
        service('superglobals')->setServer('HTTP_HOST', $host);
        service('superglobals')->setServer('REQUEST_URI', $path);
        try {
            return $this->withHeaders($headers)->withBody($body)->call('POST', ltrim($path, '/'));
        } catch (\CodeIgniter\Exceptions\PageNotFoundException) {
            return new TestResponse((new \CodeIgniter\HTTP\Response(config(\Config\App::class)))->setStatusCode(404));
        }
    }

    /** @return array<string,mixed> */
    private static function json(TestResponse $r): array
    {
        $j = json_decode((string)$r->response()->getBody(), true);

        return is_array($j) ? $j : [];
    }

    private function claimRow(): bool
    {
        $this->assertNotNull($this->database);

        return Writes::rows($this->database->db, 'SELECT 1 AS one FROM settings WHERE name = ?', [FirstAdminClaim::SETTING]) !== [];
    }

    private function admins(): int
    {
        $this->assertNotNull($this->database);

        return (int)(Writes::rows($this->database->db, "SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")[0]['n'] ?? 0);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResetThenClaimAndTheTokenIsNeverLogged(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'ops-accounts-log');
        ini_set('error_log', (string)$log);
        $this->wire(true);
        $this->assertNotNull($this->database);
        $users = new UserRepository($this->database->db);
        $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');

        $refused = $this->post('ops.internal', OpsAccountsEndpoint::CLAIM_PATH, $this->signed(OpsAccountsEndpoint::CLAIM_PATH));
        $refused->assertStatus(409);
        $this->assertSame(['ok' => false, 'code' => 'active_admin_exists'], self::json($refused));
        $this->assertFalse($this->claimRow());

        $reset = $this->post('ops.internal', OpsAccountsEndpoint::RESET_PATH, $this->signed(OpsAccountsEndpoint::RESET_PATH));
        $reset->assertStatus(200);
        $this->assertSame(['ok' => true], self::json($reset));
        $this->assertSame(0, $this->admins());
        $this->assertSame(1, (int)(Writes::rows($this->database->db, 'SELECT COUNT(*) AS n FROM users')[0]['n'] ?? 0));

        $claim = $this->post('ops.internal', OpsAccountsEndpoint::CLAIM_PATH, $this->signed(OpsAccountsEndpoint::CLAIM_PATH));
        $claim->assertStatus(200);
        $this->assertSame('no-store', $claim->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('', $claim->response()->getHeaderLine('Set-Cookie'));
        $j = self::json($claim);
        $this->assertTrue($j['ok'] ?? null);
        $this->assertIsString($j['token'] ?? null);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $j['token']);
        $this->assertStringEndsWith(' UTC', (string)($j['expires_at'] ?? ''));
        $this->assertTrue($this->claimRow());
        // Only the token's hash is stored.
        $this->assertStringNotContainsString($j['token'], (string)json_encode(Writes::rows($this->database->db, 'SELECT value FROM settings')));

        $logs = (string)file_get_contents((string)$log);
        foreach (glob(WRITEPATH . 'logs/*') ?: [] as $f) {
            $logs .= (string)file_get_contents($f);
        }
        $this->assertStringNotContainsString($j['token'], $logs);
        @unlink((string)$log);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesBadStaleAndOtherPathSignatures(): void
    {
        $this->wire(true);
        $this->assertNotNull($this->database);
        (new UserRepository($this->database->db))->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $now = (new Clock())->unix();
        foreach ([OpsAccountsEndpoint::CLAIM_PATH, OpsAccountsEndpoint::RESET_PATH] as $path) {
            $cases = [
                'unsigned' => ['Content-Type' => 'application/json'],
                'bad' => [OpsSignature::HEADER => 't=' . $now . ',v1=' . str_repeat('0', 64)],
                'stale' => $this->signed($path, '{}', $now - OpsSignature::WINDOW - 5),
                'future' => $this->signed($path, '{}', $now + OpsSignature::WINDOW + 5),
                'backup path' => $this->signed($path, '{}', null, '/internal/backup'),
                'other action' => $this->signed($path, '{}', null, $path === OpsAccountsEndpoint::CLAIM_PATH ? OpsAccountsEndpoint::RESET_PATH : OpsAccountsEndpoint::CLAIM_PATH),
            ];
            foreach ($cases as $name => $headers) {
                $r = $this->post('ops.internal', $path, $headers);
                $this->assertSame(401, $r->response()->getStatusCode(), $path . ' ' . $name);
                $this->assertSame(['ok' => false, 'code' => 'unauthorized'], self::json($r), $path . ' ' . $name);
            }
            $other = $this->post('ops.internal', $path, $this->signed($path, '{}'), '{"x":1}');
            $this->assertSame(401, $other->response()->getStatusCode(), $path . ' other body');
        }
        $this->assertSame(1, $this->admins());
        $this->assertFalse($this->claimRow());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNotForwardedOrOtherHostsOrSelfHosted(): void
    {
        $this->wire(true);
        $this->assertNotNull($this->database);
        (new UserRepository($this->database->db))->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        foreach (['X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-For', 'X-Apotheke-Client-IP'] as $h) {
            $r = $this->post('ops.internal', OpsAccountsEndpoint::RESET_PATH, $this->signed(OpsAccountsEndpoint::RESET_PATH) + [$h => 'x']);
            $this->assertContains($r->response()->getStatusCode(), [404, 421], $h);
            $this->assertStringNotContainsString('"ok"', (string)$r->response()->getBody(), $h);
        }
        $r = $this->post('localhost', OpsAccountsEndpoint::RESET_PATH, $this->signed(OpsAccountsEndpoint::RESET_PATH));
        $this->assertContains($r->response()->getStatusCode(), [404, 421]);
        $this->assertStringNotContainsString('"ok"', (string)$r->response()->getBody());
        $this->assertSame(1, $this->admins());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSelfHostedDoesNotHaveIt(): void
    {
        $this->wire(false);
        $this->assertNotNull($this->database);
        (new UserRepository($this->database->db))->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        foreach ([OpsAccountsEndpoint::CLAIM_PATH, OpsAccountsEndpoint::RESET_PATH] as $path) {
            $r = $this->post('ops.internal', $path, $this->signed($path));
            $this->assertContains($r->response()->getStatusCode(), [404, 421], $path);
            $this->assertStringNotContainsString('"ok"', (string)$r->response()->getBody(), $path);
        }
        $this->assertSame(1, $this->admins());
        $this->assertFalse($this->claimRow());
    }
}
