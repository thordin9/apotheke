<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Jobs\RestoreConfirmJob;
use Apotheke\Backup\Jobs\RestoreConfirmRecovery;
use Apotheke\Backup\RestoreUploads;
use Apotheke\Backup\Restorer;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Jobs\JobQueue;
use Apotheke\Core\Jobs\JobRunner;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * restore.confirm is a background job only when the database cannot run an interactive transaction.
 * A second run, a second worker, and a crash after "applying" do not restore again.
 */
final class RestoreConfirmJobTest extends CIUnitTestCase
{
    /** @var list<TestDatabase> */
    private array $databases = [];

    /** @var list<resource> */
    private array $processes = [];

    private string $zip = '';

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        if ($this->zip !== '') {
            @unlink($this->zip);
            $this->zip = '';
        }
        foreach ($this->databases as $database) {
            $database->drop();
        }
        $this->databases = [];
        parent::tearDown();
    }

    public function testAConnectionStaysSynchronousAndABatchDatabaseIsAJob(): void
    {
        $database = $this->database('sqlite');
        $this->assertFalse(RestoreConfirmJob::asynchronous(new ConnectionExecutor($database->db)));
        $this->assertTrue(RestoreConfirmJob::asynchronous(new LocalBatchExecutor($database->db)));
        $this->assertFalse(RestoreConfirmJob::asynchronous(null));
    }

    #[DataProvider('drivers')]
    public function testTheJobRestoresOnceAndASecondRunDoesNot(string $driver): void
    {
        $target = $this->target($driver);
        $uploads = $this->uploads($target);
        $id = $this->ready($uploads, $this->restorer($target));
        $before = $this->names($target);
        $queue = new JobQueue($target->db, new Clock());
        $job = $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload($id, 'local', false), 'ada', RestoreConfirmJob::ONCE);
        $runner = new JobRunner($queue, [RestoreConfirmJob::TYPE => new RestoreConfirmJob($uploads, $this->restorer($target))], 'worker-a', null, static function (string $line): void {
        });
        $this->assertSame('done', $runner->runOnce()['status'] ?? null);
        $done = $queue->find($job);
        $this->assertSame(['done' => 1, 'total' => 1], $done?->checkpoint);
        $this->assertSame('restored', $uploads->get($id)['status']);
        $this->assertNotSame($before, $this->names($target));
        $after = $this->names($target);

        $again = $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload($id, 'local', false), 'ada', RestoreConfirmJob::ONCE);
        $this->assertSame('done', $runner->runOnce()['status'] ?? null);
        $this->assertSame($after, $this->names($target));
        $this->assertSame($done?->result, $queue->find($again)?->result);
    }

    public function testACrashAfterApplyingDoesNotRestoreAgain(): void
    {
        $target = $this->target('sqlite');
        $uploads = $this->uploads($target);
        $id = $this->ready($uploads, $this->restorer($target));
        $manifest = $target->dataDir() . '/uploads/restore/' . $id . '/manifest.json';
        $stored = json_decode((string)file_get_contents($manifest), true, 8, JSON_THROW_ON_ERROR);
        $stored['status'] = 'applying';
        $stored['fingerprint'] = hash_file('sha256', $target->dataDir() . '/uploads/restore/' . $id . '/backup.zip');
        file_put_contents($manifest, json_encode($stored, JSON_THROW_ON_ERROR));
        $before = $this->names($target);
        $queue = new JobQueue($target->db, new Clock());
        $job = $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload($id, 'local', false), 'ada', RestoreConfirmJob::ONCE);
        $runner = new JobRunner($queue, [RestoreConfirmJob::TYPE => new RestoreConfirmJob($uploads, $this->restorer($target))], 'worker-a', null, static function (string $line): void {
        });
        $this->assertSame('failed', $runner->runOnce()['status'] ?? null);
        $this->assertSame(RestoreUploads::INTERRUPTED, $queue->find($job)?->error);
        $this->assertSame($before, $this->names($target));
        $this->assertSame('interrupted', $uploads->get($id)['status']);
    }

    public function testASecondWorkerDoesNotClaimTheRunningJob(): void
    {
        $target = $this->database('sqlite');
        $queue = new JobQueue($target->db, new Clock(), 30);
        $other = new JobQueue($target->connectAgain(), new Clock(), 30);
        $id = $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload(str_repeat('ab', 16), 'local', false), 'ada', RestoreConfirmJob::ONCE);
        $this->assertNotNull($queue->tryClaim($id, 'worker-a'));
        $this->assertNull($other->tryClaim($id, 'worker-b'));
        $held = $queue->find($id);
        $this->assertSame('worker-a', $held?->leaseOwner);
        $this->assertSame(1, $held?->attempts);
    }

    public function testTheSameUploadIsNotQueuedTwice(): void
    {
        $target = $this->database('sqlite');
        $queue = new JobQueue($target->db, new Clock());
        $other = new JobQueue($target->connectAgain(), new Clock());
        $payload = RestoreConfirmJob::payload(str_repeat('cd', 16), 'local', true);
        $first = $queue->enqueue(RestoreConfirmJob::TYPE, $payload, 'ada', RestoreConfirmJob::ONCE);
        $second = $other->enqueue(RestoreConfirmJob::TYPE, $payload, 'ada', RestoreConfirmJob::ONCE);
        $this->assertSame($first, $second);
        $this->assertSame(1, (int)($target->db->query('SELECT COUNT(*) AS n FROM jobs')->getRowArray()['n'] ?? 0));
        $this->assertNotNull($queue->tryClaim($first, 'worker-a'));
        $this->assertNull($other->tryClaim($first, 'worker-b'));
    }

    #[DataProvider('drivers')]
    public function testAnExpiredClaimKeepsTheFinishedResult(string $driver): void
    {
        $target = $this->database($driver);
        putenv('APOTHEKE_CLOCK=2026-11-01 10:00:00');
        try {
            $queue = new JobQueue($target->db, new Clock());
            $other = new JobQueue($target->connectAgain(), new Clock());
            $id = $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload(str_repeat('ef', 16), 'local', false), 'ada', RestoreConfirmJob::ONCE);
            $job = $queue->claim('worker-a');
            $this->assertNotNull($job);
            $this->assertSame($id, $job->id);
            putenv('APOTHEKE_CLOCK=2026-11-01 10:10:00');
            $this->assertNull($other->claim('worker-b'), 'the expired attempt is not run again');
            $seen = (new RestoreConfirmRecovery($queue, $this->uploads($target)))->recover($job)->view();
            $this->assertSame('running', $seen['status']);
            $this->assertNull($seen['error']);
            $this->assertNull($seen['result']);
            $result = ['restored' => true, 'tables' => ['items' => 1], 'photos' => 0, 'warnings' => [], 'token_cleared' => false];
            $this->assertTrue($queue->complete($job, $result));
            $done = $other->find($id);
            $this->assertSame('done', $done?->status);
            $this->assertSame($result, $done?->result);
            $this->assertNull($done?->error);
        } finally {
            putenv('APOTHEKE_CLOCK');
        }
    }

    public function testARemoteConfirmRunsOnceThroughTheProductionBridge(): void
    {
        $target = $this->target('sqlite');
        $port = $this->server();
        $storage = new R2Storage(new R2Bridge('http://127.0.0.1:' . $port));
        $uploads = new RestoreUploads($target->dataDir(), 8 * 1024 * 1024, 20 * 1024 * 1024, static fn (): string => 'local', 8, static fn (): float => 1e12, null, null, $storage);
        $id = $this->ready($uploads, $this->restorer($target));
        $queue = new JobQueue($target->db, new Clock());
        $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload($id, 'local', false), 'ada', RestoreConfirmJob::ONCE);
        $runner = new JobRunner($queue, [RestoreConfirmJob::TYPE => new RestoreConfirmJob($uploads, $this->restorer($target))], 'worker-a', null, static function (string $line): void {
        });
        $this->assertSame('done', $runner->runOnce()['status'] ?? null);
        $names = $this->names($target);
        $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload($id, 'local', false), 'ada', RestoreConfirmJob::ONCE);
        $this->assertSame('done', $runner->runOnce()['status'] ?? null);
        $this->assertSame($names, $this->names($target));
        $this->assertSame('restored', $uploads->get($id)['status']);
        file_get_contents('http://127.0.0.1:' . $port . '/stop');
    }

    public function testACrashAfterTheManifestIsRestoredIsRecordedOnTheJob(): void
    {
        $target = $this->target('sqlite');
        $uploads = $this->uploads($target);
        $id = $this->ready($uploads, $this->restorer($target));
        $before = $this->names($target);
        $queue = new JobQueue($target->db, new Clock());
        $jobId = $queue->enqueue(RestoreConfirmJob::TYPE, RestoreConfirmJob::payload($id, 'local', false), 'ada', RestoreConfirmJob::ONCE);
        $job = $queue->claim('worker-a');
        $this->assertNotNull($job);
        $manifest = $target->dataDir() . '/uploads/restore/' . $id . '/manifest.json';
        $stored = json_decode((string)file_get_contents($manifest), true, 8, JSON_THROW_ON_ERROR);
        $stored['status'] = 'restored';
        $stored['result'] = ['tables' => ['items' => 1], 'photos' => 0, 'warnings' => [], 'token_cleared' => false];
        file_put_contents($manifest, json_encode($stored, JSON_THROW_ON_ERROR));
        $recovery = new RestoreConfirmRecovery($queue, $uploads);
        $first = $recovery->recover($job)->view();
        $second = $recovery->recover($queue->find($jobId) ?? $job)->view();
        $this->assertSame('done', $first['status']);
        $this->assertSame(RestoreConfirmJob::resultOf($stored['result']), $first['result']);
        $this->assertNull($first['error']);
        $this->assertSame($first, $second);
        $this->assertSame($before, $this->names($target));
        $runner = new JobRunner($queue, [RestoreConfirmJob::TYPE => new RestoreConfirmJob($uploads, $this->restorer($target))], 'worker-b', null, static function (string $line): void {
        }, $recovery);
        $this->assertNull($runner->runOnce());
        $this->assertSame('done', $queue->find($jobId)?->status);
        $this->assertSame($before, $this->names($target));
    }

    public function testAnExpiryFailureIsNotASecondJob(): void
    {
        $target = $this->database('sqlite');
        $queue = new JobQueue($target->db, new Clock());
        $other = new JobQueue($target->connectAgain(), new Clock());
        $payload = RestoreConfirmJob::payload(str_repeat('ab', 16), 'local', false);
        $id = $queue->enqueue(RestoreConfirmJob::TYPE, $payload, 'ada', RestoreConfirmJob::ONCE);
        $target->db->query(
            "UPDATE jobs SET status = 'failed', error = ?, result = NULL WHERE id = ?",
            [JobQueue::EXPIRED_ERROR, $id],
        );
        $this->assertSame($id, $other->enqueue(RestoreConfirmJob::TYPE, $payload, 'ada', RestoreConfirmJob::ONCE));
        $this->assertSame(1, (int)($target->db->query('SELECT COUNT(*) AS n FROM jobs')->getRowArray()['n'] ?? 0));
    }

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    private function database(string $driver): TestDatabase
    {
        $database = TestDatabase::create($driver);
        $this->databases[] = $database;

        return $database;
    }

    private function target(string $driver): TestDatabase
    {
        $source = TestDatabase::create($driver);
        $at = "'2026-01-02 08:00:00'";
        $source->db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Saw', 1, '', 'saw', $at, $at)");
        $this->zip = sys_get_temp_dir() . '/apotheke-restore-job-' . bin2hex(random_bytes(4)) . '.zip';
        $this->backups($source)->create($this->zip);
        $source->drop();
        $target = $this->database($driver);
        $target->db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Old', 1, '', 'old', '2025-01-01 00:00:00', '2025-01-01 00:00:00')");

        return $target;
    }

    private function backups(TestDatabase $database): Backups
    {
        return new Backups($database->db, new Clock(), $this->settings($database), $database->dataDir(), $database->config->originalsDir());
    }

    private function settings(TestDatabase $database): SettingsService
    {
        return new SettingsService(
            new SettingsRepository($database->db),
            new SettingsSchema(),
            new LocalBrandStore($database->dataDir() . '/brand'),
            new Clock(),
            $database->config->values(),
            static fn (string $message): null => null,
            new CommandLine(),
        );
    }

    private function restorer(TestDatabase $database): Restorer
    {
        $undo = new UndoTokens($database->db, new Clock(), 600, static function (string $file): void {
        }, static function (string $file): void {
        });
        $known = static fn (): array => array_keys((new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available());

        return new Restorer(
            $database->db,
            $this->backups($database),
            $this->settings($database),
            $undo,
            $known,
            new LocalStorage($database->dataDir()),
            new LocalStorage($database->config->originalsDir(), 0640),
            256,
            new RequestCleanup(),
        );
    }

    private function uploads(TestDatabase $database): RestoreUploads
    {
        return new RestoreUploads($database->dataDir(), 8 * 1024 * 1024, 20 * 1024 * 1024, static fn (): string => 'local', 8, static fn (): float => 1e12);
    }

    private function ready(RestoreUploads $uploads, Restorer $restorer): string
    {
        $bytes = (string)file_get_contents($this->zip);
        [$id] = $uploads->create(strlen($bytes), hash('sha256', $bytes));
        $part = sys_get_temp_dir() . '/apotheke-restore-part-' . $id;
        file_put_contents($part, $bytes);
        try {
            $uploads->append($id, 0, $part, strlen($bytes));
        } finally {
            @unlink($part);
        }
        $uploads->finish($id, static function (string $zip) use ($restorer): array {
            $opened = $restorer->open($zip);
            $opened['zip']->close();

            return $opened;
        });

        return $id;
    }

    /** @return list<string> */
    private function names(TestDatabase $database): array
    {
        $names = [];
        foreach ($database->db->query('SELECT name FROM items ORDER BY name')->getResultArray() as $row) {
            $names[] = (string)$row['name'];
        }

        return $names;
    }

    private function server(): int
    {
        $pipes = [];
        $process = proc_open(
            ['node', dirname(__DIR__, 2) . '/Unit/Core/Storage/_restore-lease-server.mjs'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->processes[] = $process;
        $port = (int)fgets($pipes[1]);
        $this->assertGreaterThan(0, $port);

        return $port;
    }
}
