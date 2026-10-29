<?php

declare(strict_types=1);

namespace Apotheke\Settings\Branding;

use Apotheke\Core\Libraries\AssetUrl;
use Apotheke\Settings\BrandStore;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;

/**
 * Public branding output: /theme.css, /labels.css, the web app manifest,
 * the favicon and the logo/icon URLs. Bytes match legacy src/settings.php.
 * Built per request by Config\Services; holds no state of its own.
 */
final class Branding
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly BrandStore $brand,
        private readonly AssetUrl $assets,
    ) {
    }

    /** The generated stylesheet for a custom accent; '' for the default accent. */
    public function themeCss(): string
    {
        return Accent::css((string)$this->settings->get('accent'));
    }

    /** Generated print stylesheet for the QR label grid (legacy labels_css()). */
    public function labelsCss(): string
    {
        $s = $this->settings;
        $w = (int)$s->get('label_width_mm');
        $hgt = (int)$s->get('label_height_mm');
        $c = (int)$s->get('label_columns');
        $g = (int)$s->get('label_gap_mm');
        $qr = max(8, min($w - 6, $hgt - ($s->get('label_show_sub') ? 18 : 12) - ($s->get('label_show_url') ? 4 : 0)));

        return "/* QR label grid from Settings > QR labels */\n@media print{\n"
            . ".labels{grid-template-columns:repeat($c,{$w}mm)!important;gap:{$g}mm!important;justify-content:start}\n"
            . ".label{width:{$w}mm;height:{$hgt}mm;overflow:hidden;padding:2mm!important;box-sizing:border-box;justify-content:center"
            . ($s->get('label_border') ? '' : ';border:0!important') . "}\n"
            . ".label .qr{width:{$qr}mm!important;max-width:{$qr}mm!important;height:{$qr}mm!important}\n}\n";
    }

    /**
     * The web app manifest (legacy manifest_data()).
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        $s = $this->settings;
        $accent = (string)$s->get('accent');
        $logo = (string)$s->get('logo');
        $custom = $logo !== '';
        $icons = [];
        if ($logo === 'svg') {
            $icons[] = ['src' => $this->logoUrl(), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
        } elseif (!$custom) {
            $icons[] = ['src' => '/assets/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
        }
        $icons[] = ['src' => $this->iconUrl('192'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
        $icons[] = ['src' => $this->iconUrl('512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
        $icons[] = ['src' => $this->iconUrl('maskable-512'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
        $name = (string)$s->get('app_name');

        return [
            'name' => $name, 'short_name' => mb_substr($name, 0, 12),
            'description' => $name . ', ' . (string)$s->get('app_tagline'),
            'start_url' => '/', 'scope' => '/', 'display' => 'standalone',
            'background_color' => $custom ? (string)$s->get('icon_background') : '#FBF1E3',
            'theme_color' => strcasecmp($accent, SettingsSchema::ACCENT_DEFAULT) === 0 ? '#C8502D' : Accent::variants($accent)['light']['accent'],
            'icons' => $icons,
        ];
    }

    /** Cache buster for brand files (legacy brand_version()). */
    public function version(): string
    {
        $logo = (string)$this->settings->get('logo');
        $name = 'logo.' . ($logo !== '' ? $logo : 'none');
        $time = $this->brand->modifiedAt($name);
        $size = $this->brand->size($name);
        if ($time === null || $size === null) {
            return '0';
        }

        return substr(md5((string)$time . $size . (string)$this->settings->get('icon_background')), 0, 8);
    }

    /** URL of the header logo (custom or the bundled one). */
    public function logoUrl(): string
    {
        $logo = (string)$this->settings->get('logo');

        return $logo !== '' && $this->brand->has("logo.$logo") ? "/brand/logo.$logo?v=" . $this->version() : $this->assets->url('icon.svg');
    }

    /** URL of a PNG icon (32, 180, 192, 512, maskable-512): generated from a raster logo, else the bundled icon. */
    public function iconUrl(string $size): string
    {
        $map = ['32' => 'icon-32.png', '180' => 'apple-touch-icon.png', '192' => 'icon-192.png', '512' => 'icon-512.png', 'maskable-512' => 'icon-maskable-512.png'];
        $own = $size === '180' ? 'icon-180.png' : "icon-$size.png";
        if ($this->settings->get('logo') === 'png' && $this->brand->has($own)) {
            return "/brand/$own?v=" . $this->version();
        }

        return $this->assets->url($map[$size] ?? 'icon-192.png');
    }

    public function faviconSvgUrl(): string
    {
        return $this->settings->get('logo') === 'svg' && $this->brand->has('logo.svg')
            ? '/brand/logo.svg?v=' . $this->version() : $this->assets->url('favicon.svg');
    }

    /** The favicon bytes: generated from a raster logo, else the bundled one. */
    public function favicon(): string
    {
        if ($this->settings->get('logo') === 'png') {
            $own = $this->brand->read('favicon.ico');
            if ($own !== null) {
                return $own;
            }
        }
        $bundled = @file_get_contents($this->assets->path('favicon.ico'));

        return $bundled === false ? '' : $bundled;
    }
}
