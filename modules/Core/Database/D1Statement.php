<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use PDO;
use PDOStatement;

/**
 * A prepared statement (a PDOStatement, so PDO::prepare()'s return type matches). Bind on
 * execute(), then read a materialised D1Result. Iteration goes through the result.
 */
final class D1Statement extends PDOStatement
{
    /** @var list<scalar|null> */
    private array $params = [];
    private ?D1Result $result = null;

    public string $queryString;

    public function __construct(string $sql, private readonly D1Pdo $pdo)
    {
        $this->queryString = $sql;
    }

    /** @param list<scalar|null>|null $params */
    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? $this->params;
        $this->result = $this->pdo->runPrepared($this->queryString, $this->params);

        return true;
    }

    public function getIterator(): \Iterator
    {
        return $this->result?->getIterator() ?? new \ArrayIterator([]);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->result?->fetch($mode) ?? false;
    }

    /** @return array<int,mixed> */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->result?->fetchAll($mode) ?? [];
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->result?->fetchColumn($column) ?? false;
    }

    public function rowCount(): int
    {
        return $this->result?->rowCount() ?? 0;
    }

    public function closeCursor(): bool
    {
        return $this->result?->closeCursor() ?? true;
    }
}
