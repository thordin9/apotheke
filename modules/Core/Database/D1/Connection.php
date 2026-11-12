<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\D1Limits;
use Apotheke\Core\Database\WriteClock;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\SQLite3\Connection as SQLite3Connection;
use LogicException;
use Throwable;

/**
 * The CodeIgniter driver over the edge bridge (ADR-002, P4-5): `DBDriver => 'Apotheke\Core\Database\D1'`, hostname
 * `http://d1.internal`. Every statement goes to the Installation Durable Object's SQLite (or a D1 database) through
 * D1HttpExecutor; nothing is opened locally. The SQLite3 driver's builder, forge and SQL stay in use, because the
 * bridge runs SQLite.
 *
 * The bridge runs single statements and atomic batches, never BEGIN or SAVEPOINT, so transactions work like this:
 * - Outside a transaction, a write is one batch: the statement and then `SELECT changes(), last_insert_rowid()`,
 *   so affectedRows() and insertID() belong to that statement. A read is one query.
 * - transBegin() opens a queue. Writes are queued and transCommit() sends them as one batch: all of them or none.
 *   transRollback() drops the queue; nothing was sent.
 * - A read before the first queued write runs at once. It sees committed data and holds no lock, so a write that
 *   depends on it has to carry its own guard (a unit of work). A read after a queued write, a write with RETURNING
 *   inside a transaction, and affectedRows() or insertID() while their answer is not known are refused, never
 *   answered from stale data.
 * - Statements the bridge does not run (BEGIN, SAVEPOINT, ATTACH, undocumented PRAGMAs) are refused before anything
 *   is sent or queued.
 * - A unit of work runs its batch on batch() (P4-5). It cannot open inside a transaction (its batch would commit
 *   before the transaction, and a rollback could not undo it), and while it is open a write on the connection itself
 *   is refused: each write is either in the transaction's batch or in the unit's, never split between them. A
 *   second unit while one is open (from another registry, so it would not join) is refused too.
 * - atomic() is Writes::atomic() on this driver: the callback may run one unit of work and no other write, so the
 *   step it stands for is still one batch. A second unit, or a write outside the unit, is refused before it is sent.
 * - A transport or bridge failure fails the statement: false, or a DatabaseException with DBDebug on. The database's
 *   own text is never passed on.
 */
class Connection extends SQLite3Connection
{
    /** The SQLite version the bridge guarantees at least (D1 and Durable Objects run newer); never queried. */
    public const SQLITE_FLOOR = '3.33.0';

    /** Hosts the bridge may be: the outbound handler's name, or loopback for a local harness. */
    private const HOSTS = ['d1.internal', '127.0.0.1', 'localhost'];

    public $DBDriver = 'Apotheke\\Core\\Database\\D1';

    /** Test seam: (method, url, json) → raw body. Null in production (D1HttpExecutor's own POST). */
    protected mixed $transport = null;

    /** @var list<array{sql:string,params:list<scalar|null>}> writes of the open transaction, not yet sent */
    private array $queue = [];

    /** The last write's changes() and last_insert_rowid(), or null when not known. */
    private ?int $changes = null;

    private ?int $lastId = null;

    /** @var array{code:int,message:string} */
    private array $lastError = ['code' => 0, 'message' => ''];

    /** The bridge client connect() made (also connID). */
    private ?D1HttpExecutor $bridge = null;

    /** Units of work open on batch() (a joined unit does not open another). */
    private int $units = 0;

    private ?Batch $batch = null;

    /** Inside atomic(): how many units have opened (null: not inside atomic()). */
    private ?int $atomicUnits = null;

    /** Every write this connection sent, per table (ReadCache). */
    private WriteClock $clock;

    /** @param array<string,mixed> $params */
    public function __construct(array $params)
    {
        parent::__construct($params);
        $this->clock = new WriteClock();
        // The bridge enforces foreign keys and owns durability; these PRAGMAs and the busy timeout are local only.
        $this->foreignKeys = false;
        $this->busyTimeout = null;
        $this->synchronous = null;
    }

    public function connect(bool $persistent = false)
    {
        $base = rtrim($this->hostname !== '' ? $this->hostname : D1HttpExecutor::DEFAULT_BASE, '/');
        $host = strtolower((string)parse_url($base, PHP_URL_HOST));
        if (!in_array($host, self::HOSTS, true)) {
            throw new DatabaseException('The D1 bridge must be d1.internal or a loopback harness.');
        }

        $this->bridge = new D1HttpExecutor($base, $this->transport);

        // The SQLite3 driver's generics name SQLite3 as the handle; this driver's handle is the bridge client.
        // @phpstan-ignore return.type
        return $this->bridge;
    }

    public function reconnect(): void
    {
    }

    protected function _close(): void
    {
    }

