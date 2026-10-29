<?php

declare(strict_types=1);

namespace Apotheke\Media;

use Imagick;
use ImagickException;
use Throwable;

/**
 * Decode limits for uploaded photos, ported from main's src/image-color.php
 * (#72, #79). Checked on the header, before any full decode.
 *
 * Any frame: at most 250 megapixels and 30,000 px a side. A JPEG may use
 * that whole range because it is decoded already reduced (jpeg:size).
 * HEIC/HEIF has no reduced decode, so it stops at the ImageMagick policy the
 * Docker image ships: 128 megapixels and 16,384 px a side. GD needs 4 bytes a
 * pixel, so WebP and GIF stop at 40 megapixels, and PNG (an alpha PNG is a
 * second frame) at 26 megapixels, to stay under a 256M memory_limit.
 */
final class ImageLimits
{
    public const MAX_PIXELS = 250_000_000;
    public const MAX_SIDE = 30000;
    public const HEIC_MAX_PIXELS = 128_000_000;
    // Must equal docker/imagemagick-policy.xml width and height.
    public const HEIC_MAX_SIDE = 16384;
    public const GD_MAX_PIXELS = 40_000_000;
    public const PNG_MAX_PIXELS = 26_000_000;

    /** Imagick's own allocator is outside PHP's memory_limit; these match the shipped policy. */
    public const IMAGICK_MEMORY = 256 * 1024 * 1024;
    public const IMAGICK_MAP = 256 * 1024 * 1024;
    public const IMAGICK_DISK = 1024 * 1024 * 1024;

    public const TOO_LARGE = 'Image dimensions are too large.';

    /** Once per process (legacy imagick_set_resource_limits()). */
    public static function applyImagickLimits(): void
    {
        if (!class_exists(Imagick::class)) {
            return;
        }
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, self::IMAGICK_MEMORY);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, self::IMAGICK_MAP);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, self::IMAGICK_DISK);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, self::HEIC_MAX_PIXELS);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_WIDTH, self::HEIC_MAX_SIDE);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, self::HEIC_MAX_SIDE);
    }

    public static function dimensionsTooLarge(int $w, int $h): bool
    {
        if ($w < 1 || $h < 1 || $w > self::MAX_SIDE || $h > self::MAX_SIDE) {
            return true;
        }

        return (float)$w * (float)$h > (float)self::MAX_PIXELS;
    }

    public static function heicDimensionsTooLarge(int $w, int $h): bool
    {
        if ($w < 1 || $h < 1 || $w > self::HEIC_MAX_SIDE || $h > self::HEIC_MAX_SIDE) {
            return true;
        }

        return (float)$w * (float)$h > (float)self::HEIC_MAX_PIXELS;
    }

    /** JPEG, PNG, WebP or GIF header. A JPEG above the GD budget is not over this limit. */
    public static function rasterHeaderOverLimit(int $w, int $h, int $type): bool
    {
        if (self::dimensionsTooLarge($w, $h)) {
            return true;
        }
        $px = (float)$w * (float)$h;
        if ($type === IMAGETYPE_PNG) {
            return $px > (float)self::PNG_MAX_PIXELS;
        }

        return $px > (float)self::GD_MAX_PIXELS && $type !== IMAGETYPE_JPEG;
    }

    /**
     * HEIC/HEIF header. Null means the header could not be read, which is not this refusal.
     *
     * @param array{0:int,1:int}|null $dims
     */
    public static function heicHeaderOverLimit(?array $dims): bool
    {
        return $dims !== null && (self::heicDimensionsTooLarge($dims[0], $dims[1]) || self::frameExceedsPolicy($dims[0], $dims[1]));
    }

    /**
     * A full-frame decode against the limits Imagick enforces. Width and height
     * refuse the read; a Q16 pixel cache (about 8 bytes a pixel) has to fit in
     * memory, map and disk together. Not for a JPEG header (jpeg:size).
     */
    public static function frameExceedsPolicy(int $w, int $h): bool
    {
        if (!class_exists(Imagick::class) || $w < 1 || $h < 1) {
            return false;
        }
        $width = (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH);
        $height = (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_HEIGHT);
        if (($width > 0 && $w > $width) || ($height > 0 && $h > $height)) {
            return true;
        }
        $budget = (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY)
            + (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_MAP)
            + (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_DISK);

        return $budget > 0 && (float)$w * (float)$h * 8 > (float)$budget;
    }

    /** The wand reported a resource limit. A codec error or a short file does not match. */
    public static function isResourceLimit(Throwable $e): bool
    {
        if (!class_exists(ImagickException::class) || !$e instanceof ImagickException) {
            return false;
        }

        return preg_match('/exceeds limit|limit exceeded|cache resources exhausted|memory allocation failed|memory allocation error|time limit exceeded|resource limit|security limit/i', $e->getMessage()) === 1;
    }

    /** HEIC often keeps only "Failed to read the file"; that counts only when the frame is already outside the limits. */
    public static function failureIsTooLarge(Throwable $e, int $w, int $h): bool
    {
        if (self::isResourceLimit($e)) {
            return true;
        }

        return class_exists(ImagickException::class)
            && $e instanceof ImagickException
            && $e->getMessage() === 'Failed to read the file'
            && self::frameExceedsPolicy($w, $h);
    }

    /** libjpeg's scaled-decode hint, or null when the frame is already within $maxSide. */
    public static function jpegSizeHint(int $w, int $h, int $maxSide): ?string
    {
        $long = max($w, $h);
        if ($long < 1 || $maxSide < 1 || $long <= $maxSide) {
            return null;
        }
        $s = $maxSide / $long;

        return max(1, (int)round($w * $s)) . 'x' . max(1, (int)round($h * $s));
    }
}
