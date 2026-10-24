<?php
declare(strict_types=1);

/*
 * Imagick colour helpers shared by the display copy (items.php) and the analysis JPEG (autofill.php).
 * Neither of those files should load the other just to flatten transparency or convert CMYK.
 *
 * Dimension limits (header only, before a full decode):
 * IMAGE_MAX_PIXELS is 250 megapixels and IMAGE_MAX_SIDE is 30,000 px. A JPEG may use that whole range:
 * jpeg:size decodes it already reduced, and the decoded frame stays within 128 megapixels.
 * HEIC/HEIF has no reduced decode, so the header stops at the same bounds as the ImageMagick policy:
 * IMAGE_HEIC_MAX_PIXELS is 128 megapixels and IMAGE_HEIC_MAX_SIDE is 16,384 px.
 * IMAGE_HEIC_MAX_SIDE must equal docker/imagemagick-policy.xml width and height, the policy the image ships. That value is exactly 16384 px.
 * A 48 MP frame (8064×6048) is inside both. A 200 MP sensor (16384×12288) is inside the JPEG cap and
 * outside the HEIC cap.
 * IMAGE_GD_MAX_PIXELS is 40 megapixels: GD truecolor is 4 bytes a pixel, so 40 MP is 160 MB and fits under
 * memory_limit 256M. Larger JPEG photos are decoded already reduced. Larger PNG, WebP and GIF photos are refused.
 * Imagick's memory, map, disk, area and side limits match that policy: 256 MiB, 256 MiB, 1 GiB, 128 MP, 16,384 px.
 */

const IMAGE_MAX_PIXELS = 250_000_000;
const IMAGE_MAX_SIDE = 30000;
const IMAGE_HEIC_MAX_PIXELS = 128_000_000;
// Must equal docker/imagemagick-policy.xml width and height. The shipped value is exactly 16384 px.
const IMAGE_HEIC_MAX_SIDE = 16384;
const IMAGE_GD_MAX_PIXELS = 40_000_000;

const IMAGICK_LIMIT_MEMORY = 256 * 1024 * 1024;
const IMAGICK_LIMIT_MAP = 256 * 1024 * 1024;
const IMAGICK_LIMIT_DISK = 1024 * 1024 * 1024;
const IMAGICK_LIMIT_AREA = IMAGE_HEIC_MAX_PIXELS;
const IMAGICK_LIMIT_WIDTH = IMAGE_HEIC_MAX_SIDE;
const IMAGICK_LIMIT_HEIGHT = IMAGE_HEIC_MAX_SIDE;

/** Once per process. Imagick's own allocator is outside PHP's memory_limit. */
function imagick_set_resource_limits(): void
{
    if (!class_exists('Imagick')) return;
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, IMAGICK_LIMIT_MEMORY);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, IMAGICK_LIMIT_MAP);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_DISK, IMAGICK_LIMIT_DISK);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, IMAGICK_LIMIT_AREA);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_WIDTH, IMAGICK_LIMIT_WIDTH);
    Imagick::setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, IMAGICK_LIMIT_HEIGHT);
}

function image_dimensions_too_large(int $w, int $h): bool
{
    if ($w < 1 || $h < 1 || $w > IMAGE_MAX_SIDE || $h > IMAGE_MAX_SIDE) return true;
    return (float)$w * (float)$h > (float)IMAGE_MAX_PIXELS;
}

/** HEIC/HEIF is decoded in full, so the header stops at 128 megapixels and IMAGE_HEIC_MAX_SIDE. JPEG does not use this. */
function image_heic_dimensions_too_large(int $w, int $h): bool
{
    if ($w < 1 || $h < 1 || $w > IMAGE_HEIC_MAX_SIDE || $h > IMAGE_HEIC_MAX_SIDE) return true;
    return (float)$w * (float)$h > (float)IMAGE_HEIC_MAX_PIXELS;
}

/** Same header stop image_store() uses for a JPEG, PNG, WebP or GIF. JPEG above the GD budget is not over this limit. */
function image_raster_header_over_limit(int $w, int $h, int $type): bool
{
    return image_dimensions_too_large($w, $h)
        || ((float)$w * (float)$h > (float)IMAGE_GD_MAX_PIXELS && $type !== IMAGETYPE_JPEG);
}

/** Same header stop image_store() uses for HEIC/HEIF. Null means the header could not be read, which is not this refusal. */
function image_heic_header_over_limit(?array $dims): bool
{
    return $dims !== null && (image_heic_dimensions_too_large($dims[0], $dims[1]) || imagick_frame_exceeds_policy($dims[0], $dims[1]));
}

/**
 * Header only, no pixel decode. The upload path and /autofill/suggest both refuse with this error.
 * A resource-limit failure while reading a HEIC header is the same error.
 */
function image_assert_bytes_within_limits(string $bytes): void
{
    if ($bytes === '') return;
    if (function_exists('autofill_is_heic') && autofill_is_heic($bytes)) {
        if (image_heic_header_over_limit(image_ping_heic($bytes))) throw new UserError('Image dimensions are too large.', 422);
        return;
    }
    $info = @getimagesizefromstring($bytes);
    if ($info && image_raster_header_over_limit((int)$info[0], (int)$info[1], (int)$info[2])) {
        throw new UserError('Image dimensions are too large.', 422);
    }
}

