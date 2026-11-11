<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Filters\SessionCookie;
use Apotheke\Core\Http\SessionGate;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Storage\LocalStorage;
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
 * When DB_DRIVER=d1, SessionCookie stores and loads sessions through the
 * database handler, never a sess_* file.
 */
final class SessionCookieDatabaseTest extends CIUnitTestCase
{
    private const ID = 'cccccccccccccccccccccccccc';

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testADatabaseSessionIsLoadedAndNotWrittenToDisk(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $now = time();
            $database->db->query(
                'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
                [hash('sha256', self::ID), 'uid|i:3;auth_epoch|s:16:"0123456789abcdef";csrf|s:4:"tok1";last_activity|i:' . $now . ';', $now + 86400, '', 0],
            );
            $filter = new SessionCookie();
            $this->request($filter, $database, [SessionGate::COOKIE => self::ID], false);
            $this->assertSame(3, $_SESSION['uid'] ?? null);
            $this->assertSame('tok1', $_SESSION['csrf'] ?? null);
            $sessions = $database->dataDir() . '/sessions';
            $this->assertFileDoesNotExist($sessions . '/sess_' . self::ID);
            $this->assertFalse(is_dir($sessions) && scandir($sessions) !== ['.', '..'] && glob($sessions . '/sess_*') !== []);
            $this->assertSame([], glob($sessions . '/sess_*') ?: []);
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnExpiredDatabaseSessionIsNotLoaded(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $database->db->query(
                'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
                [hash('sha256', self::ID), 'uid|i:3;last_activity|i:' . time() . ';', time() - 10, '', 0],
            );
            $filter = new SessionCookie();
            $this->request($filter, $database, [SessionGate::COOKIE => self::ID]);
            $this->assertSame([], $_SESSION);
            $row = $database->db->query('SELECT id FROM sessions WHERE id = ?', [hash('sha256', self::ID)])->getRowArray();
            $this->assertNotNull($row, 'expiry is a live-check; gc, not the filter, deletes the row');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSqliteKeepsTheFileHandler(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $save = $database->config->dataDir() . '/sessions';
            mkdir($save, 0775, true);
            file_put_contents($save . '/sess_' . self::ID, 'uid|i:3;auth_epoch|s:16:"0123456789abcdef";csrf|s:4:"tok1";last_activity|i:' . time() . ';');
            $filter = new SessionCookie();
            Services::reset();
            Services::injectMock('settings', $this->settings($database));
            Services::injectMock('apothekeConfig', $database->config);
            $config = config(App::class);
            $request = new IncomingRequest($config, new SiteURI($config, 'items'), null, new UserAgent());
            $cookies = [SessionGate::COOKIE => self::ID, 'apotheke_consent' => 'v1'];
            $request->setGlobal('cookie', $cookies);
            $_COOKIE = $cookies;
            $this->assertNull($filter->before($request));
            $this->assertSame(3, $_SESSION['uid'] ?? null);
            $rows = $database->db->query('SELECT id FROM sessions')->getResultArray();
            $this->assertSame([], $rows, 'PDO sqlite must not use the sessions table');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    /**
     * L-1894: another request claims the row while this one holds the session.
     * Its write stores nothing, session_write_close() returns normally and the
     * session is closed, and the next request in the same worker loads the
     * other request's payload.
     */
    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASupersededWriteClosesTheSessionAndTheNextRequestWorks(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $this->seedSession($database, 3);
            $filter = new SessionCookie();
            $this->request($filter, $database, [SessionGate::COOKIE => self::ID], false);
            $this->assertSame(3, $_SESSION['uid'] ?? null);

            $this->assertTrue(session_start());
            $_SESSION['uid'] = 4;
            $this->supersede($database, 5);
            $this->assertTrue(session_write_close());
            $this->assertSame(PHP_SESSION_NONE, session_status());
            $this->assertSame(['other-claim', 5], $this->rowState($database), 'the newer payload is kept');

            $database->db->query('UPDATE sessions SET lock_token = ?, locked_until = ? WHERE id = ?', ['', 0, hash('sha256', self::ID)]);
            $this->request($filter, $database, [SessionGate::COOKIE => self::ID], false);
            $this->assertSame(5, $_SESSION['uid'] ?? null, 'next request: the newer payload');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    /**
     * L-1894: a request left its session open and another request has since
     * claimed the row. The next request's leftover close stores nothing and
     * does not throw, and that request starts with an empty $_SESSION.
     */
    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testALeftoverSupersededSessionIsClosedByTheNextRequest(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $this->seedSession($database, 3);
            $filter = new SessionCookie();
            $this->request($filter, $database, [SessionGate::COOKIE => self::ID], false);

            $this->assertTrue(session_start());
            $_SESSION['uid'] = 4;
            $this->supersede($database, 5);
            $this->assertSame(PHP_SESSION_ACTIVE, session_status(), 'the request ends with its session open');

            $this->request($filter, $database);
            $this->assertSame(['other-claim', 5], $this->rowState($database), 'the newer payload is kept');
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    private function seedSession(TestDatabase $database, int $uid): void
    {
        $now = time();
        $database->db->query(
            'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
            [hash('sha256', self::ID), 'uid|i:' . $uid . ';last_activity|i:' . $now . ';', $now + 86400, '', 0],
        );
    }

    /** Another request claims the row and stores its own payload. */
    private function supersede(TestDatabase $database, int $uid): void
    {
        $database->db->query(
            'UPDATE sessions SET data = ?, lock_token = ?, locked_until = ? WHERE id = ?',
            ['uid|i:' . $uid . ';last_activity|i:' . time() . ';', 'other-claim', time() + 60, hash('sha256', self::ID)],
        );
    }

    /** @return array{0:string,1:int|null} the row's lock token and stored uid */
    private function rowState(TestDatabase $database): array
    {
        $row = $database->db->query('SELECT data, lock_token FROM sessions WHERE id = ?', [hash('sha256', self::ID)])->getRowArray();
        $uid = preg_match('/^uid\|i:(\d+);/', (string)($row['data'] ?? ''), $m) === 1 ? (int)$m[1] : null;

        return [(string)($row['lock_token'] ?? ''), $uid];
    }

    /** @param array<string,string> $cookies */
    private function request(SessionCookie $filter, TestDatabase $database, array $cookies = [], bool $noSession = true): void
    {
        Services::reset();
        Services::injectMock('settings', $this->settings($database));
        $hosted = ApothekeConfig::load(ApothekeConfig::repoRoot(), null, [
            'DATA_DIR' => $database->dataDir(),
            'DB_DRIVER' => 'd1',
            'STORAGE_BACKEND' => 'r2',
            'SEED_DEFAULTS' => '0',
        ]);
        Services::injectMock('apothekeConfig', $hosted);
        Services::injectMock('sqlExecutor', new LocalBatchExecutor($database->db));
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'items'), null, new UserAgent());
        $cookies += ['apotheke_consent' => 'v1'];
        $request->setGlobal('cookie', $cookies);
        $_COOKIE = $cookies;
        $this->assertNull($filter->before($request));
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        if ($noSession) {
            $this->assertSame([], $_SESSION);
        }
    }

    private function settings(TestDatabase $database): SettingsService
    {
        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($database->dataDir())), new Clock(), $database->config->values(), static fn (string $m): null => null);
    }
}
