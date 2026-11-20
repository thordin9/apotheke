<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\FirstAdminClaim;
use Apotheke\Auth\HostedDisabledUsers;
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
 * POST /internal/accounts/disable and /internal/accounts/enable through the app: same signed ops.internal
 * channel as claim and reset-admins. Self-hosted, or a public or forwarded request, the path is not found.
 */
final class OpsAccountsDisableHttpTest extends CIUnitTestCase
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
        $this->sig = OpsSignature::fromAppKey('base64:' . base64_encode(implode('', array_map('chr', range(1, 32)))));
        $this->assertNotNull($this->sig);
        $db = $d->db;
        Services::injectMock('hostedDisabledUsers', new HostedDisabledUsers($db, Services::unitsOfWork(), new Clock(), $hosted));
        Services::injectMock('opsAccountsEndpoint', new OpsAccountsEndpoint($hosted, $this->sig,
            static fn (): FirstAdminClaim => Services::firstAdminClaim(false), static fn () => Services::hostedAdminReset(false),
            new Clock(), static fn (): HostedDisabledUsers => Services::hostedDisabledUsers(false)));
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

    private function disable(int $userId, string $host = 'ops.internal'): TestResponse
    {
        $body = (string)json_encode(['user_id' => $userId]);

        return $this->post($host, OpsAccountsEndpoint::DISABLE_PATH, $this->signed(OpsAccountsEndpoint::DISABLE_PATH, $body), $body);
    }

    private function enable(int $userId, string $host = 'ops.internal'): TestResponse
    {
        $body = (string)json_encode(['user_id' => $userId]);

        return $this->post($host, OpsAccountsEndpoint::ENABLE_PATH, $this->signed(OpsAccountsEndpoint::ENABLE_PATH, $body), $body);
    }

    private function flagged(int $userId): bool
    {
        $this->assertNotNull($this->database);

        return Writes::rows($this->database->db, 'SELECT 1 AS one FROM hosted_disabled_users WHERE user_id = ?', [$userId]) !== [];
    }

    /** @return string the sessions.id key */
    private function putSession(string $sid, int $userId): string
    {
        $this->assertNotNull($this->database);
        $key = hash('sha256', $sid);
        $this->database->db->query(
            'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
            [$key, 'uid|i:' . $userId . ';auth_epoch|s:16:"0123456789abcdef";last_activity|i:1760000000;', 2000000000, '', 0],
        );

        return $key;
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDisableAndEnableANonAdmin(): void
    {
        $this->wire(true);
        $this->assertNotNull($this->database);
        $users = new UserRepository($this->database->db);
        $boss = $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $looker = $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $other = $users->create('other', 'x', 'editor', 'active', '2026-01-01 00:00:00');
        $mine = $this->putSession('sess-look-aaaaaaaaaaaa', $looker);
        $theirs = $this->putSession('sess-othr-aaaaaaaaaaaa', $other);

        $r = $this->disable($looker);
        $r->assertStatus(200);
        $this->assertSame(['ok' => true], self::json($r));
        $this->assertSame('no-store', $r->response()->getHeaderLine('Cache-Control'));
        $this->assertSame('', $r->response()->getHeaderLine('Set-Cookie'));
        $this->assertTrue($this->flagged($looker));
        $this->assertSame('active', Writes::rows($this->database->db, 'SELECT status FROM users WHERE id = ?', [$looker])[0]['status'] ?? null);
        $this->assertEqualsCanonicalizing([$theirs], array_map(static fn (array $row): string => (string)$row['id'], Writes::rows($this->database->db, 'SELECT id FROM sessions')));
        $this->assertNotContains($mine, array_map(static fn (array $row): string => (string)$row['id'], Writes::rows($this->database->db, 'SELECT id FROM sessions')));

        $again = $this->disable($looker);
        $again->assertStatus(200);
        $this->assertTrue($this->flagged($looker));

        $on = $this->enable($looker);
        $on->assertStatus(200);
        $this->assertFalse($this->flagged($looker));
        $this->enable($looker)->assertStatus(200);
        $this->assertFalse($this->flagged($boss));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLastActiveAdminAndUnknownUser(): void
    {
        $this->wire(true);
        $this->assertNotNull($this->database);
        $users = new UserRepository($this->database->db);
        $boss = $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $sess = $this->putSession('sess-boss-aaaaaaaaaaaa', $boss);

        $r = $this->disable($boss);
        $r->assertStatus(409);
        $this->assertSame(['ok' => false, 'code' => 'last_active_admin'], self::json($r));
        $this->assertFalse($this->flagged($boss));
        $this->assertSame([$sess], array_map(static fn (array $row): string => (string)$row['id'], Writes::rows($this->database->db, 'SELECT id FROM sessions')));

        $missing = $this->disable(99);
        $missing->assertStatus(404);
        $this->assertSame(['ok' => false, 'code' => 'not_found'], self::json($missing));
        $this->enable(99)->assertStatus(404);

        $bad = $this->post('ops.internal', OpsAccountsEndpoint::DISABLE_PATH, $this->signed(OpsAccountsEndpoint::DISABLE_PATH, '{}'), '{}');
        $this->assertSame(400, $bad->response()->getStatusCode());
        $this->assertSame(['ok' => false, 'code' => 'bad_request'], self::json($bad));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSignatureRequiredAndSelfHostedOrForwardedIsNotThisEndpoint(): void
    {
        $this->wire(true);
        $this->assertNotNull($this->database);
        $users = new UserRepository($this->database->db);
        $looker = $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $body = (string)json_encode(['user_id' => $looker]);
        $path = OpsAccountsEndpoint::DISABLE_PATH;
        $now = (new Clock())->unix();
        $cases = [
            'unsigned' => ['Content-Type' => 'application/json'],
            'bad' => [OpsSignature::HEADER => 't=' . $now . ',v1=' . str_repeat('0', 64)],
            'stale' => $this->signed($path, $body, $now - OpsSignature::WINDOW - 5),
            'wrong path' => $this->signed($path, $body, null, OpsAccountsEndpoint::RESET_PATH),
        ];
        foreach ($cases as $name => $headers) {
            $r = $this->post('ops.internal', $path, $headers, $body);
            $this->assertSame(401, $r->response()->getStatusCode(), $name);
            $this->assertSame(['ok' => false, 'code' => 'unauthorized'], self::json($r), $name);
        }
        $wrongBody = $this->post('ops.internal', $path, $this->signed($path, $body), '{"user_id":999}');
        $this->assertSame(401, $wrongBody->response()->getStatusCode(), 'other body');
        $this->assertFalse($this->flagged($looker));

        foreach (['X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-For', 'X-Apotheke-Client-IP'] as $h) {
            $r = $this->post('ops.internal', $path, $this->signed($path, $body) + [$h => 'x'], $body);
            $this->assertContains($r->response()->getStatusCode(), [404, 421], $h);
            $this->assertStringNotContainsString('"ok"', (string)$r->response()->getBody(), $h);
        }
        $pub = $this->post('localhost', $path, $this->signed($path, $body), $body);
        $this->assertContains($pub->response()->getStatusCode(), [404, 421]);
        $this->assertFalse($this->flagged($looker));

        $this->wire(false);
        $this->assertNotNull($this->database);
        $users = new UserRepository($this->database->db);
        $looker = $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $body = (string)json_encode(['user_id' => $looker]);
        foreach ([OpsAccountsEndpoint::DISABLE_PATH, OpsAccountsEndpoint::ENABLE_PATH] as $p) {
            $r = $this->post('ops.internal', $p, $this->signed($p, $body), $body);
            $this->assertContains($r->response()->getStatusCode(), [404, 421], $p);
            $this->assertStringNotContainsString('"ok"', (string)$r->response()->getBody(), $p);
        }
        $this->assertFalse($this->flagged($looker));
    }
}
