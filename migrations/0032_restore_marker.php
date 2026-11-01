<?php
// 0032 – the restore marker (P4-2b, restore on the edge): a staged restore's swap batch writes its run id and the
// backup's SHA-256 here, so a restore found interrupted can tell whether its swap committed. It holds the last swap
// only. CI4 only (3.16 and later): 2.6.x ignores it, a backup does not carry it, and a rollback leaves it behind.
// Same SQL on SQLite, PostgreSQL and D1.
$sql = 'CREATE TABLE restore_marker (run TEXT PRIMARY KEY, sha256 TEXT NOT NULL, applied_at TEXT NOT NULL)';

return ['sqlite' => $sql, 'pgsql' => $sql];
