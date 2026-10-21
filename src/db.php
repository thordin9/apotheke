<?php
declare(strict_types=1);

/*
 * Apotheke – database access (SQLite or PostgreSQL via PDO) and the versioned migration runner.
 *
 * Portability rules used throughout the app:
 *  - case/accent-insensitive uniqueness and search use PHP-folded columns (name_key, search),
 *    so both dialects use plain UNIQUE and LIKE … ESCAPE '!' (no ILIKE/unaccent/collations needed);
 *  - natural sorting uses a PHP-computed sort_key ("box 2" < "box 10");
 *  - timestamps are stored as UTC 'Y-m-d H:i:s' text in both dialects;
 *  - the few genuinely dialect-specific bits (date bucketing, insert ids, locking) live here.
 */

const LEGACY_SCHEMA_VERSION = 3;   // PRAGMA user_version of pre-migrations SQLite installs (v1–v3)

function db_driver(): string
{
    return cfg()['db']['driver'];
}

/** Open a PDO connection for a db config block (no migrations). */
function db_connect(array $db): PDO
{
    // Drop the password from the argument before any later error, so a stack trace cannot include it.
    $password = $db['driver'] === 'pgsql' ? (string)$db['password'] : null;
    $user = $db['driver'] === 'pgsql' ? (string)$db['user'] : null;
    unset($db['password']);
    $pdo = new PDO(config_dsn($db), $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);
    if ($db['driver'] === 'sqlite') {
        $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON;');
    } else {
        $pdo->exec("SET TIME ZONE 'UTC'");
        if ((string)$db['schema'] !== '') db_pgsql_use_schema($pdo, (string)$db['schema']);
    }
    return $pdo;
}

/**
 * Point the connection at $schema. PostgreSQL checks CREATE on the database
 * before it looks at whether the schema exists, so CREATE SCHEMA IF NOT EXISTS
 * fails for a role without CREATE even when the schema is already there.
 * Look the name up first and create it only when it is missing.
 */
function db_pgsql_use_schema(PDO $pdo, string $schema): void
{
    $quoted = '"' . str_replace('"', '""', $schema) . '"';
    if (!db_pgsql_schema_exists($pdo, $schema)) {
        try {
            $pdo->exec('CREATE SCHEMA IF NOT EXISTS ' . $quoted);
        } catch (PDOException $e) {
            if ($e->getCode() === '42501' && db_pgsql_schema_exists($pdo, $schema)) {
                // Another session created it after the check. Keep going.
            } elseif ($e->getCode() === '42501') {
                throw new RuntimeException(
                    'PostgreSQL schema "' . $schema . '" does not exist, and this database role cannot create it. '
                    . 'Create the schema, then grant this role USAGE and CREATE on it.'
                );
            } else {
                throw $e;
            }
        }
    }
    $pdo->exec('SET search_path TO ' . $quoted);
}

function db_pgsql_schema_exists(PDO $pdo, string $schema): bool
{
    $st = $pdo->prepare('SELECT 1 FROM pg_catalog.pg_namespace WHERE nspname = ?');
    $st->execute([$schema]);
    return $st->fetchColumn() !== false;
}

/** The request's connection; migrations (and first-use seeding) run automatically on first use. */
function db(?PDO $replace = null): PDO
{
    static $pdo = null;
    if ($replace !== null) return $pdo = $replace;   // tests inject a connection
    if ($pdo instanceof PDO) return $pdo;
    $c = cfg();
    $pdo = db_connect($c['db']);
    migrate($pdo, $c['db']['driver'], ['data_dir' => $c['data_dir'], 'seed' => $c['seed_defaults']]);
    return $pdo;
}

/** INSERT and return the new id on both dialects. */
function db_insert(PDO $pdo, string $sql, array $args): int
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $st = $pdo->prepare($sql . ' RETURNING id');
        $st->execute($args);
        return (int)$st->fetchColumn();
    }
    $pdo->prepare($sql)->execute($args);
    return (int)$pdo->lastInsertId();
}

/**
 * SQL expression bucketing a UTC text timestamp column into local days ('Y-m-d') or months ('Y-m').
 * @return array{0:string,1:string} [expression with one placeholder, parameter]
 */
function db_local_bucket(string $driver, string $col, string $unit, string $tz): array
{
    if ($driver === 'pgsql') {
        $fmt = $unit === 'day' ? 'YYYY-MM-DD' : 'YYYY-MM';
        return ["to_char(($col::timestamp AT TIME ZONE 'UTC') AT TIME ZONE ?, '$fmt')", $tz];
    }
    // SQLite: shift by the current UTC offset (DST edge days may shift by one hour)
    $off = sprintf('%+d seconds', (new DateTimeImmutable('now', new DateTimeZone($tz)))->getOffset());
    return [$unit === 'day' ? "date($col, ?)" : "strftime('%Y-%m', $col, ?)", $off];
}

