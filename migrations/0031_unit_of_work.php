<?php
// 0031 – the unit of work's batch tables (ADR-002, P4-2a): a batch guard fails by inserting NULL into uow_guard.ok,
// and uow_refs maps the ULID of a row a batch inserts to its id, so later statements of the same batch can use it.
// Both stay empty outside a running batch. CI4 only (3.14 and later): 2.6.x ignores them and a backup does not
// carry them. Same SQL on SQLite, PostgreSQL and D1.
$sql = "CREATE TABLE uow_guard (ok INTEGER NOT NULL);\nCREATE TABLE uow_refs (ulid TEXT PRIMARY KEY, id BIGINT NOT NULL)";

return ['sqlite' => $sql, 'pgsql' => $sql];
