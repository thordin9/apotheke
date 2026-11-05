<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\D1Limits;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;
use RuntimeException;

/** D1HttpExecutor: limits, guard mapping (N-1640, M-1783), and the HTTP wire shape. */
final class D1HttpExecutorTest extends CIUnitTestCase
{
    public function testDialectIsSqliteAndConnectionIsNull(): void
    {
        $exec = new D1HttpExecutor('http://127.0.0.1:8799', static fn (): string => '{"ok":true,"rows":[]}');
        $this->assertSame(Dialect::SQLITE, $exec->dialect()->name);
        $this->assertNull($exec->connection());
    }

    public function testRefuseBeforeTransportOnTooManyParams(): void
    {
        $called = false;
        $exec = new D1HttpExecutor('http://127.0.0.1:8799', static function () use (&$called): string {
            $called = true;

            return '{"ok":true,"results":[]}';
        });
        $params = array_fill(0, D1Limits::MAX_PARAMS + 1, 1);
        $this->expectException(LogicException::class);
        try {
            $exec->run([['sql' => 'SELECT 1 WHERE 0 IN (' . implode(',', array_fill(0, count($params), '?')) . ')', 'params' => $params]]);
        } finally {
            $this->assertFalse($called, 'transport must not run when the statement is refused');
        }
    }

    public function testRefuseStatementLongerThan100kb(): void
    {
        $sql = 'SELECT ' . str_repeat('1,', D1Limits::MAX_STATEMENT_BYTES) . '1';
        $exec = new D1HttpExecutor('http://127.0.0.1:8799', static fn (): string => '{"ok":true}');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('100000 bytes');
        $exec->query($sql);
    }

    public function testGuardFailureMapsToGuardFailedCode(): void
    {
        $exec = new D1HttpExecutor('http://127.0.0.1:8799', static fn (): string => json_encode([
            'ok' => false,
            'error' => 'D1_ERROR: NOT NULL constraint failed: uow_guard.ok',
        ], JSON_THROW_ON_ERROR));
        try {
            $exec->run([['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL', 'params' => []]]);
            $this->fail('must throw');
        } catch (WriteFailed $e) {
            $this->assertSame(BatchExecutor::GUARD_FAILED, $e->getCode());
            $this->assertSame('Database write failed: a guard in the unit', $e->getMessage());
        }
    }

    /** M-1783: workerd's result-code / extended-code suffixes map to GUARD_FAILED on the HTTP path. */
    public function testWorkerdSuffixedGuardMapsToGuardFailedCode(): void
    {
        $exec = new D1HttpExecutor('http://127.0.0.1:8799', static fn (): string => json_encode([
            'ok' => false,
            'error' => 'D1_ERROR: NOT NULL constraint failed: uow_guard.ok: SQLITE_CONSTRAINT (extended: SQLITE_CONSTRAINT_NOTNULL)',
        ], JSON_THROW_ON_ERROR));
        try {
            $exec->run([['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL', 'params' => []]]);
            $this->fail('must throw');
        } catch (WriteFailed $e) {
            $this->assertSame(BatchExecutor::GUARD_FAILED, $e->getCode());
            $this->assertSame('Database write failed: a guard in the unit', $e->getMessage());
        }
    }

    public function testPlainFailureDoesNotLeakDatabaseText(): void
    {
        $exec = new D1HttpExecutor('http://127.0.0.1:8799', static fn (): string => json_encode([
            'ok' => false,
            'error' => 'UNIQUE constraint failed: items.name secret-token-value',
        ], JSON_THROW_ON_ERROR));
        try {
            $exec->run([['sql' => 'INSERT INTO items (name) VALUES (?)', 'params' => ['x']]]);
            $this->fail('must throw');
        } catch (WriteFailed $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertSame('Database write failed: the batch', $e->getMessage());
            $this->assertStringNotContainsString('secret-token-value', $e->getMessage());
        }
    }

    /** L-1784: a string "false" must not be treated as success. */
    public function testStringFalseOkIsAFailure(): void
    {
        $exec = new D1HttpExecutor('http://127.0.0.1:8799', static fn (): string => '{"ok":"false","rows":[{"n":1}]}');
        try {
            $exec->query('SELECT 1 AS n');
            $this->fail('must throw');
        } catch (WriteFailed $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertSame('Database write failed: the batch', $e->getMessage());
        }
    }

    /** L-1785: the default transport must disable redirect following. */
    public function testDefaultTransportDisablesRedirectFollowing(): void
    {
        $src = file_get_contents((new \ReflectionClass(D1HttpExecutor::class))->getFileName());
        $this->assertMatchesRegularExpression("/'follow_location'\\s*=>\\s*0/", $src);
    }

