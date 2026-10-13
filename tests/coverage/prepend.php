<?php
declare(strict_types=1);

/*
 * Coverage collector for the HTTP tests: loaded with `-d auto_prepend_file=` into every `php -S` request of the
 * integration suite (tests/lib.php adds it when APOTHEKE_COVERAGE_DIR is set). Each request only dumps pcov's raw
 * line data (cheap); tests/coverage/merge.php turns all dumps into one php-code-coverage .cov file that
 * `vendor/bin/phpcov merge` combines with the PHPUnit run.
 */
(static function (): void {
    $dir = getenv('APOTHEKE_COVERAGE_DIR') ?: ($_SERVER['APOTHEKE_COVERAGE_DIR'] ?? $_ENV['APOTHEKE_COVERAGE_DIR'] ?? '');
    if (!is_string($dir) || $dir === '') { error_log('coverage collector: APOTHEKE_COVERAGE_DIR not set'); return; }
    if (!function_exists('pcov\start')) { error_log('coverage collector: pcov not loaded'); return; }
    \pcov\start();
    register_shutdown_function(static function () use ($dir): void {
        \pcov\stop();
        $root = dirname(__DIR__, 2) . '/';
        $mine = array_values(array_filter(\pcov\waiting(), fn($f) => str_starts_with($f, $root . 'src/') || str_starts_with($f, $root . 'migrations/') || str_starts_with($f, $root . 'public/')));
        $data = \pcov\collect(\pcov\inclusive, $mine);
        @mkdir($dir, 0777, true);
        if (@file_put_contents($dir . '/' . getmypid() . '-' . bin2hex(random_bytes(6)) . '.raw', serialize($data)) === false) error_log("coverage collector: cannot write to $dir");
    });
})();
