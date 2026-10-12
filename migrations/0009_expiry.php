<?php
// 0009 – optional expiry / warranty end date per item (local calendar date YYYY-MM-DD), indexed for the filters.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('ALTER TABLE items ADD COLUMN expires_on TEXT');
    $pdo->exec('CREATE INDEX idx_items_expires ON items(expires_on)');
};
