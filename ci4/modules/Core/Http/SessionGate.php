<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * When a request gets a session (SEC-L2), when it ends, and how a sign-in is
 * written into it. Legacy init_session() and login_session() on main (#84).
 *
 * A request loads a session only when its apothekesid cookie names a session
 * file that still exists, so 404s, branding and other anonymous requests leave
 * nothing on disk. A page that renders a form starts one on demand
 * (SessionToken). The session is released right after it is loaded so
 * parallel requests never queue on its lock.
 *
 * Limits, checked on every load. An ended session is emptied and its file
 * removed, whatever PHP's garbage collector does, so a copied cookie stops
 * working on the server too:
 * - signed in: no request for longer than session_idle_hours (idle), or
 *   signed in longer ago than session_days (absolute, from signed_in_at;
 *   later requests never move it). A session without a sign-in time gets one
 *   on this request and is not ended on it.
 * - not signed in: no request for longer than session_days (SEC-N6).
 *
 * The activity stamp moves at most once a minute. Keys match the legacy
 * session file: last_activity and signed_in_at.
 */
final class SessionGate
{
    public const COOKIE = 'apothekesid';

    /** Unix time of the last request that used the session (legacy last_activity). */
    public const LAST_SEEN = 'last_activity';

    /** Unix time of the sign-in that started this session (legacy signed_in_at). */
    public const SIGNED_IN_AT = 'signed_in_at';

    /** A stamp younger than this is kept, so a busy page does not rewrite the file. */
    public const TOUCH_SECONDS = 60;

    public function __construct(private readonly int $now)
    {
    }

    /** The cookie names a session file that still exists (legacy session_cookie_live()). */
    public function isLive(string $id, string $saveDir): bool
    {
        return preg_match('/^[A-Za-z0-9,-]{22,256}$/D', $id) === 1 && is_file($saveDir . '/sess_' . $id);
    }

    /**
     * Load the session the cookie names: end it when a limit has passed,
     * otherwise record the sign-in time (once), stamp the activity and make
     * sure it has a CSRF token. PHP's session settings must already be in place
     * (SessionCookie). Returns whether a session is loaded.
     */
    public function resume(int $idleSeconds, int $lifetimeSeconds): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            return false;
        }
        if ($this->expired($_SESSION, $idleSeconds, $lifetimeSeconds)) {
            $_SESSION = [];
            session_destroy();

            return false;
        }
        $this->stampSignIn();
        $this->stamp();
        session_write_close();

        return true;
    }

    /**
     * Mark this session as signed in as $userId with the account's current
     * epoch (legacy login_session()). The id changes and the CSRF token is new.
     * A sign-in starts a new signed_in_at. With $keepSignIn, used when the
     * account already in this session is signed in again (sign out everywhere,
     * a new password for your own account), the existing sign-in time is kept,
     * so session_days does not start again. It is never taken over from
     * another account.
     */
    public function signIn(int $userId, string $epoch, bool $keepSignIn = false): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $held = $_SESSION['uid'] ?? null;
        $at = $_SESSION[self::SIGNED_IN_AT] ?? null;
        $same = is_scalar($held) && (int)$held === $userId;
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['auth_epoch'] = $epoch;
        $_SESSION['csrf'] = (new CsrfCheck())->newToken();
        $_SESSION[self::LAST_SEEN] = $this->now;
        $_SESSION[self::SIGNED_IN_AT] = $keepSignIn && $same && is_int($at) && $at > 0 ? $at : $this->now;
        session_write_close();
    }

    /**
     * Stamp the activity (at most once a minute) and give the open session a
     * CSRF token if it has none.
     */
    public function stamp(): void
    {
        $seen = $_SESSION[self::LAST_SEEN] ?? null;
        if (!is_int($seen) || $seen <= 0 || $this->now - $seen >= self::TOUCH_SECONDS) {
            $_SESSION[self::LAST_SEEN] = $this->now;
        }
        if (!is_string($_SESSION['csrf'] ?? null) || $_SESSION['csrf'] === '') {
            $_SESSION['csrf'] = (new CsrfCheck())->newToken();
        }
    }

    /** Record the sign-in time of a signed-in session once (legacy session_stamp_sign_in()). */
    public function stampSignIn(): void
    {
        if (!self::signedIn($_SESSION)) {
            return;
        }
        $at = $_SESSION[self::SIGNED_IN_AT] ?? null;
        if (!is_int($at) || $at <= 0) {
            $_SESSION[self::SIGNED_IN_AT] = $this->now;
        }
    }

    /** @param array<array-key,mixed> $session */
    public function expired(array $session, int $idleSeconds, int $lifetimeSeconds): bool
    {
        $seen = $session[self::LAST_SEEN] ?? null;
        $seen = is_int($seen) && $seen > 0 ? $seen : null;
        if (!self::signedIn($session)) {
            return $seen !== null && $this->now - $seen > $lifetimeSeconds;
        }
        if ($seen !== null && $this->now - $seen > $idleSeconds) {
            return true;
        }
        $at = $session[self::SIGNED_IN_AT] ?? null;

        return is_int($at) && $at > 0 && $this->now - $at > $lifetimeSeconds;
    }

    /** @param array<array-key,mixed> $session */
    private static function signedIn(array $session): bool
    {
        $uid = $session['uid'] ?? null;

        return is_scalar($uid) && (int)$uid > 0;
    }
}