/* ---------------------------------------------------------------- migrations */

function migrations_dir(): string
{
    return dirname(__DIR__) . '/migrations';
}

/** @return array<string,string> version => file, sorted */
function migrations_available(?string $dir = null): array
{
    $out = [];
    foreach (glob(($dir ?? migrations_dir()) . '/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $f) {
        $out[substr(basename($f), 0, 4)] = $f;
    }
    ksort($out, SORT_STRING);
    return $out;
}

function migrations_table_exists(PDO $pdo, string $driver): bool
{
    $sql = $driver === 'pgsql'
        ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = 'schema_migrations'"
        : "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'";
    return (int)$pdo->query($sql)->fetchColumn() > 0;
}

/** @return array<string,string> version => applied_at */
function migrations_applied(PDO $pdo, string $driver): array
{
    if (!migrations_table_exists($pdo, $driver)) return [];
    return $pdo->query('SELECT version, applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function migrations_pending(PDO $pdo, string $driver, ?string $dir = null): array
{
    return array_diff_key(migrations_available($dir), migrations_applied($pdo, $driver));
}

/**
 * Bring the schema up to date. Safe to call on every request (one cheap query when current).
 * All pending steps run in ONE transaction under a lock, so concurrent first requests serialise
 * and a failing step leaves the database exactly as it was.
 * Legacy SQLite installs (PRAGMA user_version 1–3, no schema_migrations) are snapshotted with
 * VACUUM INTO, converted to the v3 shape and then adopted into the migration system.
 *
 * @param array{data_dir?:string,seed?:bool,dir?:string,log?:callable} $opt
 * @return string[] versions applied in this call (plus 'legacy-vN' when a legacy install was adopted)
 */
function migrate(PDO $pdo, string $driver, array $opt = []): array
{
    $dir = $opt['dir'] ?? null;
    $log = $opt['log'] ?? static function (string $m): void {};
    $available = migrations_available($dir);
    $applied = migrations_applied($pdo, $driver);
    if (!array_diff_key($available, $applied)) return [];

    $legacy = 0;
    if ($driver === 'sqlite' && !$applied && !migrations_table_exists($pdo, $driver)) {
        $legacy = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($legacy >= 1 && !empty($opt['data_dir']) && !glob($opt['data_dir'] . '/catalog-v' . $legacy . '-backup-*.sqlite')) {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($opt['data_dir'] . '/catalog-v' . $legacy . '-backup-' . gmdate('Ymd-His') . '.sqlite'));
            $log("backup of v$legacy database written");
        }
    }

    $done = [];
    if ($driver === 'sqlite') {
        $pdo->exec('BEGIN IMMEDIATE');   // take the write lock up front
    } else {
        $pdo->beginTransaction();
        $pdo->exec('SELECT pg_advisory_xact_lock(7243100)');   // "apotheke" migrations lock
    }
    try {
        // Re-check under the lock: another request may have migrated meanwhile.
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
                // Pre-migrations install: finish the old upgrade path, then adopt the v3 schema as baseline 0001.
                require_once __DIR__ . '/legacy.php';
                if ($v < 2) legacy_migrate_to_v2($pdo, $v);
                if ($v < 3) legacy_migrate_to_v3($pdo);
                $mark->execute(['0001', 'initial_schema (adopted from legacy v' . $v . ')', now_utc()]);
                $applied['0001'] = now_utc();
                $done[] = 'legacy-v' . $v;
                $fresh = false;
                $log("adopted legacy v$v database as 0001");
            } elseif ($v === 0 && (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'items'")->fetchColumn()) {
                $pdo->exec('DROP TABLE items');   // v0: empty pre-release table
            }
        }

        foreach ($available as $ver => $file) {
            if (isset($applied[$ver])) continue;
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
            $log("applied $ver " . substr(basename($file, '.php'), 5));
        }

        if ($driver === 'sqlite') $pdo->exec('PRAGMA user_version = ' . LEGACY_SCHEMA_VERSION);   // older app versions see a v3 db
        if ($fresh && !empty($opt['seed'])) {
            require_once __DIR__ . '/seed.php';
            $n = seed_defaults($pdo);
            if ($n) $log("seeded $n starter locations/categories");
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
    return $done;
}
