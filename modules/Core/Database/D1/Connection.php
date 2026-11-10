<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\D1Limits;
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

    /** @param array<string,mixed> $params */
    public function __construct(array $params)
    {
        parent::__construct($params);
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

    private function send(string $sql): Rows|bool
    {
        $mutates = self::mutates($sql);
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

    /** A statement that changes data or schema, with or without RETURNING (CodeIgniter's isWriteType without that exception). */
    private static function mutates(string $sql): bool
    {
        return preg_match('/^\s*(WITH\s.+(\s|[)]))?"?(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER)\s/is', $sql) === 1;
    }

    /** Only messages written by Apotheke: the bridge's and D1Limits' fixed texts, never the database's. */
    private static function reason(Throwable $e): string
    {
        return $e instanceof WriteFailed || $e instanceof LogicException || $e instanceof DatabaseException
            ? $e->getMessage()
            : 'Database failed: the D1 bridge';
    }
}
