<?php

declare(strict_types=1);

namespace Apotheke\ControlPanel;

use Apotheke\Core\Libraries\Version;
use Apotheke\Search\SearchIndex;
use CodeIgniter\Database\BaseConnection;

/**
 * Control Panel → System (read-only): legacy settings_bootstrap_info(),
 * settings_database_label(), app_version(), search_engine_label() and the
 * applied migrations. The database password is only ever "set" or "empty".
 */
final class SystemInfo
{
    /**
     * @param array<string,mixed> $config ApothekeConfig values (legacy cfg())
     * @param array<string,string|false> $env the process environment
     * @param BaseConnection<object|resource, object|resource> $db
     */
    public function __construct(
        private readonly array $config,
        private readonly array $env,
        private readonly string $repoRoot,
        private readonly BaseConnection $db,
        private readonly SearchIndex $search,
    ) {
    }

    /** @return list<array{0:string,1:string,2:string}> label, value, where it comes from */
    public function rows(): array
    {
        $c = $this->config;
        /** @var array<string,mixed> $db */
        $db = is_array($c['db'] ?? null) ? $c['db'] : [];
        $env = $this->env;
        $set = static fn (string $name): bool => array_key_exists($name, $env) && is_string($env[$name]) && $env[$name] !== '';
        $src = static fn (string $name): string => $set($name) ? "env $name" : 'config.php / default';
        $str = static fn (mixed $v): string => is_scalar($v) ? (string)$v : '';
        $rows = [
            ['Data directory', $str($c['data_dir'] ?? ''), $src('DATA_DIR')],
            ['Database driver', $str($db['driver'] ?? ''), $src('DB_DRIVER')],
        ];
        if (($db['driver'] ?? '') === 'sqlite') {
            $rows[] = ['SQLite file', $str($db['sqlite_path'] ?? ''), $src('SQLITE_PATH')];
        } else {
            $rows[] = ['Database', self::databaseLabel($db), $src('DB_HOST')];
            $rows[] = ['Database user', $str($db['user'] ?? ''), $src('DB_USER')];
            $rows[] = ['Schema', $str($db['schema'] ?? '') !== '' ? $str($db['schema']) : '(default)', $src('DB_SCHEMA')];
            $rows[] = ['Database password', $str($db['password'] ?? '') !== '' ? '•••••• (set)' : '(empty)', $src('DB_PASSWORD')];
        }
        $rows[] = ['Starter data on a new database', !empty($c['seed_defaults']) ? 'yes' : 'no', $src('SEED_DEFAULTS')];
        $named = $set('APOTHEKE_CONFIG');
        $file = $named ? (string)$env['APOTHEKE_CONFIG'] : $this->repoRoot . '/config.php';
        $rows[] = ['Config file', is_file($file) ? $file : $file . ' (not present)', $named ? 'env APOTHEKE_CONFIG' : 'default'];
        $rows[] = ['PHP', PHP_VERSION, 'server'];

        return $rows;
    }

    /**
     * Legacy settings_database_label(): host, port and dbname only.
     *
     * @param array<string,mixed> $db
     */
    public static function databaseLabel(array $db): string
    {
        $str = static fn (mixed $v): string => is_scalar($v) ? (string)$v : '';
        if ($str($db['dsn'] ?? '') === '') {
            return $str($db['host'] ?? '') . ':' . $str($db['port'] ?? '') . ' / ' . $str($db['name'] ?? '');
        }
        require_once dirname(__DIR__, 3) . '/src/config.php';
        $fields = config_dsn_public_fields($str($db['dsn']));
        if ($fields === null) {
            return '<unparsed dsn>';
        }
        $parts = [];
        foreach (['host', 'port', 'dbname'] as $key) {
            if (($fields[$key] ?? '') !== '') {
                $parts[] = $key . '=' . $fields[$key];
            }
        }

        return $parts === [] ? '<unparsed dsn>' : implode(' ', $parts);
    }

    public function version(): string
    {
        return Version::current();
    }

    public function engine(): string
    {
        return $this->search->engine();
    }

    /** Legacy search_engine_label(). */
    public function engineLabel(): string
    {
        return match ($this->search->engine()) {
            'fts5' => 'SQLite FTS5 (ranked, prefix + substring, typo tolerance)',
            'pg' => 'PostgreSQL full-text (tsvector, ranked)' . ($this->search->trgm() ? ' + pg_trgm (typo tolerance)' : ' – pg_trgm not installed: typos via the built-in word list'),
            default => 'Substring search (FTS5 not available in this SQLite build)',
        };
    }

    /** @return list<string> applied migration versions, in order (legacy migrations_applied()) */
    public function migrations(): array
    {
        $q = $this->db->query('SELECT version FROM schema_migrations ORDER BY version');
        if ($q === false || is_bool($q)) {
            return [];
        }
        $out = [];
        foreach ($q->getResultArray() as $row) {
            $out[] = (string)$row['version'];
        }

        return $out;
    }
}
