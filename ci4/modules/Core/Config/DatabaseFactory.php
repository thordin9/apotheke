<?php

declare(strict_types=1);

namespace Apotheke\Core\Config;

use CodeIgniter\Database\Exceptions\DatabaseException;
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
            // CI4's initialize() logs a failed open with its stack trace to writable/logs on
            // every request (QA #137 L-500). Open the file here instead: a failure is CI4's own
            // "Unable to connect", which Services::apothekeDb turns into the sanitized line.
            // initialize() then only applies the driver's settings (foreign keys, busy timeout).
            if (!$connection->connID) {
                try {
                    $connection->connID = $connection->connect();
                } catch (\Throwable) {
                    $connection->connID = false;

                    throw new DatabaseException('Unable to connect to the database.');
                }
                $connection->initialize();
            }
            $connection->query('PRAGMA journal_mode = WAL');
            $connection->query('PRAGMA synchronous = NORMAL');

            return;
        }
        if ($driver === 'Postgre') {
            // CI4 logs pg_connect()'s warning, and libpq's text names the user.
            // With warnings muted, a failed connect is only CI4's own
            // "Unable to connect to the database."; Services::apothekeDb turns
            // it into the sanitized line. This is the request's only login
            // (BootMigration checks the schema on this connection).
            set_error_handler(static fn (): bool => true);
            try {
                $connection->initialize();
            } finally {
                restore_error_handler();
            }
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
