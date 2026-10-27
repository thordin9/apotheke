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

    public function testRestoreSkipsSignInSettingsUnlessTheCallerIsAdmin(): void
    {
        settings_cache(null, true);
        roles(true);
        current_user(null, true);
        $root = user_create('root', 'long-enough', 'admin');
        $backup = role_create('Archivist', ['view', 'backup']);
        $desk = role_create('Helpdesk', ['view', 'users', 'backup']);
        $archivist = user_create('archivist', 'long-enough', $backup);
        $help = user_create('desk', 'long-enough', $desk);
        auth_turn_on();
        settings_cache(null, true);
        $this->assertTrue(setting('auth_guest_disabled'));
        $perms = role_get($desk)['perms'];
        $dump = backup_dump($this->pdo, $this->driver);
        $dump['settings']['app_name'] = 'From backup';
        $dump['settings']['auth_guest_disabled'] = false;
        $dump['settings']['auth_register_disabled'] = false;
        $dump['settings']['session_idle_hours'] = 12;
        $dump['settings']['auth_enabled'] = false;
        $zip = $this->zipWith(['backup.json' => $dump]);
        try {
            current_user(user_get($archivist) + ['guest' => false, 'anon' => false]);
            backup_restore($this->pdo, $this->driver, $this->dataDir(), $zip);
            settings_cache(null, true);
            $this->assertSame('From backup', setting('app_name'));
            $this->assertTrue(setting('auth_guest_disabled'));
            $this->assertTrue(setting('auth_register_disabled'));
            $this->assertSame(168, setting('session_idle_hours'));
            $this->assertTrue(auth_enabled());
            $this->assertSame('admin', user_get($root)['role']);
            $this->assertSame($perms, role_get($desk)['perms']);
            current_user(user_get($help) + ['guest' => false, 'anon' => false]);
            backup_restore($this->pdo, $this->driver, $this->dataDir(), $zip);
            settings_cache(null, true);
            $this->assertTrue(setting('auth_guest_disabled'));
            $this->assertTrue(setting('auth_register_disabled'));
            $this->assertSame(168, setting('session_idle_hours'));
            $this->assertTrue(auth_enabled());
            $this->assertSame('admin', user_get($root)['role']);
            current_user(user_get($root) + ['guest' => false, 'anon' => false]);
            backup_restore($this->pdo, $this->driver, $this->dataDir(), $zip);
            settings_cache(null, true);
            $this->assertFalse(setting('auth_guest_disabled'));
            $this->assertFalse(setting('auth_register_disabled'));
            $this->assertSame(12, setting('session_idle_hours'));
            $this->assertTrue(auth_enabled(), 'sign-in mode is not a settings key a file can clear');
        } finally {
            current_user(null, true);
            roles(true);
            settings_cache(null, true);
        }
    }

    public function testRestoreRefusesUsersAndRolesTables(): void
    {
        settings_cache(null, true);
        roles(true);
        current_user(null, true);
        $root = user_create('root', 'long-enough', 'admin');
        $desk = role_create('Helpdesk', ['view', 'users', 'backup']);
        $help = user_create('desk', 'long-enough', $desk);
        auth_turn_on();
        settings_cache(null, true);
        $perms = role_get($desk)['perms'];
        $dump = backup_dump($this->pdo, $this->driver);
        $dump['tables']['users'] = [
            'columns' => ['id', 'username', 'username_key', 'password_hash', 'role', 'status', 'created_at'],
            'rows' => [[$root, 'root', 'root', 'x', 'viewer', 'active', '2026-01-01 00:00:00']],
        ];
        $dump['tables']['roles'] = [
            'columns' => ['role_key', 'name', 'perms', 'builtin'],
            'rows' => [[$desk, 'Helpdesk', '["view"]', 0]],
        ];
        $zip = $this->zipWith(['backup.json' => $dump]);
        try {
            foreach ([$help, $root] as $who) {
                current_user(user_get($who) + ['guest' => false, 'anon' => false]);
                try {
                    backup_restore($this->pdo, $this->driver, $this->dataDir(), $zip);
                    $this->fail('a backup naming users or roles was restored');
                } catch (UserError $e) {
                    $this->assertStringContainsString('Unknown table', $e->getMessage());
                }
                $this->assertSame('admin', user_get($root)['role']);
                $this->assertSame('desk', user_get($help)['username']);
                $this->assertSame($perms, role_get($desk)['perms']);
            }
        } finally {
            current_user(null, true);
            roles(true);
            settings_cache(null, true);
        }
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

    public function testIdsOfDeletedItemsAreNotReusedAfterRestore(): void
    {
        $src = $this->dataDir();
        $this->seedCatalog($src);
        $gone = item_save(null, ['name' => 'temp', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
        item_delete(item_get($gone));   // highest id now only lives on in the activity log
        $zip = $this->tmp('.zip');
        backup_create($this->pdo, $this->driver, $src, $zip);
        [$drv, $pdo] = $this->otherDriver();
        migrate($pdo, $drv, ['data_dir' => sys_get_temp_dir()]);
        backup_restore($pdo, $drv, $this->dataDir(), $zip);
        $next = db_insert($pdo, "INSERT INTO items (name, quantity, notes, search, sort_key, created_at, updated_at) VALUES ('n', 1, '', 'n', 'n', '2026-01-01 00:00:00', '2026-01-01 00:00:00')", []);
        $this->assertGreaterThan($gone, $next, 'a new item must not inherit the history of a deleted one');
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

    public function testBackupRestoresTheCameraFileAndTheStrippedCopy(): void
    {
        $bytes = $this->markedJpeg();
        $tmp = $this->tmp('.jpg');
        file_put_contents($tmp, $bytes);
        $name = image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => 'lamp.jpg']);
        $id = item_save(null, ['name' => 'Lamp', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $name, false);
        $orig = image_original_path($name);
        $this->assertNotNull($orig);
        $origHash = hash_file('sha256', $orig);
        $derivHash = hash_file('sha256', IMG_DIR . '/' . $name);
        $this->assertNotSame($origHash, $derivHash, 'the served copy is not the camera file');
        $this->assertSame(hash('sha256', $bytes), $origHash);
        $zip = $this->tmp('.zip');
        backup_create($this->pdo, $this->driver, DATA_DIR, $zip);
        item_delete(item_get($id));
        $this->assertFileDoesNotExist($orig);
        $this->assertFileDoesNotExist(IMG_DIR . '/' . $name);
        $this->assertFileDoesNotExist(THUMB_DIR . '/' . $name);
        $res = backup_restore($this->pdo, $this->driver, DATA_DIR, $zip);
        $this->assertGreaterThanOrEqual(1, $res['photos']);
        $back = image_original_path($name);
        $this->assertNotNull($back);
        $this->assertSame($origHash, hash_file('sha256', $back), 'restored original is the same bytes');
        $this->assertSame($derivHash, hash_file('sha256', IMG_DIR . '/' . $name), 'restored derivative is the stripped copy');
        $this->assertFileExists(THUMB_DIR . '/' . $name);
        $this->assertStringNotContainsString("\xFF\xE1", (string)file_get_contents(IMG_DIR . '/' . $name));
    }

    private function markedJpeg(): string
    {
        $im = imagecreatetruecolor(80, 40);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
        ob_start();
        imagejpeg($im, null, 90);
        $jpeg = (string)ob_get_clean();
        $payload = "Exif\x00\x00GPSLatitude Make Model SerialNumber";
        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
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

    /** One file in the zip may unpack to BACKUP_MAX_ENTRY bytes, by the zip's directory; one byte more is refused. */
    public function testAnEntryMayBeAtMostTheSizeCap(): void
    {
        $name = 'images/' . str_repeat('a', 32) . '.jpg';
        foreach ([BACKUP_MAX_ENTRY => null, BACKUP_MAX_ENTRY + 1 => 'A file in the backup is too large to restore: ' . $name] as $size => $error) {
            $zeros = $this->tmp('.zeros');
            $fh = fopen($zeros, 'w');
            ftruncate($fh, $size);   // sparse
            fclose($fh);
            $p = $this->zipWith(['backup.json' => $this->validDump()]);
            $z = new ZipArchive();
            $z->open($p);
            $z->addFile($zeros, $name);
            $z->setCompressionName($name, ZipArchive::CM_DEFLATE, 1);
            $z->close();
            unlink($zeros);
            try {
                backup_open($p, $this->pdo, $this->driver)['zip']->close();
                $this->assertNull($error, "$size bytes");
            } catch (UserError $e) {
                $this->assertSame($error, $e->getMessage(), "$size bytes");
            }
        }
    }

    /** An attachment whose bytes are not its type is skipped with a warning, and its temp file is removed. */
    public function testAnAttachmentOfTheWrongTypeLeavesNoTempFile(): void
    {
        $this->seedCatalog($this->dataDir());
        $dump = $this->validDump();
        $item = (int)$dump['tables']['items']['rows'][0][0];
        $bad = str_repeat('1', 32) . '.pdf';
        $ok = str_repeat('2', 32) . '.pdf';
        foreach ([1 => $bad, 2 => $ok] as $id => $file) {
            $dump['tables']['item_attachments']['rows'][] = array_map(static fn(string $c): mixed => ['id' => $id, 'item_id' => $item, 'file' => $file, 'name' => 'manual.pdf',
                'mime' => 'application/pdf', 'size' => 9, 'created_at' => '2026-01-02 08:00:00'][$c] ?? null, $dump['tables']['item_attachments']['columns']);
        }
        $zip = $this->zipWith(['backup.json' => $dump, "files/$bad" => "\x89PNG\r\n\x1a\nnot a pdf", "files/$ok" => "%PDF-1.4\n%"]);
        $dst = $this->dataDir();
        $r = backup_restore($this->pdo, $this->driver, $dst, $zip);
        $this->assertContains("Attachment $bad does not match its type and was skipped.", $r['warnings']);
        $this->assertSame([$ok], array_values(array_diff(scandir("$dst/files"), ['.', '..'])), 'only the matching attachment, and no temp file');
    }

    /**
     * Camera files that cannot be written are a warning, not a failure: the rest of the backup is restored. Here a
     * folder holds the camera file's name, then the link's name, then the originals folder's name.
     */
    public function testCameraFilesThatCannotBeWrittenAreAWarning(): void
    {
        $bytes = $this->markedJpeg();
        $tmp = $this->tmp('.jpg');
        file_put_contents($tmp, $bytes);
        $name = image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => strlen($bytes), 'name' => 'lamp.jpg']);
        $id = item_save(null, ['name' => 'Lamp', 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], $name, false);
        $orig = (string)image_original_path($name);
        $map = (string)image_original_map_path($name);
        $zip = $this->tmp('.zip');
        backup_create($this->pdo, $this->driver, DATA_DIR, $zip);
        $warning = 'Camera files could not be restored (originals directory is not writable).';
        $moved = ORIGINALS_DIR . '.moved-' . bin2hex(random_bytes(4));
        foreach (['file' => $orig, 'link' => $map, 'folder' => ORIGINALS_DIR] as $what => $blocked) {
            item_delete(item_get($id));
            $this->assertFileDoesNotExist(IMG_DIR . '/' . $name, $what);
            if ($what === 'folder') {
                rename(ORIGINALS_DIR, $moved);
                touch(ORIGINALS_DIR);
            } else {
                @unlink($blocked);
                mkdir($blocked);
            }
            try {
                $r = backup_restore($this->pdo, $this->driver, DATA_DIR, $zip);
            } finally {
                if ($what === 'folder') {
                    unlink(ORIGINALS_DIR);
                    rename($moved, ORIGINALS_DIR);
                } else {
                    rmdir($blocked);
                }
            }
            $this->assertContains($warning, $r['warnings'], $what);
            $this->assertSame('Lamp', item_get($id)['name'] ?? null, "$what: the rows are restored");
            $this->assertFileExists(IMG_DIR . '/' . $name, "$what: the photo is restored");
        }
    }
}
