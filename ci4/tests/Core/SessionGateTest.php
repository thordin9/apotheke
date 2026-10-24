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
 * a form page starts one on demand. A signed-in session ends after
 * session_idle_hours idle or session_days after sign-in (main #84); any other
 * session after session_days idle (SEC-N6). Re-signing keeps the sign-in time
 * only when asked (main #84 L-1). Each test runs in its own process so
 * session_start() works.
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

    public function testAnAnonymousSessionEndsAfterTheLifetimeIdle(): void
    {
        $gate = new SessionGate(self::NOW);
        $this->assertFalse($gate->expired([], 10, 60), 'never stamped');
        $this->assertFalse($gate->expired([SessionGate::LAST_SEEN => self::NOW - 60], 10, 60), 'exactly the lifetime');
        $this->assertTrue($gate->expired([SessionGate::LAST_SEEN => self::NOW - 61], 10, 60));
        $this->assertFalse($gate->expired([SessionGate::LAST_SEEN => (string)(self::NOW - 61)], 10, 60), 'not a stamp');
        $this->assertFalse($gate->expired([SessionGate::LAST_SEEN => self::NOW - 30, 'uid' => 0], 10, 60), 'uid 0 is not signed in');
    }

    public function testASignedInSessionEndsAfterTheIdleLimit(): void
    {
        $gate = new SessionGate(self::NOW);
        $in = ['uid' => 7, SessionGate::SIGNED_IN_AT => self::NOW - 5];
        $this->assertFalse($gate->expired($in, 10, 60), 'never stamped: this request is the first one recorded');
        $this->assertFalse($gate->expired([SessionGate::LAST_SEEN => self::NOW - 10] + $in, 10, 60), 'exactly the idle limit');
        $this->assertTrue($gate->expired([SessionGate::LAST_SEEN => self::NOW - 11] + $in, 10, 60));
        $this->assertTrue($gate->expired([SessionGate::LAST_SEEN => self::NOW - 11, 'uid' => '7'], 10, 60), 'a string uid is signed in too');
        $this->assertFalse($gate->expired([SessionGate::LAST_SEEN => (string)(self::NOW - 11)] + $in, 10, 60), 'not a stamp');
    }

    public function testASignedInSessionEndsSessionDaysAfterSignIn(): void
    {
        $gate = new SessionGate(self::NOW);
        $seen = [SessionGate::LAST_SEEN => self::NOW - 1, 'uid' => 7];
        $this->assertFalse($gate->expired($seen, 10, 60), 'no sign-in time yet: not ended, stamped on this request');
        $this->assertFalse($gate->expired([SessionGate::SIGNED_IN_AT => self::NOW - 60] + $seen, 10, 60), 'exactly session_days');
        $this->assertTrue($gate->expired([SessionGate::SIGNED_IN_AT => self::NOW - 61] + $seen, 10, 60), 'recent activity does not extend it');
        $this->assertFalse($gate->expired([SessionGate::SIGNED_IN_AT => self::NOW - 61, SessionGate::LAST_SEEN => self::NOW - 1], 10, 60), 'not signed in: only the idle lifetime');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testResumeStampsTheSessionAndReleasesIt(): void
    {
        $id = $this->session(['csrf' => '', 'uid' => 7]);
        $this->assertTrue((new SessionGate(self::NOW))->resume(3600, 86400));
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'released for parallel requests');
        $this->assertSame(self::NOW, $_SESSION[SessionGate::LAST_SEEN]);
        $this->assertSame(self::NOW, $_SESSION[SessionGate::SIGNED_IN_AT], 'a session from before the check gets its sign-in time');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $_SESSION['csrf']);
        $this->assertSame(7, $_SESSION['uid']);
        $stored = (string)file_get_contents($this->dir . '/sess_' . $id);
        $this->assertStringContainsString('last_activity|i:' . self::NOW, $stored);
        $this->assertStringContainsString('signed_in_at|i:' . self::NOW, $stored);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheStampsMoveAtMostOnceAMinuteAndTheSignInTimeNever(): void
    {
        $this->session(['csrf' => str_repeat('a', 64), 'uid' => 7, SessionGate::LAST_SEEN => self::NOW - 59, SessionGate::SIGNED_IN_AT => self::NOW - 500]);
        $this->assertTrue((new SessionGate(self::NOW))->resume(3600, 86400));
        $this->assertSame([self::NOW - 59, self::NOW - 500], [$_SESSION[SessionGate::LAST_SEEN], $_SESSION[SessionGate::SIGNED_IN_AT]], 'under a minute: kept');
        session_id(str_repeat('c', 32));
        $this->assertTrue((new SessionGate(self::NOW + 1))->resume(3600, 86400));
        $this->assertSame([self::NOW + 1, self::NOW - 500], [$_SESSION[SessionGate::LAST_SEEN], $_SESSION[SessionGate::SIGNED_IN_AT]], 'a minute: moved; the sign-in time stays');
        $this->assertSame(str_repeat('a', 64), $_SESSION['csrf']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASessionPastSessionDaysIsEndedThoughInUse(): void
    {
        $id = $this->session(['csrf' => str_repeat('a', 64), 'uid' => 7, SessionGate::LAST_SEEN => self::NOW - 5, SessionGate::SIGNED_IN_AT => self::NOW - 86401]);
        $this->assertFalse((new SessionGate(self::NOW))->resume(3600, 86400));
        $this->assertSame([], $_SESSION);
        $this->assertFileDoesNotExist($this->dir . '/sess_' . $id);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASignedInSessionIdleLongerThanTheIdleLimitIsEnded(): void
    {
        $id = $this->session(['csrf' => str_repeat('a', 64), 'uid' => 7, SessionGate::LAST_SEEN => self::NOW - 3601, SessionGate::SIGNED_IN_AT => self::NOW - 4000]);
        $this->assertFalse((new SessionGate(self::NOW))->resume(3600, 86400));
        $this->assertSame([], $_SESSION);
        $this->assertFileDoesNotExist($this->dir . '/sess_' . $id);
    }

    /**
     * Main #84 L-1 and #87: a sign-in starts a new signed_in_at. Signing the
     * same account in again with keepSignIn (sign out everywhere, a new password
     * for your own account) keeps it, so session_days does not start again.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSigningInAgainKeepsTheSignInTimeOnlyWhenAsked(): void
    {
        $old = $this->session(['csrf' => str_repeat('a', 64), 'uid' => 7, 'auth_epoch' => 'old', SessionGate::LAST_SEEN => self::NOW - 100, SessionGate::SIGNED_IN_AT => self::NOW - 5000]);
        (new SessionGate(self::NOW))->signIn(7, 'new-epoch', true);
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertNotSame($old, session_id(), 'a new session id');
        $this->assertFileDoesNotExist($this->dir . '/sess_' . $old);
        $this->assertSame([7, 'new-epoch', self::NOW, self::NOW - 5000], [$_SESSION['uid'], $_SESSION['auth_epoch'], $_SESSION[SessionGate::LAST_SEEN], $_SESSION[SessionGate::SIGNED_IN_AT]]);
        $this->assertNotSame(str_repeat('a', 64), $_SESSION['csrf'], 'a new CSRF token');
        $kept = session_id();
        $this->assertStringContainsString('signed_in_at|i:' . (self::NOW - 5000), (string)file_get_contents($this->dir . '/sess_' . $kept));
        $this->assertFalse((new SessionGate(self::NOW))->expired($_SESSION, 3600, 5000), 'kept: counted from the real sign-in');
        $this->assertTrue((new SessionGate(self::NOW))->expired($_SESSION, 3600, 4999), 'kept: ends session_days after the real sign-in');

        session_id($kept);
        (new SessionGate(self::NOW + 10))->signIn(7, 'newer');
        $this->assertSame(self::NOW + 10, $_SESSION[SessionGate::SIGNED_IN_AT], 'a sign-in without keepSignIn starts again');

        session_id(session_id());
        (new SessionGate(self::NOW + 20))->signIn(8, 'other', true);
        $this->assertSame([8, self::NOW + 20], [$_SESSION['uid'], $_SESSION[SessionGate::SIGNED_IN_AT]], 'never taken over from another account');
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnIdleAnonymousSessionIsEmptiedAndRemoved(): void
    {
        $id = $this->session([SessionGate::LAST_SEEN => self::NOW - 3601, 'csrf' => str_repeat('b', 64)]);
        $this->assertFalse((new SessionGate(self::NOW))->resume(60, 3600));
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
