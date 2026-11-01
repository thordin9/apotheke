<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

/**
 * Runs a list of statements as one atomic batch, in order, with no reads
 * by the caller in between: the contract of D1's batch() (ADR-002,
 * decision 2). If any statement fails, nothing of the batch is kept and
 * run() throws WriteFailed, with the code GUARD_FAILED when a unit-of-work
 * guard (an insert into uow_guard) aborted it.
 */
interface BatchExecutor
{
    public const GUARD_FAILED = 1;

    /**
     * @param list<array{sql:string,params:list<scalar|null>}> $statements
     * @return list<list<array<string,mixed>>> the rows each statement returned
     */
    public function run(array $statements): array;

    /**
     * A read outside any batch.
     *
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array;
}
