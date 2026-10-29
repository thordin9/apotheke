<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\Session\Session;
use LogicException;

/**
 * CI4's `session` service over the native apothekesid session (#76 L-1).
 *
 * SessionCookie and SessionToken own the session: they load it only for a
 * live cookie (SEC-L2), end it when a limit has passed and release it right
 * after loading. CI4's own Session would start a second, handler-backed
 * session for the same id whenever it is asked for one, for example by
 * storePreviousURL() on every text/html response, and its timed id change
 * would end the user's native session. This one never starts a session and
 * is read-only (#82 N-3):
 * - get(), has(), __get() and the getters read this request's $_SESSION.
 * - set(), push(), remove(), __set() and the flash and temp data methods
 *   change nothing (a no-op, so storePreviousURL() keeps working). The
 *   session's account, epoch and CSRF token change only through SessionGate.
 * - regenerate() and destroy() throw: changing the id or ending the session
 *   belongs to the sign-in code (SessionGate).
 * - close() write-closes a session a request left open, as CI4's own
 *   Session::close() and the end of a normal PHP request do (#82 L-2).
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
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** @param array<array-key,mixed>|list<string>|string $data */
    public function set($data, $value = null): void
    {
    }

    /** @param array<array-key,mixed> $data */
    public function push(string $key, array $data): void
    {
    }

    /** @param list<string>|string $key */
    public function remove($key): void
    {
    }

    public function __set(string $key, $value): void
    {
    }

    /** No flash data: nothing can be stored. */
    public function getFlashdata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    /** @param list<string>|string $key */
    public function markAsFlashdata($key): bool
    {
        return false;
    }

    /** @param list<string>|string $key */
    public function unmarkFlashdata($key): void
    {
    }

    /** No temp data: nothing can be stored. */
    public function getTempdata(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    public function removeTempdata(string $key): void
    {
    }

    /** @param array<string,int>|list<string>|string $key */
    public function markAsTempdata($key, int $ttl = 300): bool
    {
        return false;
    }

    /** @param list<string>|string $key */
    public function unmarkTempdata($key): void
    {
    }
}
