<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

use Closure;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Entity\Entity;
use stdClass;

/**
 * Rows the bridge already returned (Rows); the bridge has no cursor. Field metadata comes from the
 * first row's keys, so an empty result has no field names, and there are no column types.
 *
 * @extends BaseResult<\Apotheke\Core\Database\D1HttpExecutor, Rows>
 */
class Result extends BaseResult
{
    private int $cursor = 0;

    public function getFieldCount(): int
    {
        return count($this->rows()[0] ?? []);
    }

    /** @return list<string> */
    public function getFieldNames(): array
    {
        return array_map('strval', array_keys($this->rows()[0] ?? []));
    }

    /** @return list<stdClass> */
    public function getFieldData(): array
    {
        return array_map(static function (string $name): stdClass {
            $f = new stdClass();
            $f->name = $name;
            $f->type = null;
            $f->type_name = null;
            $f->max_length = null;
            $f->length = null;

            return $f;
        }, $this->getFieldNames());
    }

    public function freeResult()
    {
        $this->resultID = new Rows([]);
    }

    public function dataSeek(int $n = 0)
    {
        $this->cursor = max(0, $n);

        return true;
    }

    protected function fetchAssoc()
    {
        return $this->rows()[$this->cursor++] ?? false;
    }

    protected function fetchObject(string $className = stdClass::class)
    {
        if (($row = $this->fetchAssoc()) === false) {
            return false;
        }
        if ($className === stdClass::class) {
            return (object)$row;
        }
        $obj = new $className();
        if ($obj instanceof Entity) {
            return $obj->injectRawData($row);
        }
        $set = Closure::bind(function (string $key, mixed $value): void {
            $this->{$key} = $value;
        }, $obj, $className);
        foreach ($row as $key => $value) {
            $set((string)$key, $value);
        }

        return $obj;
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->resultID instanceof Rows ? $this->resultID->rows : [];
    }
}
