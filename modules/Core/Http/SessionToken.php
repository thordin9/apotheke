<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * The session's CSRF token (legacy csrf_token($create) on main, SEC-L2). A page
 * that renders a form calls this before any output, which starts the session on
 * demand with the settings SessionCookie put in place. With $create false
 * (error pages), or once output has started, it never starts one.
 */
final class SessionToken
{
    public function __construct(private readonly int $now)
    {
    }

    public function get(bool $create = true): string
    {
        $token = $_SESSION['csrf'] ?? '';
        if (is_string($token) && $token !== '') {
            return $token;
        }
        if (!$create || headers_sent()) {
            return '';
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        (new SessionGate($this->now))->stamp();
        session_write_close();
        $token = $_SESSION['csrf'] ?? '';

        return is_string($token) ? $token : '';
    }
}
