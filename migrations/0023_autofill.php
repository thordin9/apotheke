<?php
// 0023 – AI autofill (optional): seeds the new Control Panel settings autofill_enabled (off), autofill_url,
// autofill_timeout and the write-only autofill_token. No schema change.
return static function (PDO $pdo, string $driver): void {
    settings_seed($pdo);
};