/**
 * A full-frame decode (HEIC/HEIF) against the limits Imagick will actually enforce.
 * Width and height refuse the read. Area does not: a frame over the area limit spills to disk.
 * A Q16 pixel cache is about 8 bytes a pixel and has to fit in memory, map and disk together.
 * Do not use this on a JPEG header. jpeg:size opens the cache at the reduced size, so a JPEG
 * wider than 16,384 px can still be accepted when the decoded frame is not.
 */
function imagick_frame_exceeds_policy(int $w, int $h): bool
{
    if (!class_exists('Imagick') || $w < 1 || $h < 1) return false;
    $width = (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH);
    $height = (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_HEIGHT);
    if (($width > 0 && $w > $width) || ($height > 0 && $h > $height)) return true;
    $budget = (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY)
        + (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_MAP)
        + (int)Imagick::getResourceLimit(Imagick::RESOURCETYPE_DISK);
    return $budget > 0 && (float)$w * (float)$h * 8 > (float)$budget;
}

/** The wand reported a resource limit (width, memory, disk, time). A codec or a short file does not match. */
function imagick_is_resource_limit(Throwable $e): bool
{
    if (!class_exists('ImagickException') || !$e instanceof ImagickException) return false;
    return preg_match('/exceeds limit|limit exceeded|cache resources exhausted|memory allocation failed|memory allocation error|time limit exceeded|resource limit|security limit/i', $e->getMessage()) === 1;
}

/**
 * Resource-limit failures become the dimension error. HEIC/HEIF often keeps only "Failed to read the file"
 * and drops the cache reason, so that text counts only when the frame is already outside the limits.
 */
function imagick_failure_is_too_large(Throwable $e, int $w, int $h): bool
{
    if (imagick_is_resource_limit($e)) return true;
    return class_exists('ImagickException')
        && $e instanceof ImagickException
        && $e->getMessage() === 'Failed to read the file'
        && imagick_frame_exceeds_policy($w, $h);
}

/** Hint for libjpeg's scaled decode, or null when the frame is already within $maxSide. */
function image_jpeg_size_hint(int $w, int $h, int $maxSide): ?string
{
    $long = max($w, $h);
    if ($long < 1 || $maxSide < 1 || $long <= $maxSide) return null;
    $s = $maxSide / $long;
    return max(1, (int)round($w * $s)) . 'x' . max(1, (int)round($h * $s));
}

/** HEIC or HEIF from the ftyp brand. Same brands as image_bytes_heic(). */
function image_heic_magick_format(string $bytes): string
{
    $brand = strlen($bytes) >= 12 ? substr($bytes, 8, 4) : '';
    return in_array($brand, ['mif1', 'msf1'], true) ? 'HEIF' : 'HEIC';
}

/**
 * Format we will name to Imagick. Null for anything else, so the blob is not sniffed.
 * HEIC/HEIF, JPEG, PNG, GIF and WebP only.
 */
function image_explicit_format(string $bytes): ?string
{
    if (function_exists('autofill_is_heic') && autofill_is_heic($bytes)) return image_heic_magick_format($bytes);
    $info = @getimagesizefromstring($bytes);
    if (!$info) return null;
    return match ((int)$info[2]) {
        IMAGETYPE_JPEG => 'JPEG',
        IMAGETYPE_PNG => 'PNG',
        IMAGETYPE_GIF => 'GIF',
        IMAGETYPE_WEBP => 'WEBP',
        default => null,
    };
}

/** Width and height from a HEIC/HEIF header, or null when the header cannot be read. Does not decode the frame. */
function image_ping_heic(string $bytes): ?array
{
    if (!class_exists('Imagick') || $bytes === '' || !function_exists('autofill_is_heic') || !autofill_is_heic($bytes)) return null;
    try {
        $im = new Imagick();
        $im->setFormat(image_heic_magick_format($bytes));
        $im->pingImageBlob($bytes);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $im->clear();
        return [$w, $h];
    } catch (Throwable $e) {
        if (imagick_is_resource_limit($e)) throw new UserError('Image dimensions are too large.');
        return null;
    }
}

/**
 * Read $bytes as $format only, via the "FORMAT:" coder prefix (no format sniff).
 * $jpegSize is the jpeg:size hint and must be set before the read. A HEIC/HEIF blob read with
 * setFormat() can come back with pixels but an empty getImageBlob(); the prefix read exports.
 */
function imagick_read_explicit(string $bytes, string $format, ?string $jpegSize = null): Imagick
{
    $path = tempnam(sys_get_temp_dir(), 'img');
    if ($path === false) throw new RuntimeException('temp');
    try {
        if (file_put_contents($path, $bytes) === false) throw new RuntimeException('temp');
        $im = new Imagick();
        if ($jpegSize !== null && $jpegSize !== '') $im->setOption('jpeg:size', $jpegSize);
        $im->readImage($format . ':' . $path);
        return $im;
    } finally {
        @unlink($path);
    }
}

