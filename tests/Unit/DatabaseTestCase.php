<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

use PHPUnit\Framework\TestCase;

/** Gives every test a brand-new, empty database of the configured driver (SQLite file or PostgreSQL schema). */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected string $driver;
    private ?string $schema = null;
    private ?string $file = null;
    private array $db = [];

    protected function setUp(): void
    {
        $this->driver = db_driver();
        $db = cfg()['db'];
        if ($this->driver === 'pgsql') {
            $this->schema = 'u_' . bin2hex(random_bytes(6));
            $db['schema'] = $this->schema;
        } else {
            $this->file = sys_get_temp_dir() . '/apotheke-unit-' . bin2hex(random_bytes(6)) . '.sqlite';
            $db['sqlite_path'] = $this->file;
        }
        $this->db = $db;
        try {
            $this->pdo = db_connect($db);
        } catch (PDOException $e) {
            $this->markTestSkipped('database not reachable: ' . $e->getMessage());
        }
        db($this->pdo);
    }

    protected function migrateFresh(array $opt = []): array
    {
        return migrate($this->pdo, $this->driver, $opt + ['data_dir' => sys_get_temp_dir()]);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) $this->pdo->exec('DROP SCHEMA IF EXISTS "' . $this->schema . '" CASCADE');
        unset($this->pdo);
        if ($this->file !== null) foreach (glob($this->file . '*') ?: [] as $f) @unlink($f);
    }

    /** A second connection to this test's database. */
    protected function connectAgain(): PDO
    {
        return db_connect($this->db);
    }

    /**
     * Another client in its own process: runs $statements ([sql, args] pairs) in one transaction, keeps it open for
     * $holdMs and commits. Returns once the statements ran; the closure waits for the commit and returns the output.
     *
     * @param list<array{0:string,1:list<int|string|null>}> $statements
     * @return Closure():string
     */
    protected function holdInAnotherClient(array $statements, int $holdMs): Closure
    {
        $env = array_merge(getenv(), $this->driver === 'pgsql' ? ['DB_SCHEMA' => (string)$this->schema] : ['SQLITE_PATH' => (string)$this->file]);
        $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/hold-transaction.php', json_encode($statements, JSON_THROW_ON_ERROR), (string)$holdMs],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) throw new RuntimeException('cannot start the other client');
        $out = (string)fgets($pipes[1]);
        $wait = static function () use ($proc, $pipes, &$out): string {
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return $out;
        };
        if ($out !== "held\n") throw new RuntimeException('the other client failed: ' . $wait());
        return $wait;
    }

    protected function tableExists(string $t): bool
    {
        $sql = $this->driver === 'pgsql'
            ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = " . $this->pdo->quote($t)
            : "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote($t);
        return (int)$this->pdo->query($sql)->fetchColumn() > 0;
    }
}
