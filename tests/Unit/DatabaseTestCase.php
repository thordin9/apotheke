<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Gives every test a brand-new, empty database of the configured driver (SQLite file or PostgreSQL schema). */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected string $driver;
    private ?string $schema = null;
    private ?string $file = null;

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

    protected function tableExists(string $t): bool
    {
        $sql = $this->driver === 'pgsql'
            ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = " . $this->pdo->quote($t)
            : "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $this->pdo->quote($t);
        return (int)$this->pdo->query($sql)->fetchColumn() > 0;
    }
}
