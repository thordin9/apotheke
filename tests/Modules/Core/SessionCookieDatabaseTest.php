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
