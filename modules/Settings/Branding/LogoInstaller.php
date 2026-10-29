<?php

declare(strict_types=1);

namespace Apotheke\Settings\Branding;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Settings\BrandStore;
use Apotheke\Settings\SettingsService;
use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMEntityReference;
use DOMNode;
use DOMProcessingInstruction;
use GdImage;
use RuntimeException;

/**
 * Control Panel → Appearance → Upload a logo (legacy brand_store(),
 * svg_sanitize(), icon_square(), gd_png() and ico_from_pngs() in
 * 2.x settings.php). An SVG is cleaned and kept as is; a PNG, JPEG or WebP
 * becomes a square 512 px PNG plus the favicon, the home-screen icon and the
 * app icons on the icon background colour. The files go to the BrandStore,
 * so the same bytes as legacy end up in DATA_DIR/brand.
 */
final class LogoInstaller
{
    /** Legacy LOGO_MAX_BYTES. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const SVG_MAX_BYTES = 512 * 1024;

    private const ELEMENTS = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'lineargradient', 'radialgradient',
        'stop', 'title', 'desc', 'clippath', 'mask', 'symbol', 'use', 'text', 'tspan'];

    private const ATTRIBUTES = ['id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-miterlimit', 'opacity', 'transform', 'offset', 'stop-color', 'stop-opacity', 'gradientunits',
        'gradienttransform', 'spreadmethod', 'clip-path', 'clip-rule', 'mask', 'maskunits', 'clippathunits', 'preserveaspectratio',
        'font-family', 'font-size', 'font-weight', 'font-style', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'version', 'href'];

    public function __construct(private readonly BrandStore $store, private readonly SettingsService $settings)
    {
    }

    /**
     * Install an uploaded logo. Throws UserError (422) with legacy's message.
     *
     * @param int $error the upload's UPLOAD_ERR_* code
     * @param bool $uploaded is_uploaded_file() of $tmp (legacy skips that check on the command line)
     * @param callable(int):string $errorText legacy upload_error_text()
     * @return string 'svg' or 'png'
     */
    public function install(int $error, string $tmp, int $size, bool $uploaded, callable $errorText): string
    {
        if ($error !== UPLOAD_ERR_OK) {
            throw new UserError($errorText($error));
        }
        if (!$uploaded) {
            throw new UserError('Invalid upload.');
        }
        if ($size > self::MAX_BYTES) {
            throw new UserError('That logo is too large (max 2 MB).');
        }
        $bytes = (string)@file_get_contents($tmp);
        $head = ltrim(substr($bytes, 0, 512));
        if (str_starts_with($head, '<?xml') || str_starts_with($head, '<svg') || preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg\b/s', $bytes)) {
            $clean = self::sanitizeSvg($bytes);
            $this->store->clear();
            $this->put('logo.svg', $clean);
            $this->settings->store(['logo' => 'svg']);

            return 'svg';
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new UserError('Unsupported logo. Use SVG, PNG, JPEG or WebP.');
        }
        if ($info[0] < 64 || $info[1] < 64) {
            throw new UserError('The logo must be at least 64×64 pixels.');
        }
        if ($info[0] > 4096 || $info[1] > 4096) {
            throw new UserError('The logo can be at most 4096×4096 pixels.');
        }
        $src = @imagecreatefromstring($bytes);
        if (!$src instanceof GdImage) {
            throw new UserError('Could not read that image.');
        }
        imagesavealpha($src, true);
        $bg = (string)$this->settings->get('icon_background');
        $files = [
            'logo.png' => self::png(self::square($src, 512)),
            'icon-32.png' => self::png(self::square($src, 32)),
            'icon-180.png' => self::png(self::square($src, 180, $bg, 0.08)),
            'icon-192.png' => self::png(self::square($src, 192)),
            'icon-512.png' => self::png(self::square($src, 512)),
            'icon-maskable-512.png' => self::png(self::square($src, 512, $bg, 0.2)),
            'favicon.ico' => self::ico([16 => self::png(self::square($src, 16)), 32 => self::png(self::square($src, 32)), 48 => self::png(self::square($src, 48))]),
        ];
        $this->store->clear();
        foreach ($files as $name => $data) {
            $this->put($name, $data);
        }
        $this->settings->store(['logo' => 'png']);

        return 'png';
    }

    /** Back to the bundled logo (legacy brand_reset()). */
    public function reset(): void
    {
        $this->store->clear();
        $this->settings->store(['logo' => '']);
    }

