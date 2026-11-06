<?php
declare(strict_types=1);
namespace Tests\Backup;
use Apotheke\Backup\Backups;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\TestDatabase;
final class BackupStorageTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;
    private string $zip = '';
    protected function tearDown(): void { $this->database?->drop(); @unlink($this->zip); parent::tearDown(); }
    public function testExportReadsEveryObjectThroughRemoteStorage(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $d = $this->database;
        $photo = 'abcdef0123456789abcdef0123456789.jpg';
        $attachment = 'fedcba9876543210fedcba9876543210.pdf';
        $original = str_repeat('a', 64) . '.png';
        $db = $d->db;
        $db->query("INSERT INTO locations (id, name, name_key, is_default, created_at) VALUES (1, 'Shed', 'shed', 0, '2026-01-01')");
        $db->query('INSERT INTO items (id, image, name, location_id, quantity, notes, search, created_at, updated_at) VALUES (?, ?, ?, 1, 1, ?, ?, ?, ?)', [7, $photo, 'Lamp', '', 'lamp', '2026-01-01', '2026-01-01']);
        $db->query('INSERT INTO item_photos (item_id, file, position, created_at) VALUES (7, ?, 0, ?)', [$photo, '2026-01-01']);
        $r = $db->query('INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (7, ?, ?, ?, ?, ?)', [$attachment, 'a.pdf', 'application/pdf', 11, '2026-01-01']);
        $this->assertNotFalse($r, $db->error()['message']);
        $this->assertCount(1, $db->query('SELECT file FROM item_attachments')->getResultArray());
        $objects = ['images/' . $photo => 'photo bytes', 'thumbs/' . $photo => 'thumb bytes', 'files/' . $attachment => 'file bytes', 'originals/map/' . substr($photo, 0, 32) => $original, 'originals/' . $original => 'original bytes'];
        $bridge = new R2Bridge(transport: function (string $method, string $url) use ($objects, &$reads) {
            $this->assertContains($method, ['GET', 'HEAD']);
            $path = rawurldecode(substr(parse_url($url, PHP_URL_PATH), 1));
            $this->assertArrayHasKey($path, $objects);
            $reads[] = $path;
            return ['status' => 200, 'body' => json_encode(['ok' => true, 'found' => true, 'bytes' => base64_encode($objects[$path]), 'etag' => 'one', 'modified' => 0])];
        });
        $settings = new SettingsService(new SettingsRepository($db), new SettingsSchema(), new LocalBrandStore($d->dataDir() . '/brand'), new Clock(), $d->config->values(), actor: new CommandLine());
        $this->zip = $d->dataDir() . '/backup.zip';
        $backup = new Backups($db, new Clock(), $settings, $d->dataDir().'/no-local-data', '/no-local-originals', new R2Storage($bridge), new R2Storage($bridge->forOriginals()));
        $this->assertSame([], $backup->create($this->zip)['missing']);
        sort($reads); $expected = array_keys($objects); sort($expected);
        $this->assertEqualsCanonicalizing($expected, array_values(array_unique($reads)));
        $zip = new \ZipArchive(); $this->assertTrue($zip->open($this->zip));
        $this->assertSame('photo bytes', $zip->getFromName('images/' . $photo));
        $this->assertSame('original bytes', $zip->getFromName('originals/' . $original));
        $this->assertSame('thumb bytes', $zip->getFromName('thumbs/' . $photo));
        $this->assertSame('file bytes', $zip->getFromName('files/' . $attachment));
        $this->assertSame([$photo => $original], json_decode($zip->getFromName('originals/index.json'), true));
        $zip->close();
        $this->assertSame([], glob($d->dataDir().'/tmp/backup-entry-*'));
    }
}
