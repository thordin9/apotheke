<?php
// 0019 – full-text search. search_docs holds one folded document per item (name, tags, everything else) plus the original
// text for snippets; it is (re)built by src/search.php whenever the catalog changes. SQLite adds an FTS5 table and its
// vocabulary (typo tolerance), PostgreSQL a weighted tsvector column with a GIN index and, when the pg_trgm extension can be
// installed, a trigram index. Both extras are optional: without them search falls back to substring matching.
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('CREATE TABLE search_docs (
        item_id  BIGINT NOT NULL PRIMARY KEY REFERENCES items(id) ON DELETE CASCADE,
        name_f   TEXT NOT NULL,
        tags_f   TEXT NOT NULL,
        other_f  TEXT NOT NULL,
        other    TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE search_meta (k TEXT NOT NULL PRIMARY KEY, v TEXT NOT NULL)');
    if ($driver === 'pgsql') {
        $pdo->exec("ALTER TABLE search_docs ADD COLUMN tsv tsvector GENERATED ALWAYS AS (
            setweight(to_tsvector('simple', name_f), 'A') || setweight(to_tsvector('simple', tags_f), 'B') || setweight(to_tsvector('simple', other_f), 'C')) STORED");
        $pdo->exec('CREATE INDEX search_docs_tsv ON search_docs USING GIN (tsv)');
        $pdo->exec('SAVEPOINT trgm');
        try {
            $pdo->exec('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            $pdo->exec("CREATE INDEX search_docs_trgm ON search_docs USING GIN ((name_f || ' ' || tags_f || ' ' || other_f) gin_trgm_ops)");
            $pdo->exec('RELEASE SAVEPOINT trgm');
        } catch (PDOException) {
            $pdo->exec('ROLLBACK TO SAVEPOINT trgm');   // no permission / not installed: search works without typo index
        }
        return;
    }
    try {
        $pdo->exec("CREATE VIRTUAL TABLE search_fts USING fts5(name, tags, other, tokenize = 'unicode61 remove_diacritics 2')");
        $pdo->exec("CREATE VIRTUAL TABLE search_fts_v USING fts5vocab(search_fts, 'row')");
    } catch (PDOException) {
        // SQLite built without FTS5: substring search only
    }
};
