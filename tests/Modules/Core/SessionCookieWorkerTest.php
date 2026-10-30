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
            file_put_contents($save . '/sess_' . $id, 'uid|i:3;auth_epoch|s:16:"0123456789abcdef";csrf|s:4:"tok1";last_activity|i:' . time() . ';');
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

    /**
     * #82 L-2: an error path left the previous request's session open. The
     * worker loop's session()->close() and the next request's filter close it,
     * and the next, cookieless request starts without its data.
     */
    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASessionLeftOpenIsClosedAndNotSeenByTheNextRequest(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $save = (string)getenv('DATA_DIR') . '/sessions';
            @mkdir($save, 0775, true);
            $id = str_repeat('e', 26);
            file_put_contents($save . '/sess_' . $id, 'uid|i:3;auth_epoch|s:16:"0123456789abcdef";csrf|s:4:"tok1";last_activity|i:' . time() . ';');
            $filter = new SessionCookie();
            $this->request($filter, $database, null, [SessionGate::COOKIE => $id], false);
            // The request then reopened it and failed before releasing it.
            session_id($id);
            session_start();
            $this->assertSame(PHP_SESSION_ACTIVE, session_status());
            $this->assertSame(3, $_SESSION['uid'] ?? null);

            $this->request($filter, $database);
            $this->assertSame([], $_SESSION, 'the next request sees none of it');
            $this->assertFileExists($save . '/sess_' . $id, 'closed, not ended');

            session_id($id);
            session_start();
            Services::session()->close();
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), "the worker loop's close() write-closes it");
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /**
     * Main #84: a signed-in session ends after session_idle_hours with no
     * request, and session_days after sign-in even while in use. A lower
     * idle limit saved between two requests applies to the next one.
     */
    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheIdleAndAbsoluteLimitsEndASignedInSession(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $save = (string)getenv('DATA_DIR') . '/sessions';
            @mkdir($save, 0775, true);
            $filter = new SessionCookie();
            $now = time();
            $write = static function (string $id, int $seen, int $at) use ($save): void {
                file_put_contents($save . '/sess_' . $id, 'uid|i:3;auth_epoch|s:3:"abc";csrf|s:4:"tok1";last_activity|i:' . $seen . ';signed_in_at|i:' . $at . ';');
            };

            $idle = str_repeat('1', 26);
            $write($idle, $now - 2 * 3600, $now - 3 * 3600);
            $this->request($filter, $database, null, [SessionGate::COOKIE => $idle], false);
            $this->assertSame(3, $_SESSION['uid'] ?? null, 'two hours idle: within the default 168');
            $this->assertSame([], $this->settings($database)->save(['session_idle_hours' => 1]));
            $write($idle, $now - 2 * 3600, $now - 3 * 3600);
            $this->request($filter, $database, null, [SessionGate::COOKIE => $idle]);
            $this->assertFileDoesNotExist($save . '/sess_' . $idle, 'idle limit lowered to 1 hour: ended on the next request');

            $old = str_repeat('2', 26);
            $write($old, $now - 30, $now - 31 * 86400);
            $this->request($filter, $database, null, [SessionGate::COOKIE => $old]);
            $this->assertFileDoesNotExist($save . '/sess_' . $old, 'signed in 31 days ago: ended though in use');

            // A hand-edited row outside 1–8760 is clamped, as legacy session_idle_seconds() does.
            $database->db->table('settings')->where('name', 'session_idle_hours')->update(['value' => '99999']);
            $long = str_repeat('3', 26);
            $write($long, $now - 8000 * 3600, $now - 86400);
            $this->request($filter, $database, null, [SessionGate::COOKIE => $long], false);
            $this->assertSame(3, $_SESSION['uid'] ?? null, '8000 hours idle with the row clamped to 8760');
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
        $cookies += ['apotheke_consent' => 'v1'];   // cookie consent (Config\Consent)
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
