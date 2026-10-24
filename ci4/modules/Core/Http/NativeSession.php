<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\Session\Session;
use LogicException;

/**
 * CI4's `session` service over the native apothekesid session (#76 L-1).
 *
 * SessionCookie and SessionToken own the session: they load it only for a
 * live cookie (SEC-L2), end it when idle (SEC-N6) and release it right after
 * loading. CI4's own Session would start a second, handler-backed session for
 * the same id whenever it is asked for one, for example by storePreviousURL()
 * on every text/html response, and its timed id change would end the user's
 * native session. This one never starts, changes or ends a session: get/set
 * work on this request's $_SESSION in memory, which is never written back, as
 * legacy keeps no previous URL either. Changing the id belongs to the sign-in
 * code (SessionGate), so regenerate() and destroy() refuse loudly.
 */
final class NativeSession extends Session
{
    public function __construct()
    {
        // No handler and no cookie: nothing here talks to PHP's session API.
        helper('array');
    }

    public function start(): static
    {
        return $this;
    }

    public function regenerate(bool $destroy = false): never
    {
        throw new LogicException('The native session is changed through SessionGate, not CI4\'s Session.');
    }

    public function destroy(): never
    {
        throw new LogicException('The native session is ended through SessionGate, not CI4\'s Session.');
    }

    public function close(): void
    {
    }
}
