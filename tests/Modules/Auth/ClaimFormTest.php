<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\FirstAdminClaim;
use Apotheke\Core\Hosted\HostedLimits;
use Apotheke\Core\Hosted\PlanLimits;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\TestDatabase;

/**
 * GET and POST /claim (operator console C1): 404 without APOTHEKE_HOSTED; hosted, the page takes the token and makes
 * the first admin, once. Each test runs in its own process so session_start() works.
 */
final class ClaimFormTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        unset($_COOKIE['apothekesid']);
        service('superglobals')->unsetCookie('apothekesid');
        $this->database?->drop();
        $this->database = null;
        Services::reset(true);
        parent::tearDown();
    }

    private function wire(bool $hosted): void
    {
        $this->assertNotNull($this->database);
        Services::reset(true);
        Services::injectMock('apothekeConfig', $this->database->config);
        Services::injectMock('apothekeDb', $this->database->db);
        if ($hosted) {
            Services::injectMock('hostedLimits', HostedLimits::hosted(static fn (): PlanLimits => PlanLimits::none()));
        }
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheClaimPageIsNotFoundWhenSelfHosted(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->wire(false);
        $this->get('claim')->assertStatus(404);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $csrf = bin2hex(random_bytes(8));
        $pw = 'pw-' . bin2hex(random_bytes(8));
        $this->wire(false);
        $this->liveSession($csrf);
        $this->withSession(['csrf' => $csrf])->post('claim', ['csrf' => $csrf, 'token' => 'x', 'username' => 'owner', 'new' => $pw, 'confirm' => $pw])->assertStatus(404);
        $this->assertSame([], $this->database->db->query('SELECT id FROM users')->getResultArray());
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAHostedOwnerClaimsTheFirstAdminOnce(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->wire(true);
        $token = Services::firstAdminClaim()->create()['token'];
        $pw = 'pw-' . bin2hex(random_bytes(8));
        foreach ([[303, 'owner'], [403, 'again']] as [$status, $name]) {
            $csrf = bin2hex(random_bytes(8));
            $this->wire(true);
            $this->liveSession($csrf);
            $response = $this->withSession(['csrf' => $csrf])->post('claim', ['csrf' => $csrf, 'token' => $token, 'username' => $name, 'new' => $pw, 'confirm' => $pw]);
            $response->assertStatus($status);
            if ($status === 303) {
                $this->assertStringEndsWith('/?done=welcome', $response->response()->getHeaderLine('Location'));
            } else {
                $this->assertStringContainsString('This claim link is not valid. Ask for a new one.', (string)$response->response()->getBody());
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
        }
        // The page itself (it reads the token from the link's fragment).
        $this->wire(true);
        $page = $this->get('claim');
        $page->assertStatus(200);
        $this->assertStringContainsString('id="claim-token"', (string)$page->response()->getBody());
        $this->assertStringContainsString('no-store', $page->response()->getHeaderLine('Cache-Control'));
        $this->assertSame([['username' => 'owner', 'role' => 'admin']], $this->database->db->query('SELECT username, role FROM users')->getResultArray());
        $this->assertSame([], $this->database->db->query('SELECT name FROM settings WHERE name = ?', [FirstAdminClaim::SETTING])->getResultArray());
    }

    /** A stored session holding $csrf, named by the request's apothekesid cookie (as in RegisterFormTest). */
    private function liveSession(string $csrf): void
    {
        $database = $this->database;
        $this->assertNotNull($database);
        $save = $database->dataDir() . '/sessions';
        if (!is_dir($save)) {
            mkdir($save, 0775, true);
        }
        $id = bin2hex(random_bytes(16));
        file_put_contents($save . '/sess_' . $id, 'csrf|s:' . strlen($csrf) . ':"' . $csrf . '";last_activity|i:' . time() . ';');
        $_COOKIE['apothekesid'] = $id;
        service('superglobals')->setCookie('apothekesid', $id);
        session_id($id);   // an earlier request in this process left its own id set
    }
}
