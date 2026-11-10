<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\RestoreFence;
use Apotheke\Backup\RestoreMutation;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LeaseDenied;
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use Apotheke\Core\Storage\LocalStorage;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The fence row is the catalog owner for every restore commit, and a second
 * client cannot commit a takeover while that row is held.
 */
final class RestoreFenceTest extends CIUnitTestCase
{
    /** @var list<resource> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        $this->processes = [];
        parent::tearDown();
    }

    public function testATransactionDoesNotCommitAfterTheFenceIsTaken(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $db = $database->db;
            $db->query('CREATE TABLE fence_probe (id INTEGER PRIMARY KEY)');
            RestoreFence::claim($db, 'holder-a');
            RestoreFence::claim($db, 'holder-b');
            try {
                Writes::transaction($db, static function () use ($db): void {
                    $db->query('INSERT INTO fence_probe (id) VALUES (7)');
                    RestoreFence::rejectUnless($db, 'holder-a');
                });
                $this->fail('The stale transaction must not commit.');
            } catch (LeaseDenied) {
            }
            $this->assertSame([], $db->query('SELECT id FROM fence_probe')->getResultArray());
            Writes::transaction($db, static function () use ($db): void {
                $db->query('INSERT INTO fence_probe (id) VALUES (7)');
                RestoreFence::rejectUnless($db, 'holder-b');
            });
            $this->assertSame([['id' => 7]], $db->query('SELECT id FROM fence_probe')->getResultArray());
        } finally {
            $database->drop();
        }
    }

    public function testSettingsAndUndoCheckTheFenceInsideTheCommit(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $db = $database->db;
            RestoreFence::claim($db, 'holder-a');
            RestoreFence::claim($db, 'holder-b');
            $settings = $this->settings($database);
            $depth = 0;
            try {
                $settings->import($this->settingsJson('Taken'), null, function () use ($db, &$depth): void {
                    $depth = (int)$db->transDepth;
                    RestoreFence::rejectUnless($db, 'holder-a');
                });
                $this->fail('The stale settings import must not commit.');
            } catch (LeaseDenied) {
            }
            $this->assertGreaterThan(0, $depth, 'The fence check runs inside the settings transaction.');
            $this->assertSame('Apotheke', $settings->get('app_name'));

            $released = [];
            $undo = new UndoTokens($db, new Clock(), 600, static function (string $file) use (&$released): void {
                $released[] = $file;
            }, static function (): void {
            });
            $db->table('undo_tokens')->insert([
                'token' => str_repeat('ab', 16), 'action' => 'delete', 'label' => 'one',
                'payload' => '{}', 'files' => '[["img","photo.jpg"]]',
                'created_at' => '2026-01-01 00:00:00', 'expires_at' => '2026-01-02 00:00:00',
            ]);
            $undoDepth = 0;
            try {
                $undo->prune(true, function () use ($db, &$undoDepth): void {
                    $undoDepth = (int)$db->transDepth;
                    RestoreFence::rejectUnless($db, 'holder-a');
                });
                $this->fail('The stale undo prune must not commit.');
            } catch (LeaseDenied) {
            }
            $this->assertGreaterThan(0, $undoDepth, 'The fence check runs inside the undo transaction.');
            $this->assertSame([], $released);
            $this->assertCount(1, $db->table('undo_tokens')->get()->getResultArray());

            $settings->import($this->settingsJson('Kept'), null, static function () use ($db): void {
                RestoreFence::rejectUnless($db, 'holder-b');
            });
            $this->assertSame('Kept', (new SettingsService(
                new SettingsRepository($db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($database->dataDir())),
                new Clock(), $database->config->values(), null, new CommandLine(),
            ))->get('app_name'));
            $undo->prune(true, static function () use ($db): void {
                RestoreFence::rejectUnless($db, 'holder-b');
            });
            $this->assertSame(['photo.jpg'], $released);
            $this->assertSame([], $db->table('undo_tokens')->get()->getResultArray());
        } finally {
            $database->drop();
        }
    }

    public function testAClaimWaitsUntilTheOpenFenceTransactionCommits(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            RestoreFence::claim($database->db, 'holder-a');
            $wait = $database->holdInAnotherClient([
                ['UPDATE restore_marker SET applied_at = ? WHERE run = ? AND sha256 = ?', ['2026-01-01 00:00:00.held', RestoreFence::RUN, 'holder-a']],
            ], 700);
            $other = $database->connectAgain();
            $started = hrtime(true);
            RestoreFence::claim($other, 'holder-b');
            $waited = (hrtime(true) - $started) / 1e9;
            $output = $wait();
            $this->assertStringContainsString('committed', $output);
            $this->assertGreaterThan(0.4, $waited, 'claim() ran while the other transaction still held the row.');
            $row = $other->query('SELECT sha256 FROM restore_marker WHERE run = ?', [RestoreFence::RUN])->getRowArray();
            $this->assertSame('holder-b', $row['sha256'] ?? null);
            try {
                Writes::transaction($database->db, static function () use ($database): void {
                    $database->db->table('settings')->where('name', 'app_name')->delete();
                    $database->db->table('settings')->insert(['name' => 'app_name', 'value' => '"Stale"', 'updated_at' => '2026-01-01 00:00:00']);
                    RestoreFence::rejectUnless($database->db, 'holder-a');
                });
                $this->fail('The holder who lost the committed takeover must not write settings.');
            } catch (LeaseDenied) {
            }
            $kept = $database->db->table('settings')->where('name', 'app_name')->get()->getRowArray();
            $this->assertNotSame('"Stale"', $kept['value'] ?? null);
        } finally {
            $database->drop();
        }
    }

    #[DataProvider('drivers')]
    public function testAClaimWaitsPastTheUsualSqliteTimeout(string $driver): void
    {
        $database = TestDatabase::create($driver);
        try {
            RestoreFence::claim($database->db, 'holder-a');
            $wait = $database->holdInAnotherClient([
                ['UPDATE restore_marker SET applied_at = ? WHERE run = ? AND sha256 = ?', ['2026-01-01 00:00:00.held', RestoreFence::RUN, 'holder-a']],
            ], 12000);
            $other = $database->connectAgain();
            $started = hrtime(true);
            RestoreFence::claim($other, 'holder-b');
            $waited = (hrtime(true) - $started) / 1e9;
            $output = $wait();
            $this->assertStringContainsString('committed', $output);
            $this->assertGreaterThan(10, $waited, 'claim() waited through the old SQLite busy timeout.');
            $this->assertLessThan(30, $waited);
            $row = $other->query('SELECT sha256 FROM restore_marker WHERE run = ?', [RestoreFence::RUN])->getRowArray();
            $this->assertSame('holder-b', $row['sha256'] ?? null);
            if ($driver === 'sqlite') {
                $timeout = $other->query('PRAGMA busy_timeout')->getRowArray();
                $this->assertSame(5000, (int)array_values($timeout ?? [])[0]);
            }
            try {
                Writes::transaction($database->db, static function () use ($database): void {
                    $database->db->table('settings')->where('name', 'app_name')->delete();
                    $database->db->table('settings')->insert(['name' => 'app_name', 'value' => '"Late"', 'updated_at' => '2026-01-01 00:00:00']);
                    RestoreFence::rejectUnless($database->db, 'holder-a');
                });
                $this->fail('The holder who lost the committed takeover must not write settings.');
            } catch (LeaseDenied) {
            }
            $kept = $database->db->table('settings')->where('name', 'app_name')->get()->getRowArray();
            $this->assertNotSame('"Late"', $kept['value'] ?? null);
        } finally {
            $database->drop();
        }
    }

    public function testAHeldBridgeWriteBlocksTheTakeoverAndKeepsExistingBytes(): void
    {
        $database = TestDatabase::create('sqlite');
        $port = $this->server();
        $url = 'http://127.0.0.1:' . $port;
        $storage = new R2Storage(new R2Bridge($url));
        try {
            $storage->write('images/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg', 'PHOTO-OLD');
            $this->assertFalse(RestoreMutation::createIfAbsent($storage, 'images/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg', 'PHOTO-NEW'));
            $this->assertSame('PHOTO-OLD', $storage->read('images/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg'));

            RestoreFence::claim($database->db, 'holder-a');
            RestoreMutation::replace($database->db, 'holder-a', $storage, 'map/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'pointer-a');
            $this->assertSame('pointer-a', $storage->read('map/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
            RestoreFence::claim($database->db, 'holder-b');
            try {
                RestoreMutation::replace($database->db, 'holder-a', $storage, 'map/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'pointer-stale');
                $this->fail('A stale holder must not replace the map pointer.');
            } catch (LeaseDenied) {
            }
            $this->assertSame('pointer-a', $storage->read('map/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
            RestoreMutation::replace($database->db, 'holder-b', $storage, 'map/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'pointer-b');
            $this->assertSame('pointer-b', $storage->read('map/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));

            RestoreFence::claim($database->db, 'holder-a');
            file_get_contents($url . '/hold-next');
            $holder = $this->worker(['replace', json_encode($database->clientEnv(), JSON_THROW_ON_ERROR), $url, 'map/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'from-a', 'holder-a']);
            $this->assertSame('1', $this->waitHolding($url));
            $challenger = $this->worker(['takeover', json_encode($database->clientEnv(), JSON_THROW_ON_ERROR), 'holder-b', $url, 'map/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'from-b']);
            $started = $this->readUntil($challenger[1], "started\n");
            usleep(300000);
            $this->assertSame('started', trim($started));
            $this->assertSame('', stream_get_contents($challenger[1]));
            $this->assertNull($storage->read('map/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
            file_get_contents($url . '/release-holds');
            $holderText = $this->finish($holder);
            $challengerText = $this->finish($challenger);
            $this->assertStringContainsString("replaced\n", $holderText);
            $this->assertStringContainsString("claimed\n", $challengerText);
            $this->assertStringContainsString("wrote\n", $challengerText);
            $this->assertSame('from-b', $storage->read('map/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
            $owner = $database->connectAgain()->query('SELECT sha256 FROM restore_marker WHERE run = ?', [RestoreFence::RUN])->getRowArray();
            $this->assertSame('holder-b', $owner['sha256'] ?? null);
        } finally {
            file_get_contents($url . '/stop');
            $database->drop();
        }
    }

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    private function settings(TestDatabase $database): SettingsService
    {
        return new SettingsService(
            new SettingsRepository($database->db),
            new SettingsSchema(),
            new LocalBrandStore(new LocalStorage($database->dataDir())),
            new Clock(),
            $database->config->values(),
            null,
            new CommandLine(),
        );
    }

    private function settingsJson(string $name): string
    {
        return (string)json_encode(['format' => 'apotheke-settings', 'settings' => ['app_name' => $name]], JSON_THROW_ON_ERROR);
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

    /**
     * @param list<string> $args
     * @return array{0:resource,1:resource,2:resource}
     */
    private function worker(array $args): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Backup/restore-fence-worker.php', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PATH' => (string)getenv('PATH'), 'CI_ENVIRONMENT' => 'testing'],
        );
        $this->assertIsResource($process);
        $this->processes[] = $process;
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes[1], $pipes[2]];
    }

    private function waitHolding(string $url): string
    {
        $until = microtime(true) + 5;
        $holding = '';
        do {
            $holding = (string)file_get_contents($url . '/holding');
            if ($holding === '1') {
                return $holding;
            }
            usleep(20000);
        } while (microtime(true) < $until);

        return $holding;
    }

    /** @param resource $pipe */
    private function readUntil($pipe, string $text): string
    {
        $until = microtime(true) + 5;
        $body = '';
        do {
            $body .= (string)stream_get_contents($pipe);
            if (str_contains($body, $text)) {
                return $body;
            }
            usleep(20000);
        } while (microtime(true) < $until);

        return $body;
    }

    /**
     * @param array{0:resource,1:resource,2:resource} $worker
     */
    private function finish(array $worker): string
    {
        stream_set_blocking($worker[1], true);
        stream_set_blocking($worker[2], true);
        $text = stream_get_contents($worker[1]) . stream_get_contents($worker[2]);
        $code = proc_close($worker[0]);
        $this->assertSame(0, $code, $text);

        return $text;
    }
}
