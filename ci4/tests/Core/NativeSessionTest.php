<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\NativeSession;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * #76 L-1: CI4's session service never starts a session of its own. #82 N-3:
 * it is read-only. #82 L-2: close() write-closes a session left open.
 */
final class NativeSessionTest extends CIUnitTestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSessionServiceNeverStartsASession(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-native-session-' . getmypid();
        @mkdir($dir, 0775, true);
        try {
            config(\Config\Session::class)->savePath = $dir;
            Services::resetSingle('session');
            $_SESSION = ['csrf' => 'kept'];
            $session = session();
            $this->assertInstanceOf(NativeSession::class, $session);
            $this->assertSame($session, Services::session(), 'shared per request');
            $fresh = Services::session(null, false);
            $this->assertInstanceOf(NativeSession::class, $fresh);
            $this->assertSame($fresh, $fresh->start());
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
            // What storePreviousURL() does on a text/html answer: nothing is stored.
            $session->set('_ci_previous_url', 'http://localhost/x');
            $this->assertSame('kept', $session->get('csrf'));
            $this->assertSame(['csrf' => 'kept'], $_SESSION);
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
            $this->assertSame([], glob($dir . '/*') ?: [], 'no handler file');
            $session->close();
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        } finally {
            @rmdir($dir);
            Services::reset();
        }
    }

    /** #82 N-3: every data change is a no-op; reads still work. */
    public function testTheSessionDataCannotBeChanged(): void
    {
        $_SESSION = ['uid' => 7, 'auth_epoch' => 'e', 'csrf' => 'tok'];
        $before = $_SESSION;
        $session = new NativeSession();
        $session->set('uid', 1);
        $session->set(['auth_epoch' => 'x', 'csrf' => 'y']);
        $session->set(['new-key']);
        $session->push('csrf', ['z']);
        $session->remove('uid');
        $session->remove(['auth_epoch', 'csrf']);
        $session->uid = 2;
        $session->setFlashdata('note', 'hi');
        $session->keepFlashdata('csrf');
        $session->setTempdata('temp', 'v', 60);
        $session->markAsTempdata('csrf');
        $session->removeTempdata('csrf');
        $session->unmarkFlashdata('csrf');
        $session->unmarkTempdata('csrf');
        $this->assertSame($before, $_SESSION, 'nothing changed');
        $this->assertSame([7, 'e', 'tok'], [$session->get('uid'), $session->get('auth_epoch'), $session->csrf]);
        $this->assertTrue($session->has('csrf'));
        $this->assertSame([[], null, [], null], [$session->getFlashdata(), $session->getFlashdata('note'), $session->getTempdata(), $session->getTempdata('temp')]);
        $this->assertSame($before, $_SESSION, 'reading changed nothing either');
        $_SESSION = [];
    }

    /** #82 L-2: a session a request left open is write-closed. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCloseWritesAnOpenSession(): void
    {
        $dir = sys_get_temp_dir() . '/apotheke-native-close-' . getmypid();
        @mkdir($dir, 0775, true);
        try {
            ini_set('session.use_cookies', '0');
            session_save_path($dir);
            session_id(str_repeat('d', 26));
            session_start();
            $_SESSION['uid'] = 9;
            (new NativeSession())->close();
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
            $this->assertStringContainsString('uid|i:9', (string)file_get_contents($dir . '/sess_' . str_repeat('d', 26)));
            (new NativeSession())->close();
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status(), 'nothing open: no-op');
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            @rmdir($dir);
        }
    }

    public function testRegenerateAndDestroyRefuse(): void
    {
        $session = new NativeSession();
        try {
            $session->regenerate();
            $this->fail('regenerate() must refuse');
        } catch (LogicException) {
        }
        $this->expectException(LogicException::class);
        $session->destroy();
    }
}
