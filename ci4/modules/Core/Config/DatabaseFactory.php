<?php

declare(strict_types=1);

namespace Apotheke\Core\Config;

use SQLite3;

/** CI4 connection settings plus the pragmas and session setup legacy applies on connect. */
final class DatabaseFactory
{
    public function __construct(private ApothekeConfig $config, private DsnTranslator $translator)
    {
    }

    /** @return array<string,mixed> */
    public function group(): array
    {
        return $this->translator->translate($this->config->database());
    }

    public function apply(object $connection): void
    {
        $driver = (string)($connection->DBDriver ?? '');
        if ($driver === 'SQLite3') {
            $connection->query('PRAGMA journal_mode = WAL');
            $connection->query('PRAGMA synchronous = NORMAL');

            return;
        }
        if ($driver === 'Postgre') {
            if ($connection->query("SET TIME ZONE 'UTC'") === false) {
                throw new \RuntimeException('Could not set the database time zone.');
            }
            $schema = (string)($this->config->database()['schema'] ?? '');
            (new PgsqlSchema())->apply(new Ci4SchemaSession($connection), $schema);
        }
    }

    public function applySqlite3(SQLite3 $db): void
    {
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA foreign_keys = ON');
    }
}
