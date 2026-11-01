<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;

/** A bare test database with the unit-of-work tables (migration 0031) and two test tables. */
final class UowSchema
{
    /** @return array{0:TestDatabase,1:BaseConnection<object|resource, object|resource>} */
    public static function create(string $driver): array
    {
        $database = TestDatabase::create($driver, false);
        $db = $database->db;
        $pk = $driver === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY';
        foreach (['CREATE TABLE uow_guard (ok INTEGER NOT NULL)', 'CREATE TABLE uow_refs (ulid TEXT PRIMARY KEY, id BIGINT NOT NULL)',
            "CREATE TABLE uw_parent (id $pk, name TEXT NOT NULL)",
            "CREATE TABLE uw_child (id $pk, parent_id INTEGER NOT NULL REFERENCES uw_parent (id), name TEXT NOT NULL)"] as $sql) {
            $db->query($sql);
        }

        return [$database, $db];
    }
}
