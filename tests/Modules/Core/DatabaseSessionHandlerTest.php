<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\BatchExecutor;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Http\DatabaseSessionHandler;
use Apotheke\Core\Http\SessionGate;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * Hosted sessions (DB_DRIVER=d1) live in the sessions table. The handler
 * claims a lock with one UPDATE and a later SELECT, never begins a
 * transaction, and never puts a session id or data in an error.
 */
final class DatabaseSessionHandlerTest extends CIUnitTestCase
{
    private const NOW = 1_800_000_000;

    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    #[DataProvider('drivers')]
    public function testANewSessionIsWrittenAndReadBack(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $clock = self::NOW;
            $handler = $this->handler($database, $clock);
            $this->assertSame('', $handler->read(self::ID));
            $this->assertTrue($handler->write(self::ID, 'payload-one'));
            $handler->close();
            $again = $this->handler($database, $clock);
            $this->assertSame('payload-one', $again->read(self::ID));
            $again->close();
            $this->assertTrue($this->handler($database, $clock)->exists(self::ID));
            $this->assertTrue($this->handler($database, $clock)->validateId(self::ID));
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAnExpiredSessionIsNotLiveAndGcRemovesIt(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $clock = self::NOW;
            $handler = $this->handler($database, $clock, 60);
            $handler->read(self::ID);
            $this->assertTrue($handler->write(self::ID, 'stale'));
            $handler->close();
            $later = $this->handler($database, $clock + 61, 60);
            $this->assertFalse($later->exists(self::ID));
            $this->assertFalse($later->validateId(self::ID));
            $this->assertSame('', $later->read(self::ID));
            $later->close();
            $this->assertSame(0, $this->handler($database, $clock + 61, 60)->gc(60));
            $rows = $database->db->query('SELECT id FROM sessions')->getResultArray();
            $this->assertSame([], $rows);
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testDestroyRemovesTheRow(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $handler = $this->handler($database, self::NOW);
            $handler->read(self::ID);
            $handler->write(self::ID, 'gone');
            $handler->close();
            $this->assertTrue($this->handler($database, self::NOW)->destroy(self::ID));
            $this->assertFalse($this->handler($database, self::NOW)->exists(self::ID));
            $this->assertSame('', $this->handler($database, self::NOW)->read(self::ID));
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testUpdateTimestampExtendsExpiry(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $handler = $this->handler($database, self::NOW, 60);
            $handler->read(self::ID);
            $handler->write(self::ID, 'kept');
            $handler->close();
            $this->assertTrue($this->handler($database, self::NOW + 30, 60)->updateTimestamp(self::ID, 'kept'));
            $this->assertTrue($this->handler($database, self::NOW + 80, 60)->exists(self::ID));
            $this->assertFalse($this->handler($database, self::NOW + 91, 60)->exists(self::ID));
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAHeldLockIsNotStolenAndTheWaiterSeesTheWrite(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $setup = $this->handler($database, self::NOW);
            $setup->read(self::ID);
            $setup->write(self::ID, 'first');
            $setup->close();

            $env = json_encode($database->clientEnv(), JSON_THROW_ON_ERROR);
            $worker = dirname(__DIR__) . '/_support/session-lock-worker.php';
            $procEnv = $database->clientEnv() + ['PATH' => (string)getenv('PATH')];
            $hold = proc_open(
                [PHP_BINARY, $worker, 'hold', $env, self::ID, 'second', '250'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $holdPipes,
                null,
                $procEnv,
            );
            $this->assertIsResource($hold);
            $line = (string)fgets($holdPipes[1]);
            $this->assertSame("held\n", $line, (string)stream_get_contents($holdPipes[2]));

            $follow = proc_open(
                [PHP_BINARY, $worker, 'follow', $env, self::ID, 'third', '0'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $followPipes,
                null,
                $procEnv,
            );
            $this->assertIsResource($follow);
            $holdOut = stream_get_contents($holdPipes[1]) . stream_get_contents($holdPipes[2]);
            $followOut = stream_get_contents($followPipes[1]) . stream_get_contents($followPipes[2]);
            $holdCode = proc_close($hold);
            $followCode = proc_close($follow);
            $this->assertSame(0, $holdCode, $holdOut);
            $this->assertSame(0, $followCode, $followOut);
            $this->assertStringContainsString("wrote\n", $holdOut);
            $this->assertStringContainsString('saw-second', $followOut);
            $this->assertStringContainsString("wrote\n", $followOut);
            $this->assertStringNotContainsString(self::ID, $holdOut . $followOut);
            $row = $database->db->query('SELECT data FROM sessions WHERE id = ?', [hash('sha256', self::ID)])->getRowArray();
            $this->assertSame('third', (string)($row['data'] ?? ''));
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAHeldLockIsNotClaimedWhenTheWhereClauseIsHonoured(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $database->db->query(
                'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
                [hash('sha256', self::ID), 'secret', self::NOW + 3600, 'held-by-other', self::NOW + 100],
            );
            $waits = 0;
            $handler = $this->handler($database, self::NOW, 3600, static function () use (&$waits): void {
                $waits++;
            }, 1);
            try {
                $handler->read(self::ID);
                $this->fail('a held lock must not be read');
            } catch (RuntimeException $e) {
                $this->assertSame('Could not read the session.', $e->getMessage());
                $this->assertStringNotContainsString(self::ID, $e->getMessage());
                $this->assertStringNotContainsString('secret', $e->getMessage());
            }
            $this->assertSame(0, $waits, 'one attempt does not wait after the last try');
            $row = $database->db->query('SELECT data, lock_token FROM sessions WHERE id = ?', [hash('sha256', self::ID)])->getRowArray();
            $this->assertSame('secret', (string)($row['data'] ?? ''));
            $this->assertSame('held-by-other', (string)($row['lock_token'] ?? ''));
        } finally {
            $database->drop();
        }
    }

    public function testErrorsNeverContainTheSessionIdOrData(): void
    {
        $exec = new class implements BatchExecutor {
            public function run(array $statements): array
            {
                throw new RuntimeException('boom id=aaaaaaaaaaaaaaaaaaaaaaaaaa data=secret-payload');
            }

            public function query(string $sql, array $params = []): array
            {
                throw new RuntimeException('boom id=aaaaaaaaaaaaaaaaaaaaaaaaaa data=secret-payload');
            }
        };
        $handler = new DatabaseSessionHandler($exec, 3600, static fn (): int => self::NOW, static function (int $us): void {});
        foreach ([
            fn () => $handler->read(self::ID),
            fn () => $handler->write(self::ID, 'secret-payload'),
            fn () => $handler->destroy(self::ID),
            fn () => $handler->gc(3600),
            fn () => $handler->exists(self::ID),
            fn () => $handler->updateTimestamp(self::ID, 'secret-payload'),
        ] as $call) {
            try {
                $call();
                $this->fail('the failing executor must surface as a generic error');
            } catch (RuntimeException $e) {
                $text = $e->getMessage() . $e->getTraceAsString();
                $this->assertMatchesRegularExpression('/^Could not (read the session|store the session|remove the session|expire idle sessions)\.$/', $e->getMessage());
                $this->assertStringNotContainsString(self::ID, $e->getMessage());
                $this->assertStringNotContainsString('secret-payload', $e->getMessage());
                $this->assertNull($e->getPrevious(), $text);
            }
        }
    }

    public function testHandlerSqlNeverStartsWithAComment(): void
    {
        $seen = [];
        $inner = new class implements BatchExecutor {
            /** @var list<string> */
            public array $sql = [];

            public function run(array $statements): array
            {
                foreach ($statements as $statement) {
                    $this->sql[] = $statement['sql'];
                }

                return array_fill(0, count($statements), []);
            }

            public function query(string $sql, array $params = []): array
            {
                $this->sql[] = $sql;

                return [];
            }
        };
        $clock = self::NOW;
        $handler = new DatabaseSessionHandler($inner, 3600, static fn (): int => $clock, static function (int $us): void {}, 10, 1);
        try {
            $handler->read(self::ID);
        } catch (RuntimeException) {
        }
        try {
            $handler->write(self::ID, 'x');
        } catch (RuntimeException) {
        }
        try {
            $handler->destroy(self::ID);
        } catch (RuntimeException) {
        }
        try {
            $handler->gc(60);
        } catch (RuntimeException) {
        }
        try {
            $handler->exists(self::ID);
        } catch (RuntimeException) {
        }
        try {
            $handler->updateTimestamp(self::ID, 'x');
        } catch (RuntimeException) {
        }
        $this->assertNotSame([], $inner->sql);
        foreach ($inner->sql as $sql) {
            $trim = ltrim($sql);
            $this->assertDoesNotMatchRegularExpression('/^(--|#|\/\*)/', $trim, $sql);
            $this->assertMatchesRegularExpression('/^(SELECT|INSERT|UPDATE|DELETE)\b/i', $trim, $sql);
            $seen[] = $trim;
        }
        $this->assertNotSame([], $seen);
    }

    public function testAnInvalidIdIsIgnored(): void
    {
        $exec = new class implements BatchExecutor {
            public int $calls = 0;

            public function run(array $statements): array
            {
                $this->calls++;

                return [];
            }

            public function query(string $sql, array $params = []): array
            {
                $this->calls++;

                return [];
            }
        };
        $handler = new DatabaseSessionHandler($exec, 3600, static fn (): int => self::NOW, static function (int $us): void {});
        $this->assertSame('', $handler->read('short'));
        $this->assertFalse($handler->write('short', 'x'));
        $this->assertTrue($handler->destroy('short'));
        $this->assertFalse($handler->exists('short'));
        $this->assertFalse($handler->validateId('short'));
        $this->assertFalse($handler->updateTimestamp('short', 'x'));
        $this->assertSame(0, $exec->calls);
    }

    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRegenerateMovesTheRowAndDropsTheOldId(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $handler = $this->handler($database, time());
            session_set_save_handler($handler, true);
            ini_set('session.use_strict_mode', '0');
            session_id(self::ID);
            session_start();
            $_SESSION['uid'] = 3;
            session_regenerate_id(true);
            $new = session_id();
            session_write_close();
            $this->assertNotSame(self::ID, $new);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9,-]{22,256}$/D', $new);
            $probe = $this->handler($database, time());
            $this->assertFalse($probe->exists(self::ID));
            $this->assertTrue($probe->exists($new));
            $this->assertStringContainsString('uid', $probe->read($new));
            $probe->close();
            $this->assertSame([['id' => hash('sha256', $new)]], $database->db->query('SELECT id FROM sessions')->getResultArray(), 'the old row is gone, the new one keyed by its digest');
            $this->assertNoRawValue($database, [self::ID, $new]);
        } finally {
            $database->drop();
        }
    }

    /** Session hardening: sign-out through SessionGate removes the row and leaves no new session behind. */
    #[DataProvider('drivers')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSignOutRemovesTheRowAndStartsNoNewSession(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $handler = $this->handler($database, time());
            session_set_save_handler($handler, true);
            ini_set('session.use_strict_mode', '0');
            session_id(self::ID);
            session_start();
            $_SESSION['uid'] = 3;
            session_write_close();
            $this->assertTrue($this->handler($database, time())->exists(self::ID));
            (new SessionGate(time()))->signOut([SessionGate::COOKIE]);
            $this->assertSame(PHP_SESSION_NONE, session_status());
            $this->assertSame([], $_SESSION);
            $this->assertSame([], $database->db->query('SELECT id FROM sessions')->getResultArray(), 'no row, old or new');
        } finally {
            $database->drop();
        }
    }

    // --- row keys -------------------------------------------------------------------------------------------------

    /** @param list<string> $raw */
    private function assertNoRawValue(TestDatabase $database, array $raw): void
    {
        foreach ($database->db->query('SELECT * FROM sessions')->getResultArray() as $row) {
            foreach ($row as $col => $v) {
                foreach ($raw as $id) {
                    $this->assertStringNotContainsString($id, (string)$v, 'column ' . $col);
                }
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function row(TestDatabase $database, string $key): ?array
    {
        return $database->db->query('SELECT data, expires_at, lock_token, locked_until FROM sessions WHERE id = ?', [$key])->getRowArray();
    }

    private function seed(TestDatabase $database, string $key, string $data, int $expires, string $token = '', int $until = 0): void
    {
        $database->db->query('INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)', [$key, $data, $expires, $token, $until]);
    }

    #[DataProvider('drivers')]
    public function testTheRowIsKeyedByTheDigestOfTheSessionId(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $handler = $this->handler($database, self::NOW);
            $handler->read(self::ID);
            $this->assertTrue($handler->write(self::ID, 'payload-one'));
            $handler->close();
            $rows = $database->db->query('SELECT id FROM sessions')->getResultArray();
            $this->assertSame([['id' => hash('sha256', self::ID)]], $rows);
            $this->assertSame(64, strlen((string)$rows[0]['id']));
            $this->assertNoRawValue($database, [self::ID]);
            $this->assertNull($this->row($database, self::ID), 'nothing is found by the raw value');
            $this->assertSame('payload-one', (string)($this->row($database, hash('sha256', self::ID))['data'] ?? ''));
        } finally {
            $database->drop();
        }
    }

    /** A row keyed by the raw value (a decoy) is never read, locked, extended, removed or written by any path. */
    #[DataProvider('drivers')]
    public function testEveryLookupUsesTheDigest(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $key = hash('sha256', self::ID);
            $this->seed($database, self::ID, 'decoy', self::NOW + 100);
            $decoy = $this->row($database, self::ID);
            $handler = $this->handler($database, self::NOW, 3600, null, 2);
            $this->assertFalse($handler->exists(self::ID));
            $this->assertFalse($handler->validateId(self::ID));
            $this->assertTrue($handler->updateTimestamp(self::ID, 'x'));
            $this->assertTrue($handler->destroy(self::ID));
            $this->assertSame('', $handler->read(self::ID), 'no row under the digest yet');
            $this->assertTrue($handler->write(self::ID, 'real'));
            $handler->close();
            $this->assertSame($decoy, $this->row($database, self::ID), 'the decoy is untouched');
            $this->assertSame('real', (string)($this->row($database, $key)['data'] ?? ''));

            $reader = $this->handler($database, self::NOW + 1, 3600, null, 2);
            $this->assertTrue($reader->exists(self::ID));
            $this->assertSame('real', $reader->read(self::ID));
            $this->assertNotSame('', (string)($this->row($database, $key)['lock_token'] ?? ''), 'the digest row is locked');
            $reader->close();
            $this->assertSame(['', 0], [(string)$this->row($database, $key)['lock_token'], (int)$this->row($database, $key)['locked_until']], 'and released by its digest');
            $this->assertTrue($this->handler($database, self::NOW + 50)->updateTimestamp(self::ID, 'real'));
            $this->assertSame(self::NOW + 50 + 3600, (int)$this->row($database, $key)['expires_at']);
            $this->assertTrue($this->handler($database, self::NOW + 50)->destroy(self::ID));
            $this->assertNull($this->row($database, $key));
            $this->assertSame($decoy, $this->row($database, self::ID));
        } finally {
            $database->drop();
        }
    }

    // --- stale locks ----------------------------------------------------------------------------------------------

    /** @return iterable<string,array{0:string,1:int}> */
    public static function staleLocks(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ', expired' => [$driver, -5];
            yield $driver . ', expires now' => [$driver, 0];
        }
    }

    /** A lock another token holds whose locked_until <= now is taken over by a new opener. */
    #[DataProvider('staleLocks')]
    public function testAStaleLockIsTakenOver(string $driver, int $until): void
    {
        $database = TestDatabase::create($driver);
        try {
            $key = hash('sha256', self::ID);
            $this->seed($database, $key, 'old', self::NOW + 3600, 'other-token', self::NOW + $until);
            $handler = $this->handler($database, self::NOW, 3600, static function (): void {
                throw new RuntimeException('a stale lock must be taken at once');
            }, 1);
            $this->assertSame('old', $handler->read(self::ID));
            $row = $this->row($database, $key);
            $this->assertNotContains((string)$row['lock_token'], ['', 'other-token']);
            $this->assertSame(self::NOW + 10, (int)$row['locked_until']);
            $this->assertTrue($handler->write(self::ID, 'new'));
            $handler->close();
            $this->assertSame(['new', ''], [(string)$this->row($database, $key)['data'], (string)$this->row($database, $key)['lock_token']]);
        } finally {
            $database->drop();
        }
    }

    /** One second before it lapses the lock is refused; a waiter takes it once the clock passes it. */
    #[DataProvider('drivers')]
    public function testAValidLockIsRefusedThenTakenWhenItLapses(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $key = hash('sha256', self::ID);
            $this->seed($database, $key, 'old', self::NOW + 3600, 'other-token', self::NOW + 1);
            try {
                $this->handler($database, self::NOW, 3600, static function (): void {
                }, 1)->read(self::ID);
                $this->fail('a valid lock must not be taken');
            } catch (RuntimeException $e) {
                $this->assertSame('Could not read the session.', $e->getMessage());
            }
            $this->assertSame(['old', 'other-token', self::NOW + 1], [(string)$this->row($database, $key)['data'],
                (string)$this->row($database, $key)['lock_token'], (int)$this->row($database, $key)['locked_until']]);
            $clock = self::NOW;
            $waits = 0;
            $waiter = new DatabaseSessionHandler(new LocalBatchExecutor($database->db), 3600, static function () use (&$clock): int {
                return $clock;
            }, static function () use (&$clock, &$waits): void {
                $waits++;
                $clock++;
            }, 10, 3);
            $this->assertSame('old', $waiter->read(self::ID));
            $this->assertSame(1, $waits, 'refused at now, taken at locked_until');
            $this->assertNotSame('other-token', (string)$this->row($database, $key)['lock_token']);
            $waiter->close();
        } finally {
            $database->drop();
        }
    }

    /** A write without the lock goes through over a stale lock and not over a valid one. */
    #[DataProvider('drivers')]
    public function testAWriteGoesThroughAStaleLockButNotAValidOne(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $key = hash('sha256', self::ID);
            $this->seed($database, $key, 'old', self::NOW + 3600, 'other-token', self::NOW);
            $this->assertTrue($this->handler($database, self::NOW)->write(self::ID, 'over-stale'));
            $this->assertSame(['over-stale', ''], [(string)$this->row($database, $key)['data'], (string)$this->row($database, $key)['lock_token']]);
            $database->db->query('UPDATE sessions SET lock_token = ?, locked_until = ? WHERE id = ?', ['other-token', self::NOW + 1, $key]);
            $this->assertTrue($this->handler($database, self::NOW)->write(self::ID, 'over-valid'), 'a valid lock keeps its row; the write is a no-op');
            $this->assertSame(['over-stale', 'other-token'], [(string)$this->row($database, $key)['data'], (string)$this->row($database, $key)['lock_token']]);
        } finally {
            $database->drop();
        }
    }

