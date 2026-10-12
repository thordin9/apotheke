<?php
// 0008 – nested locations: a location may sit inside another one (garage › shelf 2 › box 1024).
// Top-level locations have parent_id NULL. Cycles and the depth limit are enforced in location_set_parent().
return static function (PDO $pdo, string $driver): void {
    $pdo->exec($driver === 'pgsql'
        // deferred, so a restore can insert children before their parents
        ? 'ALTER TABLE locations ADD COLUMN parent_id INTEGER REFERENCES locations(id) ON DELETE SET NULL DEFERRABLE INITIALLY DEFERRED'
        : 'ALTER TABLE locations ADD COLUMN parent_id INTEGER REFERENCES locations(id) ON DELETE SET NULL');
    $pdo->exec('CREATE INDEX idx_locations_parent ON locations(parent_id)');
};
