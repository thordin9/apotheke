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
$fixture = (string)realpath($fixture);   // the app boot changes the directory
$root = is_file('/var/www/app/spark') ? '/var/www/app' : dirname(__DIR__);
// The app's services without a request; apothekeDb() migrates, as 2.x db() did.
define('FCPATH', $root . '/public/');
define('ENVIRONMENT', 'production');
chdir(FCPATH);
require $root . '/app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
Config\Services::apothekeDb();
$dataDir = Config\Services::apothekeConfig()->dataDir();

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
if (!Apotheke\Media\Storage\ImagickDecoder::isHeic($bytes)) $fail('fixture is not a HEIC container');
if (!str_contains($bytes, 'GPSLatitude') || !str_contains($bytes, 'SerialNumber')) $fail('fixture has no EXIF GPS text');

$tmp = tempnam(sys_get_temp_dir(), 'heic');
if ($tmp === false) $fail('temp');
file_put_contents($tmp, $bytes);
try {
    $name = Config\Services::imageStore()->store($tmp, strlen($bytes));
} catch (Throwable $e) {
    $fail('image_store: ' . $e->getMessage());
} finally {
    @unlink($tmp);
}

$originals = Config\Services::apothekeConfig()->originalsDir();
if (count(glob($originals . '/*.heic') ?: []) !== 1) $fail('original was not kept as heic');
$orig = (new Apotheke\Media\Storage\OriginalStore(new Apotheke\Core\Storage\LocalStorage($originals)))->read($name);
if ($orig === null || hash('sha256', $orig) !== hash('sha256', $bytes)) $fail('original sha256 changed');

$served = (string)file_get_contents($dataDir . '/images/' . $name);
$info = getimagesizefromstring($served);
if (!$info || $info[2] !== IMAGETYPE_JPEG || !str_starts_with($served, "\xFF\xD8")) $fail('served copy is not a JPEG');
if ($info[0] === 64 && $info[1] === 64) $fail('served copy is the placeholder');
if ($info[0] !== 96 || $info[1] !== 64) $fail('served JPEG is ' . $info[0] . 'x' . $info[1]);
heic_check_stripped($served, 'served');

$autofill = Config\Services::autofill();
$analysis = $autofill->analysis()->jpeg($bytes);
if (!is_string($analysis) || !str_starts_with($analysis, "\xFF\xD8")) $fail('analysis copy is not a JPEG');
$ai = getimagesizefromstring($analysis);
if (!$ai || $ai[2] !== IMAGETYPE_JPEG || $ai[0] !== 96 || $ai[1] !== 64) $fail('analysis JPEG has the wrong size');
heic_check_stripped($analysis, 'analysis');

$opened = $autofill->analysis()->open($bytes, 1600, '{}');
if (!$opened['ok']) $fail('analysis file was not written: ' . ($opened['message'] ?? ''));
$path = $dataDir . '/' . $opened['path'];
if (!is_file($path)) $fail('analysis temp file missing');
heic_check_stripped((string)file_get_contents($path), 'analysis file');
$autofill->releaseAnalysis($opened['path']);
if (is_file($path)) $fail('analysis temp file was left behind');

echo "ok heic round-trip\n";

function heic_check_stripped(string $jpeg, string $which): void
{
    if (str_contains($jpeg, 'GPSLatitude') || str_contains($jpeg, 'SerialNumber') || str_contains($jpeg, "\xFF\xE1")) {
        fwrite(STDERR, "$which JPEG still has EXIF/GPS\n");
        exit(1);
    }
}
