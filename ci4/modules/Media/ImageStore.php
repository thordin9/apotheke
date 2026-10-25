<?php

declare(strict_types=1);

namespace Apotheke\Media;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Media\Storage\ImagickDecoder;
use Apotheke\Media\Storage\OriginalStore;
use Apotheke\Media\Storage\ServedImageWriter;
use Apotheke\Settings\SettingsService;
use GdImage;

/**
 * Port of main's image_store() and image_delete(): keep the upload
 * byte-identical as the original, and write the served JPEG and thumbnail
 * (EXIF orientation applied, longest side `image_full_max`, `jpeg_quality`,
 * metadata stripped). HEIC/HEIF is kept as the original; when it cannot be
 * converted the served file is a placeholder, never the HEIC.
 *
 * Decode limits (ImageLimits) are checked on the header before any full
 * decode. A JPEG above the GD budget is decoded already reduced. When a
 * decode fails, the original and any served file are removed again; when
 * the request dies first (a fatal error), RequestCleanup removes them.
 */
final class ImageStore
{
    private const TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly OriginalStore $originals,
        private readonly ServedImageWriter $served,
        private readonly ImagickDecoder $imagick,
        private readonly RequestCleanup $cleanup,
    ) {
    }

    /** A served photo name: 32 hex characters and `.jpg`. Nothing else reaches the disk or a URL. */
    public static function validName(?string $name): bool
    {
        return is_string($name) && preg_match('/^[a-f0-9]{32}\.jpg$/D', $name) === 1;
    }

    /** Bytes per photo: the `max_upload_mb` setting. Also the forms' MAX_FILE_SIZE value. */
    public function maxUpload(): int
    {
        return (int)$this->settings->get('max_upload_mb') * 1024 * 1024;
    }

    public function uploadErrorText(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That photo is too large (max ' . ($this->maxUpload() >> 20) . ' MB).',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Server could not store the upload.',
            default => 'Upload failed (code ' . $code . ').',
        };
    }

    /**
     * Store one uploaded file (PHP's tmp_name, size and error) and return the
     * served name. A UserError says what the user can fix.
     */
    public function store(string $tmp, int $size, int $error = UPLOAD_ERR_OK): string
    {
        if ($error !== UPLOAD_ERR_OK) {
            throw new UserError($this->uploadErrorText($error));
        }
        if (!is_uploaded_file($tmp) && PHP_SAPI !== 'cli') {
            throw new UserError('Invalid upload.');
        }
        if ($size > $this->maxUpload()) {
            throw new UserError($this->uploadErrorText(UPLOAD_ERR_INI_SIZE));
        }

        $info = @getimagesize($tmp);
        $type = $info === false ? 0 : $info[2];
        $ext = self::TYPES[$type] ?? null;
        $bytes = (string)@file_get_contents($tmp);
        $heic = $ext === null && ImagickDecoder::isHeic($bytes);
        if ($heic) {
            $ext = 'heic';
            if (ImageLimits::heicHeaderOverLimit($this->imagick->pingHeic($bytes))) {
                throw new UserError(ImageLimits::TOO_LARGE);
            }
        } elseif ($ext === null || $info === false) {
            throw new UserError('Unsupported image. Please use JPEG, PNG, WebP or GIF (on iPhone, HEIC photos are converted automatically).');
        } elseif (ImageLimits::rasterHeaderOverLimit($info[0], $info[1], $type)) {
            throw new UserError(ImageLimits::TOO_LARGE);
        }

        $name = bin2hex(random_bytes(16)) . '.jpg';
        if (!$this->originals->save($name, $bytes, $ext)) {
            throw new UserError('Server could not save the image.');
        }
        $undo = 'media:' . $name;
        $this->cleanup->add($undo, fn () => $this->delete($name));
        $done = false;
        try {
            $fullMax = (int)$this->settings->get('image_full_max');
            if ($heic) {
                $gd = $this->imagick->heicToGd($bytes, $fullMax, $this->quality());
                $ok = ($gd !== null && $this->writePair($gd, $name)) || $this->served->writePlaceholder($name, $fullMax, $this->thumbMax(), $this->quality());
            } elseif ((float)$info[0] * (float)$info[1] > (float)ImageLimits::GD_MAX_PIXELS) {
                $gd = $this->imagick->scaledJpeg($bytes, $fullMax);
                if ($gd === null || !$this->writePair($gd, $name)) {
                    throw new UserError(class_exists('Imagick') ? 'Could not read that image.' : ImageLimits::TOO_LARGE);
                }
                $ok = true;
            } else {
                unset($bytes);
                $ok = $this->writePair($this->decodeGd($tmp, $type), $name);
            }
            if (!$ok) {
                throw new UserError('Server could not save the image.');
            }
            $done = true;

            return $name;
        } finally {
            $this->cleanup->drop($undo);
            if (!$done) {
                $this->delete($name);
            }
        }
    }

    /** Remove the served copy, the thumbnail, the original and its map entry. */
    public function delete(?string $name): void
    {
        if ($name === null || !self::validName($name)) {
            return;
        }
        $this->served->delete($name);
        $this->originals->forget($name);
    }

    private function decodeGd(string $tmp, int $type): GdImage
    {
        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default => @imagecreatefromgif($tmp),
        };
        if (!$src instanceof GdImage) {
            throw new UserError('Could not read that image.');
        }
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $src = $this->orient($src, (int)(is_array($exif) ? ($exif['Orientation'] ?? 1) : 1));
        }

        return $src;
    }

    private function writePair(GdImage $src, string $name): bool
    {
        return $this->served->writePair($src, $name, (int)$this->settings->get('image_full_max'), $this->thumbMax(), $this->quality());
    }

    private function quality(): int
    {
        return (int)$this->settings->get('jpeg_quality');
    }

    private function thumbMax(): int
    {
        return (int)$this->settings->get('image_thumb_max');
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

        return $out instanceof GdImage ? $out : $im;
    }
}
