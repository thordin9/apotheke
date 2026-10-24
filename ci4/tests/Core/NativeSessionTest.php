<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Http\NativeSession;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/** #76 L-1: CI4's session service never starts a session of its own. */
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
            // What storePreviousURL() does on a text/html answer.
            $session->set('_ci_previous_url', 'http://localhost/x');
            $this->assertSame('kept', $session->get('csrf'));
            $this->assertSame('http://localhost/x', $_SESSION['_ci_previous_url'] ?? null);
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
            $this->assertSame([], glob($dir . '/*') ?: [], 'no handler file');
            $session->close();
            $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());
        } finally {
            @rmdir($dir);
            Services::reset();
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
