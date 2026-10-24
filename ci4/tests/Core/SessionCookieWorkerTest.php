<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Filters\SessionCookie;
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
 * to the second. Each test runs in its own process so session_start() works.
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
            $settings = new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null);
            Services::injectMock('settings', $settings);
            $filter = new SessionCookie();

            $this->assertSame(30 * 86400, $this->request($filter), 'first request: default 30 days');
            $this->assertSame([], $settings->save(['session_days' => 7]));
            $this->assertSame(7 * 86400, $this->request($filter), 'second request: 7 days');
            $this->assertSame([], $settings->save(['session_days' => 30]));
            $this->assertSame(30 * 86400, $this->request($filter), 'third request: back to 30 days');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /** One request through the filter; returns the cookie lifetime it set. */
    private function request(SessionCookie $filter): int
    {
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'items'), null, new UserAgent());
        $this->assertNull($filter->before($request));
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $lifetime = session_get_cookie_params()['lifetime'];
        $this->assertSame((string)$lifetime, ini_get('session.gc_maxlifetime'));
        // End of the worker request.
        session_write_close();
        $_SESSION = [];

        return $lifetime;
    }
}
