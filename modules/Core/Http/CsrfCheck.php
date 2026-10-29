<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * Port of legacy check_csrf(): the token lives in the session ($_SESSION['csrf'])
 * and comes back in the `csrf` form field or the X-CSRF-Token header. The field
 * wins when it is present, as in legacy (`$_POST['csrf'] ?? header`).
 */
final class CsrfCheck
{
    public const STATUS = 419;

    /** CI4 needs a reason phrase for 419, which it does not know. */
    public const REASON = 'Page Expired';

    public const MESSAGE = 'Your session expired. Reload the page and try again.';

    /** Methods that never change state (RFC 9110 safe methods). */
    private const SAFE = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function applies(string $method): bool
    {
        return !in_array(strtoupper($method), self::SAFE, true);
    }

    /** @param array<array-key,mixed> $post */
    public function passes(string $sessionToken, array $post, string $header): bool
    {
        $sent = isset($post['csrf']) ? $post['csrf'] : $header;
        if (!is_string($sent)) {
            return false;
        }

        return $sessionToken !== '' && hash_equals($sessionToken, $sent);
    }

    /** A new token, as legacy init_session() makes one. */
    public function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function jsonBody(): string
    {
        $json = json_encode(['ok' => false, 'error' => self::MESSAGE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"ok":false,"error":"Your session expired."}';
    }
}
