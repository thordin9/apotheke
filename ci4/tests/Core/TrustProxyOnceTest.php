<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DsnTranslator;
use Apotheke\Core\Filters\SessionCookie;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SQLite3;

/**
 * SEC-N11: trust_proxy is read once per request, by Config\App, on one
 * connection. The session filter reuses that value instead of reading again.
 */
final class TrustProxyOnceTest extends CIUnitTestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $group = (new DsnTranslator())->translate(ApothekeConfig::fromEnvironment()->database());
        $this->assertSame('SQLite3', $group['DBDriver']);
        $this->path = (string)$group['database'];
        // Another test may have created the default database: keep it aside.
        if (is_file($this->path)) {
            rename($this->path, $this->path . '.trust-once');
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        if (is_file($this->path . '.trust-once')) {
            rename($this->path . '.trust-once', $this->path);
        }
        Factories::reset('config');
        parent::tearDown();
    }

    public function testAppReadsTrustProxyOnce(): void
    {
        $this->seed('true');
        $this->assertTrue((new App())->trustProxy);
        $this->seed('false');
        $this->assertFalse((new App())->trustProxy);
    }

    public function testTheSessionFilterReusesTheAppValueWithoutAnotherRead(): void
    {
        $this->seed('true');
        $app = new App();
        $this->assertTrue($app->trustProxy);
        Factories::injectMock('config', App::class, $app);
        // With the database gone, any second read would answer "not trusted".
        unlink($this->path);
        $filter = new SessionCookie();
        $this->assertTrue($filter->secureCookie($this->request($app, ['HTTP_X_FORWARDED_PROTO' => 'https'])));
        $this->assertFalse($filter->secureCookie($this->request($app, ['HTTP_X_FORWARDED_PROTO' => 'http'])));

        $off = new App();
        $this->assertFalse($off->trustProxy);
        $this->seed('true');
        Factories::injectMock('config', App::class, $off);
        $this->assertFalse($filter->secureCookie($this->request($off, ['HTTP_X_FORWARDED_PROTO' => 'https'])));
    }

    public function testConfigAppIsTheOnlyTrustProxyReader(): void
    {
        $root = dirname(__DIR__, 2);
        $readers = [];
        foreach (['app', 'modules'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $name = (string)$file;
                if (!str_ends_with($name, '.php') || str_ends_with($name, '/TrustProxyReader.php')) {
                    continue;
                }
                $count = substr_count((string)file_get_contents($name), 'TrustProxyReader(');
                if ($count > 0) {
                    $readers[substr($name, strlen($root) + 1)] = $count;
                }
            }
        }
        $this->assertSame(['app/Config/App.php' => 1], $readers);
    }

    private function seed(string $value): void
    {
        $db = new SQLite3($this->path);
        $db->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $db->exec("DELETE FROM settings WHERE name = 'trust_proxy'");
        $db->exec("INSERT INTO settings (name, value, updated_at) VALUES ('trust_proxy', '" . $value . "', '2026-01-01')");
        $db->close();
    }

    /** @param array<string,string> $server */
    private function request(App $app, array $server): IncomingRequest
    {
        $request = new IncomingRequest($app, new SiteURI($app, 'items'), null, new UserAgent());
        $request->setGlobal('server', $server);

        return $request;
    }
}
