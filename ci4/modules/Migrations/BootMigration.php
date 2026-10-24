<?php

declare(strict_types=1);

namespace Apotheke\Migrations;

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DsnTranslator;

/**
 * Brings the schema up to date once per process and database, before the
 * first CI4 connection is used.
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
 * The memo is deliberate process state, keyed by database; it is recorded only
 * after migrate() succeeds, so a failed attempt is retried on the next request.
 */
final class BootMigration
{
    /** @var array<string,true> database key => migrated in this process */
    private static array $done = [];

    /** @return bool whether this call ran the migration check */
    public static function ensure(ApothekeConfig $config, ?PdoMigrationRunner $runner = null): bool
    {
        $db = $config->database();
        $key = self::key($db);
        if (isset(self::$done[$key])) {
            return false;
        }
        $runner ??= new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');
        $pdo = $runner->connect($db);
        try {
            $runner->migrate($pdo, (string)($db['driver'] ?? ''), [
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
