<?php
// 0012 – optional barcode per item (EAN/UPC/QR text as scanned), indexed for /barcode/{code} lookups. Not unique: several
// items may share a product barcode; the lookup then lists them.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('ALTER TABLE items ADD COLUMN barcode TEXT');
    $pdo->exec('CREATE INDEX idx_items_barcode ON items(barcode)');
};
