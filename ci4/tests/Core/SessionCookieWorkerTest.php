<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Filters\SessionCookie;
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

    private function settings(TestDatabase $database): SettingsService
    {
        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null);
    }

    /**
     * One worker request through the filter: shared services are reset and the
     * request gets a new SettingsService, as CI4's worker mode does. Returns the
     * cookie lifetime it set.
     */
    private function request(SessionCookie $filter, TestDatabase $database, ?SessionLifetime $shared = null): int
    {
        Services::reset();
        Services::injectMock('settings', $this->settings($database));
        if ($shared !== null) {
            Services::injectMock('sessionLifetime', $shared);
        }
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'items'), null, new UserAgent());
        $this->assertNull($filter->before($request));
        // No session cookie, so no session is started (SEC-L2); the settings are in place for one.
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        $lifetime = session_get_cookie_params()['lifetime'];
        $this->assertSame((string)$lifetime, ini_get('session.gc_maxlifetime'));

        return $lifetime;
    }
}
