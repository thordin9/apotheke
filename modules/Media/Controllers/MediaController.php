<?php

declare(strict_types=1);

namespace Apotheke\Media\Controllers;

use Apotheke\Media\Storage\ServedImageWriter;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /media/{full|thumb}/{32hex}.jpg: the served copy or the thumbnail,
 * never an original. Port of main's serve_image(): anything that is not a
 * served name, or a missing file, is an empty 404.
 */
final class MediaController extends Controller
{
    public function serve(string $kind = '', string $name = ''): ResponseInterface
    {
        /** @var ServedImageWriter $served */
        $served = service('servedImages');
        $response = service('response');
        $response->removeHeader('Cache-Control');
        $mtime = $served->modifiedAt($kind, $name);
        $bytes = $mtime === null ? null : $served->read($kind, $name);
        if ($mtime === null || $bytes === null) {
            $response->removeHeader('Content-Type');

            return $response->setStatusCode(404)->setBody('');
        }
        $etag = '"' . $name . '-' . $mtime . '"';
        $response->setHeader('Cache-Control', 'private, max-age=31536000, immutable');
        $response->setHeader('ETag', $etag);
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        if ($this->request->getHeaderLine('If-None-Match') === $etag) {
            $response->removeHeader('Content-Type');

            return $response->setStatusCode(304)->setBody('');
        }
        $response->setHeader('Content-Type', 'image/jpeg');
        $response->setHeader('Content-Length', (string)strlen($bytes));

        return $response->setStatusCode(200)->setBody($bytes);
    }
}
