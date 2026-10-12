<?php
// 0007 – lending tracker: who has the item, since when (local date) and an optional due date (YYYY-MM-DD).
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('ALTER TABLE items ADD COLUMN lent_to TEXT');
    $pdo->exec('ALTER TABLE items ADD COLUMN lent_at TEXT');
    $pdo->exec('ALTER TABLE items ADD COLUMN lent_due TEXT');
    $pdo->exec('CREATE INDEX idx_items_lent ON items(lent_due) ');
};
