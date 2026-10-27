<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * The CI4 restore's own checks, on the configured driver (streaming
 * restore follow-up):
 *
 * - N-443 (stricter than legacy): the backup's settings are restored only
 *   for a user who may change settings, and by the command line.
 * - N-444, N-454: a photo that is not a JPEG is skipped, a missing
 *   thumbnail is drawn again only within the decode limits, and a confirm
 *   token must be exactly 32 hex digits.
 * - N-453: a failed restore removes the folders it had to create.
 * - N-446: bin/backup.php into a missing folder says "Cannot write", and
 *   the zip is written 0600.
 * - N-445 (PostgreSQL): a restore that loses a deadlock to another write
 *   is tried again, and then restores.
 * - L-480, N-442: an entry over MAX_ENTRY is refused from the zip's
 *   directory, before any of it is read or any file written.
 * - L-480: an entry larger than the size the zip gives it is refused, and
 *   at most a chunk past that size is unpacked (a 300 MB entry that says
 *   100 bytes, under `ulimit -f`).
 * - N-481: a link where a new file would go stops the restore; the link
 *   is neither replaced nor removed, and nothing changes.
 */
final class Ci4BackupRestoreChecksTest extends TestCase
{
    private const CLOCK = '2026-06-15 21:30:00';

    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    protected function setUp(): void
    {
        if (!is_file(ROOT . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    public function testTheSettingsAreRestoredOnlyForWhoMayChangeThem(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('keeper', 'Keeper', '[\"view\",\"backup\"]', 0, '2026-01-01 00:00:00'), ('steward', 'Steward', '[\"view\",\"backup\",\"settings\"]', 0, '2026-01-01 00:00:00')");
            $users = [];
            foreach (['ada' => 'admin', 'kim' => 'keeper', 'sam' => 'steward'] as $name => $role) {
                $users[$name] = $this->user($pdo, $name, $role);
            }
            foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'app_name' => 'Backed up'] as $k => $v) {
                $this->setting($pdo, $k, $v);
            }
            [$ada, $adaCsrf] = $this->session($server, $dir, $users['ada']);
            $zip = $ada->get('/backup.zip');
            $this->assertSame(200, $zip['status']);
            file_put_contents($dir . '/home.zip', $zip['body']);

            foreach (['kim' => 'Current', 'sam' => 'Backed up', 'ada' => 'Backed up'] as $who => $expected) {
                $this->setting($pdo, 'app_name', 'Current');
                $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
                [$client, $csrf] = $this->session($server, $dir, $users[$who]);
                $up = $client->post('/backup/restore', ['csrf' => $csrf], ['backup' => ['name' => 'backup.zip', 'type' => 'application/zip', 'data' => $zip['body']]]);
                $this->assertSame(200, $up['status'], $who . ': ' . substr((string)$up['body'], 0, 500));
                $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => self::token((string)$up['body']), 'confirm' => '1']);
                $this->assertSame([303, '/backup?restored=1'], [$r['status'], $r['headers']['location'] ?? null], $who);
                $this->assertSame('Drill', $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn(), $who . ': the catalog is restored');
                $this->assertSame($expected, $this->settingValue($pdo, 'app_name'), $who . ': app_name');
            }
            $this->setting($pdo, 'app_name', 'Current');
            $cli = $this->cli($dir, 'restore', ['home.zip', '--yes']);
            $this->assertSame([0, ''], [$cli['exit'], $cli['stderr']], $cli['stdout']);
            $this->assertSame('Backed up', $this->settingValue($pdo, 'app_name'), 'the command line restores the settings');
        });
    }

    public function testRestoredPhotosAreCheckedAndThumbnailsDrawnWithinTheLimits(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $drawn = str_repeat('1', 32) . '.jpg';
            $notJpeg = str_repeat('2', 32) . '.jpg';
            $wide = str_repeat('3', 32) . '.jpg';
            $dump = $this->dump($server, $dir);
            $photos = &$dump['tables']['item_photos'];
            foreach ([$drawn, $notJpeg, $wide] as $i => $f) {
                $photos['rows'][] = array_map(static fn (string $c, mixed $v): mixed => ['id' => 10 + $i, 'file' => $f, 'position' => 1 + $i][$c] ?? $v, $photos['columns'], $photos['rows'][0]);
            }
            unset($photos);
            $im = imagecreatetruecolor(30001, 1);   // over Media's side limit, cheap to draw
            ob_start();
            imagejpeg($im);
            $wideJpeg = (string)ob_get_clean();
            $this->zip($dir . '/photos.zip', ['backup.json' => $dump, 'images/' . $drawn => img_jpeg(2000, 1500), 'images/' . $notJpeg => img_png(40, 30), 'images/' . $wide => $wideJpeg]);
            $cli = $this->cli($dir, 'restore', ['photos.zip', '--yes']);
            $this->assertSame([0, ''], [$cli['exit'], $cli['stderr']], $cli['stdout']);

            $thumb = @getimagesize($dir . '/thumbs/' . $drawn);
            $this->assertIsArray($thumb, 'N-454: a missing thumbnail is drawn from the photo');
            $this->assertSame(IMAGETYPE_JPEG, $thumb[2]);
            $this->assertLessThan(2000, $thumb[0]);
            $this->assertFileExists($dir . '/images/' . $drawn);
            $this->assertFileDoesNotExist($dir . '/images/' . $notJpeg, 'N-444: a photo that is not a JPEG is skipped');
            $this->assertFileDoesNotExist($dir . '/thumbs/' . $notJpeg);
            $this->assertFileExists($dir . '/images/' . $wide);
            $this->assertFileDoesNotExist($dir . '/thumbs/' . $wide, 'N-444: no thumbnail is drawn past the decode limits');
            $this->assertSame([], glob($dir . '/tmp/restore-*.part') ?: [], 'no temp entry is left');
        });
    }

    public function testAConfirmTokenMustBeExactlyAToken(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $zip = $server->client()->get('/backup.zip')['body'];
            $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
            [$client, $csrf] = $this->session($server, $dir, null);
            $token = self::token((string)$client->post('/backup/restore', ['csrf' => $csrf], ['backup' => ['name' => 'b.zip', 'type' => 'application/zip', 'data' => $zip]])['body']);
            $this->assertNotSame('', $token);
            $hex = str_repeat('c', 32);
            // Each of these names a waiting upload, through a folder or a name the test makes; only the regex refuses them.
            mkdir($dir . '/tmp/restore-x');
            mkdir($dir . '/tmp/restore-' . $hex);
            copy($dir . '/tmp/restore-' . $token . '.zip', $dir . '/tmp/' . $hex . '.zip');
            copy($dir . '/tmp/restore-' . $token . '.zip', $dir . '/tmp/restore-' . $token . "\n.zip");
            foreach (['x/../' . $hex, $hex . '/../restore-' . $token, $token . "\n", strtoupper($token)] as $bad) {
                $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $bad, 'confirm' => '1']);
                $this->assertSame(410, $r['status'], json_encode($bad, JSON_THROW_ON_ERROR));
                $this->assertSame('Changed', $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn());
            }
            $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
            $this->assertSame([303, 'Drill'], [$r['status'], $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn()]);
        });
    }

    public function testAFailedRestoreRemovesTheFoldersItMade(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $dump = $this->dump($server, $dir);
            $file = str_repeat('9', 32) . '.pdf';
            $original = str_repeat('b', 63) . '2.png';
            $files = &$dump['tables']['item_attachments'];
            $files['rows'][] = array_map(static fn (string $c): mixed => ['id' => 1, 'item_id' => 1, 'file' => $file, 'name' => 'm.pdf', 'mime' => 'application/pdf', 'size' => 9, 'created_at' => '2026-01-02 08:00:00'][$c] ?? null, $files['columns']);
            unset($files);
            $items = &$dump['tables']['items'];
            $items['rows'][0][array_search('location_id', $items['columns'], true)] = 999;   // breaks a key: fails at the rows
            unset($items);
            $this->zip($dir . '/bad.zip', ['backup.json' => $dump, 'files/' . $file => "%PDF-1.4\n%", 'originals/index.json' => [self::PHOTO => $original], 'originals/' . $original => img_png(8, 8)]);
            rrmdir($dir . '/files');   // the one folder the app does not make at start
            $cli = $this->cli($dir, 'restore', ['bad.zip', '--yes']);
            $this->assertSame([1, "The backup could not be restored. Nothing was changed.\n"], [$cli['exit'], $cli['stderr']], $cli['stdout']);
            $this->assertDirectoryDoesNotExist($dir . '/files', 'N-453: the empty files/ it made is gone');
            $this->assertSame([], array_values(array_diff(scandir($dir . '/originals') ?: [], ['.', '..', '.htaccess', 'map'])), 'the original it wrote is gone');
            $this->assertDirectoryExists($dir . '/images', 'a folder that was there stays');
            $this->assertSame([], glob($dir . '/tmp/restore-*.part') ?: []);
        });
    }

    public function testABackupIntoAMissingFolderSaysSoAndIsPrivate(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $r = $this->cli($dir, 'backup', ['missing/folder/home.zip']);
            $this->assertSame([1, '', "Cannot write $dir/missing/folder/home.zip\n"], [$r['exit'], $r['stdout'], $r['stderr']], 'N-446');
            $this->assertDirectoryDoesNotExist($dir . '/missing');
            $r = $this->cli($dir, 'backup', ['home.zip']);
            $this->assertSame([0, ''], [$r['exit'], $r['stderr']], $r['stdout']);
            clearstatcache();
            $this->assertSame(0600, fileperms($dir . '/home.zip') & 0777, 'N-446: the zip is for its owner only');
            $this->assertSame([], glob($dir . '/tmp/backup-*') ?: [], 'no temp backup.json is left');
        });
    }

    public function testARestoreThatLosesADeadlockIsTriedAgain(): void
    {
        if (test_driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL: SQLite has one writer, no deadlock');
        }
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            file_put_contents($dir . '/home.zip', $server->client()->get('/backup.zip')['body']);
            // The other client: holds items, then (once the restore waits for items) wants locations.
            $other = proc_open([PHP_BINARY, ROOT . '/ci4/tests/_support/hold-transaction.php',
                (string)json_encode([["UPDATE items SET name = 'Other' WHERE id = 1", []]]), '300',
                (string)json_encode([["INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Shed', 'shed', 'shed', '2026-01-01')", []]])],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, array_merge(getenv(), test_server_env($dir)));
            $this->assertIsResource($other);
            $this->assertSame("held\n", (string)fgets($pipes[1]));
            $restore = $this->cliStart($dir, ['home.zip', '--yes']);
            $waiting = false;
            for ($i = 0; $i < 300 && !$waiting; $i++) {
                usleep(20_000);
                $waiting = (int)$pdo->query("SELECT COUNT(*) FROM pg_locks WHERE NOT granted AND relation = 'items'::regclass")->fetchColumn() > 0;
            }
            $this->assertTrue($waiting, 'the restore waits for items, holding locations');
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
            $out = $this->cliFinish($restore);
            $otherOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($other);
            $this->assertSame("second\ncommitted\n", $otherOut, 'the other write won the deadlock');
            $this->assertSame([0, ''], [$out['exit'], $out['stderr']], $out['stdout']);
            $this->assertSame(['Drill'], $pdo->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'the catalog is the backup');
            $this->assertSame(['Garage'], $pdo->query('SELECT name FROM locations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        });
    }

    public function testAnEntryOverTheCapIsRefusedBeforeItIsRead(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $dump = $this->dump($server, $dir);
            $big = str_repeat('4', 32) . '.pdf';
            $files = &$dump['tables']['item_attachments'];
            $files['rows'][] = array_map(static fn (string $c): mixed => ['id' => 1, 'item_id' => 1, 'file' => $big, 'name' => 'big.pdf', 'mime' => 'application/pdf', 'size' => 9, 'created_at' => '2026-01-02 08:00:00'][$c] ?? null, $files['columns']);
            unset($files);
            $photo = str_repeat('5', 32) . '.jpg';
            $photos = &$dump['tables']['item_photos'];
            $photos['rows'][] = array_map(static fn (string $c, mixed $v): mixed => ['id' => 9, 'file' => $photo, 'position' => 1][$c] ?? $v, $photos['columns'], $photos['rows'][0]);
            unset($photos);
            // 300 MB of zeros, with honest sizes (deflated to a few hundred kB), after a photo that would be written first.
            $zeros = $dir . '/zeros.bin';
            $fh = fopen($zeros, 'wb');
            ftruncate($fh, 300 * 1024 * 1024);
            fclose($fh);
            $z = new ZipArchive();
            $this->assertTrue($z->open($dir . '/big.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $z->addFromString('backup.json', (string)json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $z->addFromString('images/' . $photo, img_jpeg(40, 30));
            $z->addFile($zeros, 'files/' . $big);
            $z->close();
            unlink($zeros);
            $before = $pdo->query('SELECT COUNT(*) FROM item_attachments')->fetchColumn();
            $t = microtime(true);
            $r = $this->cli($dir, 'restore', ['big.zip', '--yes']);
            $this->assertSame([1, "Invalid backup: A file in the backup is too large to restore: files/$big\n"], [$r['exit'], $r['stderr']], $r['stdout']);
            $this->assertLessThan(10.0, microtime(true) - $t, 'refused without unpacking it');
            $this->assertFileDoesNotExist($dir . '/images/' . $photo);
            $this->assertFileDoesNotExist($dir . '/files/' . $big);
            $this->assertSame($before, $pdo->query('SELECT COUNT(*) FROM item_attachments')->fetchColumn());
            $this->assertSame([], glob($dir . '/tmp/restore-*.part') ?: []);
        });
    }

    public function testAnEntryLargerThanItsSizeIsRefusedWithoutUnpackingIt(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $dump = $this->dump($server, $dir);
            $files = &$dump['tables']['item_attachments'];
            foreach (['7', '8'] as $id => $c) {
                $files['rows'][] = array_map(static fn (string $col): mixed => ['id' => $id + 1, 'item_id' => 1, 'file' => str_repeat($c, 32) . '.pdf', 'name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 9, 'created_at' => '2026-01-02 08:00:00'][$col] ?? null, $files['columns']);
            }
            unset($files);
            $json = (string)json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $small = str_repeat('7', 32) . '.pdf';
            $big = str_repeat('8', 32) . '.pdf';
            // A small lie (one read): the CRC is right, only the size is not.
            $this->zip($dir . '/small.zip', ['backup.json' => $dump, 'files/' . $small => str_repeat("%PDF-1.4 x\n", 80)]);
            self::sayEntrySize($dir . '/small.zip', 'files/' . $small, 100);
            // A large one: 300 MB of zeros that say 100 bytes, restored with at most 20 MB of file writes.
            $zeros = $dir . '/zeros.bin';
            $fh = fopen($zeros, 'wb');
            ftruncate($fh, 300 * 1024 * 1024);
            fclose($fh);
            $z = new ZipArchive();
            $this->assertTrue($z->open($dir . '/big.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $z->addFromString('backup.json', $json);
            $z->addFile($zeros, 'files/' . $big);
            $z->close();
            unlink($zeros);
            self::sayEntrySize($dir . '/big.zip', 'files/' . $big, 100);
            $before = $pdo->query('SELECT COUNT(*) FROM item_attachments')->fetchColumn();
            foreach (['small.zip' => null, 'big.zip' => 20480] as $zip => $limitKb) {
                $r = $this->cli($dir, 'restore', [$zip, '--yes'], $limitKb);
                $this->assertSame([1, "The backup could not be restored. Nothing was changed.\n"], [$r['exit'], $r['stderr']], $zip . ' ' . $r['stdout']);
            }
            $this->assertFileDoesNotExist($dir . '/files/' . $small);
            $this->assertFileDoesNotExist($dir . '/files/' . $big);
            $this->assertSame($before, $pdo->query('SELECT COUNT(*) FROM item_attachments')->fetchColumn());
            $this->assertSame([], glob($dir . '/tmp/restore-*.part') ?: []);
        });
    }

    /** Rewrite the unpacked size entry $name gives in both its headers (no data descriptor, no ZIP64). */
    private static function sayEntrySize(string $zipPath, string $name, int $size): void
    {
        $b = (string)file_get_contents($zipPath);
        foreach (["PK\x03\x04" => [22, 26, 30], "PK\x01\x02" => [24, 28, 46]] as $sig => [$at, $nameLen, $nameAt]) {
            for ($p = strpos($b, $sig); $p !== false; $p = strpos($b, $sig, $p + 4)) {
                $n = unpack('v', substr($b, $p + $nameLen, 2))[1];
                if (substr($b, $p + $nameAt, $n) === $name) {
                    $b = substr_replace($b, pack('V', $size), $p + $at, 4);
                    break;
                }
            }
        }
        file_put_contents($zipPath, $b);
        $z = new ZipArchive();
        $z->open($zipPath, ZipArchive::RDONLY);
        $said = $z->statName($name)['size'] ?? null;
        $z->close();
        if ($said !== $size) {
            throw new \RuntimeException("could not rewrite the size of $name");
        }
    }

    public function testALinkWhereANewFileGoesStopsTheRestore(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $dump = $this->dump($server, $dir);
            $photo = str_repeat('6', 32) . '.jpg';
            $photos = &$dump['tables']['item_photos'];
            $photos['rows'][] = array_map(static fn (string $c, mixed $v): mixed => ['id' => 9, 'file' => $photo, 'position' => 1][$c] ?? $v, $photos['columns'], $photos['rows'][0]);
            unset($photos);
            $this->zip($dir . '/link.zip', ['backup.json' => $dump, 'images/' . $photo => img_jpeg(40, 30), 'thumbs/' . $photo => img_jpeg(20, 15)]);
            $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
            $target = $dir . '/elsewhere.jpg';
            symlink($target, $dir . '/images/' . $photo);   // dangling
            $r = $this->cli($dir, 'restore', ['link.zip', '--yes']);
            $this->assertSame([1, "The backup could not be restored. Nothing was changed.\n"], [$r['exit'], $r['stderr']], $r['stdout']);
            $this->assertTrue(is_link($dir . '/images/' . $photo), 'N-481: the link is not replaced or removed');
            $this->assertSame($target, readlink($dir . '/images/' . $photo));
            $this->assertFileDoesNotExist($target, 'nothing is written through it');
            $this->assertFileDoesNotExist($dir . '/thumbs/' . $photo);
            $this->assertSame('Changed', $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn());
        });
    }

    /** @param callable(Server, string, PDO): void $fn */
    private function withCatalog(callable $fn): void
    {
        $dir = tmpdir('backup-checks');
        $server = new Server($dir, ['APOTHEKE_CLOCK' => self::CLOCK], 'ci4');
        try {
            $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
            $pdo = testdb($dir);
            $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage', 'garage', 'garage', '2026-01-01 00:00:00')");
            $pdo->exec("INSERT INTO items (name, location_id, quantity, notes, image, search, sort_key, created_at, updated_at) VALUES ('Drill', 1, 1, '', '" . self::PHOTO . "', 'drill', 'drill', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
            $pdo->exec("INSERT INTO item_photos (item_id, file, position, created_at) VALUES (1, '" . self::PHOTO . "', 0, '2026-01-02 08:00:00')");
            foreach (['images', 'thumbs', 'files', 'originals/map'] as $sub) {
                @mkdir($dir . '/' . $sub, 0775, true);
            }
            file_put_contents($dir . '/images/' . self::PHOTO, img_jpeg(64, 48));
            file_put_contents($dir . '/thumbs/' . self::PHOTO, img_jpeg(32, 24));
            $fn($server, $dir, $pdo);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /** @return array<string,mixed> the download's backup.json */
    private function dump(Server $server, string $dir): array
    {
        $path = $dir . '/download.zip';
        file_put_contents($path, $server->client()->get('/backup.zip')['body']);
        $z = new ZipArchive();
        $this->assertTrue($z->open($path, ZipArchive::RDONLY));
        $json = (string)$z->getFromName('backup.json');
        $z->close();
        unlink($path);

        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,string|array<mixed>> $entries */
    private function zip(string $path, array $entries): void
    {
        $z = new ZipArchive();
        $this->assertTrue($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $data) {
            $z->addFromString($name, is_array($data) ? (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $data);
        }
        $z->close();
    }

    private function user(PDO $pdo, string $name, string $role): array
    {
        $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        $st = $pdo->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $st->execute([$name]);

        return $st->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * A client with a session holding a CSRF token, signed in as $user (null: sign-in is off).
     *
     * @param array{id:int|string,password_hash:string}|null $user
     * @return array{0:Client,1:string}
     */
    private function session(Server $server, string $dir, ?array $user): array
    {
        $client = $server->client();
        $token = bin2hex(random_bytes(32));
        $sid = bin2hex(random_bytes(13));
        @mkdir($dir . '/sessions', 0700, true);
        $auth = $user === null ? '' : 'uid|i:' . $user['id'] . ';auth_epoch|s:16:"' . substr(hash('sha256', $user['password_hash'] . '|' . $user['id']), 0, 16) . '";signed_in_at|i:' . time() . ';';
        file_put_contents($dir . '/sessions/sess_' . $sid, $auth . 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';');
        $client->cookies['apothekesid'] = $sid;

        return [$client, $token];
    }

    private function setting(PDO $pdo, string $name, mixed $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value), '2026-01-01']);
    }

    private function settingValue(PDO $pdo, string $name): mixed
    {
        $st = $pdo->prepare('SELECT value FROM settings WHERE name = ?');
        $st->execute([$name]);

        return json_decode((string)$st->fetchColumn(), true);
    }

    private static function token(string $html): string
    {
        return preg_match('/name="token" value="([a-f0-9]{32})"/', $html, $m) === 1 ? $m[1] : '';
    }

    /** @return array<string,string> */
    private function cliEnv(string $dir): array
    {
        $env = array_merge(getenv(), test_server_env($dir), ['APOTHEKE_CLOCK' => self::CLOCK, 'APOTHEKE_APP' => 'ci4', 'CI_ENVIRONMENT' => 'production']);
        unset($env['APP_PASSWORD']);

        return array_map('strval', $env);
    }

    /**
     * bin/<form>.php, started in $dir.
     *
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cli(string $dir, string $form, array $args, ?int $fileLimitKb = null): array
    {
        $cmd = [PHP_BINARY, ROOT . '/bin/' . $form . '.php', ...$args];
        if ($fileLimitKb !== null) {   // a larger write kills the process (SIGXFSZ)
            $cmd = ['/bin/bash', '-c', 'ulimit -f ' . $fileLimitKb . ' && exec "$@"', 'bash', ...$cmd];
        }
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $this->cliEnv($dir));
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        return $this->cliFinish([$proc, $pipes[1], $pipes[2]]);
    }

    /**
     * bin/restore.php started and left running.
     *
     * @param list<string> $args
     * @return array{0:resource,1:resource,2:resource}
     */
    private function cliStart(string $dir, array $args): array
    {
        $proc = proc_open([PHP_BINARY, ROOT . '/bin/restore.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $this->cliEnv($dir));
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        return [$proc, $pipes[1], $pipes[2]];
    }

    /**
     * @param array{0:resource,1:resource,2:resource} $run
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cliFinish(array $run): array
    {
        [$proc, $out, $err] = $run;
        $stdout = (string)stream_get_contents($out);
        $stderr = (string)stream_get_contents($err);
        fclose($out);
        fclose($err);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($proc)];
    }
}
