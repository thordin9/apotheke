<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Filters\SessionCookie;
use Apotheke\Core\Http\SessionGate;
use Apotheke\Core\Http\SessionLifetime;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\Support\TestDatabase;

/**
 * SEC-N9 in a long-lived worker: the session filter runs twice in one PHP
 * process, and a session_days change saved between the two requests applies
 * to the second. Each request gets a new SettingsService and the save goes
 * through another instance, so a held instance would show a stale value (#74
 * L-1). Each test runs in its own process so session_start() works.
 */
final class SessionCookieWorkerTest extends CIUnitTestCase
{
    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASavedSessionDaysAppliesToTheNextRequest(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            // The Control Panel saves through its own instance, as another request would.
            $writer = $this->settings($database);
            $filter = new SessionCookie();

            $this->assertSame(30 * 86400, $this->request($filter, $database), 'first request: default 30 days');
            $this->assertSame([], $writer->save(['session_days' => 7]));
            $this->assertSame(7 * 86400, $this->request($filter, $database), 'second request: 7 days');
            $this->assertSame([], $writer->save(['session_days' => 30]));
            $this->assertSame(30 * 86400, $this->request($filter, $database), 'third request: back to 30 days');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /**
     * #74 N-1: the filter builds its own SessionLifetime (sessionLifetime(false))
     * on every request, so a shared instance can never carry a value over.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheFilterIgnoresASharedSessionLifetime(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $this->assertSame([], $this->settings($database)->save(['session_days' => 9]));
            $filter = new SessionCookie();
            $shared = new SessionLifetime(static fn (): int => 2);
            $this->assertSame(9 * 86400, $this->request($filter, $database, $shared));
            $this->assertSame(2 * 86400, Services::sessionLifetime()->seconds(), 'the shared instance is the injected one');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /**
     * #76 L-3: two requests in one worker process. The second has no cookie,
     * so it must not see the first one's session (uid, epoch, CSRF token).
     */
    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASecondRequestDoesNotSeeThePreviousSession(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            // SessionCookie keeps sessions under the environment's DATA_DIR.
            $save = (string)getenv('DATA_DIR') . '/sessions';
            @mkdir($save, 0775, true);
            $id = str_repeat('f', 26);
            file_put_contents($save . '/sess_' . $id, 'uid|i:3;auth_epoch|s:16:"0123456789abcdef";csrf|s:4:"tok1";last_seen|i:' . time() . ';');
            $filter = new SessionCookie();

            $this->request($filter, $database, null, [SessionGate::COOKIE => $id], false);
            $this->assertSame(3, $_SESSION['uid'] ?? null, 'first request: its own session');
            $this->assertSame('tok1', $_SESSION['csrf'] ?? null);

            $this->request($filter, $database);
            $this->assertSame([], $_SESSION, 'second request, no cookie: no session data');

            $_SESSION = ['uid' => 3];
            $this->request($filter, $database, null, [], true, 'healthz');
            $this->assertSame([], $_SESSION, '/healthz starts empty too');

            $_SESSION = ['uid' => 3];
            $this->request($filter, $database, null, [SessionGate::COOKIE => str_repeat('a', 26)]);
            $this->assertSame([], $_SESSION, 'a cookie whose session is gone loads nothing');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    private function settings(TestDatabase $database): SettingsService
    {
        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null);
    }

    /**
     * One worker request through the filter: shared services are reset and the
     * request gets a new SettingsService, as CI4's worker mode does. Returns the
     * cookie lifetime it set.
     */
    /** @param array<string,string> $cookies */
    private function request(SessionCookie $filter, TestDatabase $database, ?SessionLifetime $shared = null, array $cookies = [], bool $noSession = true, string $path = 'items'): int
    {
        Services::reset();
        Services::injectMock('settings', $this->settings($database));
        if ($shared !== null) {
            Services::injectMock('sessionLifetime', $shared);
        }
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, $path), null, new UserAgent());
        $request->setGlobal('cookie', $cookies);
        // PHP's session_start() reads the id from $_COOKIE, as on a web request.
        $_COOKIE = $cookies;
        $this->assertNull($filter->before($request));
        // The session is released right after loading; without a live cookie none is started (SEC-L2).
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        if ($noSession) {
            $this->assertSame([], $_SESSION);
        }
        $lifetime = session_get_cookie_params()['lifetime'];
        $this->assertSame((string)$lifetime, ini_get('session.gc_maxlifetime'));

        return $lifetime;
    }
}
