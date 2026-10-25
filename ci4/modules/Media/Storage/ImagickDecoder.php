<?php

declare(strict_types=1);

namespace Apotheke\Media\Storage;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Media\ImageLimits;
use GdImage;
use Imagick;
use ImagickPixel;
use RuntimeException;
use Throwable;

/**
 * The Imagick decodes behind the served copy (PLAN's HeicConverter): a
 * HEIC/HEIF frame, and a JPEG above the GD budget decoded already reduced.
 * Both hand back a stripped GD image, or null so the caller can fall back
 * (HEIC: the placeholder). A resource-limit failure is the "too large"
 * UserError, not a server error. Ported from main's items.php/image-color.php.
 */
final class ImagickDecoder
{
    /** ISO BMFF "ftyp" box with a HEIF brand. */
    public static function isHeic(string $bytes): bool
    {
        return strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp'
            && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true);
    }

    /** Imagick in this PHP decodes HEIC/HEIF (libheif). */
    public function heicSupported(): bool
    {
        if (!class_exists(Imagick::class)) {
            return false;
        }
        try {
            return count(Imagick::queryFormats('HEIC')) > 0 || count(Imagick::queryFormats('HEIF')) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Width and height from a HEIC/HEIF header without decoding the frame, or null.
     *
     * @return array{0:int,1:int}|null
     */
    public function pingHeic(string $bytes): ?array
    {
        if (!class_exists(Imagick::class) || !self::isHeic($bytes)) {
            return null;
        }
        try {
            $im = new Imagick();
            $im->setFormat(self::heicFormat($bytes));
            $im->pingImageBlob($bytes);
            $dims = [$im->getImageWidth(), $im->getImageHeight()];
            $im->clear();

            return $dims;
        } catch (Throwable $e) {
            if (ImageLimits::isResourceLimit($e)) {
                throw new UserError(ImageLimits::TOO_LARGE);
            }

            return null;
        }
    }

    /** HEIC/HEIF → the same stripped JPEG as other photos, as GD. Null: use the placeholder. */
    public function heicToGd(string $bytes, int $maxSide, int $quality): ?GdImage
    {
        if (!$this->heicSupported()) {
            return null;
        }
        $dims = $this->pingHeic($bytes);
        if ($dims === null) {
            return null;
        }
        [$frameW, $frameH] = $dims;
        if (ImageLimits::heicDimensionsTooLarge($frameW, $frameH) || ImageLimits::frameExceedsPolicy($frameW, $frameH)) {
            throw new UserError(ImageLimits::TOO_LARGE);
        }
        try {
            $im = $this->readExplicit($bytes, self::heicFormat($bytes), null);
            $im->autoOrient();
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if (ImageLimits::dimensionsTooLarge($w, $h)) {
                $im->clear();
                throw new UserError(ImageLimits::TOO_LARGE);
            }
            $this->matchGd($im);
            if (max($w, $h) > $maxSide) {
                $s = $maxSide / max($w, $h);
                $filter = defined('Imagick::FILTER_LANCZOS') ? Imagick::FILTER_LANCZOS : 1;
                $im->resizeImage(max(1, (int)round($w * $s)), max(1, (int)round($h * $s)), $filter, 1);
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);
            $im->stripImage();

            return $this->toGd($im);
        } catch (UserError $e) {
            throw $e;
        } catch (Throwable $e) {
            if (ImageLimits::failureIsTooLarge($e, $frameW, $frameH)) {
                throw new UserError(ImageLimits::TOO_LARGE);
            }

            return null;
        }
    }

    /** A JPEG decoded already reduced to about $maxSide on the long edge, oriented and stripped. Null when Imagick cannot read it. */
    public function scaledJpeg(string $bytes, int $maxSide): ?GdImage
    {
        if (!class_exists(Imagick::class) || $bytes === '') {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || ImageLimits::dimensionsTooLarge($info[0], $info[1])) {
            return null;
        }
        try {
            $im = $this->readExplicit($bytes, 'JPEG', ImageLimits::jpegSizeHint($info[0], $info[1], $maxSide));
            $im->autoOrient();
            $this->matchGd($im);
            $im->setImageFormat('jpeg');
            $im->stripImage();

            return $this->toGd($im);
        } catch (Throwable $e) {
            if (ImageLimits::isResourceLimit($e)) {
                throw new UserError(ImageLimits::TOO_LARGE);
            }

            return null;
        }
    }

    private static function heicFormat(string $bytes): string
    {
        return in_array(substr($bytes, 8, 4), ['mif1', 'msf1'], true) ? 'HEIF' : 'HEIC';
    }

    private function toGd(Imagick $im): ?GdImage
    {
        $blob = $im->getImageBlob();
        $im->clear();
        if (!str_starts_with($blob, "\xFF\xD8")) {
            return null;
        }
        $gd = @imagecreatefromstring($blob);

        return $gd instanceof GdImage ? $gd : null;
    }

    /**
     * Read $bytes as $format only, through the "FORMAT:" coder prefix, so the
     * blob is never sniffed. A HEIC blob read with setFormat() can come back
     * with an empty getImageBlob(); the prefixed file read exports.
     */
    private function readExplicit(string $bytes, string $format, ?string $jpegSize): Imagick
    {
        $path = tempnam(sys_get_temp_dir(), 'img');
        if ($path === false) {
            throw new RuntimeException('temp');
        }
        try {
            if (file_put_contents($path, $bytes) === false) {
                throw new RuntimeException('temp');
            }
            $im = new Imagick();
            if ($jpegSize !== null) {
                $im->setOption('jpeg:size', $jpegSize);
            }
            $im->readImage($format . ':' . $path);

            return $im;
        } finally {
            @unlink($path);
        }
    }

    /** Same flatten as GD's fit: transparent pixels become white, CMYK becomes sRGB. */
    private function matchGd(Imagick $im): void
    {
        $cmyk = defined('Imagick::COLORSPACE_CMYK') ? Imagick::COLORSPACE_CMYK : 12;
        if ($im->getImageColorspace() === $cmyk) {
            $this->cmykToSrgb($im);
        }
        if (!$this->hasTransparency($im)) {
            return;
        }
        $im->setImageBackgroundColor(new ImagickPixel('white'));
        if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
            $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);

            return;
        }
        if (!defined('Imagick::LAYERMETHOD_FLATTEN')) {
            return;
        }
        $flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $flat->setImageFormat('png');
        $blob = $flat->getImageBlob();
        $flat->clear();
        $im->clear();
        $im->readImageBlob($blob);
    }

    private function hasTransparency(Imagick $im): bool
    {
        if (!$im->getImageAlphaChannel()) {
            return false;
        }
        try {
            $range = $im->getImageChannelRange(Imagick::CHANNEL_ALPHA);

            return $range['minima'] < (float)$im->getQuantum();
        } catch (Throwable) {
            return true;
        }
    }

    /** transformImageColorspace matches GD. An sRGB ICC file is used only when the image stays CMYK. */
    private function cmykToSrgb(Imagick $im): void
    {
        $srgb = defined('Imagick::COLORSPACE_SRGB') ? Imagick::COLORSPACE_SRGB : 13;
        $im->transformImageColorspace($srgb);
        if ($im->getImageColorspace() === $srgb) {
            return;
        }
        try {
            $existing = $im->getImageProfile('icc');
        } catch (Throwable) {
            return;
        }
        if ($existing === '') {
            return;
        }
        foreach (['/usr/share/color/icc/ghostscript/srgb.icc', '/usr/share/color/icc/colord/sRGB.icc', '/usr/share/color/icc/sRGB.icc'] as $path) {
            $data = is_file($path) ? @file_get_contents($path) : false;
            if (is_string($data) && strlen($data) > 128 && substr($data, 36, 4) === 'acsp') {
                $im->profileImage('icc', $data);

                return;
            }
        }
    }
}
