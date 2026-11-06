<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\BatchUnitOfWork;
use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\D1Limits;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Test\CIUnitTestCase;
use LogicException;

/** Requires the harness's real workerd local D1 endpoint; nothing uses a Cloudflare account. */
final class D1IntegrationTest extends CIUnitTestCase
{
    private ?D1HttpExecutor $d1 = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!getenv('TEST_D1_URL')) {
            $this->markTestSkipped('TEST_D1_URL selects the local workerd harness.');
        }
        $this->d1 = new D1HttpExecutor(getenv('TEST_D1_URL'));
    }

    public function testWorkerdGuardMappingAndOriginalCallerError(): void
    {
        $d1 = $this->d1();
        $d1->exec('CREATE TABLE IF NOT EXISTS uow_guard (ok INTEGER NOT NULL)');
        try {
            $d1->run([
                ['sql' => 'INSERT INTO uow_guard (ok) VALUES (1)', 'params' => []],
                ['sql' => 'INSERT INTO uow_guard (ok) SELECT NULL', 'params' => []],
            ]);
            $this->fail('a real workerd NOT NULL violation must fail');
        } catch (WriteFailed $e) {
            $this->assertSame(BatchExecutor::GUARD_FAILED, $e->getCode());
            $this->assertSame('Database write failed: a guard in the unit', $e->getMessage());
        }
        $this->assertSame([['n' => 0]], $d1->query('SELECT COUNT(*) AS n FROM uow_guard'), 'failed first batch cannot leave a guard row');
    }

    public function testRefusalsForTheSingleStatementD1AndDOShapeAreBeforeTransport(): void
    {
        $calls = 0;
        $d1 = new D1HttpExecutor(getenv('TEST_D1_URL'), function () use (&$calls): string {
            $calls++;
            throw new \RuntimeException('a refused request cannot be sent');
        });
        foreach ([
            ['BEGIN', []], ['COMMIT', []], ['SAVEPOINT local', []], ['RELEASE local', []], ['ROLLBACK', []],
            ['PRAGMA journal_mode', []], ['ATTACH DATABASE ? AS x', ['x']],
            ['SELECT 1 AS n; SELECT 2 AS m', []],
            ['SELECT ' . str_repeat('1,', 50_001) . '1', []],
        ] as [$sql, $params]) {
            try {
                $d1->run([['sql' => $sql, 'params' => $params]]);
                $this->fail('must refuse ' . substr($sql, 0, 25));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $params = array_fill(0, D1Limits::MAX_PARAMS + 1, 1);
        try {
            $d1->query('SELECT 1 AS n IN (' . implode(',', array_fill(0, count($params), '?')) . ')', $params);
            $this->fail('too many parameters must be refused');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, $calls);
    }

    /** Seven compiled steps; the sixth guard fails, so three unexpected prefixes cost three calls. */
    public function testSevenStepReplayCostAndCallerFailure(): void
    {
        $calls = [];
        $raw = fn(string $method, string $url, string $body): string => $this->rawPost($url, $body);
        $transport = function (string $method, string $url, string $body) use (&$calls, $raw): string {
            $calls[] = [parse_url($url, PHP_URL_PATH), $body];
            $result = $raw($method, $url, $body);
            if (count($calls) === 2) {
                // Two inserts compile to four statements each; add the
                // fail-plain suffix and verify the actual SQL boundary.
                $steps = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                $this->assertCount(9, $steps);
                $this->assertSame('INSERT INTO uow_refs (ulid, id) SELECT NULL, NULL', end($steps)['sql']);
                $this->assertSame('write_failed', json_decode($result, true)['code'] ?? null, 'the suffix rejects the prefix');
            }
            return $result;
        };
        $d1 = new D1HttpExecutor(getenv('TEST_D1_URL'), $transport);
        $base = new D1HttpExecutor(getenv('TEST_D1_URL'));
        foreach (array_values([
            'parent' => 'CREATE TABLE uow_d1_ref (id INTEGER PRIMARY KEY, name TEXT NOT NULL)',
            'guard' => 'CREATE TABLE IF NOT EXISTS uow_guard (ok INTEGER NOT NULL)',
            'refs' => 'CREATE TABLE IF NOT EXISTS uow_refs (ulid TEXT PRIMARY KEY, id BIGINT NOT NULL)',
        ]) as $sql) {
            $base->exec($sql);
        }
        $unit = new BatchUnitOfWork($d1);
        $parent = $unit->insert('uow_d1_ref', ['name' => 'x'], 'parent');
        $unit->insert('uow_d1_ref', ['name' => 'after-' . $parent->ulid], 'after');
        foreach (range(1, 5) as $g) {
            $expects = $g === 5 ? 1 : 0;
            $failure = fn (): UserError => new UserError('final guard ' . $g . ' changed', 409);
            $unit->write('UPDATE uow_d1_ref SET name = ? WHERE name = ?', ['y', 'does-not-exist'], 'guard-' . $g, $expects, $failure);
        }
        try {
            $unit->flush();
            $this->fail('a real D1 guard must reject the seventh step');
        } catch (UserError $e) {
            $this->assertSame('final guard 5 changed', $e->getMessage());
            $this->assertSame(409, $e->getCode());
        }
        $batches = array_values(array_filter($calls, fn(array $c): bool => $c[0] === '/batch'));
        $this->assertCount(4, $batches, 'one original + 3 (floor(log2(7))) unexpected prefix replays');
        $this->assertCount(0, $d1->query('SELECT * FROM uow_refs'));
        $this->assertCount(0, $d1->query('SELECT * FROM uow_d1_ref'));
    }

    private function d1(): D1HttpExecutor { return $this->d1 ?? throw new \LogicException('fixture was not initialized'); }

    private function rawPost(string $url, string $body): string
    {
        return file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST',
            'header' => "content-type: application/json\r\n", 'content' => $body, 'ignore_errors' => true]]));
    }
}
