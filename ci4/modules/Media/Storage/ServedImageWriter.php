<?php

declare(strict_types=1);

namespace Apotheke\Media\Storage;

use Apotheke\Core\Storage\Storage;
use Apotheke\Media\ImageStore;
use GdImage;
use Throwable;

/**
 * The photo everyone sees: `images/<32hex>.jpg` and `thumbs/<32hex>.jpg`
 * under DATA_DIR, both JPEGs from GD, so no metadata survives. Same GD calls
 * as main's image_write_pair(), so the bytes match.
 */
final class ServedImageWriter
{
    public const KINDS = ['full' => 'images', 'thumb' => 'thumbs'];

    /** $files is rooted at DATA_DIR. */
    public function __construct(private readonly Storage $files)
    {
    }

    /** Full size within $fullMax and a thumbnail within $thumbMax; the thumbnail uses quality − 6. */
    public function writePair(GdImage $src, string $name, int $fullMax, int $thumbMax, int $quality): bool
    {
        $full = $this->jpeg($this->fit($src, $fullMax), $quality);
        $thumb = $this->jpeg($this->fit($src, $thumbMax), $quality - 6);
        if ($full === null || $thumb === null) {
            return false;
        }
        try {
            $this->files->write('images/' . $name, $full);
            $this->files->write('thumbs/' . $name, $thumb);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** Plain grey 64×64 photo for a kept upload that cannot be shown (a HEIC without libheif). Never the original bytes. */
    public function writePlaceholder(string $name, int $fullMax, int $thumbMax, int $quality): bool
    {
        $im = imagecreatetruecolor(64, 64);
        $grey = imagecolorallocate($im, 226, 226, 226);
        imagefill($im, 0, 0, $grey === false ? 0 : $grey);

        return $this->writePair($im, $name, $fullMax, $thumbMax, $quality);
    }

    /** $kind is `full` or `thumb`. Null when the name is not a served name or the file is missing. */
    public function read(string $kind, string $name): ?string
    {
        $key = $this->key($kind, $name);

        return $key === null ? null : $this->files->read($key);
    }

    public function modifiedAt(string $kind, string $name): ?int
    {
        $key = $this->key($kind, $name);

        return $key === null ? null : $this->files->modifiedAt($key);
    }

    /**
     * Copy the served photo and, when there is one, its thumbnail to $to
     * (legacy image_copy()). False when the photo is missing; a failed
     * thumbnail copy removes the new photo first.
     */
    public function copy(string $from, string $to): bool
    {
        $full = $this->read('full', $from);
        if ($full === null || !ImageStore::validName($to)) {
            return false;
        }
        $this->files->write('images/' . $to, $full);
        $thumb = $this->read('thumb', $from);
        if ($thumb !== null) {
            try {
                $this->files->write('thumbs/' . $to, $thumb);
            } catch (Throwable $e) {
                $this->files->delete('images/' . $to);
                throw $e;
            }
        }

        return true;
    }

    public function delete(string $name): void
    {
        if (ImageStore::validName($name)) {
            $this->files->delete('images/' . $name);
            $this->files->delete('thumbs/' . $name);
        }
    }

    private function key(string $kind, string $name): ?string
    {
        return isset(self::KINDS[$kind]) && ImageStore::validName($name) ? self::KINDS[$kind] . '/' . $name : null;
    }

    /** Scale to fit within $max × $max on white (flattens transparency). Never enlarges. */
    private function fit(GdImage $src, int $max): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $s = min(1, $max / max($w, $h));
        $nw = max(1, (int)round($w * $s));
        $nh = max(1, (int)round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white === false ? 0 : $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($dst, true);

        return $dst;
    }

    private function jpeg(GdImage $im, int $quality): ?string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return null;
        }
        $ok = imagejpeg($im, $stream, $quality);
        rewind($stream);
        $out = stream_get_contents($stream);
        fclose($stream);

        return $ok && is_string($out) && $out !== '' ? $out : null;
    }
}