    public function testBatchPostsStatementsAndReturnsRows(): void
    {
        $seen = null;
        $exec = new D1HttpExecutor('http://d1.internal', static function (string $method, string $url, string $body) use (&$seen): string {
            $seen = [$method, $url, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];

            return json_encode([
                'ok' => true,
                'results' => [
                    ['rows' => [], 'changes' => 1],
                    ['rows' => [['n' => 1]], 'changes' => 0],
                ],
            ], JSON_THROW_ON_ERROR);
        });
        $out = $exec->run([
            ['sql' => 'INSERT INTO t (n) VALUES (?)', 'params' => [1]],
            ['sql' => 'SELECT n FROM t', 'params' => []],
        ]);
        $this->assertSame('POST', $seen[0]);
        $this->assertSame('http://d1.internal/batch', $seen[1]);
        $this->assertSame([[], [['n' => 1]]], $out);
    }

    public function testExecPostsSqlWithoutBindings(): void
    {
        $seen = null;
        $exec = new D1HttpExecutor('http://127.0.0.1:9', static function (string $m, string $u, string $b) use (&$seen): string {
            $seen = json_decode($b, true, 512, JSON_THROW_ON_ERROR);

            return '{"ok":true,"count":2}';
        });
        $this->assertSame(['count' => 2], $exec->exec("CREATE TABLE a(x); CREATE TABLE b(y);"));
        $this->assertSame(['sql' => "CREATE TABLE a(x); CREATE TABLE b(y);"], $seen);
    }

    public function testBaseRejectsUserinfoAndPath(): void
    {
        $this->expectException(RuntimeException::class);
        new D1HttpExecutor('http://user:pass@d1.internal/batch');
    }

    /**
     * M-1783 / N-1640: positive forms (incl. workerd suffixes) and negatives that must stay plain failures.
     *
     * @return list<array{0:string,1:bool}>
     */
    public static function guardTexts(): array
    {
        return [
            // Positive: unsuffixed and prefixed.
            ['NOT NULL constraint failed: uow_guard.ok', true],
            ['D1_ERROR: NOT NULL constraint failed: uow_guard.ok', true],
            ['Error: NOT NULL constraint failed: uow_guard.ok', true],
            ['SQLite: NOT NULL constraint failed: uow_guard.ok', true],
            ['SQLSTATE[23000]: NOT NULL constraint failed: uow_guard.ok', true],
            ['Driver note: NOT NULL constraint failed: uow_guard.ok', true],
            // Positive: workerd result-code and extended-code suffixes (M-1783).
            ['NOT NULL constraint failed: uow_guard.ok: SQLITE_CONSTRAINT', true],
            ['NOT NULL constraint failed: uow_guard.ok: SQLITE_CONSTRAINT (extended: SQLITE_CONSTRAINT_NOTNULL)', true],
            ['D1_ERROR: NOT NULL constraint failed: uow_guard.ok: SQLITE_CONSTRAINT (extended: SQLITE_CONSTRAINT_NOTNULL)', true],
            ['NOT NULL constraint failed: uow_guard.ok at offset 12: SQLITE_CONSTRAINT (extended: SQLITE_CONSTRAINT_NOTNULL)', true],
            // Positive: PostgreSQL.
            ['null value in column "ok" of relation "uow_guard" violates not-null constraint', true],
            ['ERROR: null value in column "ok" of relation "uow_guard" violates not-null constraint', true],
            // Negatives: must not over-match.
            ['NOT NULL constraint failed: items.name', false],
            ['UNIQUE constraint failed: uow_guard.ok', false],
            ['UNIQUE constraint failed: uow_guard.ok: SQLITE_CONSTRAINT', false],
            ['not null constraint failed: uow_guard.ok', false], // lowercase
            [' NOT NULL constraint failed: uow_guard.ok', false], // leading space
            ['SQLITE_CONSTRAINT: NOT NULL constraint failed: uow_guard.ok', false], // code-before-text
            ['D1_EXEC_ERROR: NOT NULL constraint failed: uow_guard.ok', false], // exec prefix
            ['SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: uow_guard.ok', false],
            ['NOT NULL constraint failed: uow_guard.ok: SQLITE_CONSTRAINT_UNIQUE', false],
            ['NOT NULL constraint failed: uow_guard.ok: SQLITE_BUSY', false],
            ["NOT NULL constraint failed: uow_guard.ok\nDETAIL: secret-token-value", true], // second line ignored
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guardTexts')]
    public function testIsGuardFailureTexts(string $error, bool $expect): void
    {
        $this->assertSame($expect, D1Limits::isGuardFailure($error), $error);
    }
}
