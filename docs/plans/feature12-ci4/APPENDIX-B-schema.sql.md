# Appendix B — SQLite schema at v2.5.0 / 5633472 (PR #28 merged; fresh `php bin/migrate.php`, all 25 migrations)

Re-dumped on 2026-10-20 at `56334727bf8f`. **PR #28 added no migration and no DDL**: the dump below is byte-identical to the
one taken at `56baf17` (checked with `diff`), and the `settings` table still seeds 62 keys. v2.5.0 (`87850e3`) adds
only VERSION, CHANGELOG.md and README coverage tables on top (verified), so this schema is the v2.5.0 schema.

What #28 changed lives **outside the database**: `ORIGINALS_DIR` (default `DATA_DIR/originals`, 0750) with
`<64 hex>.<ext>` originals (0640), `map/<32 hex>` pointer files and a `.htaccess` deny file; temporary analysis files in
`DATA_DIR/tmp`; and the backup zip's `originals/index.json` + `originals/*` entries. The CI4 port must treat these as part
of the storage contract exactly like the tables (see PLAN §3.5).

PostgreSQL differs only as noted in Appendix A.5 (identity columns, SMALLINT is_default, tsvector/GIN, optional pg_trgm, deferred parent FK).

```sql
CREATE TABLE activity (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id    INTEGER,
        item_name  TEXT NOT NULL,
        action     TEXT NOT NULL,
        details    TEXT NOT NULL DEFAULT '{}',
        created_at TEXT NOT NULL
    , actor TEXT);
CREATE TABLE app_settings (name TEXT NOT NULL PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE auth_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind      TEXT NOT NULL,
        client    TEXT NOT NULL,
        at        INTEGER NOT NULL
    );
CREATE TABLE categories (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        name_key   TEXT    NOT NULL UNIQUE,
        is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
        created_at TEXT    NOT NULL
    , sort_key TEXT NOT NULL DEFAULT '');
CREATE TABLE imports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file_name   TEXT NOT NULL,
        status      TEXT NOT NULL,
        total_rows  INTEGER NOT NULL DEFAULT 0,
        mapping     TEXT NOT NULL,
        options     TEXT NOT NULL,
        result      TEXT,
        actor       TEXT,
        created_at  TEXT NOT NULL,
        finished_at TEXT
    );
CREATE TABLE item_attachments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id    INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
        file       TEXT NOT NULL UNIQUE,
        name       TEXT NOT NULL,
        mime       TEXT NOT NULL,
        size       BIGINT NOT NULL,
        created_at TEXT NOT NULL
    );
CREATE TABLE item_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id    INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
        file       TEXT    NOT NULL,
        position   INTEGER NOT NULL DEFAULT 0,
        created_at TEXT    NOT NULL
    , version INTEGER NOT NULL DEFAULT 1, edited_at TEXT);
CREATE TABLE item_tags (
        item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
        tag_id  INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
        PRIMARY KEY (item_id, tag_id)
    );
CREATE TABLE items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL,
        quantity    INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
        notes       TEXT    NOT NULL DEFAULT '',
        image       TEXT,
        search      TEXT    NOT NULL DEFAULT '',  -- fold(name + notes)
        created_at  TEXT    NOT NULL,
        updated_at  TEXT    NOT NULL,
        category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL
    , sort_key TEXT NOT NULL DEFAULT '', value_cents BIGINT, purchase_date TEXT, serial TEXT NOT NULL DEFAULT '', min_qty INTEGER, lent_to TEXT, lent_at TEXT, lent_due TEXT, expires_on TEXT, barcode TEXT, favourite INTEGER NOT NULL DEFAULT 0);
CREATE TABLE locations (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT    NOT NULL,
        name_key   TEXT    NOT NULL UNIQUE,   -- fold(name): case/accent-insensitive uniqueness
        is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)),
        created_at TEXT    NOT NULL
    , sort_key TEXT NOT NULL DEFAULT '', parent_id INTEGER REFERENCES locations(id) ON DELETE SET NULL);
CREATE TABLE recent_views (
        scope     TEXT NOT NULL,
        item_id   INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
        seq       BIGINT NOT NULL,
        viewed_at TEXT NOT NULL,
        PRIMARY KEY (scope, item_id)
    );
CREATE TABLE roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role_key   TEXT NOT NULL UNIQUE,
        name       TEXT NOT NULL,
        perms      TEXT NOT NULL,
        builtin    INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL
    );
CREATE TABLE saved_filters (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        name_key   TEXT NOT NULL UNIQUE,
        query      TEXT NOT NULL,
        sort_key   TEXT NOT NULL,
        created_at TEXT NOT NULL
    );
CREATE TABLE schema_migrations (
        version    TEXT NOT NULL PRIMARY KEY,
        name       TEXT NOT NULL,
        applied_at TEXT NOT NULL
    );
CREATE TABLE search_docs (
        item_id  BIGINT NOT NULL PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
        name_f   TEXT NOT NULL,
        tags_f   TEXT NOT NULL,
        other_f  TEXT NOT NULL,
        other    TEXT NOT NULL
    );
CREATE VIRTUAL TABLE search_fts USING fts5(name, tags, other, tokenize = 'unicode61 remove_diacritics 2');
CREATE TABLE 'search_fts_config'(k PRIMARY KEY, v) WITHOUT ROWID;
CREATE TABLE 'search_fts_content'(id INTEGER PRIMARY KEY, c0, c1, c2);
CREATE TABLE 'search_fts_data'(id INTEGER PRIMARY KEY, block BLOB);
CREATE TABLE 'search_fts_docsize'(id INTEGER PRIMARY KEY, sz BLOB);
CREATE TABLE 'search_fts_idx'(segid, term, pgno, PRIMARY KEY(segid, term)) WITHOUT ROWID;
CREATE VIRTUAL TABLE search_fts_v USING fts5vocab(search_fts, 'row');
CREATE TABLE search_meta (k TEXT NOT NULL PRIMARY KEY, v TEXT NOT NULL);
CREATE TABLE settings (
        name       TEXT NOT NULL PRIMARY KEY,
        value      TEXT NOT NULL,
        updated_at TEXT NOT NULL
    );
CREATE TABLE sqlite_sequence(name,seq);
CREATE TABLE stocktake_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stocktake_id     INTEGER NOT NULL REFERENCES stocktakes(id) ON DELETE CASCADE,
        item_id          INTEGER REFERENCES items(id) ON DELETE SET NULL,
        item_name        TEXT NOT NULL,
        expected         INTEGER NOT NULL,
        home_location_id INTEGER,
        home_location    TEXT NOT NULL DEFAULT '',
        found            INTEGER NOT NULL DEFAULT 0,
        found_at         TEXT,
        moved            INTEGER NOT NULL DEFAULT 0
    );
CREATE TABLE stocktakes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        location_id   INTEGER REFERENCES locations(id) ON DELETE SET NULL,
        location_name TEXT NOT NULL,
        status        TEXT NOT NULL,
        started_at    TEXT NOT NULL,
        finished_at   TEXT,
        moves_applied INTEGER NOT NULL DEFAULT 0,
        summary       TEXT NOT NULL DEFAULT '{}'
    );
CREATE TABLE tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        name_key   TEXT NOT NULL UNIQUE,
        sort_key   TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL
    );
CREATE TABLE undo_tokens (
        token      TEXT PRIMARY KEY,
        action     TEXT NOT NULL,
        label      TEXT NOT NULL,
        payload    TEXT NOT NULL,
        files      TEXT NOT NULL,
        created_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
    );
CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT NOT NULL,
        username_key  TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role          TEXT NOT NULL,
        created_at    TEXT NOT NULL,
        last_login_at TEXT
    , status TEXT NOT NULL DEFAULT 'active');
CREATE INDEX auth_attempts_k ON auth_attempts (kind, client, at);
CREATE INDEX idx_activity_item ON activity(item_id, id);
CREATE UNIQUE INDEX idx_categories_one_default ON categories(is_default) WHERE is_default = 1;
CREATE INDEX idx_categories_sort ON categories(sort_key);
CREATE INDEX idx_item_attachments_item ON item_attachments(item_id);
CREATE INDEX idx_item_photos_item ON item_photos(item_id, position);
CREATE INDEX idx_item_tags_tag ON item_tags(tag_id);
CREATE INDEX idx_items_barcode ON items(barcode);
CREATE INDEX idx_items_category ON items(category_id);
CREATE INDEX idx_items_expires ON items(expires_on);
CREATE INDEX idx_items_favourite ON items(favourite);
CREATE INDEX idx_items_lent ON items(lent_due) ;
CREATE INDEX idx_items_location ON items(location_id);
CREATE INDEX idx_items_name     ON items(name COLLATE NOCASE);
CREATE INDEX idx_items_sort ON items(sort_key);
CREATE INDEX idx_items_updated  ON items(updated_at);
CREATE INDEX idx_items_value ON items(value_cents);
CREATE UNIQUE INDEX idx_locations_one_default ON locations(is_default) WHERE is_default = 1;
CREATE INDEX idx_locations_parent ON locations(parent_id);
CREATE INDEX idx_locations_sort ON locations(sort_key);
CREATE INDEX idx_recent_views_item ON recent_views(item_id);
CREATE INDEX idx_recent_views_scope ON recent_views(scope, seq);
CREATE UNIQUE INDEX idx_stocktake_lines_item ON stocktake_lines(stocktake_id, item_id);
CREATE INDEX idx_stocktakes_status ON stocktakes(status, location_id);
CREATE INDEX idx_undo_tokens_expires ON undo_tokens(expires_at);
```
