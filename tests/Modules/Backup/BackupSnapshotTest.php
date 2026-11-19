<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\Backups;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The backup's dump reads every table inside one read transaction: rows another client adds while the dump runs
 * are in none of its tables, on SQLite and PostgreSQL.
 */
final class BackupSnapshotTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @var list<string> */
    private array $files = [];

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            if ($driver !== 'd1') {
                yield $driver => [$driver];
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        $this->files = [];
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /** @param \Closure(string):void|null $tableDone */
    private function backups(?\Closure $tableDone): Backups
    {
        $d = $this->database ?? throw new \RuntimeException('no database');
        $settings = new SettingsService(new SettingsRepository($d->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($d->dataDir())), new Clock(),
            $d->config->values(), static fn (string $m): null => null, new CommandLine());

        return new Backups($d->db, new Clock(), $settings, $d->dataDir(), $d->config->originalsDir(), null, null, null, $tableDone);
    }

    #[DataProvider('drivers')]
    public function testTheDumpIsOneSnapshot(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $d = $this->database;
        $d->db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Lamp', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $other = $d->connectAgain();
        $added = false;
        // After the first table (locations), another client adds a location and an item and commits.
        $tableDone = static function (string $table) use ($other, &$added): void {
            if ($added) {
                return;
            }
            $added = true;
            $other->query("INSERT INTO locations (name, name_key, created_at) VALUES ('Attic', 'attic-snapshot', '2026-01-01 00:00:00')");
            $other->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('Late', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        };
        $zip = $d->dataDir() . '/snapshot-' . bin2hex(random_bytes(4)) . '.zip';
        $this->files[] = $zip;
        $made = $this->backups($tableDone)->create($zip);
        $this->assertTrue($added);
        $this->assertSame(1, $made['tables']['items'], 'the item added during the dump is not in it');
        $this->assertSame(['Late', 'Lamp'], array_column($d->db->query('SELECT name FROM items ORDER BY name DESC')->getResultArray(), 'name'), 'it was committed');
        $json = json_decode((string)file_get_contents('zip://' . $zip . '#backup.json'), true);
        $this->assertSame(['Lamp'], array_map(static fn (array $r): string => (string)$r[array_search('name', $json['tables']['items']['columns'], true)], $json['tables']['items']['rows']));
        $this->assertNotContains('Attic', array_map(static fn (array $r): string => (string)$r[array_search('name', $json['tables']['locations']['columns'], true)], $json['tables']['locations']['rows']));

        $this->assertSame(1, (int)$d->db->query("SELECT COUNT(*) AS n FROM locations WHERE name = 'Attic'")->getRow()->n);

        // Nothing is left open: the next dump sees both, and the connection still writes.
        $again = $d->dataDir() . '/snapshot-again.zip';
        $this->files[] = $again;
        $this->assertSame(2, $this->backups(null)->create($again)['tables']['items']);
        $d->db->query("INSERT INTO items (name, quantity, created_at, updated_at) VALUES ('After', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $this->assertSame(3, (int)$other->query('SELECT COUNT(*) AS n FROM items')->getRow()->n);
        $other->close();
    }
}
