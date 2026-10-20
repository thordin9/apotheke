<?php
declare(strict_types=1);

/*
 * Function JIT miscompiles the finder loops in the encoder. Those loops
 * count up from a negative index. On PHP 8.2 and 8.3, opcache.jit=1235
 * (which shivammathur/setup-php enables) can emit a wrong matrix; on PHP 8.4
 * that mode can hang GET /labels. Any other function-JIT mode (CRTO
 * trigger digit other than 5, or the string "function") can emit a different
 * matrix. Tracing JIT (trigger digit 5, or "tracing" / "on") matches the
 * interpreter. Turn function JIT off before the encoder file is compiled.
 * The guard runs on every PHP version (affected: 8.2–8.4) whenever function JIT is active.
 */

/** True when opcache.jit is tracing JIT. $kind is opcache_get_status()["jit"]["kind"] (the CRTO trigger digit). */
function qr_jit_is_tracing(string $jit, int $kind): bool
{
    $jit = strtolower(trim($jit));
    if ($jit === 'tracing' || $jit === 'on') return true;
    if ($jit === 'function') return false;
    if (preg_match('/^[0-9]{4}$/', $jit) === 1) return $jit[2] === '5';
    // "on" is reported as "1". kind is the trigger digit, and 5 is tracing.
    return $kind === 5;
}

(static function (): void {
    if (!function_exists('opcache_get_status')) return;
    $st = opcache_get_status(false);
    // ini_set() warns once the engine is already off, and the built-in server
    // restores the ini value at the end of each request without turning JIT back on.
    if (!is_array($st) || empty($st['jit']['on'])) return;
    $jit = (string)ini_get('opcache.jit');
    $kind = (int)($st['jit']['kind'] ?? -1);
    if (qr_jit_is_tracing($jit, $kind)) return;
    ini_set('opcache.jit', 'disable');
})();

require_once __DIR__ . '/qr-encode.php';
