<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\D1\Connection;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * The D1 driver against the real Installation object handler (edge/src/installation.ts) on a local node:sqlite
 * stand-in for its storage (edge/tests/do-bridge-server.mjs). Skipped without node 22.6+ (node:sqlite).
 */
final class D1DriverBridgeTest extends CIUnitTestCase
{
    /** @var resource|null */
    private $server = null;
    private string $url = '';

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 3);
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        $ok = $node !== '' && trim((string)shell_exec(escapeshellarg($node) . " -e \"try{require('node:sqlite');console.log('y')}catch{}\" 2>/dev/null")) === 'y';
        if (!$ok || !is_dir($root . '/edge/node_modules')) {
            $this->markTestSkipped('needs node with node:sqlite and edge/node_modules');
        }
        $this->server = proc_open([$node, '--experimental-strip-types', '--no-warnings', $root . '/edge/tests/do-bridge-server.mjs'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root);
        $port = (int)trim((string)fgets($pipes[1]));
        $this->assertGreaterThan(0, $port, 'the bridge server prints its port');
        $this->url = 'http://127.0.0.1:' . $port;
        $this->db()->query('CREATE TABLE uow_guard (ok INTEGER NOT NULL)');
        $this->db()->query('CREATE TABLE item (id INTEGER PRIMARY KEY, name TEXT NOT NULL, qty INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1)');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    private function db(): Connection
    {
        $db = Database::connect(['DBDriver' => 'Apotheke\\Core\\Database\\D1', 'hostname' => $this->url, 'database' => '', 'DBDebug' => false], false);
        $this->assertInstanceOf(Connection::class, $db);

        return $db;
    }

    /** A refused statement fails with the generic message; the SQLite class and phrase are kept only as its cause. */
    public function testARefusedStatementKeepsTheSqliteClassAsItsCause(): void
    {
        try {
            (new \Apotheke\Core\Database\D1HttpExecutor($this->url))->query("SELECT page_count AS n FROM pragma_page_count() WHERE 'literal-xyz' <> ?", ['bound-xyz']);
            $this->fail('the runtime refuses page_count');
        } catch (\Apotheke\Core\Exceptions\WriteFailed $e) {
            $this->assertSame('Database write failed: the batch', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertSame('SQLITE_AUTH: not authorized', $e->getPrevious()->getMessage());
        }
    }

    public function testTheRealHandlerGivesExactIdsCountsAndRows(): void
    {
        $db = $this->db();
        $db->table('item')->insertBatch([['name' => "O'Brien"], ['name' => 'b'], ['name' => 'c']]);
        $this->assertSame([3, 3], [$db->affectedRows(), $db->insertID()]);
        $db->table('item')->where('id >', 1)->update(['qty' => 4]);
        $this->assertSame(2, $db->affectedRows());
        $this->assertSame([['name' => "O'Brien", 'qty' => 0], ['name' => 'b', 'qty' => 4], ['name' => 'c', 'qty' => 4]], $db->table('item')->select('name, qty')->orderBy('id')->get()->getResultArray());
    }

    public function testACommittedTransactionIsAllOrNothingOnTheObject(): void
    {
        $db = $this->db();
        $db->table('item')->insert(['name' => 'keep', 'qty' => 1]);
        try {
            Writes::transaction($db, function () use ($db): void {
                $db->query("UPDATE item SET qty = 50 WHERE name = 'keep'");
                $db->query('INSERT INTO item (name) VALUES (NULL)');
            });
            $this->fail('the batch must fail');
        } catch (WriteFailed) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([['qty' => 1]], $db->query('SELECT qty FROM item')->getResultArray());
        Writes::transaction($db, function () use ($db): void {
            $db->query("UPDATE item SET qty = qty + 1 WHERE name = 'keep'");
            $db->query("INSERT INTO item (name) VALUES ('new')");
        });
        $this->assertSame([['n' => 2, 'q' => 2]], $db->query('SELECT COUNT(*) AS n, MAX(qty) AS q FROM item')->getResultArray());
    }

    /** @return list<array{ok:bool,code:int}> */
    private function race(string $mode, int $n): array
    {
        $procs = [];
        for ($i = 0; $i < $n; $i++) {
            $p = proc_open([PHP_BINARY, __DIR__ . '/_d1-driver-race-client.php', $this->url, $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $procs[] = [$p, $pipes];
        }
        foreach ($procs as [, $pipes]) {
            $this->assertSame("ready\n", fgets($pipes[1]), 'every racer has read and queued before any commits');
        }
        foreach ($procs as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
        }
        $out = [];
        foreach ($procs as [$p, $pipes]) {
            $out[] = json_decode((string)fgets($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
            proc_close($p);
        }

        return $out;
    }

    public function testConcurrentGuardedCompareAndSwapExactlyOneWins(): void
    {
        $this->db()->query("INSERT INTO item (id, name, qty, version) VALUES (1, 'a', 0, 1)");
        $out = $this->race('cas', 6);
        $this->assertCount(1, array_filter($out, static fn (array $r): bool => $r['ok']), json_encode($out));
        $this->assertCount(5, array_filter($out, static fn (array $r): bool => !$r['ok'] && $r['code'] === BatchExecutor::GUARD_FAILED));
        $this->assertSame([['version' => 2, 'g' => 0]], $this->db()->query('SELECT version, (SELECT COUNT(*) FROM uow_guard) AS g FROM item')->getResultArray());
    }

    public function testConcurrentRelativeWritesAllLand(): void
    {
        $this->db()->query("INSERT INTO item (id, name, qty) VALUES (1, 'a', 0)");
        $out = $this->race('add', 6);
        $this->assertSame(array_fill(0, 6, true), array_column($out, 'ok'));
        $this->assertSame([['qty' => 6]], $this->db()->query('SELECT qty FROM item')->getResultArray());
    }

    /** M-1844 on the real handler: a commented write is queued (a rollback leaves nothing) and counted outside. */
    public function testACommentedWriteIsQueuedAndCountedOnTheObject(): void
    {
        $db = $this->db();
        $db->query("INSERT INTO item (name) VALUES ('a'), ('b')");
        foreach (["-- c\nUPDATE item SET qty = 9", "/* a */\n\t-- b\n/* c */INSERT INTO item (name) VALUES ('x')"] as $sql) {
            $db->transBegin();
            $this->assertTrue($db->query($sql), $sql);
            $db->transRollback();
            $this->assertSame([['name' => 'a', 'qty' => 0], ['name' => 'b', 'qty' => 0]], $db->table('item')->select('name, qty')->orderBy('id')->get()->getResultArray(), $sql);
        }
        $this->assertTrue($db->query("/* c */ UPDATE item SET qty = 9 WHERE name = 'b'"));
        $this->assertSame(1, $db->affectedRows());
        $this->assertTrue($db->query("-- c\n  DELETE FROM item"));
        $this->assertSame(2, $db->affectedRows());
    }
}
