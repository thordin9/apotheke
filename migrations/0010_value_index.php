<?php
// 0010 – index on the purchase price (items.value_cents) for "sort by value" and the overview's value totals.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('CREATE INDEX idx_items_value ON items(value_cents)');
};
