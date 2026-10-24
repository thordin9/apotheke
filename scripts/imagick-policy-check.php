<?php
declare(strict_types=1);

/*
 * Checks that the ImageMagick policy (docker/imagemagick-policy.xml) is in effect for Imagick (S-M1, S-L1).
 * Run in the image: at build time from the Dockerfile and again in CI (scripts/docker-heic.sh).
 * Exit 0 when every limit matches and the denied coders are refused, 1 otherwise.
 */

$fail = 0;
$say = static function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) $fail = 1;
};
if (!class_exists('Imagick')) { fwrite(STDERR, "Imagick missing\n"); exit(1); }

// Width and height are plain pixel counts (16384; note that 16KP would mean 16000). MP is decimal; MiB and GiB are binary.
$want = ['WIDTH' => 16384, 'HEIGHT' => 16384, 'AREA' => 128000000, 'MEMORY' => 268435456, 'MAP' => 268435456, 'DISK' => 1073741824];
foreach ($want as $name => $limit) {
    $c = 'Imagick::RESOURCETYPE_' . $name;
    $got = defined($c) ? (int)Imagick::getResourceLimit(constant($c)) : -1;
    $say($got === $limit, "limit $name = $limit (got $got)");
}

// TXT, MVG and MSL are built into every ImageMagick, so they must be refused by the policy itself.
// A coder this build doesn't include at all ("no decode delegate") is refused just as well.
foreach (['TXT', 'TEXT', 'MVG', 'MSL', 'LABEL', 'CAPTION', 'HTTP', 'HTTPS', 'FTP', 'URL', 'XPS', 'EPHEMERAL'] as $coder) {
    try {
        (new Imagick())->readImage($coder . ':/etc/hostname');
        $say(false, "coder $coder refused (it was read)");
    } catch (ImagickException $e) {
        $msg = $e->getMessage();
        $byPolicy = str_contains($msg, 'security policy');
        $say($byPolicy || (!in_array($coder, ['TXT', 'MVG', 'MSL'], true) && str_contains($msg, 'no decode delegate')), "coder $coder refused" . ($byPolicy ? ' by the policy' : ' (not built in)'));
    }
}

$wide = '';
try {
    $g = imagecreatetruecolor(16385, 1);
    ob_start(); imagepng($g); $wide = (string)ob_get_clean();
    (new Imagick())->readImageBlob($wide);
    $say(false, 'a 16385 px wide image is refused (it was read)');
} catch (ImagickException $e) {
    // ImageMagick says "exceeds limit"; its PNG reader passes the limit to libpng, which says "Invalid IHDR data"
    $say(true, 'a 16385 px wide image is refused (' . substr($e->getMessage(), 0, 60) . ')');
}

try {
    $g = imagecreatetruecolor(64, 48);
    ob_start(); imagepng($g); $png = (string)ob_get_clean();
    $im = new Imagick();
    $im->readImageBlob($png);
    $im->setImageFormat('jpeg');
    $say($im->getImageWidth() === 64 && strlen($im->getImageBlob()) > 0, 'an ordinary PNG still decodes and encodes as JPEG');
} catch (Throwable $e) {
    $say(false, 'an ordinary PNG still decodes: ' . $e->getMessage());
}
$fmts = array_map('strtoupper', Imagick::queryFormats());
$say(in_array('HEIC', $fmts, true) || in_array('HEIF', $fmts, true), 'HEIC/HEIF still listed');

exit($fail);
