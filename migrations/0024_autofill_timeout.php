<?php
// 0024 – AI autofill timeout default 30 s → 50 s. The service's Jetson backend now waits up to 45 s (a cold model
// load plus one answer), so Apotheke should wait about 5 s longer. A stored value of exactly 30 (the old default that
// 0023 seeded) moves to 50; any other value was chosen by an admin and stays. No schema change.
return static function (PDO $pdo, string $driver): void {
    $pdo->prepare("UPDATE settings SET value = ?, updated_at = ? WHERE name = 'autofill_timeout' AND value = ?")
        ->execute([json_encode(50), now_utc(), json_encode(30)]);
    settings_cache(null, true);
};
