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
    /** @var callable|null fn(string $sql): ?bool — return true to reject a statement */
    public $reject = null;

    private PDO $db;

    public function __construct()
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function run(array $statements): array
    {
        $out = [];
        foreach ($statements as $s) {
            $stmt = $this->db->prepare($s['sql']);
            $stmt->execute($s['params'] ?? []);
            $out[] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        return $out;
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
            try {
                $this->db->exec($statement);
            } catch (Throwable $e) {
                throw new WriteFailed('Database write failed: ' . $e->getMessage());
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