    // --- expired claims ------------------------------------------------------------------------------------------

    /**
     * A handler whose clock is read from $clock, so a test can let its lease run out.
     */
    private function clocked(TestDatabase $database, int &$clock): DatabaseSessionHandler
    {
        return new DatabaseSessionHandler(new LocalBatchExecutor($database->db), 3600, static function () use (&$clock): int {
            return $clock;
        }, static function (int $us): void {
            throw new RuntimeException('no wait expected');
        }, 10, 1);
    }

    /**
     * A claims, its lease runs out, and B claims the row.
     *
     * @return array{0:DatabaseSessionHandler,1:DatabaseSessionHandler} [A, B]
     */
    private function staleClaim(TestDatabase $database, int &$clock): array
    {
        $this->seed($database, hash('sha256', self::ID), 'old', self::NOW + 3600);
        $a = $this->clocked($database, $clock);
        $this->assertSame('old', $a->read(self::ID));
        $clock = self::NOW + 11;
        $b = $this->clocked($database, $clock);
        $this->assertSame('old', $b->read(self::ID));

        return [$a, $b];
    }

    /** L-1894: an expired claim stores nothing, and write() returns true instead of throwing. */
    private function assertStaleWriteRefused(DatabaseSessionHandler $a): void
    {
        $this->assertTrue($a->write(self::ID, 'from-a'), 'an expired claim stores nothing and does not fail');
    }

