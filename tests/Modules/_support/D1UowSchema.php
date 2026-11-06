<?php

declare(strict_types=1);

namespace Tests\Support;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\SqlExecutor;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;

/** Unique D1 UOW tables and a thin CI4-shaped read adapter for the test callbacks. */
final class D1UowSchema
{
    public readonly D1UowExecutor $executor;
    public int $transDepth = 0;
    public mixed $resultID = false;
    private readonly string $prefix;

    public function __construct(public readonly D1HttpExecutor $d1, private readonly BaseConnection $db)
    {
        $this->prefix = 'd1x_' . bin2hex(random_bytes(5));
        $this->executor = new D1UowExecutor($db, $d1, $this->rewrite(...));
        $foundation = (require dirname(__DIR__, 3) . '/migrations/0031_unit_of_work.php')['sqlite'];
        $this->d1->exec(str_replace('CREATE TABLE ', 'CREATE TABLE IF NOT EXISTS ', $foundation));
        $this->exec('CREATE TABLE uw_parent (id INTEGER PRIMARY KEY, name TEXT NOT NULL, note TEXT)');
        $this->exec('CREATE TABLE uw_child (id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL, name TEXT NOT NULL)');
    }

    public function drop(): void
    {
        foreach ($this->executor->run(array_map(fn(string $name): array => ['sql' => 'DROP TABLE ' . $name, 'params' => []],
            ['uw_child', 'uw_parent'])) as $ignored);
        $this->db->close();
    }

    /** Select from real workerd/D1; write through its atomic batch API. */
    public function query(string $sql, array $params = []): BaseResult
    {
        $read = str_starts_with(ltrim($sql), 'SELECT') || str_starts_with(ltrim($sql), 'PRAGMA');
        $rewritten = $this->rewrite($sql);
        $rows = $read ? $this->d1->query($rewritten, $params) : $this->executor->run([['sql' => $sql, 'params' => $params]])[0] ?? [];
        $result = new D1UowResult($rows);
        $this->resultID = $result;

        return $result;
    }

    public function rewrite(string $sql): string
    {
        return (string) preg_replace_callback('/\b(?:uw_parent|uw_child)\b/',
            fn(array $m): string => $this->prefix . '_' . $m[0], $sql);
    }

    private function exec(string $sql): void
    {
        array_map(fn(string $s): array => $this->d1->exec($this->rewrite($s) . ';'), explode(";\n", $sql));
    }
}

final class D1UowExecutor implements BatchExecutor, SqlExecutor
{
    public function __construct(private readonly BaseConnection $db, private readonly D1HttpExecutor $d1, private readonly \Closure $rewrite) {}

    public function dialect(): Dialect { return Dialect::of($this->db); }
    public function connection(): ?BaseConnection { return null; }

    public function run(array $statements): array
    {
        return $this->d1->run(array_map(fn(array $s): array => ['sql' => ($this->rewrite)($s['sql']), 'params' => $s['params']], $statements));
    }

    public function query(string $sql, array $params = []): array { return $this->d1->query(($this->rewrite)($sql), $params); }
}

/** Minimal CI4 cursor over real D1 result rows (the tests read no result metadata). */
final class D1UowResult extends BaseResult
{
    private int $cursor = 0;
    /** @param list<array<string,mixed>> $rows */
    public function __construct(private readonly array $rows) {}
    public function getResultArray(): array { return $this->rows; }
    public function getResultObject(): array { return array_map(fn($row) => (object) $row, $this->rows); }
    public function getRow($n = 0, string $type = 'object') { return $type === 'object' ? (object)($this->rows[$n] ?? []) : ($this->rows[$n] ?? null); }
    public function getFieldCount(): int { return count($this->rows[0] ?? []); }
    public function getFieldNames(): array { return array_keys($this->rows[0] ?? []); }
    public function getFieldData(): array { return []; }
    public function freeResult(): void {}
    public function dataSeek(int $n = 0): bool { if (!isset($this->rows[$n])) return false; $this->cursor = $n; return true; }
    public function fetchAssoc(): array|false { if (!isset($this->rows[$this->cursor])) return false; return $this->rows[$this->cursor++]; }
    public function fetchObject(string $className = 'stdClass'): ?object { $row = $this->fetchAssoc(); if ($row === false) return null; $this->resultArray[] = $row; $this->rowData = $row; $obj = new $className(); foreach ($row as $k => $v) { $obj->$k = $v; } return $obj; }
}
