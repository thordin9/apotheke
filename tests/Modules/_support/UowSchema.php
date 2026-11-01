<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;

/**
 * A bare test database with the unit-of-work tables (`uow_guard`, `uow_refs`, from migration 0031) and two test
 * tables.
 */
final class UowSchema
{
    /** @return array{0:TestDatabase,1:BaseConnection<object|resource, object|resource>} */
    public static function create(string $driver): array
    {
        $database = TestDatabase::create($driver, false);
        $db = $database->db;
        $pk = $driver === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY';
        $uow = require dirname(__DIR__, 3) . '/migrations/0031_unit_of_work.php';
        foreach ([...explode(";\n", $uow[$driver]), "CREATE TABLE uw_parent (id $pk, name TEXT NOT NULL, note TEXT)",
            "CREATE TABLE uw_child (id $pk, parent_id INTEGER NOT NULL REFERENCES uw_parent (id), name TEXT NOT NULL)"] as $sql) {
            $db->query($sql);
        }

        return [$database, $db];
    }
}