    public function getVersion(): string
    {
        return self::SQLITE_FLOOR;
    }

    /** @return Rows|bool */
    protected function execute(string $sql)
    {
        try {
            return $this->send($sql);
        } catch (UnitRuleViolation $e) {
            throw $e;   // never a false the caller might ignore
        } catch (Throwable $e) {
            $this->lastError = ['code' => (int)$e->getCode(), 'message' => self::reason($e)];
            if ($this->DBDebug) {
                throw new DatabaseException($this->lastError['message'], $this->lastError['code']);
            }

            return false;
        }
    }

    public function affectedRows(): int
    {
        return $this->known($this->changes, 'affected rows');
    }

    public function insertID(): int
    {
        return $this->known($this->lastId, 'the insert id');
    }

    /** @return array{code:int,message:string} */
    public function error(): array
    {
        return $this->lastError;
    }

    protected function _escapeString(string $str): string
    {
        // What SQLite3::escapeString() does: double the single quotes.
        return str_replace("'", "''", $str);
    }

    /** The queue is empty here: commit and rollback both clear it. */
    protected function _transBegin(): bool
    {
        return true;
    }

    protected function _transCommit(): bool
    {
        $queue = $this->queue;
        $this->queue = [];
        $this->changes = $this->lastId = null;
        if ($queue === []) {
            return true;
        }
        try {
            $this->bridge()->run($queue);

            return true;
        } catch (Throwable $e) {
            $this->lastError = ['code' => (int)$e->getCode(), 'message' => self::reason($e)];

            return false;
        }
    }

    protected function _transRollback(): bool
    {
        $this->queue = [];
        $this->changes = $this->lastId = null;

        return true;
    }

    /** The batch executor for units of work on this connection. */
    public function batch(): Batch
    {
        return $this->batch ??= new Batch($this);
    }

    public function enterUnit(): void
    {
        if ($this->transDepth > 0) {
            throw new UnitRuleViolation('D1 cannot run a unit of work inside a transaction; run the unit on its own.');
        }
        if ($this->units > 0) {
            throw new UnitRuleViolation('D1 cannot open a second unit of work while one is open; join the open unit.');
        }
        if ($this->atomicUnits !== null && $this->atomicUnits++ > 0) {
            throw new UnitRuleViolation('D1 runs one unit of work per atomic step; this step would commit in two batches.');
        }
        $this->units++;
    }

    /**
     * Writes::atomic() on D1: $fn runs one unit of work at most, and writes nothing else.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function atomic(callable $fn): mixed
    {
        if ($this->transDepth > 0 || $this->atomicUnits !== null || $this->units > 0) {
            throw new UnitRuleViolation('D1 cannot nest an atomic step in a transaction, a unit of work or another atomic step.');
        }
        $this->atomicUnits = 0;
        try {
            return $fn();
        } finally {
            $this->atomicUnits = null;
        }
    }

    public function leaveUnit(): void
    {
        $this->units = max(0, $this->units - 1);
    }

    /**
     * @param list<array{sql:string,params:list<scalar|null>}> $statements
     * @return list<list<array<string,mixed>>>
     */
    public function runBatch(array $statements): array
    {
        if ($this->transDepth > 0) {
            throw new UnitRuleViolation('D1 cannot send a batch inside a transaction; run the unit on its own.');
        }
        $this->changes = $this->lastId = null;
        foreach ($statements as $s) {
            $this->clock->wrote($s['sql']);
        }

        return $this->bridge()->run($statements);
    }

