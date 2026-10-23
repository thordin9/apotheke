<?php
// 0026 – Maximum size of the JPEG sent to AI autofill (Apotheke 2.6): autofill_max_image_bytes.
// "auto" (the default on new and existing installs) uses max_image_bytes from the service's GET /v1/info, or 983040
// when that answer is unavailable. An explicit value is a whole number of bytes from 65536 to 10485760. There is no
// environment override. The stored photo is not resized. No schema change.
// Plain SQL with a literal value (no settings_schema()), so it runs in any context, e.g. bin/migrate.php or a bare db().
return static function (PDO $pdo, string $driver): void {
    $have = $pdo->query("SELECT 1 FROM settings WHERE name = 'autofill_max_image_bytes'")->fetchColumn();
    if ($have) return;
    $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)')->execute(['autofill_max_image_bytes', json_encode('auto'), now_utc()]);
    if (function_exists('settings_cache')) settings_cache(null, true);
};
