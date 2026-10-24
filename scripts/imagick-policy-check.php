<?php
declare(strict_types=1);

/*
 * Checks that the ImageMagick policy (docker/imagemagick-policy.xml) is in effect for Imagick (S-M1, S-L1).
 * Run in the image: at build time from the Dockerfile and again in CI (scripts/docker-heic.sh).
 * Exit 0 when every limit matches, the denied coders are refused and `magick -list policy` shows no rights for
 * delegates or "@file" paths and a time limit of 60 s or less; 1 otherwise.
 * Every `delegate` and `path` rule must have rights None, so a later, narrower allow rule fails too (#73 QA N-1).
 */

/** `magick -list policy` output as a list of blocks: domain plus name, value, rights and pattern when shown. */
function imagick_policy_blocks(string $listing): array
{
    $blocks = [];
    foreach (preg_split('/^\s*Policy:\s*/m', $listing) ?: [] as $i => $chunk) {
        if ($i === 0) continue;
        $b = ['domain' => strtolower(trim((string)strtok($chunk, "\n")))];
        if (preg_match_all('/^\s*(name|value|rights|pattern):\s*(.*?)\s*$/m', $chunk, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $k, $v]) $b[$k] = $v;
        }
        $blocks[] = $b;
    }
    return $blocks;
}

/**
 * Delegates and "@file" paths: `delegate *` and `path @*` must be there with rights None, and no other delegate or
 * path rule may grant anything (a narrower allow rule would re-enable what the blanket rule refuses).
 * @return list<array{0:bool,1:string}>
 */
function imagick_policy_rights_checks(array $blocks): array
{
    $out = [];
    foreach (['delegate' => '*', 'path' => '@*'] as $domain => $pattern) {
        $rules = array_filter($blocks, static fn(array $b): bool => $b['domain'] === $domain);
        $blanket = array_filter($rules, static fn(array $b): bool => ($b['pattern'] ?? '') === $pattern);
        $allow = array_filter($rules, static fn(array $b): bool => strcasecmp($b['rights'] ?? '', 'None') !== 0);
        $out[] = [$blanket !== [] && $allow === [], "$domain rules have rights None (" . ($blanket === [] ? "no $domain $pattern rule"
            : ($allow === [] ? count($rules) . ' rule(s)' : 'allowed: ' . implode(', ', array_map(static fn(array $b): string => ($b['pattern'] ?? '?') . ' ' . ($b['rights'] ?? '?'), $allow)))) . ')'];
    }
    return $out;
}

// Loaded by a test for the functions above: stop here.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

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
// Rights and the time limit come from `magick -list policy` (Imagick can't report them): delegates and "@file"
// paths must have no rights at all, and the time limit must be 60 s or less. A saved listing can be passed as the
// first argument instead (for testing the check itself).
$listing = isset($argv[1]) ? (string)@file_get_contents($argv[1]) : (string)shell_exec('magick -list policy 2>/dev/null');
$blocks = imagick_policy_blocks($listing);
$say($blocks !== [], 'magick -list policy shows the policy');
foreach (imagick_policy_rights_checks($blocks) as [$ok, $what]) $say($ok, $what);
$times = array_map(static fn(array $b): string => $b['value'] ?? '', array_filter($blocks, static fn(array $b): bool => $b['domain'] === 'resource' && strcasecmp($b['name'] ?? '', 'time') === 0));
$timeOk = $times !== [] && array_filter($times, static fn(string $v): bool => !ctype_digit($v) || (int)$v > 60 || (int)$v === 0) === [];
$say($timeOk, 'limit TIME <= 60 s (got ' . ($times === [] ? 'none' : implode(', ', $times)) . ')');

$fmts = array_map('strtoupper', Imagick::queryFormats());
$say(in_array('HEIC', $fmts, true) || in_array('HEIF', $fmts, true), 'HEIC/HEIF still listed');

exit($fail);