    /**
     * Several reads in one batch, for Writes::rowsMany(): each statement is a SELECT, none comes after a queued
     * write, and each passes D1Limits. Null when one does not (or the batch fails): the caller reads them one at a
     * time, so the failure is reported as a single read reports it.
     *
     * @param list<array{0:string,1:list<mixed>}> $queries
     * @return list<list<array<string,mixed>>>|null
     */
    public function readMany(array $queries): ?array
    {
        if ($this->transDepth > 0 && $this->queue !== []) {
            return null;
        }
        $statements = [];
        foreach ($queries as [$sql, $binds]) {
            if (self::mutates($sql) || preg_match('/^\s*SELECT\b/i', $sql) !== 1) {
                return null;
            }
            foreach ($binds as $v) {
                if ($v !== null && !is_scalar($v)) {
                    return null;   // read one at a time, where the query builder handles (or refuses) it
                }
            }
            $params = array_map(static fn (mixed $v): mixed => is_bool($v) ? (int)$v : $v, $binds);
            try {
                D1Limits::refuse($sql, count($params));
            } catch (Throwable) {
                return null;
            }
            $statements[] = ['sql' => $sql, 'params' => $params];
        }
        try {
            return $this->bridge()->run($statements);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<scalar|null> $params
     * @return list<array<string,mixed>>
     */
    public function readBatch(string $sql, array $params): array
    {
        if ($this->transDepth > 0 && $this->queue !== []) {
            throw new LogicException('D1 cannot read after a queued write in the same transaction; use a unit of work.');
        }

        return $this->bridge()->query($sql, $params);
    }

    /** The writes this connection sent (and the ones reported to it), for ReadCache. */
    public function writeClock(): WriteClock
    {
        return $this->clock;
    }

    private function send(string $sql): Rows|bool
    {
        $this->clock->wrote($sql);   // a queued write too: nothing is remembered inside a transaction
        $mutates = self::mutates($sql);
        if ($mutates && $this->units > 0) {
            throw new UnitRuleViolation('D1 writes while a unit of work is open go through the unit.');
        }
        if ($mutates && $this->atomicUnits !== null) {
            throw new UnitRuleViolation('D1 writes in an atomic step go through its unit of work.');
        }
        if ($this->transDepth > 0) {
            if (!$mutates) {
                if ($this->queue !== []) {
                    throw new LogicException('D1 cannot read after a queued write in the same transaction; use a unit of work.');
                }

                return new Rows($this->bridge()->query($sql));
            }
            if (!$this->isWriteType($sql)) {
                throw new LogicException('D1 cannot return rows from a write inside a transaction; use a unit of work.');
            }
            D1Limits::refuse($sql, 0);
            $this->queue[] = ['sql' => $sql, 'params' => []];
            $this->changes = $this->lastId = null;

            return true;
        }
        if (!$mutates) {
            return new Rows($this->bridge()->query($sql));
        }
        $this->changes = $this->lastId = null;
        [$rows, $meta] = $this->bridge()->run([
            ['sql' => $sql, 'params' => []],
            ['sql' => 'SELECT changes() AS changes, last_insert_rowid() AS id', 'params' => []],
        ]) + [[], []];
        $this->changes = (int)($meta[0]['changes'] ?? 0);
        $this->lastId = (int)($meta[0]['id'] ?? 0);

        return $this->isWriteType($sql) ? true : new Rows($rows);
    }

    private function bridge(): D1HttpExecutor
    {
        if ($this->bridge === null) {
            $this->initialize();
        }

        return $this->bridge ?? throw new DatabaseException('The D1 bridge is not connected.');
    }

    private function known(?int $value, string $what): int
    {
        if ($value === null) {
            throw new DatabaseException('D1 does not know ' . $what . ' while writes are queued or after a batch; use a unit of work.');
        }

        return $value;
    }

    /**
     * A write without RETURNING: CodeIgniter answers true for it instead of a result. Read on the text the
     * statement guard reads (D1Limits::leading()), so comments, literals and quoted names never decide it.
     */
    public function isWriteType($sql): bool
    {
        $first = D1Limits::leading((string)$sql);

        return self::changes($first) && preg_match('/(?<!")\bRETURNING\b(?!")/i', $first) !== 1;
    }

    /** A statement that changes data or schema, with or without RETURNING. */
    private static function mutates(string $sql): bool
    {
        return self::changes(D1Limits::leading($sql));
    }

    /** $first (D1Limits::leading()) runs INSERT, UPDATE, DELETE, REPLACE, CREATE, DROP or ALTER as its statement. */
    private static function changes(string $first): bool
    {
        return in_array(self::verb($first), ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'DROP', 'ALTER'], true);
    }

    /** The statement's own keyword: the first word, or after a WITH the first verb outside the CTEs' parentheses. */
    private static function verb(string $first): string
    {
        $word = strtoupper(preg_match('/^\w+/', $first, $m) === 1 ? $m[0] : '');
        if ($word !== 'WITH') {
            return $word;
        }
        $depth = 0;
        for ($i = 4, $n = strlen($first); $i < $n; $i++) {
            $c = $first[$i];
            if ($c === '"') {
                $i = (int)(strpos($first, '"', $i + 1) ?: $n);   // a quoted name (leading() keeps it whole)
            } elseif ($c === '(' || $c === ')') {
                $depth += $c === '(' ? 1 : -1;
            } elseif ($depth === 0 && preg_match('/\G(SELECT|VALUES|INSERT|UPDATE|DELETE|REPLACE)\b/i', $first, $v, 0, $i) === 1
                && preg_match('/\w/', $first[$i - 1]) !== 1) {
                return strtoupper($v[1]);
            }
        }

        return 'WITH';
    }

    /** Only messages written by Apotheke: the bridge's and D1Limits' fixed texts, never the database's. */
    private static function reason(Throwable $e): string
    {
        return $e instanceof WriteFailed || $e instanceof LogicException || $e instanceof DatabaseException
            ? $e->getMessage()
            : 'Database failed: the D1 bridge';
    }
}
