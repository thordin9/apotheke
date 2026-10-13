<?php
// 0021 – photo editing (crop / rotate in the browser, applied on the server): item_photos.version counts the saved
// versions (1 = as uploaded), edited_at is the time of the last edit. Every edit writes a new file name.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('ALTER TABLE item_photos ADD COLUMN version INTEGER NOT NULL DEFAULT 1');
    $pdo->exec('ALTER TABLE item_photos ADD COLUMN edited_at TEXT');
};
