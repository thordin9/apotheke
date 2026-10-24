<?php
// 0027 – Idle sign-in limit (session_idle_hours). A signed-in session with no request for this many
// hours ends. Default 168 (7 days). Whole hours from 1 to 8760 (365 days). The absolute session_days
// limit is unchanged. Plain SQL with a literal value (no settings_schema()), so it runs in any context.
return static function (PDO $pdo, string $driver): void {
    $have = $pdo->query("SELECT 1 FROM settings WHERE name = 'session_idle_hours'")->fetchColumn();
    if ($have) return;
    $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)')->execute(['session_idle_hours', json_encode(168), now_utc()]);
    if (function_exists('settings_cache')) settings_cache(null, true);
};
