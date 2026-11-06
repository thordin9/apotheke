<?php

declare(strict_types=1);

namespace Tests\Migrations;

use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Core\Database\D1Pdo;
use Apotheke\Core\Database\D1Sql;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Migrations\D1MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PDO;
use RuntimeException;

require_once __DIR__ . '/D1SqliteStandIn.php';

/** P4-3 PR 3: the D1 migration dialect and the real migration set end to end. */
final class D1MigrationRunnerTest extends CIUnitTestCase
{
    private function realMigrationsDir(): string
    {
        return dirname(__DIR__, 3) . '/migrations';
    }

    /** Records exec/query/run calls and returns canned rows, so no real workerd is needed. */
    private function fakeExecutor(array $queryRows = [], ?callable $onExec = null): D1HttpExecutor
    {
        return new D1HttpExecutor('http://d1.internal', static function (string $m, string $url, string $body) use ($queryRows, $onExec): string {
            $path = parse_url($url, PHP_URL_PATH);
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if ($path === '/exec') {
                if ($onExec !== null) {
                    $onExec($payload['sql']);
                }

                return '{"ok":true,"count":1}';
            }
            if ($path === '/query') {
                return json_encode(['ok' => true, 'rows' => $queryRows], JSON_THROW_ON_ERROR);
            }
            // /batch
            $n = is_array($payload) ? count($payload) : 1;

            return json_encode(['ok' => true, 'results' => array_fill(0, $n, ['rows' => []])], JSON_THROW_ON_ERROR);
        });
    }

    // ---------------------------------------------------------------------
    // M-1800 / L-1801 / L-1803: every real migration file, end to end.
    // ---------------------------------------------------------------------

    public function testEveryRealMigrationAppliesAndRerunIsNoOp(): void
    {
        $standIn = new D1SqliteStandIn();
        $runner = new D1MigrationRunner($this->realMigrationsDir());

        $applied = $runner->migrate($standIn);
        $available = $runner->available();
        $recorded = $runner->applied($standIn);

        $this->assertSame(array_keys($available), $applied, 'every real migration file applies');
        $this->assertSame(
            array_keys($available),
            array_keys($recorded),
            'every version is recorded in schema_migrations (0028 iterates; 0013/0022/0023 use cfg())',
        );

        // 0028 reads PRAGMA table_info(users) via foreach (M-1800) and adds session_epoch:
        // reaching this version at all proves iteration did not throw an uninitialized Error.
        $cols = $standIn->query('PRAGMA table_info(users)');
        $names = array_column($cols, 'name');
        $this->assertContains('session_epoch', $names, '0028 iterated table_info and added session_epoch');

        // A rerun records no new work and changes nothing.
        $rerun = $runner->migrate($standIn);
        $this->assertSame([], $rerun, 'rerun is a no-op');
        $this->assertSame(array_keys($available), array_keys($runner->applied($standIn)));
    }

