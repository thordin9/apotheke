<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * Legacy's path for a REQUEST_URI: rawurldecode() of its path, once. parse_url() rejects some
 * targets, such as /stats:1 or ///x (a colon and digits, or three slashes); their path is then
 * the target up to the first "?" or "#", so such a request matches no route and is a 404 under
 * the same handling as any other path, never "/". Used before the framework boots
 * (public/index.php), so it needs nothing else.
 */
final class RequestPath
{
    public static function of(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        if ($path === false) {
            $path = preg_split('/[?#]/', $requestUri, 2)[0];
        }

        return rawurldecode((string)($path ?: '/'));
    }
}
