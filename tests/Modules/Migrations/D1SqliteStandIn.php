<?php

declare(strict_types=1);

namespace Tests\Migrations;

use Apotheke\Core\Database\D1Executor;
use Apotheke\Core\Exceptions\WriteFailed;
use PDO;
use Throwable;

/**
 * A real in-memory SQLite database behind the D1Executor interface, used to run the real
 * migration set end to end (L-1803). query/run/exec map to PDO; exec splits a multi-statement
 * string the way D1 does. Rejections surface as WriteFailed (a PDOException) so the FTS
 * fallback in 0019 is exercised (N-1805).
 */
final class D1SqliteStandIn implements D1Executor
{
    /** @var list<string> statements that reached exec() */
    public array $execLog = [];
    /** @var list<list<string>> SQL of each run() batch, in order */
    public array $batches = [];
    /** @var callable|null fn(string $sql): ?bool — return true to reject a statement */
    public $reject = null;

    private PDO $db;

    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Like D1: sqlite_master lists an internal _cf_ table that no statement may touch.
        $this->db->exec('CREATE TABLE _cf_METADATA (key INTEGER PRIMARY KEY, value BLOB)');
    }

    public function query(string $sql, array $params = []): array
    {
        try {
            return $this->execute($sql, $params);
        } catch (WriteFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new WriteFailed('Database write failed: the batch', 0, $e);
        }
    }

    private function execute(string $sql, array $params): array
    {
        $this->refuseInternal($sql);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * D1 answers SQLITE_AUTH for any statement naming one of its _cf_ tables, in any case or
     * quoting. The same text inside a string literal or a comment is not a name and is accepted.
     */
    private function refuseInternal(string $sql): void
    {
        $names = preg_replace("~'(?:[^']|'')*'|--[^\n]*|/\*.*?(?:\*/|$)~s", ' ', $sql) ?? $sql;
        if (preg_match('/(?<![\w$])_cf_/i', $names) === 1) {
            throw new WriteFailed('Database write failed: the batch');
        }
    }

    public function run(array $statements): array
    {
        $this->batches[] = array_map(static fn (array $s): string => $s['sql'], $statements);
        // D1 /batch is atomic; a later failure must not leave earlier statements of this batch.
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $out = [];
            foreach ($statements as $s) {
                if ($this->reject !== null && ($this->reject)($s['sql']) === true) {
                    throw new WriteFailed('Database write failed: the batch');
                }
                try {
                    $out[] = $this->execute($s['sql'], $s['params'] ?? []);
                } catch (WriteFailed $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw new WriteFailed('Database write failed: the batch', 0, $e);
                }
            }
            if ($own) {
                $this->db->commit();
            }

            return $out;
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e instanceof WriteFailed ? $e : new WriteFailed('Database write failed: the batch', 0, $e);
        }
    }

    public function exec(string $sql): array
    {
        $count = 0;
        foreach ($this->split($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            $this->execLog[] = $statement;
            if ($this->reject !== null && ($this->reject)($statement) === true) {
                throw new WriteFailed('Database write failed: the batch');
            }
            $this->refuseInternal($statement);
            try {
                $this->db->exec($statement);
            } catch (Throwable $e) {
                throw new WriteFailed('Database write failed: the batch', 0, $e);
            }
            $count++;
        }

        return ['count' => $count];
    }

    /** @return list<string> split on top-level semicolons (quotes/comments respected) */
    private function split(string $sql): array
    {
        $parts = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"' || $c === '`') {
                $buf .= $c;
                $i++;
                while ($i < $len) {
                    $buf .= $sql[$i];
                    if ($sql[$i] === $c) {
                        if ($i + 1 < $len && $sql[$i + 1] === $c) {
                            $buf .= $sql[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }
            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($c === ';') {
                $parts[] = $buf;
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }

        return $parts;
    }
}
