<?php
// 0016 – short-lived undo tokens: a snapshot of what an edit/move/delete/bulk action changed, plus the photo and
// attachment files a delete keeps on disk until the token expires (soft delete).
return static function (PDO $pdo, string $driver): void {
    $pdo->exec('CREATE TABLE undo_tokens (
        token      TEXT PRIMARY KEY,
        action     TEXT NOT NULL,
        label      TEXT NOT NULL,
        payload    TEXT NOT NULL,
        files      TEXT NOT NULL,
        created_at TEXT NOT NULL,
        expires_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX idx_undo_tokens_expires ON undo_tokens(expires_at)');
};
