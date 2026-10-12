<?php
// 0006 – optional per-item low-stock level: an item is "running low" when quantity <= min_qty.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('ALTER TABLE items ADD COLUMN min_qty INTEGER');
};
