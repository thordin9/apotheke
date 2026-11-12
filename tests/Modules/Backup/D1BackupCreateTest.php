<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Commands\BackupCli;
use Apotheke\Backup\Controllers\BackupController;
use Apotheke\Backup\Restorer;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\RequestCleanup;
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
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * A backup of the hosted database (DB_DRIVER=d1, photos and files in R2): tables larger than one read come in
 * pages with the same bytes as a local SQLite backup of the same rows, the dump says "d1" and restores on SQLite,
 * and a temp disk too small for the spooled objects stops the backup with nothing left behind.
 */
final class D1BackupCreateTest extends CIUnitTestCase
{
    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    private const FILE = 'fedcba9876543210fedcba9876543210.pdf';

    /** @var list<TestDatabase> */
    private array $databases = [];

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 3);
        $ok = trim((string)shell_exec("node -e \"try{require('node:sqlite');console.log('y')}catch{}\" 2>/dev/null")) === 'y';
        if (!$ok || !is_dir($root . '/edge/node_modules')) {
            $this->markTestSkipped('needs node with node:sqlite and edge/node_modules');
        }
        putenv('APOTHEKE_CLOCK=2026-11-12 10:00:00');
        $this->server = $_SERVER;
    }

    /** @var array<string,mixed> */
    private array $server = [];

    protected function tearDown(): void
    {
        if ($this->server !== []) {
            $_SERVER = $this->server;
        }
        putenv('APOTHEKE_CLOCK');
        Events::removeAllListeners('DBQuery');
        Services::reset(true);
        foreach ($this->databases as $d) {
            $d->drop();
        }
        $this->databases = [];
        parent::tearDown();
    }

    public function testAHostedBackupReadsInPagesAndMatchesALocalOne(): void
    {
        [$d1, $d1Json] = $this->backup('d1');
        [, $sqliteJson] = $this->backup('sqlite');
        $this->assertSame('d1', $d1Json['driver']);
        $this->assertSame('sqlite', $sqliteJson['driver']);
        $this->assertSame(Backups::BATCH * 2 + 1, count($d1Json['tables']['activity']['rows']));
        $this->assertSame(Backups::BATCH + 100, count($d1Json['tables']['item_tags']['rows']));
        unset($d1Json['driver'], $sqliteJson['driver']);
        $this->assertSame($sqliteJson, $d1Json);

        // No read returned a whole table: activity took three pages, item_tags two (on its two-column key).
        $reads = [];
        Events::on('DBQuery', static function ($q) use (&$reads): void {
            $sql = $q->getQuery();
            if (preg_match('/^SELECT .* FROM (activity|item_tags)\b/', $sql, $m) === 1) {
                $reads[] = $m[1];
                if (!str_contains($sql, ' LIMIT ' . Backups::BATCH)) {
                    throw new \LogicException('an unpaged read: ' . $sql);
                }
            }
        });
        $this->backups($d1, $this->r2($this->objects()))->create($d1->dataDir() . '/again.zip');
        $this->assertSame(['item_tags' => 2, 'activity' => 3], array_count_values($reads));
    }

    public function testAHostedBackupRestoresOnSqlite(): void
    {
        [$d1] = $this->backup('d1');
        $zip = $d1->dataDir() . '/backup.zip';
        $target = $this->database('sqlite');
        $restorer = $this->restorer($target);
        $opened = $restorer->open($zip);
        $opened['zip']->close();
        $this->assertSame('d1', $opened['driver']);
        $restorer->restore($zip, false);
        $this->assertSame(Backups::BATCH * 2 + 1, (int)$target->db->query('SELECT COUNT(*) AS n FROM activity')->getRow()->n);
        $this->assertSame(Backups::BATCH + 100, (int)$target->db->query('SELECT COUNT(*) AS n FROM item_tags')->getRow()->n);
        $this->assertSame($this->objects()['files/' . self::FILE], file_get_contents($target->dataDir() . '/files/' . self::FILE));
        $this->assertFileExists($target->dataDir() . '/images/' . self::PHOTO);
    }

    public function testATempDiskTooSmallStopsTheBackupWithNothingLeft(): void
    {
        $d = $this->database('d1');
        $this->seed($d->db);
        $zip = $d->dataDir() . '/backup.zip';
        $seen = [];
        $backups = $this->backups($d, $this->r2($this->objects()), static function (string $dir) use (&$seen): float {
            $seen[] = $dir;

            return (float)(Backups::SPOOL_HEADROOM + 10);
        });
        try {
            $backups->create($zip);
            $this->fail('A disk without room for the objects must stop the backup.');
        } catch (UserError $e) {
            $this->assertSame(Backups::NO_SPACE, $e->getMessage());
        }
        $this->assertSame([$d->dataDir() . '/tmp'], array_values(array_unique($seen)));
        $this->assertFileDoesNotExist($zip);
        $this->assertSame([], glob($d->dataDir() . '/tmp/backup-*') ?: []);

        // apotheke:backup prints the message and exits 1.
        $err = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $this->assertNotFalse($err);
        $this->assertNotFalse($out);
        $cli = new BackupCli($backups, $this->restorer($d), $d->dataDir(), STDIN, $out, $err);
        $this->assertSame(1, $cli->backup([$zip]));
        rewind($err);
        $this->assertSame(Backups::NO_SPACE . "\n", stream_get_contents($err));
        $this->assertFileDoesNotExist($zip);

        // GET /backup.zip shows the message on the backup page (422), and its temp zip is gone.
        Services::reset(true);
        Services::injectMock('apothekeDb', $d->db);
        Services::injectMock('backups', $backups);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = Services::request(null, false);
        Services::injectMock('request', $request);
        $c = new BackupController();
        $c->initController($request, Services::response(null, false), Services::logger());
        $r = $c->download();
        $this->assertSame(422, $r->getStatusCode());
        $this->assertStringContainsString(htmlspecialchars(Backups::NO_SPACE), (string)$r->getBody());
        $this->assertSame([], array_merge(glob($d->dataDir() . '/tmp/dl-*') ?: [], glob($d->dataDir() . '/tmp/backup-*') ?: []));

        // With room, the same backup is made.
        $roomy = $this->backups($d, $this->r2($this->objects()), static fn (): float => 1e12);
        $this->assertSame([], $roomy->create($zip)['missing']);
    }

    /** @return array{0:TestDatabase,1:array<string,mixed>} the database and its backup.json */
    private function backup(string $driver): array
    {
        $d = $this->database($driver);
        $this->seed($d->db);
        $zip = $d->dataDir() . '/backup.zip';
        $storage = $driver === 'd1' ? $this->r2($this->objects()) : null;
        if ($storage === null) {
            foreach ($this->objects() as $key => $bytes) {
                $path = str_starts_with($key, 'originals/') ? $d->config->originalsDir() . '/' . substr($key, 10) : $d->dataDir() . '/' . $key;
                @mkdir(dirname($path), 0770, true);
                file_put_contents($path, $bytes);
            }
        }
        $result = $this->backups($d, $storage)->create($zip);
        $this->assertSame([], $result['missing']);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($zip));
        $this->assertSame($this->objects()['images/' . self::PHOTO], $z->getFromName('images/' . self::PHOTO));
        $this->assertSame($this->objects()['files/' . self::FILE], $z->getFromName('files/' . self::FILE));
        $json = json_decode((string)$z->getFromName('backup.json'), true, 512, JSON_THROW_ON_ERROR);
        $z->close();
        $this->assertSame([], glob($d->dataDir() . '/tmp/backup-*') ?: []);

        return [$d, $json];
    }

    private function database(string $driver): TestDatabase
    {
        return $this->databases[] = TestDatabase::create($driver);
    }

    /** The same rows on either database: activity over two pages, item_tags over one on a two-column key. */
    private function seed(BaseConnection $db): void
    {
        $at = "'2026-01-01 00:00:00'";
        $sql = [
            "INSERT INTO locations (id, name, name_key, is_default, created_at) VALUES (1, 'Shed', 'shed', 0, $at)",
            "INSERT INTO items (id, image, name, location_id, quantity, notes, search, created_at, updated_at) VALUES (7, '" . self::PHOTO . "', 'Lamp', 1, 1, 'ünïcode \"quoted\"', 'lamp', $at, $at)",
            "INSERT INTO items (id, name, location_id, quantity, notes, search, created_at, updated_at) VALUES (9, 'Rope', 1, 2, '', 'rope', $at, $at)",
            "INSERT INTO item_photos (item_id, file, position, created_at) VALUES (7, '" . self::PHOTO . "', 0, $at)",
            "INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (7, '" . self::FILE . "', 'a.pdf', 'application/pdf', 10, $at)",
            'INSERT INTO tags (id, name, name_key, created_at) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < ' . (Backups::BATCH / 2 + 50) . ") SELECT i * 3, 'tag ' || i, 'tag ' || i, $at FROM n",
            'INSERT INTO item_tags (item_id, tag_id) SELECT 9, id FROM tags',
            'INSERT INTO item_tags (item_id, tag_id) SELECT 7, id FROM tags',
            'INSERT INTO activity (item_id, item_name, action, details, created_at) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < ' . (Backups::BATCH * 2 + 1) . ")"
                . " SELECT 7, 'Lamp', 'edited', '{\"n\":' || i || '}', $at FROM n",
            "DELETE FROM activity WHERE id IN (5, 6, 700)",
            'INSERT INTO activity (item_id, item_name, action, details, created_at) VALUES (NULL, \'gone\', \'deleted\', \'{}\', ' . $at . '), (9, \'Rope\', \'created\', \'{}\', ' . $at . '), (9, \'Rope\', \'edited\', \'{}\', ' . $at . ')',
        ];
        foreach ($sql as $q) {
            $this->assertNotFalse($db->query($q), $q . ': ' . $db->error()['message']);
        }
    }

    /** @return array<string,string> the stored objects: a photo, its thumbnail, a kept original and an attachment */
    private function objects(): array
    {
        $im = imagecreatetruecolor(8, 6);
        ob_start();
        imagejpeg($im);
        $jpeg = (string)ob_get_clean();
        $original = str_repeat('a', 64) . '.png';

        return ['images/' . self::PHOTO => $jpeg, 'thumbs/' . self::PHOTO => $jpeg, 'files/' . self::FILE => "%PDF-1.4\n%x\n",
            'originals/map/' . substr(self::PHOTO, 0, 32) => $original, 'originals/' . $original => 'original bytes'];
    }

    /** @param array<string,string> $objects */
    private function r2(array $objects): R2Bridge
    {
        return new R2Bridge(transport: static function (string $method, string $url) use ($objects): array {
            $path = rawurldecode(substr((string)parse_url($url, PHP_URL_PATH), 1));
            $body = array_key_exists($path, $objects) ? ['ok' => true, 'found' => true, 'bytes' => base64_encode($objects[$path]), 'etag' => 'one', 'modified' => 0]
                : ['ok' => true, 'found' => false];

            return ['status' => 200, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];
        });
    }

    private function settings(TestDatabase $d): SettingsService
    {
        return new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($d->dataDir())), new Clock(),
            $d->config->values(), static fn (string $m): null => null, new CommandLine());
    }

    private function backups(TestDatabase $d, ?R2Bridge $bridge, ?\Closure $freeSpace = null): Backups
    {
        return $bridge === null
            ? new Backups($d->db, new Clock(), $this->settings($d), $d->dataDir(), $d->config->originalsDir())
            : new Backups($d->db, new Clock(), $this->settings($d), $d->dataDir(), $d->config->originalsDir(), new R2Storage($bridge), new R2Storage($bridge->forOriginals()), $freeSpace);
    }

    private function restorer(TestDatabase $d): Restorer
    {
        $undo = new UndoTokens($d->db, new Clock(), 600, static function (string $f): void {
        }, static function (string $f): void {
        });
        $known = static fn (): array => array_keys((new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->available());

        return new Restorer($d->db, $this->backups($d, null), $this->settings($d), $undo, $known, new LocalStorage($d->dataDir()),
            new LocalStorage($d->config->originalsDir(), 0640), 256, new RequestCleanup());
    }
}
