<?php

declare(strict_types=1);

namespace Apotheke\Migrations;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DsnTranslator;
use Closure;

/**
 * Brings the schema up to date once per process and database, on the CI4
 * connection the request already holds.
 *
 * Legacy db() runs migrate() on the first connection of every PHP process.
 * Under php-fpm a process serves one request at a time and the static resets
 * with it, so legacy checks once per request; `bin/migrate.php` is the
 * explicit way. This keeps that: under php-fpm and spark a process is one
 * request or command, so the check runs exactly as on legacy. In a
 * long-lived worker (FrankenPHP worker mode) it runs once at the worker's
 * first request instead of on every request. A deploy that adds a migration
 * restarts the workers, so the new step still runs before any request uses it.
 *
 * The check is legacy's two reads (does schema_migrations exist, which
 * versions it holds) on the CI4 connection, so a request whose schema is
 * current logs in to the database once, as legacy does (QA #134 L-472). Only
 * when a migration is missing, or the reads fail, does the PDO runner open its
 * own connection and migrate, in one transaction under the legacy lock.
 *
 * The memo is deliberate process state, keyed by database; it is recorded only
 * after the check or migrate() succeeds, so a failed attempt is retried on the
 * next request.
 */
final class BootMigration
{
    /** @var array<string,true> database key => migrated in this process */
    private static array $done = [];

    /**
     * @param object $connection the request's CI4 connection (BaseConnection), already set up by DatabaseFactory
     * @param ?Closure():PdoMigrationRunner $runner builds the runner, only when a migration is missing
     * @return bool whether this call ran the migrations (false: already current, or checked before)
     */
    public static function ensure(ApothekeConfig $config, object $connection, ?Closure $runner = null): bool
    {
        $db = $config->database();
        $key = self::key($db);
        if (isset(self::$done[$key])) {
            return false;
        }
        $driver = (string)($db['driver'] ?? '');
        if (self::current($connection, $driver)) {
            self::$done[$key] = true;

            return false;
        }
        $runner = $runner !== null ? $runner() : new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');
        $pdo = $runner->connect($db);
        try {
            $runner->migrate($pdo, $driver, [
                'data_dir' => $config->dataDir(),
                'seed' => (bool)($config->values()['seed_defaults'] ?? false),
            ]);
        } finally {
            $runner->close($pdo);
        }
        self::$done[$key] = true;

        return true;
    }

    /** Whether this process already migrated that database. */
    public static function done(ApothekeConfig $config): bool
    {
        return isset(self::$done[self::key($config->database())]);
    }

    /** Forget every database (tests, or after a restore replaced the database file). */
    public static function forget(): void
    {
        self::$done = [];
    }

    /**
     * Legacy migrations_pending() on the CI4 connection: every migration file
     * has its row. A read that fails counts as not current, so the runner
     * reports the database's own error.
     */
    private static function current(object $connection, string $driver): bool
    {
        $exists = $driver === 'pgsql'
            ? "SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'schema_migrations'"
            : "SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'";
        $rows = self::rows($connection, $exists);
        if ($rows === null || (int)($rows[0]['n'] ?? 0) === 0) {
            return false;
        }
        $rows = self::rows($connection, 'SELECT version FROM schema_migrations');
        if ($rows === null) {
            return false;
        }
        $applied = array_flip(array_map(static fn (array $row): string => (string)$row['version'], $rows));
        foreach (glob(PdoMigrationRunner::repoRoot() . '/migrations/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $file) {
            if (!isset($applied[substr(basename($file), 0, 4)])) {
                return false;
            }
        }

        return true;
    }

    /** @return ?list<array<string,mixed>> null when the read failed */
    private static function rows(object $connection, string $sql): ?array
    {
        /** @var \CodeIgniter\Database\BaseConnection<object|resource, object|resource> $connection */
        $result = $connection->query($sql);
        if (!is_object($result)) {
            return null;
        }
        /** @var \CodeIgniter\Database\BaseResult<object|resource, object|resource> $result */
        $rows = $result->getResultArray();
        // A SQLite statement left open keeps its read snapshot; let a later write see the current database.
        $result->freeResult();

        return array_values($rows);
    }

    /** @param array<string,mixed> $db */
    private static function key(array $db): string
    {
        return hash('sha256', implode("\0", [
            (string)($db['driver'] ?? ''),
            (new DsnTranslator())->pdoDsn($db),
            (string)($db['schema'] ?? ''),
            (string)($db['user'] ?? ''),
        ]));
    }
}
