<?php
declare(strict_types=1);

/*
 * Upgrade steps of the pre-migrations SQLite schema (PRAGMA user_version 1 → 2 → 3).
 * Only used once, to convert old installs before they are adopted into schema_migrations.
 */

/** v0/v1 → v2: managed locations table; items.location (text) → items.location_id. */
function legacy_migrate_to_v2(PDO $pdo, int $v): void
{

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS locations (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
            is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
            created_at TEXT    NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_locations_one_default ON locations(is_default) WHERE is_default = 1;

        CREATE TABLE items_v2 (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            name        TEXT    NOT NULL,
            location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL,
            quantity    INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
            notes       TEXT    NOT NULL DEFAULT '',
            image       TEXT,
            search      TEXT    NOT NULL DEFAULT '',  -- fold(name + notes)
            created_at  TEXT    NOT NULL,
            updated_at  TEXT    NOT NULL
        );
    SQL);

    if ($v === 1) {
        // v1 stored free-text locations on items: turn them into rows, dedupe case-insensitively, link items.
        $ins = $pdo->prepare('INSERT OR IGNORE INTO locations (name, name_key, created_at) VALUES (?,?,?)');
        $ids = [];
        $rows = $pdo->query('SELECT id, name, location, quantity, notes, image, created_at, updated_at FROM items ORDER BY id')->fetchAll();
        foreach ($rows as $r) {
            $name = clean_line((string)$r['location'], 120);
            if ($name === '') continue;
            $key = fold($name);
            if (!isset($ids[$key])) {
                $ins->execute([$name, $key, $r['created_at']]);
                $st = $pdo->prepare('SELECT id FROM locations WHERE name_key = ?');
                $st->execute([$key]);
                $ids[$key] = (int)$st->fetchColumn();
                $st->closeCursor();   // an open read cursor would block DROP TABLE below
            }
        }
        $copy = $pdo->prepare('INSERT INTO items_v2 (id, name, location_id, quantity, notes, image, search, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($rows as $r) {
            $name = clean_line((string)$r['location'], 120);
            $copy->execute([$r['id'], $r['name'], $name === '' ? null : $ids[fold($name)], $r['quantity'], $r['notes'], $r['image'],
                            fold($r['name'] . "\n" . $r['notes']), $r['created_at'], $r['updated_at']]);
        }
        $pdo->exec('DROP TABLE items');
    } else {
        $pdo->exec('DROP TABLE IF EXISTS items');
    }

    $pdo->exec(<<<SQL
        ALTER TABLE items_v2 RENAME TO items;
        CREATE INDEX idx_items_location ON items(location_id);
        CREATE INDEX idx_items_name     ON items(name COLLATE NOCASE);
        CREATE INDEX idx_items_updated  ON items(updated_at);
    SQL);
}

/** v2 → v3: managed categories table + items.category_id. */
function legacy_migrate_to_v3(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS categories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
            is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
            created_at TEXT    NOT NULL
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_one_default ON categories(is_default) WHERE is_default = 1;
        ALTER TABLE items ADD COLUMN category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL;
        CREATE INDEX idx_items_category ON items(category_id);
    SQL);
}

