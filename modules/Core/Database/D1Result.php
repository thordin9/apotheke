<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use PDO;
use PDOStatement;

/**
 * A materialised result set (a PDOStatement, so the PDO return types match), iterable via
 * getIterator() so foreach works (M-1800). fetch/fetchAll/fetchColumn read the same rows.
 */
final class D1Result extends PDOStatement
{
    private int $pos = 0;

    /** @param list<array<string,mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->rows);
    }

    public function __destruct()
    {
        // no driver resources to free
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        if ($this->pos >= count($this->rows)) {
            return false;
        }

        return $this->shape($this->rows[$this->pos++], $mode);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_KEY_PAIR) {
            $out = [];
            foreach ($this->rows as $row) {
                $v = array_values($row);
                $out[$v[0]] = $v[1] ?? null;
            }

            return $out;
        }

        return array_map(fn (array $r) => $this->shape($r, $mode), $this->rows);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->pos >= count($this->rows)) {
            return false;
        }
        $row = array_values($this->rows[$this->pos++]);

        return $row[$column] ?? false;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }

    public function closeCursor(): bool
    {
        $this->pos = count($this->rows);

        return true;
    }

    /** @param array<string,mixed> $row */
    private function shape(array $row, int $mode): mixed
    {
        return match ($mode) {
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_COLUMN => array_values($row)[0] ?? null,
            default => $row,
        };
    }
}
