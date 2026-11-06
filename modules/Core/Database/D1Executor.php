<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

/**
 * The D1 migration channel's executor (P4-3 PR 3): a BatchExecutor plus exec()
 * for multi-statement SQL with no bindings. D1HttpExecutor implements it over
 * the edge bridge; the tests use a SQLite stand-in.
 */
interface D1Executor extends BatchExecutor
{
    /**
     * Run one or more statements (no bindings), as D1's exec().
     *
     * @return array{count:int}
     */
    public function exec(string $sql): array;
}
