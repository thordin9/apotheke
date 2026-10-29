<?php

declare(strict_types=1);

namespace Apotheke\Migrations;

use Apotheke\Core\Config\DsnException;
use Apotheke\Core\Config\DsnTranslator;
use Apotheke\Core\Config\PdoSchemaSession;
use Apotheke\Core\Config\PgsqlSchema;
use Apotheke\Core\Exceptions\DatabaseUnavailable;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../Core/Config/PgsqlSchema.php';
require_once __DIR__ . '/../Core/Config/DsnTranslator.php';
require_once __DIR__ . '/../Core/Exceptions/DatabaseUnavailable.php';

/** Port of migrate(): one transaction, the legacy lock, and the v1–v3 adopt path. */
final class PdoMigrationRunner
{
    public const CREDENTIALS_DIFFER = 'DB_DSN sets a user or password that differs from DB_USER / DB_PASSWORD. '
        . 'DB_USER and DB_PASSWORD are used, as the legacy app does on PHP 8.3.';

    public function __construct(private string $migrationsDir)
    {
        // config.php defines cfg(). The shim fills in only what is still missing.
        self::loadConfig();
        require_once __DIR__ . '/LegacyFunctions.php';
    }

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Warn once per PHP process (a request under php-fpm or php -S, one run under spark); config does not change within it. */
    private static bool $warnedCredentialsDiffer = false;

    /**
     * The differing-credentials warning, once per process. CI4's own connect
     * (Config\Services::apothekeDb) and this runner share it, so a request that
     * also migrates still logs it once.
     */
    public static function warnCredentialsDiffer(): void
    {
        if (self::$warnedCredentialsDiffer) {
            return;
        }
        self::$warnedCredentialsDiffer = true;
        error_log('[apotheke] ' . self::CREDENTIALS_DIFFER);
    }

