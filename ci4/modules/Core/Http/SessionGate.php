<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * When a request gets a session (SEC-L2) and when an idle one ends (SEC-N6).
 *
 * Legacy init_session() on main: a request loads a session only when its
 * apothekesid cookie names a session file that still exists, so 404s, branding
 * and other anonymous requests leave nothing on disk. A page that renders a form
 * starts one on demand (SessionToken). The session is released right after it
 * is loaded so parallel requests never queue on its lock.
 *
 * Idle limit: a session not used for longer than its lifetime (session_days)
 * is emptied and its file removed, whatever PHP's garbage collector does, so a
 * copied cookie stops working on the server too.
 */
final class SessionGate
{
    public const COOKIE = 'apothekesid';

    /** Unix time of the last request that used the session. */
    public const LAST_SEEN = 'last_seen';

    public function __construct(private readonly int $now)
    {
    }

    /** The cookie names a session file that still exists (legacy session_cookie_live()). */
    public function isLive(string $id, string $saveDir): bool
    {
        return preg_match('/^[A-Za-z0-9,-]{22,256}$/D', $id) === 1 && is_file($saveDir . '/sess_' . $id);
    }

    /**
     * Load the session the cookie names: end it when idle too long, otherwise
     * stamp last_seen and make sure it has a CSRF token. PHP's session settings
     * must already be in place (SessionCookie). Returns whether a session is loaded.
     */
    public function resume(int $idleSeconds): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            return false;
        }
        if ($this->expired($_SESSION, $idleSeconds)) {
            $_SESSION = [];
            session_destroy();

            return false;
        }
        $this->stamp();
        session_write_close();

        return true;
    }

    /** Stamp last_seen and give the open session a CSRF token if it has none. */
    public function stamp(): void
    {
        $_SESSION[self::LAST_SEEN] = $this->now;
        if (!is_string($_SESSION['csrf'] ?? null) || $_SESSION['csrf'] === '') {
            $_SESSION['csrf'] = (new CsrfCheck())->newToken();
        }
    }

    /** @param array<array-key,mixed> $session */
    public function expired(array $session, int $idleSeconds): bool
    {
        $seen = $session[self::LAST_SEEN] ?? null;

        return is_int($seen) && $this->now - $seen > $idleSeconds;
    }
}
