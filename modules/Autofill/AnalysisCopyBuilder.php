<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use GdImage;
use Imagick;
use Throwable;

/**
 * The JPEG sent to the service. Long edge at most the upload cap (never
 * enlarged), EXIF orientation applied, metadata stripped. Quality steps
 * down by 10 from the configured quality, and 60 is tried before the long
 * edge shrinks, until the JPEG (not the multipart wrapper) is under the cap.
 * The original bytes are never written and never returned.
 */
final class AnalysisCopyBuilder
{
    public const UPLOAD_MAX_SIDE = 1600;

    public const UPLOAD_JPEG_QUALITY = 80;

    public const SIDE_MIN = 64;

    public const SIDE_MAX = 4096;

    public function __construct(
        private readonly ImageLimit $limits,
        private readonly TempFile $temps,
        private readonly LogRedactor $log,
    ) {
    }

    public function uploadMaxSide(): int
    {
        $v = getenv('AUTOFILL_UPLOAD_MAX_SIDE');
        if (is_string($v) && preg_match('/^\d{1,5}$/D', $v)) {
            $n = (int)$v;
            if ($n >= self::SIDE_MIN && $n <= self::SIDE_MAX) {
                return $n;
            }
        }

        return self::UPLOAD_MAX_SIDE;
    }

    public function uploadQuality(): int
    {
        $v = getenv('AUTOFILL_UPLOAD_JPEG_QUALITY');
        if (is_string($v) && preg_match('/^\d{1,3}$/D', $v)) {
            $n = (int)$v;
            if ($n >= 40 && $n <= 95) {
                return $n;
            }
        }

        return self::UPLOAD_JPEG_QUALITY;
    }

    /** @return list<int> highest first; 60 is always included when the configured quality is at least 60 */
    public function qualities(): array
    {
        $q = $this->uploadQuality();
        $out = [];
        for ($n = $q; $n >= 60; $n -= 10) {
            $out[] = $n;
        }
        if ($out === []) {
            return [$q];
        }
        if ($out[array_key_last($out)] !== 60) {
            $out[] = 60;
        }

        return $out;
    }

    /** @return list<int> largest first, then four fifths, never below 64, at most 24 */
    public function sides(int $side): array
    {
        $n = max(1, $side);
        $out = [];
        while (count($out) < 24) {
            $out[] = $n;
            if ($n <= self::SIDE_MIN) {
                break;
            }
            $next = (int)floor($n * 4 / 5);
            if ($next > $n - 1) {
                $next = $n - 1;
            }
            if ($next < self::SIDE_MIN) {
                $next = self::SIDE_MIN;
            }
            $n = $next;
        }

        return $out;
    }

