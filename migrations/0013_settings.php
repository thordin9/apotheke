<?php
// 0013 – Control Panel settings (key/value, JSON values). Seeded from the current config.php / environment values so an
// upgrade keeps the existing configuration; from now on the database is the source of truth for these options.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('CREATE TABLE settings (
        name       TEXT NOT NULL PRIMARY KEY,
        value      TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    settings_seed($pdo);
};
