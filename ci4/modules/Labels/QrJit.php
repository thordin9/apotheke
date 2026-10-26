<?php

declare(strict_types=1);

namespace Apotheke\Labels;

/**
 * Legacy src/qr.php. Function JIT miscompiles the encoder's finder loops,
 * which count up from a negative index: opcache.jit=1235 can emit a wrong
 * matrix on PHP 8.2 and 8.3 and can hang the labels page on PHP 8.4, and any
 * other function-JIT mode can emit a different matrix. Tracing JIT matches
 * the interpreter. guard() turns function JIT off before QrCode is compiled,
 * so call it before the first use of QrCode (the autoloader loads QrCode on
 * that use).
 */
final class QrJit
{
    /**
     * True when opcache.jit is tracing JIT. $kind is
     * opcache_get_status()["jit"]["kind"] (the CRTO trigger digit).
     */
    public static function isTracing(string $jit, int $kind): bool
    {
        $jit = strtolower(trim($jit));
        if ($jit === 'tracing' || $jit === 'on') {
            return true;
        }
        if ($jit === 'function') {
            return false;
        }
        if (preg_match('/^[0-9]{4}$/', $jit) === 1) {
            return $jit[2] === '5';
        }

        // "on" is reported as "1". kind is the trigger digit, and 5 is tracing.
        return $kind === 5;
    }

    public static function guard(): void
    {
        if (!function_exists('opcache_get_status')) {
            return;
        }
        $st = opcache_get_status(false);
        // ini_set() warns once the engine is already off, and the built-in server
        // restores the ini value at the end of each request without turning JIT back on.
        if (!is_array($st) || !is_array($st['jit'] ?? null) || empty($st['jit']['on'])) {
            return;
        }
        $kind = $st['jit']['kind'] ?? -1;
        if (self::isTracing((string)ini_get('opcache.jit'), is_int($kind) ? $kind : -1)) {
            return;
        }
        ini_set('opcache.jit', 'disable');
    }

    /** Legacy qr_svg(): the QR code of $data as an SVG; $title becomes its aria-label. */
    public static function svg(string $data, string $title = '', string $ecl = 'M'): string
    {
        self::guard();

        return (new QrCode($data, $ecl))->svg(4, $title);
    }
}