    public function heic(string $bytes): bool
    {
        return strlen($bytes) >= 12 && substr($bytes, 4, 4) === 'ftyp'
            && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true);
    }

    /** @return list<string> */
    public function enginesFor(string $bytes): array
    {
        return $this->pickEngines(
            $this->heic($bytes),
            function_exists('imagecreatefromstring') && function_exists('imagejpeg'),
            class_exists(Imagick::class),
            $this->heicSupported(),
        );
    }

    /**
     * @param list<string>|null $engines
     */
    public function jpeg(string $bytes, ?int $maxSide = null, ?int $quality = null, ?array $engines = null, ?string $exifPath = null): ?string
    {
        $maxSide ??= $this->uploadMaxSide();
        $quality ??= $this->uploadQuality();
        $engines ??= $this->enginesFor($bytes);
        foreach ($engines as $engine) {
            $out = match ($engine) {
                'gd' => $this->gd($bytes, $maxSide, $quality, $exifPath),
                'imagick' => $this->imagick($bytes, $maxSide, $quality),
                default => null,
            };
            if (is_string($out) && str_starts_with($out, "\xFF\xD8")) {
                return $out;
            }
        }

        return null;
    }

    /** @return array{body:string,content_type:string} */
    public function multipart(string $jpeg, string $contextJson): array
    {
        $boundary = 'ApothekeForm' . bin2hex(random_bytes(8));
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"image\"; filename=\"photo.jpg\"\r\n"
            . "Content-Type: image/jpeg\r\n\r\n"
            . $jpeg . "\r\n"
            . "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"context\"\r\n"
            . "Content-Type: application/json\r\n\r\n"
            . $contextJson . "\r\n"
            . "--$boundary--\r\n";

        return ['body' => $body, 'content_type' => 'multipart/form-data; boundary=' . $boundary];
    }

    /**
     * Build the analysis JPEG and the multipart body. The JPEG is stored
     * under autofill/tmp when it fits; the caller deletes that key.
     *
     * @param list<string>|null $engines
     * @return array{ok:true,path:string,body:string,content_type:string,jpeg:string,width:int,height:int}|array{ok:false,code:string,message:string}
     */
    public function open(string $bytes, int $side, string $contextJson, ?array $engines = null, ?string $exifPath = null): array
    {
        $fail = static fn (string $code, string $msg): array => ['ok' => false, 'code' => $code, 'message' => $msg];
        if ($bytes === '') {
            return $fail('no_image', 'No photo.');
        }
        $engines ??= $this->enginesFor($bytes);
        if ($engines === []) {
            $this->log->warning('suggest: skipped, no image library for this photo');

            return $fail('unsupported', 'No suggestions: this kind of file cannot be read as a photo.');
        }
        $limit = $this->limits->target(true);
        $saw = false;
        foreach ($this->sides($side) as $trySide) {
            foreach ($this->qualities() as $q) {
                $jpeg = $this->jpeg($bytes, $trySide, $q, $engines, $exifPath);
                if ($jpeg === null) {
                    if ($saw) {
                        break 2;
                    }
                    $this->log->warning('suggest: skipped, could not build a stripped JPEG');

                    return $fail('unsupported', 'No suggestions: this kind of file cannot be read as a photo.');
                }
                $saw = true;
                $part = $this->multipart($jpeg, $contextJson);
                if (strlen($jpeg) <= $limit) {
                    try {
                        $path = $this->temps->putJpeg($jpeg);
                    } catch (Throwable) {
                        return $fail('unavailable', ResponseClassifier::UNAVAILABLE);
                    }
                    $info = @getimagesizefromstring($jpeg);

                    return [
                        'ok' => true,
                        'path' => $path,
                        'body' => $part['body'],
                        'content_type' => $part['content_type'],
                        'jpeg' => $jpeg,
                        'width' => (int)($info[0] ?? 0),
                        'height' => (int)($info[1] ?? 0),
                    ];
                }
            }
        }
        $this->log->warning('suggest: analysis copy over the image limit');

        return $fail('too_large', $this->limits->tooLargeMessage($limit));
    }

    /**
     * @return list<string>
     */
    private function pickEngines(bool $heic, bool $gd, bool $imagick, bool $heicOk): array
    {
        if ($heic) {
            return $imagick && $heicOk ? ['imagick'] : [];
        }
        $out = [];
        if ($gd) {
            $out[] = 'gd';
        }
        if ($imagick) {
            $out[] = 'imagick';
        }

        return $out;
    }

    private function heicSupported(): bool
    {
        if (!class_exists(Imagick::class)) {
            return false;
        }
        try {
            // count() rejects a false return from older Imagick; an empty list is "not supported".
            if (count(Imagick::queryFormats('HEIC')) > 0) {
                return true;
            }

            return count(Imagick::queryFormats('HEIF')) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function gd(string $bytes, int $maxSide, int $quality, ?string $exifPath): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $types = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
        if (!$info || !in_array($info[2], $types, true) || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 60_000_000) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src instanceof GdImage) {
            return null;
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = $exifPath !== null && is_file($exifPath) ? @exif_read_data($exifPath) : false;
            if (!is_array($exif)) {
                $exif = $this->exifFromBytes($bytes);
            }
            if (is_array($exif)) {
                $src = $this->orient($src, (int)($exif['Orientation'] ?? 1));
            }
        }
        $im = $this->fit($src, max(1, $maxSide));
        $out = $this->jpegBytes($im, max(1, min(100, $quality)));

        return is_string($out) && str_starts_with($out, "\xFF\xD8") ? $out : null;
    }

    /**
     * EXIF from a memory stream: a data: URI needs allow_url_fopen (L-270).
     *
     * @return array<string,mixed>|false
     */
    private function exifFromBytes(string $bytes): array|false
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            return false;
        }
        try {
            fwrite($stream, $bytes);
            rewind($stream);

            return @exif_read_data($stream);
        } finally {
            fclose($stream);
        }
    }

    private function imagick(string $bytes, int $maxSide, int $quality): ?string
    {
        if (!class_exists(Imagick::class) || $bytes === '') {
            return null;
        }
        try {
            $im = new Imagick();
            $im->readImageBlob($bytes);
            $im->setFirstIterator();
            $im->autoOrient();
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            if ($w < 1 || $h < 1 || $w * $h > 60_000_000) {
                $im->clear();

                return null;
            }
            $this->matchGd($im);
            $long = max($w, $h);
            if ($long > $maxSide) {
                $s = $maxSide / $long;
                $nw = max(1, (int)round($w * $s));
                $nh = max(1, (int)round($h * $s));
                $filter = defined('Imagick::FILTER_LANCZOS') ? Imagick::FILTER_LANCZOS : 1;
                $im->resizeImage($nw, $nh, $filter, 1);
            }
            $im->setImageFormat('jpeg');
            $im->setOption('jpeg:sampling-factor', '2x2,1x1,1x1');
            $im->setImageProperty('jpeg:sampling-factor', '2x2,1x1,1x1');
            $im->setImageCompressionQuality(max(1, min(100, $quality)));
            $im->stripImage();
            $out = $im->getImageBlob();
            $im->clear();

            return str_starts_with($out, "\xFF\xD8") ? $out : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function jpegBytes(GdImage $im, int $quality): ?string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            return null;
        }
        $ok = imagejpeg($im, $stream, $quality);
        rewind($stream);
        $out = stream_get_contents($stream);
        fclose($stream);

        return $ok && is_string($out) ? $out : null;
    }

    private function orient(GdImage $im, int $orientation): GdImage
    {
        $out = match ($orientation) {
            2 => imageflip($im, IMG_FLIP_HORIZONTAL) ? $im : $im,
            3 => imagerotate($im, 180, 0),
            4 => imageflip($im, IMG_FLIP_VERTICAL) ? $im : $im,
            5 => imageflip($im, IMG_FLIP_HORIZONTAL) ? imagerotate($im, 90, 0) : $im,
            6 => imagerotate($im, -90, 0),
            7 => imageflip($im, IMG_FLIP_HORIZONTAL) ? imagerotate($im, -90, 0) : $im,
            8 => imagerotate($im, 90, 0),
            default => $im,
        };
        if ($out instanceof GdImage && $out !== $im) {
            return $out;
        }

        return $im;
    }

    /** Scale to fit within $max on a white background. Never enlarges. */
    private function fit(GdImage $src, int $max): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $s = min(1, $max / max($w, $h));
        $nw = max(1, (int)round($w * $s));
        $nh = max(1, (int)round($h * $s));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    /** Same flatten as GD: transparent pixels become white, CMYK becomes sRGB. */
    private function matchGd(Imagick $im): void
    {
        $cmyk = defined('Imagick::COLORSPACE_CMYK') ? Imagick::COLORSPACE_CMYK : 12;
        if ($im->getImageColorspace() === $cmyk) {
            $this->cmykToSrgb($im);
        }
        if (!$this->hasTransparency($im)) {
            return;
        }
        $im->setImageBackgroundColor(new \ImagickPixel('white'));
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

    /** This Imagick build reports the alpha channel as a bool, then the pixel range says whether any pixel is clear. */
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

    /** transformImageColorspace matches GD. An sRGB ICC file is used only when the image stays CMYK. Not cached. */
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
        $icc = $this->srgbIcc();
        if ($icc !== null) {
            $im->profileImage('icc', $icc);
        }
    }

    private function srgbIcc(): ?string
    {
        foreach ([
            '/usr/share/color/icc/ghostscript/srgb.icc',
            '/usr/share/color/icc/colord/sRGB.icc',
            '/usr/share/color/icc/sRGB.icc',
        ] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $data = @file_get_contents($path);
            if (is_string($data) && strlen($data) > 128 && substr($data, 36, 4) === 'acsp') {
                return $data;
            }
        }

        return null;
    }
}
