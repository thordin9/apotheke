<?php
// 0002 – portable natural sorting (sort_key computed in PHP) + app_settings key/value table.
return static function (PDO $pdo, string $driver): void {
    foreach (['locations', 'categories', 'items'] as $t) {
        $pdo->exec("ALTER TABLE $t ADD COLUMN sort_key TEXT NOT NULL DEFAULT ''");
        $rows = $pdo->query("SELECT id, name FROM $t")->fetchAll(PDO::FETCH_KEY_PAIR);
        $up = $pdo->prepare("UPDATE $t SET sort_key = ? WHERE id = ?");
        foreach ($rows as $id => $name) $up->execute([natkey((string)$name), $id]);
        $pdo->exec("CREATE INDEX idx_{$t}_sort ON $t(sort_key)");
    }
    $pdo->exec('CREATE TABLE app_settings (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL)');
};
