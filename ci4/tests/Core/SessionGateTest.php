<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\SessionGate;
use Apotheke\Core\Http\SessionToken;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * SEC-L2: a request loads a session only when its cookie names a live one, and
 * a form page starts one on demand. SEC-N6: an idle session ends after its
 * lifetime. Each test runs in its own process so session_start() works.
 */
final class SessionGateTest extends CIUnitTestCase
{
    private const NOW = 1_800_000_000;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/apotheke-ci4-sess-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    public function testOnlyACookieNamingAnExistingFileIsLive(): void
    {
        $gate = new SessionGate(self::NOW);
        $id = str_repeat('a', 26);
        $this->assertFalse($gate->isLive($id, $this->dir), 'no file');
        touch($this->dir . '/sess_' . $id);
        $this->assertTrue($gate->isLive($id, $this->dir));
        foreach (['short', str_repeat('a', 257), str_repeat('a', 25) . '/', '../' . str_repeat('a', 26), ''] as $bad) {
            $this->assertFalse($gate->isLive($bad, $this->dir), $bad);
        }
    }

    public function testIdleLongerThanTheLimitHasExpired(): void
    {
        $gate = new SessionGate(self::NOW);
        $this->assertFalse($gate->expired([], 60), 'never stamped');
        $this->assertFalse($gate->expired([SessionGate::LAST_SEEN => self::NOW - 60], 60), 'exactly the limit');
        $this->assertTrue($gate->expired([SessionGate::LAST_SEEN => self::NOW - 61], 60));
        $this->assertFalse($gate->expired([SessionGate::LAST_SEEN => (string)(self::NOW - 61)], 60), 'not a stamp');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResumeStampsTheSessionAndReleasesIt(): void
    {
        $id = $this->session(['csrf' => '', 'uid' => 7]);
        $this->assertTrue((new SessionGate(self::NOW))->resume(3600));
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'released for parallel requests');
        $this->assertSame(self::NOW, $_SESSION[SessionGate::LAST_SEEN]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $_SESSION['csrf']);
        $this->assertSame(7, $_SESSION['uid']);
        $this->assertStringContainsString('last_seen|i:' . self::NOW, (string)file_get_contents($this->dir . '/sess_' . $id));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnIdleSessionIsEmptiedAndRemoved(): void
    {
        $id = $this->session([SessionGate::LAST_SEEN => self::NOW - 3601, 'uid' => 7, 'csrf' => str_repeat('b', 64)]);
        $this->assertFalse((new SessionGate(self::NOW))->resume(3600));
        $this->assertSame([], $_SESSION);
        $this->assertFileDoesNotExist($this->dir . '/sess_' . $id);
        $this->assertSame('', (new SessionToken(self::NOW))->get(false), 'nothing left to read');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTokenStartsASessionOnlyWhenAsked(): void
    {
        $this->configure();
        $this->assertSame('', (new SessionToken(self::NOW))->get(false));
        $this->assertSame([], glob($this->dir . '/sess_*'), 'no session file without a form');
        $token = (new SessionToken(self::NOW))->get();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $token);
        $this->assertCount(1, glob($this->dir . '/sess_*') ?: []);
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame(self::NOW, $_SESSION[SessionGate::LAST_SEEN]);
        $this->assertSame($token, (new SessionToken(self::NOW + 5))->get(), 'the same token for the rest of the request');
    }

    private function configure(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '0');
        session_save_path($this->dir);
        session_name(SessionGate::COOKIE);
    }

    /**
     * A stored session with $data, as an earlier request left it. Returns its id.
     *
     * @param array<string,mixed> $data
     */
    private function session(array $data): string
    {
        $this->configure();
        $id = str_repeat('c', 32);
        session_id($id);
        ini_set('session.use_strict_mode', '0');
        session_start();
        $_SESSION = $data;
        session_write_close();
        $_SESSION = [];
        ini_set('session.use_strict_mode', '1');
        session_id($id);

        return $id;
    }
}
