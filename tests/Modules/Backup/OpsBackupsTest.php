<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\OpsBackupEndpoint;
use Apotheke\Backup\OpsBackupError;
use Apotheke\Backup\OpsBackups;
use Apotheke\Backup\StoredBackups;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Security\OpsSignature;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Core\Storage\Storage;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Operator console C2: one stored backup per lifecycle job, and the signed /internal/backup endpoint on ops.internal.
 * Keys are built at run time.
 */
final class OpsBackupsTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:bool}> driver, batch (one batch per unit, as on D1) */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver, false];
            if ($driver !== 'd1') {
                yield $driver . ' batch' => [$driver, true];
            }
        }
    }

    /** @return iterable<string,array{0:bool}> */
    public static function pgModes(): iterable
    {
        yield 'connection' => [false];
        yield 'batch' => [true];
    }

    protected function tearDown(): void
    {
        putenv('APOTHEKE_CLOCK');
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    private function d(): TestDatabase
    {
        return $this->database ?? throw new \RuntimeException('no database');
    }

    private function settings(): SettingsService
    {
        $d = $this->d();

        return new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($d->dataDir())), new Clock(),
            $d->config->values(), static fn (string $m): null => null, new CommandLine());
    }

    /** @param (\Closure(string):void)|null $tableDone after each table of the dump */
    private function stored(int $keep = 5, ?string $dataDir = null, ?\Closure $tableDone = null, ?Storage $store = null): StoredBackups
    {
        $d = $this->d();

        return new StoredBackups($store ?? new LocalStorage($d->dataDir()), new Backups($d->db, new Clock(), $this->settings(), $dataDir ?? $d->dataDir(), $d->config->originalsDir(), null, null, null, $tableDone), new Clock(), $keep);
    }

    /** The files under DATA_DIR/backups, relative to it. @return list<string> */
    private function backupFiles(): array
    {
        $dir = $this->d()->dataDir() . '/' . rtrim(StoredBackups::PREFIX, '/');
        $out = [];
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $out[] = substr((string)$f, strlen($dir) + 1);
            }
        }
        sort($out);

        return $out;
    }

    /**
     * DATA_DIR's storage, recording each key written in $written and calling $then right after a key ending in
     * "/$after" is written.
     *
     * @param list<string> $written
     */
    private function watched(\Closure $then, string $after, array &$written): Storage
    {
        return new class (new LocalStorage($this->d()->dataDir()), $then, $after, $written) implements Storage {
            /** @param list<string> $written */
            public function __construct(private readonly LocalStorage $inner, private readonly \Closure $then, private readonly string $after, private array &$written)
            {
            }

            public function read(string $key): ?string
            {
                return $this->inner->read($key);
            }

            public function write(string $key, string $bytes): void
            {
                $this->inner->write($key, $bytes);
                $this->written[] = $key;
                if ($this->after !== '' && str_ends_with($key, '/' . $this->after)) {
                    ($this->then)();
                }
            }

            public function writeStream(string $key, mixed $stream): void
            {
                $this->inner->writeStream($key, $stream);
                $this->written[] = $key;
            }

            public function delete(string $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(string $key): bool
            {
                return $this->inner->exists($key);
            }

            public function modifiedAt(string $key): ?int
            {
                return $this->inner->modifiedAt($key);
            }
        };
    }

    /** Another request takes the job's claim over (as after STALE_SECONDS). */
    private function takeOver(string $job): void
    {
        Writes::check($this->d()->connectAgain()->query('UPDATE settings SET value = ? WHERE name = ?',
            [(string)json_encode(['state' => 'running', 'since' => (new Clock())->unix(), 'token' => 'other']), OpsBackups::SETTING_PREFIX . $job]), 'take over');
    }

    private function ops(bool $batch = false, ?StoredBackups $stored = null): OpsBackups
    {
        $db = $this->d()->db;

        return new OpsBackups($db, $stored ?? $this->stored(), new Clock(), $batch ? UnitsOfWork::on(new LocalBatchExecutor($db)) : null);
    }

    /** @return list<string> */
    private function backupIds(): array
    {
        return array_column($this->stored()->all(), 'id');
    }

    private function record(string $job): ?string
    {
        $v = Writes::rows($this->d()->db, 'SELECT value FROM settings WHERE name = ?', [OpsBackups::SETTING_PREFIX . $job])[0]['value'] ?? null;

        return is_string($v) ? $v : null;
    }

    private function refused(\Closure $fn, string $code, int $status): void
    {
        try {
            $fn();
            $this->fail('not refused: ' . $code);
        } catch (OpsBackupError $e) {
            $this->assertSame([$code, $status], [$e->errorCode, $e->status]);
        }
    }

    #[DataProvider('modes')]
    public function testOneBackupPerJob(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $this->d()->db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $first = $this->ops($batch)->forJob('job-1');
        $this->assertTrue($first['created']);
        $this->assertSame(['export', 'job:job-1'], [$first['backup']['kind'], $first['backup']['by']]);
        $this->assertGreaterThan(0, $first['backup']['size']);
        $again = $this->ops($batch)->forJob('job-1');
        $this->assertFalse($again['created']);
        $this->assertSame($first['backup'], $again['backup']);
        $this->assertSame([$first['backup']['id']], $this->backupIds(), 'no second backup for the same job');
        $this->assertSame(['state' => 'done', 'id' => $first['backup']['id']], json_decode((string)$this->record('job-1'), true));

        $other = $this->ops($batch)->forJob('job-2');
        $this->assertTrue($other['created']);
        $this->assertCount(2, $this->backupIds());
        // The job's record is not a Control Panel setting: never exported.
        $this->assertStringNotContainsString(OpsBackups::SETTING_PREFIX, (string)json_encode($this->settings()->export()));
    }

    #[DataProvider('modes')]
    public function testAJobAlreadyRunningIsInProgressUntilItIsStale(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        putenv('APOTHEKE_CLOCK=2026-06-01 10:00:00');
        $since = (new Clock())->unix();
        $this->d()->db->query('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)',
            [OpsBackups::SETTING_PREFIX . 'job-1', json_encode(['state' => 'running', 'since' => $since, 'token' => 'other']), '2026-06-01 10:00:00']);
        putenv('APOTHEKE_CLOCK=2026-06-01 11:59:59');
        $this->refused(fn () => $this->ops($batch)->forJob('job-1'), 'backup_in_progress', 409);
        $this->assertSame([], $this->backupIds());
        putenv('APOTHEKE_CLOCK=2026-06-01 12:00:00');
        $r = $this->ops($batch)->forJob('job-1');
        $this->assertTrue($r['created'], 'a claim left by a request that died is taken over');
        $this->assertSame('done', json_decode((string)$this->record('job-1'), true)['state']);
    }

    #[DataProvider('modes')]
    public function testAFailedBackupLeavesNothingAndCanBeRetried(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        file_put_contents($this->d()->dataDir() . '/blocked', '');
        $this->refused(fn () => $this->ops($batch, $this->stored(5, $this->d()->dataDir() . '/blocked/data'))->forJob('job-1'), 'backup_failed', 500);
        $this->assertNull($this->record('job-1'));
        $this->assertSame([], $this->backupIds());
        $this->assertTrue($this->ops($batch)->forJob('job-1')['created']);
    }

    public function testARemovedBackupIsGoneAndExportsAreNotPruned(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $export = $this->ops(false, $this->stored(1))->forJob('job-1')['backup'];
        $this->stored(1)->store(StoredBackups::KIND_PRE_RESTORE, null);
        $this->stored(1)->store(StoredBackups::KIND_PRE_RESTORE, null);
        $kinds = array_column($this->stored(1)->all(), 'kind');
        sort($kinds);
        $this->assertSame(['export', 'pre-restore'], $kinds, 'keep 1 prunes the older pre-restore backup, never the export');
        $this->assertFalse($this->ops()->forJob('job-1')['created']);
        unlink($this->d()->dataDir() . '/' . StoredBackups::PREFIX . $export['id'] . '/manifest.json');
        $this->refused(fn () => $this->ops()->forJob('job-1'), 'backup_gone', 410);
        $this->refused(fn () => $this->ops()->forJob('bad id'), 'bad_request', 400);
    }

    /**
     * A backup still running when its claim is GUARD_SECONDS from STALE_SECONDS old stops before its next part and
     * keeps nothing, so a takeover never overlaps it; one second earlier it finishes.
     */
    #[DataProvider('modes')]
    public function testABackupStopsAtItsDeadline(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $this->assertSame(7200 - 600, OpsBackups::STALE_SECONDS - OpsBackups::GUARD_SECONDS);
        foreach (['job-late' => '2026-06-01 11:50:00', 'job-in-time' => '2026-06-01 11:49:59'] as $job => $at) {
            putenv('APOTHEKE_CLOCK=2026-06-01 10:00:00');
            $later = static function (string $table) use ($at): void {
                putenv('APOTHEKE_CLOCK=' . $at);
            };
            $ops = new OpsBackups($this->d()->db, $this->stored(5, null, $later), new Clock(), $batch ? UnitsOfWork::on(new LocalBatchExecutor($this->d()->db)) : null);
            if ($job === 'job-late') {
                $this->refused(fn () => $ops->forJob($job), 'backup_failed', 500);
                $this->assertNull($this->record($job), 'the claim is released');
                $this->assertSame([], $this->backupIds());
                $this->assertSame([], $this->backupFiles(), 'no part is left');
            } else {
                $this->assertTrue($ops->forJob($job)['created']);
                $this->assertCount(1, $this->backupIds());
            }
        }
    }

    /** A claim taken over while the backup runs: it stops before its next write, keeps nothing and says so (409). */
    #[DataProvider('modes')]
    public function testATakenOverBackupStopsBeforeItWrites(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        $taken = false;
        $tableDone = function (string $table) use (&$taken): void {
            if (!$taken) {
                $taken = true;
                $this->takeOver('job-1');
            }
        };
        $written = [];
        $store = $this->watched(static function (): void {
        }, '', $written);
        $ops = new OpsBackups($this->d()->db, $this->stored(5, null, $tableDone, $store), new Clock(), $batch ? UnitsOfWork::on(new LocalBatchExecutor($this->d()->db)) : null);
        $this->refused(fn () => $ops->forJob('job-1'), 'backup_in_progress', 409);
        $this->assertTrue($taken);
        $this->assertSame([], $written, 'nothing was written');
        $this->assertSame('other', json_decode((string)$this->record('job-1'), true)['token'], 'the other request keeps its claim');
        $this->assertSame([], $this->backupIds());
        $this->assertSame([], $this->backupFiles());
    }

    /**
     * Taken over after the last part and before the manifest: the manifest is never written and the parts go. Taken
     * over after the manifest, before the job is recorded: that backup is deleted again.
     */
    #[DataProvider('modes')]
    public function testATakenOverBackupKeepsNoManifest(string $driver, bool $batch): void
    {
        $this->database = TestDatabase::create($driver);
        foreach (['part-00001' => 'job-parts', 'manifest.json' => 'job-manifest'] as $after => $job) {
            $written = [];
            $store = $this->watched(fn () => $this->takeOver($job), $after, $written);
            $ops = new OpsBackups($this->d()->db, $this->stored(5, null, null, $store), new Clock(), $batch ? UnitsOfWork::on(new LocalBatchExecutor($this->d()->db)) : null);
            $this->refused(fn () => $ops->forJob($job), 'backup_in_progress', 409);
            $this->assertSame('other', json_decode((string)$this->record($job), true)['token'], $after);
            $this->assertSame([], $this->backupIds(), $after);
            $this->assertSame([], array_values(array_filter($this->backupFiles(), static fn (string $f): bool => str_contains($f, '/'))), $after . ': no part or manifest left');
            $manifests = array_values(array_filter($written, static fn (string $k): bool => str_ends_with($k, '/manifest.json')));
            $this->assertCount($after === 'manifest.json' ? 1 : 0, $manifests, $after . ': the manifest is written only when the claim was still held');
        }
    }

    /**
     * PostgreSQL, two requests for one job at once: the other one claims the job and commits a moment later. This one
     * waits for the job's key, sees the claim and makes no backup.
     */
    #[DataProvider('pgModes')]
    public function testParallelRequestsOnPostgresMakeOneBackup(bool $batch): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL only');
        }
        $this->database = TestDatabase::create('pgsql');
        $name = OpsBackups::SETTING_PREFIX . 'job-1';
        $wait = $this->d()->holdInAnotherClient([
            ['SELECT pg_advisory_xact_lock(?, ?)', UnitsOfWork::lockKeys('settings', $name)],
            ['INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [$name, (string)json_encode(['state' => 'running', 'since' => time(), 'token' => 'other']), '2026-01-01 00:00:00']],
        ], 400);
        try {
            $this->refused(fn () => $this->ops($batch)->forJob('job-1'), 'backup_in_progress', 409);
        } finally {
            $this->assertStringContainsString('committed', $wait());
        }
        $this->assertSame([], $this->backupIds());
        $this->assertSame('other', json_decode((string)$this->record('job-1'), true)['token']);
    }

    // --- the endpoint ---

    private static function appKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private function endpoint(bool $hosted, ?string $appKey): OpsBackupEndpoint
    {
        return new OpsBackupEndpoint($hosted, OpsSignature::fromAppKey($appKey), fn (): OpsBackups => $this->ops(), new Clock());
    }

    /** @return array{0:array<string,string>,1:string} headers and body of a signed request for $job */
    private static function signed(string $appKey, string $job, ?int $t = null, string $path = OpsBackupEndpoint::PATH): array
    {
        $body = (string)json_encode(['job_id' => $job]);
        $sig = OpsSignature::fromAppKey($appKey) ?? throw new \LogicException('key');

        return [['Content-Type' => 'application/json', OpsSignature::HEADER => $sig->header('POST', $path, $body, $t ?? (new Clock())->unix())], $body];
    }

    public function testTheEndpointAnswersASignedRequestWithTheBackupFields(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $key = self::appKey();
        $ep = $this->endpoint(true, $key);
        [$h, $body] = self::signed($key, 'job-1');
        $r = $ep->handle('POST', '/internal/backup', 'ops.internal', $h, $body);
        $this->assertNotNull($r);
        $this->assertSame([200, true, true], [$r['status'], $r['body']['ok'], $r['body']['created']]);
        $b = $r['body']['backup'];
        $this->assertSame(['job_id', 'id', 'key', 'prefix', 'size', 'sha256', 'parts', 'files', 'created_at'], array_keys($b));
        $this->assertSame($this->stored()->get($b['id'])['files'] ?? null, $b['files']);
        $this->assertSame(['part-00001'], array_column($b['files'], 'key'), 'relative to prefix');
        $this->assertSame('job-1', $b['job_id']);
        $this->assertSame('backups/' . $b['id'] . '/manifest.json', $b['key']);
        $this->assertSame('backups/' . $b['id'] . '/', $b['prefix']);
        $this->assertFileExists($this->d()->dataDir() . '/' . $b['key']);
        $this->assertSame($this->backupIds(), [$b['id']]);
        $this->assertSame((int)filesize($this->d()->dataDir() . '/' . $b['prefix'] . 'part-00001'), $b['size']);

        $again = $ep->handle('POST', '/internal/backup', 'OPS.internal', $h, $body);
        $this->assertNotNull($again);
        $this->assertSame([200, false, $b], [$again['status'], $again['body']['created'], $again['body']['backup']]);
        $this->assertCount(1, $this->backupIds());
    }

    public function testTheEndpointRefusesARequestWithoutAValidSignature(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $key = self::appKey();
        $ep = $this->endpoint(true, $key);
        [$h, $body] = self::signed($key, 'job-1');
        $cases = [
            'no signature' => [['Content-Type' => 'application/json'], $body],
            'another key' => [self::signed(self::appKey(), 'job-1')[0], $body],
            'another body' => [$h, (string)json_encode(['job_id' => 'job-2'])],
            'another path' => [self::signed($key, 'job-1', null, '/internal/other')[0], $body],
            'too old' => [self::signed($key, 'job-1', (new Clock())->unix() - OpsSignature::WINDOW - 1)[0], $body],
            'too new' => [self::signed($key, 'job-1', (new Clock())->unix() + OpsSignature::WINDOW + 1)[0], $body],
            'malformed' => [[OpsSignature::HEADER => 'v1=00'], $body],
        ];
        foreach ($cases as $what => [$headers, $b]) {
            $r = $ep->handle('POST', '/internal/backup', 'ops.internal', $headers, $b);
            $this->assertSame([401, 'unauthorized'], [$r['status'] ?? null, $r['body']['code'] ?? null], $what);
        }
        $this->assertSame([], $this->backupIds());
        $this->assertNull($this->record('job-1'));
        // Within the window either side: accepted.
        $edge = $ep->handle('POST', '/internal/backup', 'ops.internal', self::signed($key, 'job-1', (new Clock())->unix() - OpsSignature::WINDOW + 5)[0], $body);
        $this->assertSame(200, $edge['status'] ?? null);
        // Without a usable APP_KEY nothing is accepted.
        $none = $this->endpoint(true, null)->handle('POST', '/internal/backup', 'ops.internal', $h, $body);
        $this->assertSame([503, 'not_configured'], [$none['status'] ?? null, $none['body']['code'] ?? null]);
        $this->assertNull(OpsSignature::fromAppKey('base64:' . base64_encode('short')));
    }

    public function testTheEndpointIsNotThereForAPublicHostAForwardedRequestOrSelfHosted(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $key = self::appKey();
        [$h, $body] = self::signed($key, 'job-1');
        $hosted = $this->endpoint(true, $key);
        $this->assertNull($this->endpoint(false, $key)->handle('POST', '/internal/backup', 'ops.internal', $h, $body), 'self-hosted');
        foreach (['tenant.getapotheke.com', 'ops.internal:8080', 'ops.internal.example.com', ''] as $host) {
            $this->assertNull($hosted->handle('POST', '/internal/backup', $host, $h, $body), $host);
        }
        foreach (['X-Forwarded-Host' => 'ops.internal', 'x-forwarded-proto' => 'https', 'X-Forwarded-For' => '192.0.2.1', 'X-Apotheke-Client-IP' => '192.0.2.1'] as $name => $value) {
            $this->assertNull($hosted->handle('POST', '/internal/backup', 'ops.internal', $h + [$name => $value], $body), $name);
        }
        foreach (['/internal/backup/', '/internal/backup?x=1', '/Internal/backup', '/api/internal/backup'] as $uri) {
            $this->assertNull($hosted->handle('POST', $uri, 'ops.internal', $h, $body), $uri);
        }
        $this->assertSame([], $this->backupIds());
        $get = $hosted->handle('GET', '/internal/backup', 'ops.internal', $h, '');
        $this->assertSame([405, 'POST'], [$get['status'] ?? null, $get['allow'] ?? null]);
        $bad = $hosted->handle('POST', '/internal/backup', 'ops.internal', self::signed($key, '../x')[0], (string)json_encode(['job_id' => '../x']));
        $this->assertSame([400, 'bad_request'], [$bad['status'] ?? null, $bad['body']['code'] ?? null]);
    }

    public function testTheSignatureMatchesTheVectorTheEdgeChecks(): void
    {
        $v = json_decode((string)file_get_contents(dirname(__DIR__) . '/_support/ops-signature-vector.json'), true);
        $this->assertIsArray($v);
        $sig = OpsSignature::fromAppKey('base64:' . base64_encode(implode('', array_map('chr', range(1, 32)))));
        $this->assertNotNull($sig);
        $this->assertSame($v['header'], $sig->header($v['method'], $v['path'], $v['body'], $v['t']));
        $this->assertTrue($sig->verify($v['header'], 'POST', $v['path'], $v['body'], $v['t'] + 10));
    }
}
