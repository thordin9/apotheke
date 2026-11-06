<?php

declare(strict_types=1);

namespace Apotheke\Migrations;

use Apotheke\Core\Database\D1Executor;
use Apotheke\Core\Database\D1Pdo;
use RuntimeException;

/**
 * The migration channel for D1 (ADR-002, P4-3 PR 3). Same migration files as
 * PdoMigrationRunner, run through the D1 HTTP bridge instead of a real connection:
 *
 * - connection PRAGMAs, user_version and VACUUM INTO are skipped by the D1 dialect
 *   (D1Pdo), so a fresh D1 database starts clean and the legacy adopt path is a no-op;
 * - a migration's SQL array entry (multi-statement heredoc) runs through D1's exec();
 *   a callable migration gets the PDO-shaped D1Pdo adapter;
 * - no BEGIN/COMMIT is sent (D1 has no interactive transaction); each migration's own
 *   statements run as written, and a parent table is never dropped.
 *
 * Only the array and callable migration shapes are supported; a callable that needs a
 * real connection feature D1 lacks fails loudly rather than silently diverging.
 */
final class D1MigrationRunner
{
    public function __construct(private readonly string $migrationsDir)
    {
        // Load configuration exactly as PdoMigrationRunner does, so cfg() is available to the
        // settings seeds (0013, 0022, 0023) and the legacy helpers (L-1801).
        self::loadConfig();
        require_once __DIR__ . '/LegacyFunctions.php';
    }

    private static function loadConfig(): void
    {
        require_once dirname(__DIR__) . '/Core/Config/config-functions.php';
    }

    /** @return array<string,string> version => file */
    public function available(?string $dir = null): array
    {
        $out = [];
        foreach (glob(($dir ?? $this->migrationsDir) . '/[0-9][0-9][0-9][0-9]_*.php') ?: [] as $file) {
            $out[substr(basename($file), 0, 4)] = $file;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @return array<string,string> version => applied_at */
    public function applied(D1Executor $d1): array
    {
        if (!$this->tableExists($d1)) {
            return [];
        }
        $rows = $d1->query('SELECT version, applied_at FROM schema_migrations ORDER BY version', []);
        $out = [];
        foreach ($rows as $r) {
            $out[(string)$r['version']] = (string)$r['applied_at'];
        }

        return $out;
    }

    /**
     * Apply pending migrations on D1.
     *
     * @param array{dir?:string,log?:callable(string):void} $opt
     * @return list<string> applied versions (and adopt markers)
     */
    public function migrate(D1Executor $d1, array $opt = []): array
    {
        $log = $opt['log'] ?? static function (string $m): void {
        };
        $available = $this->available($opt['dir'] ?? null);
        $applied = $this->applied($d1);
        if (!array_diff_key($available, $applied)) {
            return [];
        }

        $pdo = new D1Pdo($d1);
        $done = [];
        // schema_migrations is created before anything else (no wrapping transaction on D1).
        $d1->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version    TEXT NOT NULL PRIMARY KEY,
                name       TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )
        SQL);
        $applied = $this->applied($d1);
        $fresh = $applied === [];

        foreach ($available as $ver => $file) {
            if (isset($applied[$ver])) {
                continue;
            }
            $m = require $file;
            if (is_callable($m)) {
                // Callable migrations get the PDO-shaped D1 adapter (dialect applied in exec()).
                $m($pdo, 'sqlite');
            } elseif (is_array($m) && isset($m['sqlite'])) {
                // Multi-statement heredoc goes to D1's exec() (no bindings).
                $this->execMigration($pdo, $m['sqlite']);
            } else {
                throw new RuntimeException("Migration $ver has no SQLite step for D1.");
            }
            $d1->run([[
                'sql' => 'INSERT INTO schema_migrations (version, name, applied_at) VALUES (?,?,?)',
                'params' => [$ver, substr(basename($file, '.php'), 5), gmdate('Y-m-d H:i:s')],
            ]]);
            $done[] = $ver;
            $log('applied ' . $ver . ' ' . substr(basename($file, '.php'), 5));
        }

        if ($fresh) {
            $log('fresh D1 database; no legacy user_version adopt path');
        }

        return $done;
    }

    /** Run a migration's SQL array entry through the D1 dialect (skips connection/PRAGMA/VACUUM). */
    private function execMigration(D1Pdo $pdo, string $sql): void
    {
        // D1Pdo::exec applies the dialect: connection PRAGMAs and user_version are skipped,
        // multi-statement strings go to exec(), and a parent DROP is refused.
        $pdo->exec($sql);
    }

    private function tableExists(D1Executor $d1): bool
    {
        $rows = $d1->query("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'", []);

        return (int)($rows[0]['n'] ?? 0) > 0;
    }
}
