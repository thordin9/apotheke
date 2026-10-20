<?php
declare(strict_types=1);

/*
 * HEIC round-trip inside the Docker image (or any PHP that has Imagick + HEIC).
 *   php tests/heic_image_check.php tests/fixtures/tiny-gps.heic
 * The original sha256 must match. The served JPEG and the analysis JPEG must be real
 * JPEGs with the EXIF/GPS text from the fixture removed. A placeholder is a failure.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
    exit(2);
}
$fixture = $argv[1] ?? '';
if ($fixture === '' || !is_file($fixture)) {
    fwrite(STDERR, "usage: heic_image_check.php fixture.heic\n");
    exit(2);
}
$root = is_file('/var/www/app/src/bootstrap.php') ? '/var/www/app' : dirname(__DIR__);
require $root . '/src/bootstrap.php';
require $root . '/src/items.php';

$fail = static function (string $msg): never {
    fwrite(STDERR, $msg . "\n");
    exit(1);
};

if (!class_exists('Imagick')) $fail('Imagick is not loaded');
$formats = array_map('strtoupper', Imagick::queryFormats());
if (!in_array('HEIC', $formats, true) && !in_array('HEIF', $formats, true)) {
    $fail('Imagick::queryFormats() does not list HEIC or HEIF');
}
echo "ok Imagick lists " . (in_array('HEIC', $formats, true) ? 'HEIC' : 'HEIF') . "\n";

$bytes = (string)file_get_contents($fixture);
if (!image_bytes_heic(substr($bytes, 0, 12))) $fail('fixture is not a HEIC container');
if (!str_contains($bytes, 'GPSLatitude') || !str_contains($bytes, 'SerialNumber')) $fail('fixture has no EXIF GPS text');

db();
$tmp = tempnam(sys_get_temp_dir(), 'heic');
if ($tmp === false) $fail('temp');
file_put_contents($tmp, $bytes);
try {
    $name = image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => 'lamp.heic']);
} catch (Throwable $e) {
    $fail('image_store: ' . $e->getMessage());
} finally {
    @unlink($tmp);
}

$orig = image_original_path($name);
if ($orig === null || !str_ends_with($orig, '.heic')) $fail('original was not kept as heic');
if (hash_file('sha256', $orig) !== hash('sha256', $bytes)) $fail('original sha256 changed');

$served = (string)file_get_contents(IMG_DIR . '/' . $name);
$info = getimagesizefromstring($served);
if (!$info || $info[2] !== IMAGETYPE_JPEG || !str_starts_with($served, "\xFF\xD8")) $fail('served copy is not a JPEG');
if ($info[0] === 64 && $info[1] === 64) $fail('served copy is the placeholder');
if ($info[0] !== 96 || $info[1] !== 64) $fail('served JPEG is ' . $info[0] . 'x' . $info[1]);
heic_check_stripped($served, 'served');

$analysis = autofill_analysis_jpeg($bytes);
if (!is_string($analysis) || !str_starts_with($analysis, "\xFF\xD8")) $fail('analysis copy is not a JPEG');
$ai = getimagesizefromstring($analysis);
if (!$ai || $ai[2] !== IMAGETYPE_JPEG || $ai[0] !== 96 || $ai[1] !== 64) $fail('analysis JPEG has the wrong size');
heic_check_stripped($analysis, 'analysis');

$opened = autofill_open_analysis($bytes, 1600, '{}');
if (!$opened['ok']) $fail('analysis file was not written: ' . ($opened['message'] ?? ''));
if (!is_file($opened['path'])) $fail('analysis temp file missing');
heic_check_stripped((string)file_get_contents($opened['path']), 'analysis file');
autofill_unlink_analysis($opened['path']);
if (is_file($opened['path'])) $fail('analysis temp file was left behind');

echo "ok heic round-trip\n";

function heic_check_stripped(string $jpeg, string $which): void
{
    if (str_contains($jpeg, 'GPSLatitude') || str_contains($jpeg, 'SerialNumber') || str_contains($jpeg, "\xFF\xE1")) {
        fwrite(STDERR, "$which JPEG still has EXIF/GPS\n");
        exit(1);
    }
}
