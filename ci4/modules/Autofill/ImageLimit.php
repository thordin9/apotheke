<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Apotheke\Settings\SettingsService;

/**
 * Byte cap for the analysis JPEG.
 *
 * Order: the service's enforced limit (`enforced_image_bytes`), then the
 * configured maximum (`autofill_max_image_bytes`; `auto` is `/v1/info`
 * `max_image_bytes`, or 983040 when that field has not been seen), then
 * the configured value as stored when the service has not said. An explicit
 * value is already bounded to 65536–10485760 by Settings. The cap is the
 * JPEG, not the multipart wrapper. A lower cap is logged once per distinct
 * pair, and the line has no token.
 */
final class ImageLimit
{
    /** Until /v1/info has cached max_image_bytes. 1 MiB − 64 KiB. */
    public const FALLBACK_BYTES = 983040;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly InfoCache $info,
        private readonly LogRedactor $log,
    ) {
    }

    public function configured(): int
    {
        $raw = $this->settings->get('autofill_max_image_bytes');
        if ($raw !== 'auto') {
            return (int)$raw;
        }
        foreach ([$this->info->get(), $this->info->get(PHP_INT_MAX)] as $info) {
            $n = is_array($info) ? ($info['max_image_bytes'] ?? null) : null;
            if (is_int($n) && $n > 0) {
                return $n;
            }
        }

        return self::FALLBACK_BYTES;
    }

    public function enforced(): ?int
    {
        return $this->positive('enforced_image_bytes');
    }

    public function advertised(): ?int
    {
        return $this->positive('max_image_bytes');
    }

    /** The cap that applies now. $warn logs when a service field lowers it, once per pair. */
    public function target(bool $warn = false): int
    {
        $target = $this->configured();
        $cap = $this->enforced();
        $why = 'enforced_image_bytes';
        if ($cap === null && $this->settings->get('autofill_max_image_bytes') !== 'auto') {
            $cap = $this->advertised();
            $why = 'max_image_bytes';
        }
        if ($cap !== null && $cap < $target) {
            if ($warn && $this->info->rememberClamp($target, $cap, $why)) {
                $this->log->warning('image limit clamped from ' . $target . ' to ' . $cap . ' bytes (' . $why . ')');
            }

            return $cap;
        }

        return $target;
    }

    /** 10485760 → "10 MiB", 983040 → "0.9 MiB", 1000 → "1 KB". A 1-decimal MiB of 0 would hide a tiny limit. */
    public function mib(int $bytes): string
    {
        $mib = $bytes / 1048576;
        if ($bytes > 0 && $mib < 0.05) {
            $kb = $bytes / 1024;
            $decimals = $kb >= 0.05 ? 1 : 2;

            return rtrim(rtrim(number_format($kb, $decimals, '.', ''), '0'), '.') . ' KB';
        }

        return rtrim(rtrim(number_format($mib, 1, '.', ''), '0'), '.') . ' MiB';
    }

    public function tooLargeMessage(int $bytes): string
    {
        return 'No suggestions: the photo is too large (max ' . $this->mib($bytes) . ' / ' . $bytes . ' bytes).';
    }

    private function positive(string $field): ?int
    {
        foreach ([$this->info->get(), $this->info->get(PHP_INT_MAX)] as $info) {
            $n = is_array($info) ? ($info[$field] ?? null) : null;
            if (is_int($n) && $n > 0) {
                return $n;
            }
        }

        return null;
    }
}
