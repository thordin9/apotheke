<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Restorer;
use Apotheke\Backup\RestoreUnit;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\ObjectLease;
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Migrations\PdoMigrationRunner;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use Apotheke\Undo\UndoTokens;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\TestDatabase;

/** Explicit R2 takes the catalog restore lease on that storage before it touches the zip or a local lock. */
final class RestoreStorageLeaseTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        $this->database?->drop();
        parent::tearDown();
    }

    public function testAHeldObjectLeaseRefusesBeforeALocalLockExists(): void
    {
        $port = $this->port();
        $storage = new R2Storage(new R2Bridge('http://127.0.0.1:' . $port));
        $lease = new ObjectLease($storage);
        $token = $lease->acquire(RestoreUnit::KEY, time(), 60);
        $restorer = $this->restorer($storage);
        try {
            $restorer->restore($this->database->dataDir() . '/missing.zip', false);
            $this->fail('A held restore lease must refuse.');
        } catch (UserError $e) {
            $this->assertSame([Restorer::BUSY, 409], [$e->getMessage(), $e->getCode()]);
        }
        $this->assertFileDoesNotExist($this->database->dataDir() . '/tmp/' . Restorer::LOCK_FILE);
        $this->assertTrue($lease->holds(RestoreUnit::KEY, $token, time()));
        $lease->release(RestoreUnit::KEY, $token);
        try {
            $restorer->restore($this->database->dataDir() . '/missing.zip', false);
            $this->fail('A missing zip is not a backup.');
        } catch (UserError $e) {
            $this->assertSame('That file is not a zip archive.', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->database->dataDir() . '/tmp/' . Restorer::LOCK_FILE);
        $this->assertFalse($lease->holds(RestoreUnit::KEY, $token, time()));
        $released = $lease->acquire(RestoreUnit::KEY, time(), 30);
        $lease->release(RestoreUnit::KEY, $released);
    }

    private function restorer(R2Storage $storage): Restorer
    {
        $this->database = TestDatabase::create('sqlite');
        $d = $this->database;
        $settings = new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore($d->dataDir() . '/brand'), new Clock(), $d->config->values(), actor: new CommandLine());
        $undo = new UndoTokens($d->db, new Clock(), 600, static function (string $file): void {
        }, static function (string $file): void {
        });
        $known = static fn (): array => array_keys((new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available());

        return new Restorer($d->db, new Backups($d->db, new Clock(), $settings, $d->dataDir(), $d->config->originalsDir()), $settings, $undo, $known, $storage, $storage, 256, new RequestCleanup());
    }

    private function port(): int
    {
        $pipes = [];
        $process = proc_open(
            ['node', dirname(__DIR__, 2) . '/Unit/Core/Storage/_restore-lease-server.mjs'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $this->server = $process;
        $port = (int)fgets($pipes[1]);
        $this->assertGreaterThan(0, $port);

        return $port;
    }
}