    #[DataProvider('drivers')]
    public function testAnExpiredClaimDoesNotOverwriteALaterReleasedWrite(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $clock = self::NOW;
            [$a, $b] = $this->staleClaim($database, $clock);
            $this->assertTrue($b->write(self::ID, 'from-b'));
            $this->assertTrue($b->close());
            $this->assertSame(['from-b', ''], [(string)$this->row($database, hash('sha256', self::ID))['data'], (string)$this->row($database, hash('sha256', self::ID))['lock_token']]);

            $this->assertStaleWriteRefused($a);
            $this->assertTrue($a->close());
            $row = $this->row($database, hash('sha256', self::ID));
            $this->assertSame(['from-b', '', 0], [(string)$row['data'], (string)$row['lock_token'], (int)$row['locked_until']]);
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAnExpiredClaimLeavesALaterHolderAlone(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $clock = self::NOW;
            [$a, $b] = $this->staleClaim($database, $clock);
            $held = $this->row($database, hash('sha256', self::ID));
            $this->assertNotSame('', (string)$held['lock_token']);

            $this->assertStaleWriteRefused($a);
            $this->assertTrue($a->updateTimestamp(self::ID, 'old'));
            $this->assertTrue($a->close());
            $this->assertSame($held, $this->row($database, hash('sha256', self::ID)));

            $this->assertTrue($b->write(self::ID, 'from-b'));
            $this->assertSame('from-b', (string)$this->row($database, hash('sha256', self::ID))['data']);
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAnExpiredClaimDoesNotRecreateADestroyedSession(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $clock = self::NOW;
            [$a, $b] = $this->staleClaim($database, $clock);
            $this->assertTrue($b->destroy(self::ID));
            $this->assertTrue($b->close());

            $this->assertStaleWriteRefused($a);
            $this->assertTrue($a->close());
            $this->assertSame([], $database->db->query('SELECT id FROM sessions')->getResultArray());
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAnExpiredClaimNobodyTookStillStores(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $this->seed($database, hash('sha256', self::ID), 'old', self::NOW + 3600);
            $clock = self::NOW;
            $a = $this->clocked($database, $clock);
            $this->assertSame('old', $a->read(self::ID));
            $clock = self::NOW + 11;
            $this->assertTrue($a->write(self::ID, 'from-a'));
            $row = $this->row($database, hash('sha256', self::ID));
            $this->assertSame(['from-a', '', self::NOW + 11 + 3600], [(string)$row['data'], (string)$row['lock_token'], (int)$row['expires_at']]);
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAHolderExtendsTheRowItClaimed(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $key = hash('sha256', self::ID);
            $this->seed($database, $key, 'old', self::NOW + 100);
            $this->seed($database, self::ID, 'decoy', self::NOW + 100);
            $decoy = $this->row($database, self::ID);
            $clock = self::NOW;
            $a = $this->clocked($database, $clock);
            $this->assertSame('old', $a->read(self::ID));
            $clock = self::NOW + 5;
            $this->assertTrue($a->updateTimestamp(self::ID, 'old'));
            $this->assertSame(self::NOW + 5 + 3600, (int)$this->row($database, $key)['expires_at']);
            $this->assertTrue($a->close());
            $this->assertSame(['old', '', 0], [(string)$this->row($database, $key)['data'], (string)$this->row($database, $key)['lock_token'], (int)$this->row($database, $key)['locked_until']]);
            $this->assertSame($decoy, $this->row($database, self::ID));
        } finally {
            $database->drop();
        }
    }

    // --- writes that do not apply ----------------------------------------------------------------------------------

    /** M-1906: the claimed UPDATE applies nothing without an error; the write fails and the row is unchanged. */
    #[DataProvider('drivers')]
    public function testAClaimedWriteThatDoesNotApplyFails(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $key = hash('sha256', self::ID);
            $this->seed($database, $key, 'old', self::NOW + 3600);
            $handler = new DatabaseSessionHandler($this->dropping($database, 'UPDATE sessions SET data'), 3600, static fn (): int => self::NOW, static function (int $us): void {});
            $this->assertSame('old', $handler->read(self::ID));
            $held = $this->row($database, $key);
            try {
                $handler->write(self::ID, 'new');
                $this->fail('a write that did not apply must fail');
            } catch (RuntimeException $e) {
                $this->assertSame('Could not store the session.', $e->getMessage());
            }
            $this->assertSame($held, $this->row($database, $key));
        } finally {
            $database->drop();
        }
    }

    /** L-1907: the unclaimed INSERT applies nothing without an error; the write fails and no row appears. */
    #[DataProvider('drivers')]
    public function testAnUnclaimedWriteThatDoesNotApplyFails(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $handler = new DatabaseSessionHandler($this->dropping($database, 'INSERT INTO sessions'), 3600, static fn (): int => self::NOW, static function (int $us): void {});
            try {
                $handler->write(self::ID, 'new');
                $this->fail('a write that did not apply must fail');
            } catch (RuntimeException $e) {
                $this->assertSame('Could not store the session.', $e->getMessage());
            }
            $this->assertSame([], $database->db->query('SELECT id FROM sessions')->getResultArray());
        } finally {
            $database->drop();
        }
    }

    /** L-1908: after a stored write the claim is spent, so a second write on the same handler is stored too. */
    #[DataProvider('drivers')]
    public function testRepeatedWritesOnOneHandlerAreStored(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $key = hash('sha256', self::ID);
            $this->seed($database, $key, 'old', self::NOW + 3600);
            $handler = $this->handler($database, self::NOW);
            $this->assertSame('old', $handler->read(self::ID));
            $this->assertTrue($handler->write(self::ID, 'one'));
            $this->assertTrue($handler->write(self::ID, 'two'));
            $this->assertSame(['two', ''], [(string)$this->row($database, $key)['data'], (string)$this->row($database, $key)['lock_token']]);
        } finally {
            $database->drop();
        }
    }

