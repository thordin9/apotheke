<?php
declare(strict_types=1);

/*
 * PHP 8.4 function JIT miscompiles the finder loops in the encoder (they count
 * down from a negative index). opcache.jit=1235, which shivammathur/setup-php
 * enables and the headless mobile job inherits, never returns from /labels.
 * opcache.jit=1205 returns a different matrix. Turn that JIT off before the
 * encoder file is compiled. disable sticks for this process. Tracing JIT
 * (1255 / "tracing") already matches the interpreter.
 */
(static function (): void {
    if (PHP_VERSION_ID < 80400 || !function_exists('opcache_get_status')) return;
    $st = opcache_get_status(false);
    // ini_set() warns once the engine is already off, and the built-in server
    // restores the ini value at the end of each request without turning JIT back on.
    if (!is_array($st) || empty($st['jit']['on'])) return;
    $jit = (string)ini_get('opcache.jit');
    if ($jit === 'tracing' || $jit === 'on' || str_starts_with($jit, '125')) return;
    ini_set('opcache.jit', 'disable');
})();

require_once __DIR__ . '/qr-encode.php';