    /**
     * Legacy svg_sanitize(): only drawing elements and presentation
     * attributes stay; no scripts, event handlers, external references,
     * foreignObject, entities or styles that load anything.
     */
    public static function sanitizeSvg(string $svg): string
    {
        if (!class_exists(DOMDocument::class)) {
            throw new UserError('SVG logos need the PHP dom extension; upload a PNG, JPEG or WebP instead.');
        }
        if (strlen($svg) > self::SVG_MAX_BYTES) {
            throw new UserError('That SVG is too large (max 512 KB).');
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) {
            throw new UserError('SVG files with a DOCTYPE or entities are not accepted.');
        }
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->documentElement;
        if (!$ok || $root === null || strtolower($root->localName) !== 'svg') {
            throw new UserError('That is not a valid SVG image.');
        }
        self::walk($root);
        if (!$root->hasAttribute('viewBox') && !($root->hasAttribute('width') && $root->hasAttribute('height'))) {
            throw new UserError('The SVG needs a viewBox (or width and height).');
        }
        $out = $doc->saveXML($root);
        if ($out === false || !str_contains($out, '<')) {
            throw new UserError('That is not a valid SVG image.');
        }
        if (!$root->hasAttribute('xmlns')) {
            $out = (string)preg_replace('/^<svg\b/', '<svg xmlns="http://www.w3.org/2000/svg"', $out, 1);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n";
    }

    private static function walk(DOMNode $n): void
    {
        foreach (iterator_to_array($n->childNodes) as $c) {
            if ($c instanceof DOMElement) {
                if (!in_array(strtolower($c->localName), self::ELEMENTS, true) || ($c->namespaceURI !== null && $c->namespaceURI !== 'http://www.w3.org/2000/svg')) {
                    $n->removeChild($c);
                    continue;
                }
                self::walk($c);
            } elseif ($c instanceof DOMProcessingInstruction || $c instanceof DOMComment || $c instanceof DOMEntityReference) {
                $n->removeChild($c);
            } elseif ($c instanceof DOMCdataSection && $n->ownerDocument !== null) {
                $n->replaceChild($n->ownerDocument->createTextNode($c->data), $c);
            }
        }
        if ($n instanceof DOMElement) {
            foreach (iterator_to_array($n->attributes) as $a) {
                $name = strtolower($a->localName);
                $val = (string)$a->value;
                $bad = !in_array($name, self::ATTRIBUTES, true)
                    || ($a->namespaceURI !== null && $a->namespaceURI !== 'http://www.w3.org/1999/xlink')
                    || preg_match('/javascript:|data:|expression\s*\(|url\s*\(\s*[\'"]?\s*(?!#)/i', $val)
                    || ($name === 'href' && !str_starts_with($val, '#'));
                if ($bad) {
                    $n->removeAttributeNode($a);
                }
            }
        }
    }

    /** Legacy icon_square(): fit $src into $size×$size, transparent or on $bg with $inset padding. */
    private static function square(GdImage $src, int $size, ?string $bg = null, float $inset = 0.0): GdImage
    {
        $im = imagecreatetruecolor($size, $size);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        if ($bg === null) {
            imagefill($im, 0, 0, (int)imagecolorallocatealpha($im, 0, 0, 0, 127));
        } else {
            [$r, $g, $b] = Accent::rgb($bg);
            imagefill($im, 0, 0, (int)imagecolorallocate($im, (int)$r, (int)$g, (int)$b));
        }
        imagealphablending($im, true);
        $w = imagesx($src);
        $h = imagesy($src);
        $box = $size * (1 - 2 * $inset);
        $s = $box / max($w, $h);
        $nw = max(1, (int)round($w * $s));
        $nh = max(1, (int)round($h * $s));
        imagecopyresampled($im, $src, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);

        return $im;
    }

    private static function png(GdImage $im): string
    {
        ob_start();
        imagepng($im, null, 9);

        return (string)ob_get_clean();
    }

    /** @param array<int,string> $pngs size => PNG bytes (legacy ico_from_pngs()) */
    private static function ico(array $pngs): string
    {
        $head = pack('vvv', 0, 1, count($pngs));
        $dir = $data = '';
        $offset = 6 + 16 * count($pngs);
        foreach ($pngs as $size => $png) {
            $dir .= pack('CCCCvvVV', $size >= 256 ? 0 : $size, $size >= 256 ? 0 : $size, 0, 0, 1, 32, strlen($png), $offset);
            $data .= $png;
            $offset += strlen($png);
        }

        return $head . $dir . $data;
    }

    private function put(string $name, string $bytes): void
    {
        try {
            $this->store->put($name, $bytes);
        } catch (RuntimeException) {
            throw new UserError('Server could not save the logo.');
        }
    }
}
