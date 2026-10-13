<?php
// 0015 – favourites (items.favourite) and the "recently viewed" list (per device cookie or shared per install).
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('ALTER TABLE items ADD COLUMN favourite INTEGER NOT NULL DEFAULT 0');
    $pdo->exec('CREATE INDEX idx_items_favourite ON items(favourite)');
    $pdo->exec('CREATE TABLE recent_views (
        scope     TEXT NOT NULL,
        item_id   INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
        seq       BIGINT NOT NULL,
        viewed_at TEXT NOT NULL,
        PRIMARY KEY (scope, item_id)
    )');
    $pdo->exec('CREATE INDEX idx_recent_views_scope ON recent_views(scope, seq)');
    $pdo->exec('CREATE INDEX idx_recent_views_item ON recent_views(item_id)');
};