    /** L-1908: a superseded request keeps its spent claim, so writing again does not replace the later payload. */
    #[DataProvider('drivers')]
    public function testASupersededRequestCannotWriteAgainOverTheLaterPayload(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $clock = self::NOW;
            [$a, $b] = $this->staleClaim($database, $clock);
            $this->assertTrue($b->write(self::ID, 'from-b'));
            $this->assertTrue($b->close());

            $this->assertStaleWriteRefused($a);
            $this->assertTrue($a->write(self::ID, 'from-a-again'));
            $this->assertSame(['from-b', ''], [(string)$this->row($database, hash('sha256', self::ID))['data'], (string)$this->row($database, hash('sha256', self::ID))['lock_token']]);
        } finally {
            $database->drop();
        }
    }

    /** L-1908: a lapsed claim writing the data the row already holds still leaves the later holder's row alone. */
    #[DataProvider('drivers')]
    public function testALapsedClaimWithIdenticalDataLeavesTheLaterHolderAlone(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            $clock = self::NOW;
            [$a] = $this->staleClaim($database, $clock);
            $held = $this->row($database, hash('sha256', self::ID));

            $this->assertTrue($a->write(self::ID, 'old'));
            $this->assertTrue($a->updateTimestamp(self::ID, 'old'));
            $this->assertSame($held, $this->row($database, hash('sha256', self::ID)));
        } finally {
            $database->drop();
        }
    }

    /** A local executor that silently skips statements starting with $prefix. */
    private function dropping(TestDatabase $database, string $prefix): BatchExecutor
    {
        return new class (new LocalBatchExecutor($database->db), $prefix) implements BatchExecutor {
            public function __construct(private readonly BatchExecutor $inner, private readonly string $prefix)
            {
            }

            public function run(array $statements): array
            {
                return $this->inner->run(array_values(array_filter($statements, fn (array $s): bool => !str_starts_with((string)$s['sql'], $this->prefix))));
            }

            public function query(string $sql, array $params = []): array
            {
                return $this->inner->query($sql, $params);
            }
        };
    }

    /**
     * @param \Closure():void|null $wait
     */
    private function handler(TestDatabase $database, int $now, int $lifetime = 3600, ?\Closure $wait = null, int $attempts = 50): DatabaseSessionHandler
    {
        return new DatabaseSessionHandler(
            new LocalBatchExecutor($database->db),
            $lifetime,
            static fn (): int => $now,
            $wait ?? static function (int $us): void { usleep($us); },
            10,
            $attempts,
        );
    }
}
