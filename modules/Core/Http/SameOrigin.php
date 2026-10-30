<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * With sign-in off, a state-changing request must come from the app's own pages
 * (SEC-M3). Port of legacy method_is_safe() and request_same_origin().
 */
final class SameOrigin
{
    public const STATUS = 403;

    public const MESSAGE = 'This request came from another site and was refused. Open the app directly and try again.';

    /** GET, HEAD and OPTIONS never change anything. */
    public function isSafe(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * Sec-Fetch-Site decides when the browser sends it (same-origin, or none for
     * a typed or bookmarked address). Otherwise Origin must be this request's
     * scheme and host, or PUBLIC_URL's origin. Without either header (curl,
     * scripts, old clients) the request is allowed.
     *
     * @param array<string,mixed> $server
     */
    public function check(array $server, bool $https, string $publicUrl = ''): bool
    {
        $site = strtolower(trim((string)($server['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($site !== '') {
            return $site === 'same-origin' || $site === 'none';
        }
        $origin = strtolower(trim((string)($server['HTTP_ORIGIN'] ?? '')));
        if ($origin === '') {
            return true;
        }

        return in_array(self::normalise($origin), $this->ownOrigins($server, $https, $publicUrl), true);
    }

    /**
     * An origin for comparison: lower case, no trailing '/', and one trailing
     * dot of the host dropped (`example.test.` is `example.test`, QA L-1140).
     */
    public static function normalise(string $origin): string
    {
        $origin = rtrim(strtolower(trim($origin)), '/');

        return (string)preg_replace('#^([a-z][a-z0-9+.-]*://[^/:\[\]]+)\.(:\d+)?$#', '$1$2', $origin);
    }

    /**
     * The app's own origins, normalised (self::normalise()): this request's
     * scheme and host, and PUBLIC_URL's origin.
     *
     * @param array<string,mixed> $server
     * @return list<string>
     */
    public function ownOrigins(array $server, bool $https, string $publicUrl = ''): array
    {
        $own = [self::normalise(($https ? 'https' : 'http') . '://' . (string)($server['HTTP_HOST'] ?? ''))];
        $p = $publicUrl !== '' ? parse_url($publicUrl) : false;
        if (is_array($p) && isset($p['scheme'], $p['host'])) {
            $host = str_contains($p['host'], ':') && $p['host'][0] !== '[' ? '[' . $p['host'] . ']' : $p['host'];
            $own[] = self::normalise($p['scheme'] . '://' . $host . (isset($p['port']) ? ':' . $p['port'] : ''));
        }

        return $own;
    }

    public function jsonBody(): string
    {
        $json = json_encode(['ok' => false, 'error' => self::MESSAGE], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($json) ? $json : '{"ok":false}';
    }
}
