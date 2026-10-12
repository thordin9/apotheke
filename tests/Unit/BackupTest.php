<?php
declare(strict_types=1);

final class BackupTest extends DatabaseTestCase
{
    private array $cleanup = [];
    private ?PDO $other = null;
    private ?string $otherSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    protected function tearDown(): void
    {
        if ($this->otherSchema !== null && $this->other) $this->other->exec('DROP SCHEMA IF EXISTS "' . $this->otherSchema . '" CASCADE');
        $this->other = null;
        foreach (array_reverse($this->cleanup) as $p) {
            if (is_dir($p)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
                @rmdir($p);
            } else {
                foreach (glob($p . '*') ?: [] as $f) @unlink($f);
            }
        }
        parent::tearDown();
    }

    private function tmp(string $suffix = ''): string
    {
        return $this->cleanup[] = sys_get_temp_dir() . '/apotheke-bk-' . bin2hex(random_bytes(5)) . $suffix;
    }

    private function dataDir(): string
    {
        $d = $this->tmp();
        mkdir("$d/images", 0775, true);
        mkdir("$d/thumbs", 0775, true);
        return $d;
    }

    private function jpeg(string $dir, int $shade): string
    {
        $name = bin2hex(random_bytes(16)) . '.jpg';
        $im = imagecreatetruecolor(40, 30);
        imagefill($im, 0, 0, imagecolorallocate($im, $shade, 100, 200 - $shade));
        imagejpeg($im, "$dir/images/$name");
        imagejpeg($im, "$dir/thumbs/$name");
        return $name;
    }

    /** A small catalog with every kind of row. */
    private function seedCatalog(string $dir): void
    {
        $loc = tax_create('location', 'Κουτί 5')['id'];
        tax_set_default('location', $loc, true);
        $cat = tax_create('category', 'Tools')['id'];
        $a = item_save(null, ['name' => 'Drill', 'location_id' => $loc, 'category_id' => $cat, 'quantity' => 2, 'notes' => "two\nlines",
            'tags' => ['Garage', 'Power'], 'value_cents' => 12990, 'purchase_date' => '2024-05-01', 'serial' => 'SN-1'], $this->jpeg($dir, 10), false);
        photo_add($a, $this->jpeg($dir, 90));
        item_save(null, ['name' => 'Nails "big"', 'location_id' => null, 'category_id' => null, 'quantity' => 0, 'notes' => '', 'tags' => ['garage']], null, false);
    }

    private function strip(array $dump): array
    {
        unset($dump['created_at'], $dump['driver']);
        return $dump;
    }

    private function otherDriver(): array
    {
        if ($this->driver === 'pgsql') {
            $f = $this->tmp('.sqlite');
            return ['sqlite', db_connect(['driver' => 'sqlite', 'sqlite_path' => $f] + cfg()['db'])];
        }
        $this->otherSchema = 'b_' . bin2hex(random_bytes(6));
        $db = ['driver' => 'pgsql', 'dsn' => '', 'host' => getenv('TEST_PG_HOST') ?: '127.0.0.1', 'port' => (int)(getenv('TEST_PG_PORT') ?: 5432),
               'name' => getenv('TEST_PG_DB') ?: 'apotheke', 'user' => getenv('TEST_PG_USER') ?: 'apotheke',
               'password' => getenv('TEST_PG_PASSWORD') ?: 'apotheke', 'schema' => $this->otherSchema] + cfg()['db'];
        try {
            $this->other = db_connect($db);
        } catch (PDOException $e) {
            $this->otherSchema = null;
            $this->markTestSkipped('PostgreSQL not reachable for the cross-database test: ' . $e->getMessage());
        }
        return ['pgsql', $this->other];
    }