    public function testResultsAreIterableForeach(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor([['a' => 1], ['a' => 2]]));
        $st = $pdo->query('SELECT a FROM t');
        $seen = [];
        foreach ($st as $row) {
            $seen[] = $row['a'];
        }
        $this->assertSame([1, 2], $seen, 'foreach iterates a query result without an uninitialized-statement Error');
    }

    // ---------------------------------------------------------------------
    // N-1805: a rejected FTS statement takes the substring fallback.
    // ---------------------------------------------------------------------

    public function testRejectedFtsTakesSubstringFallback(): void
    {
        $standIn = new D1SqliteStandIn();
        // Simulate a SQLite build without FTS5: reject the fts5 statements.
        $standIn->reject = static fn (string $sql): bool => stripos($sql, 'fts5') !== false;

        $runner = new D1MigrationRunner($this->realMigrationsDir());
        $applied = $runner->migrate($standIn);

        $this->assertContains('0019', $applied, '0019 completes via the fallback');
        $docs = $standIn->query("SELECT name FROM sqlite_master WHERE type='table' AND name='search_docs'");
        $this->assertNotSame([], $docs, 'search_docs is created (non-FTS part runs)');
        $fts = $standIn->query("SELECT name FROM sqlite_master WHERE name='search_fts'");
        $this->assertSame([], $fts, 'the rejected FTS table is absent');
    }

    public function testWriteFailedIsPdoExceptionCompatible(): void
    {
        $e = new WriteFailed('Database write failed: the batch');
        $this->assertInstanceOf(\PDOException::class, $e, 'catch (PDOException) catches the adapter failure');
    }

    // ---------------------------------------------------------------------
    // L-1802: classifier positives (must skip / must refuse).
    // ---------------------------------------------------------------------

    public function testConnectionPragmasAndUserVersionAreSkipped(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('PRAGMA busy_timeout = 5000; PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA user_version = 3');
        $pdo->exec("VACUUM INTO '/tmp/backup.sqlite'");
        $this->assertSame([], $ran, 'no connection/user_version/VACUUM statement reaches D1');
        $this->assertCount(6, $pdo->skippedStatements());
    }

    public function testQuotedSchemaQualifiedAndCommentPrefixedUserVersionSkipped(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('PRAGMA "user_version" = 5');
        $pdo->exec('PRAGMA `user_version` = 5');
        $pdo->exec('PRAGMA [user_version] = 5');
        $pdo->exec('PRAGMA main.user_version = 5');
        $pdo->exec('PRAGMA "main"."user_version" = 5');
        $pdo->exec("/* leading */ PRAGMA user_version = 5");
        $this->assertSame([], $ran, 'quoted/schema-qualified/comment-prefixed user_version are all skipped');
        $this->assertCount(6, $pdo->skippedStatements());
    }

    public function testDriverFailureTextMatchesProduction(): void
    {
        $standIn = new D1SqliteStandIn();
        try {
            $standIn->exec('CREATE TABLE');
            $this->fail('the invalid statement must fail');
        } catch (WriteFailed $e) {
            $this->assertSame('Database write failed: the batch', $e->getMessage());
            $this->assertInstanceOf(\Throwable::class, $e->getPrevious());
        }
        $pdo = new D1Pdo($standIn);
        try {
            $pdo->query('SELECT * FROM missing_table');
            $this->fail('the invalid query must fail');
        } catch (\PDOException $e) {
            $this->assertSame('Database write failed: the batch', $e->getMessage());
        }
    }

    public function testSkippedPragmaAnswersPerPragma(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $uv = $pdo->query('PRAGMA user_version')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['user_version' => 0], $uv, 'user_version reports 0');
        $fk = $pdo->query('PRAGMA foreign_keys')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['foreign_keys' => 1], $fk, 'foreign_keys reports 1 (D1 enforces FKs)');
        $quoted = $pdo->query('PRAGMA "main"."foreign_keys"')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['foreign_keys' => 1], $quoted, 'quoted schema-qualified foreign_keys reports 1');
        foreach (['journal_mode', 'busy_timeout', 'synchronous'] as $name) {
            $this->assertSame([$name => null], $pdo->query('PRAGMA ' . $name)->fetch(PDO::FETCH_ASSOC));
        }
    }

    public function testSkippedPragmaMixedWithRealSqlRunsTheRest(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('PRAGMA user_version = 3; CREATE TABLE t (id INT);');
        $this->assertSame(['CREATE TABLE t (id INT)'], $ran, 'the pragma is stripped and the rest runs');
        $this->assertCount(1, $pdo->skippedStatements());
    }

    public function testVacuumIntoWithSemicolonInPathIsSkipped(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec("VACUUM INTO '/tmp/a;b.sqlite'");
        $this->assertSame([], $ran, 'VACUUM INTO with a semicolon inside the quoted path is skipped whole');
        $this->assertCount(1, $pdo->skippedStatements());
    }

    // ---------------------------------------------------------------------
    // L-1802: parent-DROP positives on exec, query and prepare.
    // ---------------------------------------------------------------------

    /** @return iterable<string,array{string}> */
    public static function parentDropProvider(): iterable
    {
        yield 'plain' => ['DROP TABLE items'];
        yield 'if exists' => ['DROP TABLE IF EXISTS items'];
        yield 'double-quoted' => ['DROP TABLE "items"'];
        yield 'backtick' => ['DROP TABLE `items`'];
        yield 'bracket' => ['DROP TABLE [items]'];
        yield 'schema-qualified' => ['DROP TABLE main.items'];
        yield 'quoted schema-qualified' => ['DROP TABLE "main"."items"'];
        yield 'temp-qualified' => ['DROP TABLE temp.items'];
        yield 'comment-prefixed' => ["-- drop it\nDROP TABLE items"];
        yield 'after other sql' => ['SELECT 1; DROP TABLE items'];
    }

    #[DataProvider('parentDropProvider')]
    public function testParentDropRefusedOnExec(string $sql): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $this->expectException(RuntimeException::class);
        $pdo->exec($sql);
    }

    public function testParentDropRefusedOnQuery(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $this->expectException(RuntimeException::class);
        $pdo->query('DROP TABLE main."items"');
    }

    public function testParentDropRefusedOnPreparedExecute(): void
    {
        $pdo = new D1Pdo($this->fakeExecutor());
        $stmt = $pdo->prepare('DROP TABLE "items"');
        $this->expectException(RuntimeException::class);
        $stmt->execute();
    }

    // ---------------------------------------------------------------------
    // L-1802: negatives (must NOT fire / must run).
    // ---------------------------------------------------------------------

    public function testParentDropDoesNotFireInsideCommentsOrLiterals(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        // A literal and a comment that mention DROP TABLE items must not be refused.
        $pdo->exec("INSERT INTO log (msg) VALUES ('DROP TABLE items')");
        $pdo->exec("-- DROP TABLE items\nCREATE TABLE t (id INT)");
        $pdo->exec("/* DROP TABLE items */ CREATE TABLE u (id INT)");
        $this->assertCount(3, $ran, 'DROP TABLE inside a literal or comment is not a parent drop');
    }

    public function testNonParentDropIsAllowed(): void
    {
        $ran = [];
        $pdo = new D1Pdo($this->fakeExecutor([], function (string $sql) use (&$ran): void {
            $ran[] = $sql;
        }));
        $pdo->exec('DROP TABLE IF EXISTS items_backup');
        $this->assertSame(['DROP TABLE IF EXISTS items_backup'], $ran, 'a non-parent table drop is allowed');
    }

    public function testTokenizerSplitsTriggerBodyWhole(): void
    {
        $sql = "CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = 1; END; CREATE TABLE x (id INT)";
        $parts = D1Sql::split($sql);
        $this->assertCount(2, $parts, 'the trigger body semicolon does not split');
        $this->assertStringContainsString('CREATE TRIGGER', $parts[0]);
        $this->assertStringContainsString('CREATE TABLE x', $parts[1]);
    }
    public function testTriggerInnerEndAndUnterminatedDropAreHandled(): void
    {
        $inner = 'CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = CASE WHEN id > 0 THEN id ELSE 0 END; END; DROP TABLE items';
        $this->expectException(RuntimeException::class);
        (new D1Pdo($this->fakeExecutor()))->exec($inner);
    }

    public function testUnterminatedTriggerCannotHideParentDrop(): void
    {
        $this->expectException(RuntimeException::class);
        (new D1Pdo($this->fakeExecutor()))->exec('CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = 1; DROP TABLE items');
    }

    public function testTokenizerKeepsCaseAndTriggerConditionsInsideBody(): void
    {
        $case = 'CREATE TRIGGER trg AFTER INSERT ON t BEGIN UPDATE t SET id = CASE WHEN id > 0 THEN id ELSE 0 END; END; DROP TABLE items';
        $condition = 'CREATE TRIGGER trg AFTER INSERT ON t WHEN (CASE WHEN NEW.id > 0 THEN 1 ELSE 0 END) = 1 BEGIN UPDATE t SET id = 1; END; DROP TABLE items';
        foreach ([$case, $condition] as $sql) {
            $parts = D1Sql::split($sql);
            $this->assertCount(2, $parts);
            $this->assertStringStartsWith('CREATE TRIGGER', $parts[0]);
            $this->assertSame('DROP TABLE items', $parts[1]);
        }
    }

}
