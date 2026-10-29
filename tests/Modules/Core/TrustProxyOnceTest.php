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
use FilesystemIterator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\TestDatabase;

/**
 * SEC-N11 and #74 N-2: trust_proxy is read through the request's
 * SettingsService, on CI4's connection, together with session_days and
 * public_url. Nothing opens a separate settings connection per request.
 */
final class TrustProxyOnceTest extends CIUnitTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSecureFlagFollowsTheRequestSettings(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $settings = $this->settings($database);
            $this->assertFalse($this->secure($settings, ['HTTP_X_FORWARDED_PROTO' => 'https']), 'proxy not trusted');
            $this->assertSame([], $settings->save(['trust_proxy' => true]));
            $this->assertTrue($this->secure($settings, ['HTTP_X_FORWARDED_PROTO' => 'https']));
            $this->assertFalse($this->secure($settings, ['HTTP_X_FORWARDED_PROTO' => 'http']));
            $this->assertSame([], $settings->save(['trust_proxy' => false, 'public_url' => 'https://inv.example.org']));
            // Legacy session_cookie_secure(): PUBLIC_URL's https host keeps Secure behind an untrusted proxy.
            $this->assertTrue($this->secure($settings, ['HTTP_HOST' => 'INV.example.org:443']));
            $this->assertFalse($this->secure($settings, ['HTTP_HOST' => '192.168.1.10:9091']), 'opened by address over HTTP');
            $this->assertTrue($this->secure($settings, ['HTTP_HOST' => '192.168.1.10:9091', 'HTTPS' => 'on']));
        } finally {
            Services::reset();
            $database->drop();
        }
    }

    public function testNothingOpensASeparateSettingsConnection(): void
    {
        $this->assertFalse((new ReflectionClass(App::class))->hasProperty('trustProxy'));
        $root = dirname(__DIR__, 3);
        $hits = [];
        foreach (['app', 'modules'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $name = (string)$file;
                // The migration runner opens its own PDO once per process, before CI4 connects.
                if (!str_ends_with($name, '.php') || str_ends_with($name, '/PdoMigrationRunner.php')) {
                    continue;
                }
                if (preg_match('/\\bpg_connect\\s*\\(|\\bnew\\s+\\\\?(?:PDO|SQLite3)\\s*\\(|TrustProxyReader/', $this->code($name), $m) === 1) {
                    $hits[] = substr($name, strlen($root) + 1) . ': ' . $m[0];
                }
            }
        }
        $this->assertSame([], $hits);
    }

    /** The file without comments, so a docblock that names a function does not count. */
    private function code(string $file): string
    {
        $out = '';
        foreach (token_get_all((string)file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    private function settings(TestDatabase $database): SettingsService
    {
        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null);
    }

    /**
     * One request through the session filter with this SettingsService.
     *
     * @param array<string,string> $server
     */
    private function secure(SettingsService $settings, array $server): bool
    {
        Services::reset();
        Services::injectMock('settings', $settings);
        $_SERVER = $server + ['REQUEST_METHOD' => 'GET'];
        $config = config(App::class);
        $request = new IncomingRequest($config, new SiteURI($config, 'items'), null, new UserAgent());
        $this->assertNull((new SessionCookie())->before($request));
        $secure = session_get_cookie_params()['secure'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        return $secure;
    }
}