    /** @param array<string,mixed> $db */
    public function connect(array $db): PDO
    {
        self::loadConfig();
        $driver = (string)($db['driver'] ?? '');
        $dsn = config_dsn($db);
        $user = null;
        $password = null;
        if ($driver === 'pgsql') {
            try {
                [$dsn, $user, $password, $differs] = DsnTranslator::forPdo($dsn, $db);
            } catch (DsnException $e) {
                throw new MigrateConnectException($e->getMessage());
            }
            if ($differs) {
                self::warnCredentialsDiffer();
            }
        }
        unset($db['password']);
        try {
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ];
            // seconds to wait for a PostgreSQL connection (pdo_pgsql's own default is 30), as legacy db_connect()
            if (!empty($db['connect_timeout'])) {
                $options[PDO::ATTR_TIMEOUT] = (int)$db['connect_timeout'];
            }
            $pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException) {
            throw new MigrateConnectException(self::connectFailure($db));
        }
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON;');
        } else {
            $pdo->exec("SET TIME ZONE 'UTC'");
            $schema = (string)($db['schema'] ?? '');
            if ($schema !== '') {
                (new PgsqlSchema())->apply(new PdoSchemaSession($pdo), $schema);
            }
        }

        return $pdo;
    }

    /** @param array<string,mixed> $db */
    public static function connectFailure(array $db): string
    {
        self::loadConfig();

        // Same line as legacy db_connect_failure_message() in src/db.php.
        return 'Could not connect to the database (' . $db['driver'] . ' – ' . config_migrate_where($db) . ').';
    }

    /** @param array<string,mixed> $db */
    public static function banner(array $db): string
    {
        self::loadConfig();

        return 'Database: ' . $db['driver'] . ' – ' . config_migrate_where($db);
    }

    /**
     * @param array{data_dir?:string,seed?:bool,dir?:string,log?:callable(string):void} $opt
     * @return list<string>
     */
    public function migrate(PDO $pdo, string $driver, array $opt = []): array
    {
        $dir = isset($opt['dir']) ? $opt['dir'] : null;
        $log = $opt['log'] ?? static function (string $m): void {
        };
        $available = $this->available($dir);
        $applied = $this->applied($pdo, $driver);
        if (!array_diff_key($available, $applied)) {
            return [];
        }

        if ($driver === 'sqlite' && !$applied && !$this->tableExists($pdo, $driver)) {
            $legacy = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
            if ($legacy >= 1 && !empty($opt['data_dir']) && !glob($opt['data_dir'] . '/catalog-v' . $legacy . '-backup-*.sqlite')) {
                $pdo->exec('VACUUM INTO ' . $pdo->quote($opt['data_dir'] . '/catalog-v' . $legacy . '-backup-' . gmdate('Ymd-His') . '.sqlite'));
                $log("backup of v$legacy database written");
            }
        }

        $done = [];
        if ($driver === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
            $pdo->exec('SELECT pg_advisory_xact_lock(7243100)');
        }
        try {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS schema_migrations (
                    version    TEXT NOT NULL PRIMARY KEY,
                    name       TEXT NOT NULL,
                    applied_at TEXT NOT NULL
                )
            SQL);
            $applied = $pdo->query('SELECT version, applied_at FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
            $mark = $pdo->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?,?,?)');
            $fresh = !$applied;

            if ($driver === 'sqlite' && !$applied) {
                $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
                if ($v >= 1) {
                    if ($v < 2) {
                        legacy_migrate_to_v2($pdo, $v);
                    }
                    if ($v < 3) {
                        legacy_migrate_to_v3($pdo);
                    }
                    $mark->execute(['0001', 'initial_schema (adopted from legacy v' . $v . ')', now_utc()]);
                    $applied['0001'] = now_utc();
                    $done[] = 'legacy-v' . $v;
                    $fresh = false;
                    $log("adopted legacy v$v database as 0001");
                } elseif ($v === 0 && (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'items'")->fetchColumn()) {
                    $pdo->exec('DROP TABLE items');
                }
            }

            foreach ($available as $ver => $file) {
                if (isset($applied[$ver])) {
                    continue;
                }
                $m = require $file;
                if (is_callable($m)) {
                    $m($pdo, $driver);
                } elseif (is_array($m) && isset($m[$driver])) {
                    $pdo->exec($m[$driver]);
                } else {
                    throw new RuntimeException("Migration $ver has no step for driver $driver.");
                }
                $mark->execute([$ver, substr(basename($file, '.php'), 5), now_utc()]);
                $done[] = $ver;
                $log('applied ' . $ver . ' ' . substr(basename($file, '.php'), 5));
            }

            if ($driver === 'sqlite') {
                $pdo->exec('PRAGMA user_version = ' . LEGACY_SCHEMA_VERSION);
            }
            if ($fresh && !empty($opt['seed'])) {
                $n = seed_defaults($pdo);
                if ($n) {
                    $log("seeded $n starter locations/categories");
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }

        return $done;
    }

    /** @return array<string,string> */
    public function applied(PDO $pdo, string $driver): array
    {
        if (!$this->tableExists($pdo, $driver)) {
            return [];
        }

        return $pdo->query('SELECT version, applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @return array<string,string> */
    public function available(?string $dir = null): array
    {
        $out = [];
        foreach (glob(($dir ?? $this->migrationsDir) . '/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $file) {
            $out[substr(basename($file), 0, 4)] = $file;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @param-out null $pdo */
    public function close(?PDO &$pdo): void
    {
        $pdo = null;
    }

    private function tableExists(PDO $pdo, string $driver): bool
    {
        $sql = $driver === 'pgsql'
            ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'schema_migrations'"
            : "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'";

        return (int)$pdo->query($sql)->fetchColumn() > 0;
    }

    private static function loadConfig(): void
    {
        require_once self::repoRoot() . '/src/config.php';
    }
}

final class MigrateConnectException extends DatabaseUnavailable
{
}