    public function testRoundTripRestoresEverythingExactly(): void
    {
        $src = $this->dataDir();
        $this->seedCatalog($src);
        $before = backup_dump($this->pdo, $this->driver);
        $zip = $this->tmp('.zip');
        $r = backup_create($this->pdo, $this->driver, $src, $zip);
        $this->assertSame(2, $r['photos']);
        $this->assertSame(2, $r['tables']['items']);
        $this->assertSame([], $r['missing']);

        // wreck the catalog, then restore into another data dir
        item_delete(item_get((int)$before['tables']['items']['rows'][0][0]));
        tax_create('location', 'Extra');
        $dst = $this->dataDir();
        $res = backup_restore($this->pdo, $this->driver, $dst, $zip);
        $this->assertSame(2, $res['photos']);
        $this->assertSame($this->strip($before), $this->strip(backup_dump($this->pdo, $this->driver)));
        foreach (backup_photo_files($before) as $f) {
            $this->assertFileEquals("$src/images/$f", "$dst/images/$f");
            $this->assertFileExists("$dst/thumbs/$f");
        }
        // ids keep counting after the restored maximum
        $new = item_save(null, ['name' => 'after', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
        $this->assertGreaterThan(max(array_column($before['tables']['items']['rows'], 0)), $new);
        $this->assertSame(['Garage', 'Power'], array_column(item_get((int)$before['tables']['items']['rows'][0][0])['tags'], 'name'));
    }

    public function testRestoresAcrossDatabaseDrivers(): void
    {
        $src = $this->dataDir();
        $this->seedCatalog($src);
        $zip = $this->tmp('.zip');
        backup_create($this->pdo, $this->driver, $src, $zip);
        $before = backup_dump($this->pdo, $this->driver);

        [$drv, $pdo] = $this->otherDriver();
        migrate($pdo, $drv, ['data_dir' => sys_get_temp_dir()]);
        $dst = $this->dataDir();
        backup_restore($pdo, $drv, $dst, $zip);
        $this->assertSame($this->strip($before), $this->strip(backup_dump($pdo, $drv)), "$this->driver → $drv");
        // and back again
        $zip2 = $this->tmp('.zip');
        backup_create($pdo, $drv, $dst, $zip2);
        $this->pdo->exec('DELETE FROM item_tags');
        backup_restore($this->pdo, $this->driver, $this->dataDir(), $zip2);
        $this->assertSame($this->strip($before), $this->strip(backup_dump($this->pdo, $this->driver)), "$drv → $this->driver");
    }

    private function zipWith(array $files): string
    {
        $p = $this->tmp('.zip');
        $z = new ZipArchive();
        $z->open($p, ZipArchive::CREATE);
        foreach ($files as $n => $c) $z->addFromString($n, is_array($c) ? json_encode($c) : $c);
        $z->close();
        return $p;
    }

    private function validDump(): array
    {
        return backup_dump($this->pdo, $this->driver);
    }

    private function withExtraRow(string $table, array $row): array
    {
        $d = $this->validDump();
        $d['tables'][$table]['rows'][] = $row;
        return $d;
    }

    public static function invalidBackups(): array
    {
        return [
            'not a zip'      => [fn($t) => file_put_contents($p = sys_get_temp_dir() . '/apotheke-bk-nz-' . getmypid(), 'hello') ? $p : '', 'not a zip'],
            'no backup.json' => [fn($t) => $t->zipWith(['x.txt' => 'x']), 'Unexpected file'],
            'no json'        => [fn($t) => $t->zipWith(['images/' . str_repeat('a', 32) . '.jpg' => 'x']), 'backup.json is missing'],
            'bad json'       => [fn($t) => $t->zipWith(['backup.json' => '{']), 'not valid JSON'],
            'wrong format'   => [fn($t) => $t->zipWith(['backup.json' => ['format' => 'other']]), 'not an Apotheke backup'],
            'newer version'  => [fn($t) => $t->zipWith(['backup.json' => ['version' => 99] + $t->validDump()]), 'newer version'],
            'newer schema'   => [fn($t) => $t->zipWith(['backup.json' => ['schema' => ['0001', '9999']] + $t->validDump()]), 'needs a newer version'],
            'path traversal' => [fn($t) => $t->zipWith(['backup.json' => $t->validDump(), '../evil.php' => '<?php']), 'Unexpected file'],
            'unknown table'  => [fn($t) => $t->zipWith(['backup.json' => array_replace_recursive($t->validDump(), ['tables' => ['users' => ['columns' => ['id'], 'rows' => []]]])]), 'Unknown table'],
            'unknown column' => [fn($t) => $t->zipWith(['backup.json' => array_replace_recursive($t->validDump(), ['tables' => ['tags' => ['columns' => ['id', 'evil'], 'rows' => []]]])]), 'Unknown column'],
            'short row'      => [fn($t) => $t->zipWith(['backup.json' => $t->withExtraRow('tags', [1])]), 'wrong number'],
            'nested value'   => [fn($t) => $t->zipWith(['backup.json' => $t->withExtraRow('app_settings', [['x'], 'y'])]), 'not a plain value'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBackups')]
    public function testInvalidBackupsAreRejectedWithoutChanges(callable $make, string $msg): void
    {
        $this->seedCatalog($this->dataDir());
        $before = backup_dump($this->pdo, $this->driver);
        $p = $make($this);
        $this->cleanup[] = $p;
        try {
            backup_restore($this->pdo, $this->driver, $this->dataDir(), $p);
            $this->fail('expected rejection');
        } catch (UserError $e) {
            $this->assertStringContainsString($msg, $e->getMessage());
        }
        $this->assertSame($this->strip($before), $this->strip(backup_dump($this->pdo, $this->driver)));
    }

    public function testFailingInsertRollsBackEverything(): void
    {
        $this->seedCatalog($this->dataDir());
        $before = backup_dump($this->pdo, $this->driver);
        $bad = $before;
        $i = array_search('location_id', $bad['tables']['items']['columns'], true);
        $bad['tables']['items']['rows'][0][$i] = 999999;   // dangling foreign key
        try {
            backup_restore($this->pdo, $this->driver, $this->dataDir(), $this->zipWith(['backup.json' => $bad]));
            $this->fail('expected failure');
        } catch (UserError $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }
        $this->assertSame($this->strip($before), $this->strip(backup_dump($this->pdo, $this->driver)));
    }

    public function testMissingThumbnailIsRegeneratedAndNonJpegSkipped(): void
    {
        $src = $this->dataDir();
        $this->seedCatalog($src);
        $dump = $this->validDump();
        [$f1, $f2] = backup_photo_files($dump);
        $zip = $this->zipWith(['backup.json' => $dump, "images/$f1" => file_get_contents("$src/images/$f1"), "images/$f2" => 'not a jpeg']);
        $dst = $this->dataDir();
        $r = backup_restore($this->pdo, $this->driver, $dst, $zip);
        $this->assertSame(1, $r['photos']);
        $this->assertFileExists("$dst/thumbs/$f1");
        $this->assertFileDoesNotExist("$dst/images/$f2");
        $this->assertStringContainsString('not a JPEG', implode(' ', $r['warnings']));
    }
}
