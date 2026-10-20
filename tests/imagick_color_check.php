<?php
declare(strict_types=1);

/*
 * Imagick fallback inside the Docker image: transparency on white, CMYK as sRGB.
 *   php tests/imagick_color_check.php
 * Skips nothing. Missing Imagick, a black transparent pixel, or a CMYK analysis JPEG is a failure.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli only\n");
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
db();

$png = imagick_color_transparent_png();
$analysis = autofill_analysis_jpeg($png, 32, 95, ['imagick']);
if (!is_string($analysis) || !str_starts_with($analysis, "\xFF\xD8")) $fail('analysis copy of a transparent PNG is not a JPEG');
$clear = imagick_color_pixel($analysis, 24, 24);
if (min($clear) <= 250) $fail('transparent pixel is not white: ' . implode(',', $clear));
$gd = autofill_analysis_jpeg($png, 32, 95, ['gd']);
if (!is_string($gd)) $fail('GD could not read the transparent PNG');
imagick_color_near($clear, imagick_color_pixel($gd, 24, 24), 8, 'transparent PNG');
$red = imagick_color_pixel($analysis, 2, 2);
if ($red[0] < 150 || $red[1] > 40 || $red[2] > 40) $fail('opaque red pixel was not kept: ' . implode(',', $red));
if (imagick_color_colorspace($analysis) !== Imagick::COLORSPACE_SRGB) $fail('transparent PNG analysis is not sRGB');
if (str_contains($analysis, 'ICC_PROFILE')) $fail('transparent PNG analysis still has an ICC profile');

$cmyk = imagick_color_cmyk_jpeg();
if (imagick_color_components($cmyk) !== 4) $fail('CMYK fixture is not 4 components');
$converted = autofill_analysis_jpeg($cmyk, 16, 95, ['imagick']);
if (!is_string($converted) || !str_starts_with($converted, "\xFF\xD8")) $fail('CMYK analysis copy is not a JPEG');
if (imagick_color_components($converted) !== 3) $fail('CMYK analysis JPEG is still ' . imagick_color_components($converted) . ' components');
if (imagick_color_colorspace($converted) !== Imagick::COLORSPACE_SRGB) $fail('CMYK analysis JPEG is not sRGB');
if (str_contains($converted, 'ICC_PROFILE')) $fail('CMYK analysis JPEG still has an ICC profile');
$gdCmyk = autofill_analysis_jpeg($cmyk, 16, 95, ['gd']);
if (!is_string($gdCmyk)) $fail('GD could not read the CMYK JPEG');
imagick_color_near(imagick_color_pixel($converted, 4, 4), imagick_color_pixel($gdCmyk, 4, 4), 8, 'CMYK JPEG');

if (!function_exists('autofill_heic_supported') || !autofill_heic_supported()) $fail('Imagick does not list HEIC');
$tmp = tempnam(sys_get_temp_dir(), 'alpha');
if ($tmp === false) $fail('temp');
file_put_contents($tmp, $png);
$name = bin2hex(random_bytes(16)) . '.jpg';
try {
    if (!image_store_heic($tmp, $name)) $fail('image_store_heic failed on a transparent PNG');
} finally {
    @unlink($tmp);
}
$served = (string)file_get_contents(IMG_DIR . '/' . $name);
$servedClear = imagick_color_pixel($served, 24, 24);
if (min($servedClear) <= 250) $fail('served transparent pixel is not white: ' . implode(',', $servedClear));
if (imagick_color_components($served) !== 3) $fail('served JPEG is not 3 components');
if (imagick_color_colorspace($served) !== Imagick::COLORSPACE_SRGB) $fail('served JPEG is not sRGB');

echo "ok imagick white background and sRGB\n";

function imagick_color_transparent_png(): string
{
    $im = imagecreatetruecolor(32, 32);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, 0, 0, 7, 7, imagecolorallocatealpha($im, 200, 10, 10, 0));
    ob_start();
    imagepng($im);
    return (string)ob_get_clean();
}

function imagick_color_cmyk_jpeg(): string
{
    $im = new Imagick();
    $im->newImage(16, 16, new ImagickPixel('cmyk(0%,80%,90%,0%)'));
    $im->setImageColorspace(Imagick::COLORSPACE_CMYK);
    $im->setImageFormat('jpeg');
    $im->setImageCompressionQuality(95);
    $out = $im->getImageBlob();
    $im->clear();
    if (!is_string($out)) {
        fwrite(STDERR, "could not build a CMYK JPEG\n");
        exit(1);
    }
    return $out;
}

/** @return array{0:int,1:int,2:int} */
function imagick_color_pixel(string $jpeg, int $x, int $y): array
{
    $im = imagecreatefromstring($jpeg);
    if ($im === false) {
        fwrite(STDERR, "could not read a JPEG back\n");
        exit(1);
    }
    $c = imagecolorat($im, $x, $y);
    return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
}

function imagick_color_colorspace(string $jpeg): int
{
    $im = new Imagick();
    $im->readImageBlob($jpeg);
    $cs = $im->getImageColorspace();
    $im->clear();
    return $cs;
}

function imagick_color_components(string $jpeg): int
{
    $i = 2;
    $n = strlen($jpeg);
    while ($i + 3 < $n) {
        if ($jpeg[$i] !== "\xFF") { $i++; continue; }
        $marker = ord($jpeg[$i + 1]);
        while ($marker === 0xFF && $i + 2 < $n) { $i++; $marker = ord($jpeg[$i + 1]); }
        if ($marker === 0xD8 || $marker === 0xD9 || $marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) { $i += 2; continue; }
        if ($i + 4 > $n) break;
        $len = unpack('n', substr($jpeg, $i + 2, 2))[1];
        if ($len < 2) break;
        if (in_array($marker, [0xC0, 0xC1, 0xC2], true)) return ord($jpeg[$i + 9]);
        if ($marker === 0xDA) break;
        $i += 2 + $len;
    }
    return 0;
}

/** @param array{0:int,1:int,2:int} $got @param array{0:int,1:int,2:int} $want */
function imagick_color_near(array $got, array $want, int $delta, string $which): void
{
    foreach ([0, 1, 2] as $i) {
        if (abs($got[$i] - $want[$i]) > $delta) {
            fwrite(STDERR, "$which Imagick " . implode(',', $got) . " vs GD " . implode(',', $want) . "\n");
            exit(1);
        }
    }
}