/**
 * A GD image of a JPEG, decoded already reduced to about $maxSide on the long edge.
 * Orientation is applied and the result is stripped. Null when Imagick cannot read it.
 */
function image_gd_from_scaled_jpeg(string $bytes, int $maxSide): ?GdImage
{
    if (!class_exists('Imagick') || $bytes === '') return null;
    $info = @getimagesizefromstring($bytes);
    if (!$info || (int)$info[2] !== IMAGETYPE_JPEG) return null;
    if (image_dimensions_too_large((int)$info[0], (int)$info[1])) return null;
    try {
        $im = imagick_read_explicit($bytes, 'JPEG', image_jpeg_size_hint((int)$info[0], (int)$info[1], $maxSide));
        if (method_exists($im, 'autoOrient')) $im->autoOrient();
        elseif (method_exists($im, 'autoOrientImage')) $im->autoOrientImage();
        imagick_match_gd($im);
        $im->setImageFormat('jpeg');
        $im->stripImage();
        $blob = $im->getImageBlob();
        $im->clear();
        if (!is_string($blob) || !str_starts_with($blob, "\xFF\xD8")) return null;
        $gd = @imagecreatefromstring($blob);
        return $gd instanceof GdImage ? $gd : null;
    } catch (Throwable $e) {
        if (imagick_is_resource_limit($e)) throw new UserError('Image dimensions are too large.');
        return null;
    }
}

/**
 * Imagick fallback, same result as GD. image_fit() paints white and composites onto it; without that,
 * transparent pixels become black. CMYK becomes sRGB. GD's JPEG carries no ICC profile (the caller strips).
 * An opaque sRGB image is left alone, so the GD path and an already-sRGB Imagick encode do no extra work.
 */
function imagick_match_gd(Imagick $im): void
{
    $cmyk = defined('Imagick::COLORSPACE_CMYK') ? Imagick::COLORSPACE_CMYK : 12;
    if ($im->getImageColorspace() === $cmyk) imagick_cmyk_to_srgb($im);
    if (!imagick_has_transparency($im)) return;
    $im->setImageBackgroundColor(new ImagickPixel('white'));
    if (defined('Imagick::ALPHACHANNEL_REMOVE')) {
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        return;
    }
    if (!defined('Imagick::LAYERMETHOD_FLATTEN')) return;
    $flat = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
    $flat->setImageFormat('png');
    $blob = $flat->getImageBlob();
    $flat->clear();
    $im->clear();
    $im->readImageBlob($blob);
}

/** CMYK → sRGB. transformImageColorspace matches GD; an sRGB ICC file is used only if that stays CMYK. */
function imagick_cmyk_to_srgb(Imagick $im): void
{
    $srgb = defined('Imagick::COLORSPACE_SRGB') ? Imagick::COLORSPACE_SRGB : 13;
    if (method_exists($im, 'transformImageColorspace')) $im->transformImageColorspace($srgb);
    if ($im->getImageColorspace() === $srgb) return;
    $existing = '';
    try { $existing = (string)$im->getImageProfile('icc'); } catch (Throwable) { $existing = ''; }
    $icc = $existing !== '' ? imagick_srgb_icc() : null;
    if ($icc !== null) $im->profileImage('icc', $icc);
}

/** True when some pixel is not fully opaque. A missing alpha channel, and an all-opaque one, are false. */
function imagick_has_transparency(Imagick $im): bool
{
    if (!method_exists($im, 'getImageAlphaChannel')) return false;
    $alpha = $im->getImageAlphaChannel();
    if (!$alpha) return false;
    $opaque = defined('Imagick::ALPHACHANNEL_OPAQUE') ? Imagick::ALPHACHANNEL_OPAQUE : 6;
    $off = defined('Imagick::ALPHACHANNEL_DEACTIVATE') ? Imagick::ALPHACHANNEL_DEACTIVATE : 4;
    if ($alpha === $opaque || $alpha === $off) return false;
    try {
        $stats = $im->getImageChannelStatistics();
        $key = defined('Imagick::CHANNEL_ALPHA') ? Imagick::CHANNEL_ALPHA : null;
        $row = $key !== null ? ($stats[$key] ?? null) : null;
        if (is_array($row) && array_key_exists('minima', $row)) return (float)$row['minima'] < (float)$im->getQuantum();
    } catch (Throwable) {
        return true;
    }
    return true;
}

/** An sRGB ICC profile from the system colour directory, when one is installed. */
function imagick_srgb_icc(): ?string
{
    static $done = false;
    static $bytes = null;
    if ($done) return $bytes;
    $done = true;
    foreach (['/usr/share/color/icc/ghostscript/srgb.icc', '/usr/share/color/icc/colord/sRGB.icc', '/usr/share/color/icc/sRGB.icc'] as $path) {
        if (!is_file($path)) continue;
        $data = @file_get_contents($path);
        if (is_string($data) && strlen($data) > 128 && substr($data, 36, 4) === 'acsp') return $bytes = $data;
    }
    return null;
}
