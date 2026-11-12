<?php

declare(strict_types=1);

namespace Apotheke\Media\Controllers;

use Apotheke\Media\Storage\ServedImageWriter;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /media/{full|thumb}/{32hex}.jpg: the served copy or the thumbnail,
 * never an original. Port of main's serve_image(): anything that is not a
 * served name, or a missing file, is an empty 404. If-None-Match is read as
 * RFC 9110 says (a list, weak comparison, `*`), and a single byte range gets
 * 206 with Content-Range, or 416 when it starts past the end; the same for
 * local and R2 storage, which both return the whole (small) object.
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
        $response->setHeader('Accept-Ranges', 'bytes');
        if (self::noneMatch($this->request->getHeaderLine('If-None-Match'), $etag)) {
            $response->removeHeader('Content-Type');

            return $response->setStatusCode(304)->setBody('');
        }
        $length = strlen($bytes);
        $ifRange = trim($this->request->getHeaderLine('If-Range'));
        $range = $ifRange === '' || $ifRange === $etag ? self::range($this->request->getHeaderLine('Range'), $length) : null;
        if ($range === false) {
            $response->removeHeader('Content-Type');

            return $response->setStatusCode(416)->setHeader('Content-Range', 'bytes */' . $length)->setBody('');
        }
        $response->setHeader('Content-Type', 'image/jpeg');
        if ($range !== null) {
            [$first, $last] = $range;

            return $response->setStatusCode(206)
                ->setHeader('Content-Range', 'bytes ' . $first . '-' . $last . '/' . $length)
                ->setHeader('Content-Length', (string)($last - $first + 1))
                ->setBody(substr($bytes, $first, $last - $first + 1));
        }
        $response->setHeader('Content-Length', (string)$length);

        return $response->setStatusCode(200)->setBody($bytes);
    }

    /** If-None-Match (RFC 9110 §13.1.2): `*`, or any listed tag equal to $etag by the weak comparison. */
    public static function noneMatch(string $header, string $etag): bool
    {
        $header = trim($header);
        if ($header === '') {
            return false;
        }
        if ($header === '*') {
            return true;
        }
        $ours = self::opaque($etag);
        foreach (explode(',', $header) as $tag) {
            if (self::opaque(trim($tag)) === $ours) {
                return true;
            }
        }

        return false;
    }

    /**
     * One byte range of a $length-byte body (RFC 9110 §14.1.2): [first, last], false when it is not
     * satisfiable (416), or null when there is no Range, it is not bytes, it is malformed or it lists
     * several ranges; the whole body is the answer then (200).
     *
     * @return array{0:int,1:int}|false|null
     */
    public static function range(string $header, int $length): array|false|null
    {
        if (preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m) !== 1 || ($m[1] === '' && $m[2] === '')) {
            return null;
        }
        if (strlen($m[1]) > 15 || strlen($m[2]) > 15) {
            return null;
        }
        if ($m[1] === '') {
            $suffix = (int)$m[2];
            if ($suffix === 0 || $length === 0) {
                return false;
            }

            return [max(0, $length - $suffix), $length - 1];
        }
        $first = (int)$m[1];
        $last = $m[2] === '' ? $length - 1 : (int)$m[2];
        if ($m[2] !== '' && $last < $first) {
            return null;   // invalid: ignored, as RFC 9110 allows
        }
        if ($first >= $length) {
            return false;
        }

        return [$first, min($last, $length - 1)];
    }

    /** An entity tag without the weak prefix W/, or '' when it is not a quoted tag. */
    private static function opaque(string $tag): string
    {
        if (str_starts_with($tag, 'W/')) {
            $tag = substr($tag, 2);
        }

        return preg_match('/^"[^"]*"$/', $tag) === 1 ? $tag : '';
    }
}
