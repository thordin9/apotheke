<?php

declare(strict_types=1);

namespace Apotheke\Settings\Branding;

use Apotheke\Settings\SettingsSchema;

/**
 * Accent colour maths, ported from legacy settings.php: WCAG contrast,
 * light and dark variants that pass AA (4.5:1), and the /theme.css text.
 * Pure: no state.
 */
final class Accent
{
    public const DEFAULT_DARK = '#8b93ff';
    public const PRESETS = [
        '#4f46e5' => 'Indigo (default)', '#C8502D' => 'Terracotta', '#6B3A1F' => 'Brown', '#0f766e' => 'Teal',
        '#15803d' => 'Green', '#2563eb' => 'Blue', '#be123c' => 'Rose', '#7c3aed' => 'Violet', '#475569' => 'Slate',
    ];
    /** Backgrounds the accent is checked against (page + cards). */
    public const BG_LIGHT = ['#f5f5f7', '#ffffff'];
    public const BG_DARK = ['#111113', '#1c1c20'];

    /** @return array{0:float,1:float,2:float} 0–255 */
    public static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');

        return [(float)hexdec(substr($h, 0, 2)), (float)hexdec(substr($h, 2, 2)), (float)hexdec(substr($h, 4, 2))];
    }

    /** @param array<int,float|int> $c */
    public static function hex(array $c): string
    {
        return sprintf('#%02x%02x%02x', ...array_map(static fn ($v): int => (int)round(max(0, min(255, $v))), $c));
    }

    public static function luminance(string $hex): float
    {
        $l = array_map(static function (float $v): float {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    }

    public static function contrast(string $a, string $b): float
    {
        [$x, $y] = [self::luminance($a), self::luminance($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** Mix $hex towards $to by $t (0–1). */
    public static function mix(string $hex, string $to, float $t): string
    {
        $a = self::rgb($hex);
        $b = self::rgb($to);

        return self::hex([$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t]);
    }

    /**
     * Smallest mix towards $to that reaches $ratio against every background.
     *
     * @param list<string> $bgs
     */
    public static function fit(string $hex, array $bgs, string $to, float $ratio = 4.5): string
    {
        for ($i = 0; $i <= 50; $i++) {
            $c = self::mix($hex, $to, $i / 50);
            if (min(array_map(static fn (string $bg): float => self::contrast($c, $bg), $bgs)) >= $ratio) {
                return $c;
            }
        }

        return $to;
    }

    /**
     * Light and dark variants with ink, hover, soft tint and shadow (legacy accent_variants()).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function variants(string $hex): array
    {
        $hex = strtolower($hex);
        $isDefault = $hex === strtolower(SettingsSchema::ACCENT_DEFAULT);
        $light = self::fit($hex, self::BG_LIGHT, '#000000');
        // The built-in indigo keeps its hand-picked dark shade (app.css).
        $dark = $isDefault ? self::DEFAULT_DARK : self::fit($hex, self::BG_DARK, '#ffffff');
        $ink = static fn (string $c): string => self::contrast($c, '#ffffff') >= self::contrast($c, '#0b0b10') ? '#ffffff' : '#0b0b10';
        $rgba = static fn (string $c, float $a): string => 'rgba(' . implode(',', array_map('intval', self::rgb($c))) . ',' . $a . ')';
        $out = [];
        foreach (['light' => [$light, self::BG_LIGHT, '#000000', 0.10], 'dark' => [$dark, self::BG_DARK, '#ffffff', 0.16]] as $mode => [$c, $bgs, $to, $soft]) {
            $out[$mode] = [
                'accent' => $c, 'hover' => self::mix($c, $to, 0.15), 'ink' => $ink($c), 'soft' => $rgba($c, $soft), 'shadow' => $rgba($c, 0.4),
                'contrast' => round(min(array_map(static fn (string $bg): float => self::contrast($c, $bg), $bgs)), 2),
                'ink_contrast' => round(self::contrast($c, $ink($c)), 2),
            ];
        }
        $out['raw'] = [
            'light' => round(min(array_map(static fn (string $bg): float => self::contrast($hex, $bg), self::BG_LIGHT)), 2),
            'dark' => round(min(array_map(static fn (string $bg): float => self::contrast($hex, $bg), self::BG_DARK)), 2),
        ];
        $out['adjusted'] = ['light' => $light !== $hex, 'dark' => !$isDefault && $dark !== $hex];

        return $out;
    }

    /**
     * Human warnings for the Control Panel (legacy accent_warnings()).
     *
     * @return list<string>
     */
    public static function warnings(string $hex): array
    {
        $v = self::variants($hex);
        $w = [];
        foreach (['light', 'dark'] as $mode) {
            if ($v['adjusted'][$mode]) {
                $w[] = sprintf('%s is %.2f:1 on the %s background (WCAG AA needs 4.5:1) – %s mode uses %s instead.', strtoupper($hex), $v['raw'][$mode], $mode, ucfirst($mode), $v[$mode]['accent']);
            }
        }

        return $w;
    }

    /** The /theme.css text for a custom accent; '' for the default (legacy accent_css()). */
    public static function css(string $hex): string
    {
        if (strcasecmp($hex, SettingsSchema::ACCENT_DEFAULT) === 0) {
            return '';
        }
        $v = self::variants($hex);
        $vars = static fn (array $x): string => "--accent:{$x['accent']};--accent-2:{$x['hover']};--accent-ink:{$x['ink']};--accent-soft:{$x['soft']};--accent-shadow:{$x['shadow']}";

        return "/* Apotheke accent {$hex} (generated) */\n"
            . ':root{' . $vars($v['light']) . "}\n"
            . ':root[data-theme=dark]{' . $vars($v['dark']) . "}\n"
            . '@media (prefers-color-scheme:dark){:root:not([data-theme=light]):not([data-theme=dark]){' . $vars($v['dark']) . "}}\n";
    }
}
