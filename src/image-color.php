<?php
declare(strict_types=1);

/*
 * Imagick colour helpers shared by the display copy (items.php) and the analysis JPEG (autofill.php).
 * Neither of those files should load the other just to flatten transparency or convert CMYK.
 */

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
