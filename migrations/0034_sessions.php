<?php
// 0034 – hosted PHP sessions (P4-5): the D1/DO session handler stores rows here when
// DB_DRIVER=d1. PDO and the default file handler do not use the table. CI4 only: 2.6.x
// ignores it, a backup does not carry it, and a rollback leaves it behind. Same SQL on
// SQLite, PostgreSQL and D1.
$sql = "CREATE TABLE sessions (
  id TEXT NOT NULL PRIMARY KEY,
  data TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  lock_token TEXT NOT NULL DEFAULT '',
  locked_until INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX sessions_expires_at ON sessions (expires_at)";

return ['sqlite' => $sql, 'pgsql' => $sql];
