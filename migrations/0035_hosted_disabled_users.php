<?php
// 0035 – hosted per-installation disabled accounts. CI4 only: 2.6.x ignores it, a backup does not carry it,
// and a rollback leaves it behind. No foreign key. Same SQL on SQLite, PostgreSQL and D1.
$sql = "CREATE TABLE hosted_disabled_users (
  user_id INTEGER NOT NULL PRIMARY KEY,
  disabled_at TEXT NOT NULL
)";

return ['sqlite' => $sql, 'pgsql' => $sql];
