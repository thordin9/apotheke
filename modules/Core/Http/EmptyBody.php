<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * An answer without a body has no Content-Type: CI4's default text/html goes, and so does PHP's own
 * default_mimetype for this request. A streamed answer or one with a body is left as it is.
 */
final class EmptyBody
{
    public static function untype(ResponseInterface $response): void
    {
        if ($response->getBody() !== '' && $response->getBody() !== null) {
            return;
        }
        if ($response->getStatusCode() !== 204 && $response->getStatusCode() !== 304 && $response->getStatusCode() < 400) {
            return;
        }
        $response->removeHeader('Content-Type');
        if (PHP_SAPI !== 'cli') {
            ini_set('default_mimetype', '');   // this request only; PHP would add text/html otherwise
        }
    }
}
