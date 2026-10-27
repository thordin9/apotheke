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
 *   is tried again, and then restores; one that loses three times stops.
 * - L-480, N-442: an entry over MAX_ENTRY is refused from the zip's
 *   directory, before any of it is read or any file written.
 * - L-480: an entry larger than the size the zip gives it is refused, and
 *   at most a chunk past that size is unpacked (a 300 MB entry that says
 *   100 bytes, under `ulimit -f`). N-452: a restore that runs out of disk
 *   leaves no temp file and no file.
 * - N-481: a link where a new file would go stops the restore; the link
 *   is neither replaced nor removed, and nothing changes.
 * - A restore into a fresh data directory leaves every folder with the
 *   mode legacy gives it, under two umasks (files/ was 0700).
 * - The camera link map is written through a temp file: a link at a map
 *   name is left as it is and nothing is written through it, and the
 *   other map entries are still written.
 * - L-560: a link at a camera file's name, dangling or to a real file,
 *   stops the restore; the target keeps its mode (0604) and bytes. On
 *   PostgreSQL, one swapped in before the commit is not made 0640.
 * - Short writes: an entry that does not fit under a file size limit
 *   (`prlimit --fsize`, XFSZ ignored) fails the restore and leaves no temp
 *   file and no file.
 */
final class Ci4BackupRestoreChecksTest extends TestCase
{
    private const CLOCK = '2026-06-15 21:30:00';

    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    private const FILE = '99998888777766665555444433332222.pdf';

    private const ORIGINAL = 'bb00000000000000000000000000000000000000000000000000000000000002.png';

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
            $pngThumb = str_repeat('9', 32) . '.jpg';
            $dump = $this->dump($server, $dir);
            $photos = &$dump['tables']['item_photos'];
            foreach ([$drawn, $notJpeg, $wide, $pngThumb] as $i => $f) {
                $photos['rows'][] = array_map(static fn (string $c, mixed $v): mixed => ['id' => 10 + $i, 'file' => $f, 'position' => 1 + $i][$c] ?? $v, $photos['columns'], $photos['rows'][0]);
            }
            unset($photos);
            $im = imagecreatetruecolor(30001, 1);   // over Media's side limit, cheap to draw
            ob_start();
            imagejpeg($im);
            $wideJpeg = (string)ob_get_clean();
            $this->zip($dir . '/photos.zip', ['backup.json' => $dump, 'images/' . $drawn => img_jpeg(2000, 1500), 'images/' . $notJpeg => img_png(40, 30), 'images/' . $wide => $wideJpeg,
                'images/' . $pngThumb => img_jpeg(400, 300), 'thumbs/' . $pngThumb => img_png(40, 30)]);
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
            $this->assertSame(IMAGETYPE_JPEG, (@getimagesize($dir . '/thumbs/' . $pngThumb) ?: [2 => null])[2], 'a thumbnail that is not a JPEG is drawn again');
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

    public function testARestoreThatKeepsLosingDeadlocksStopsAfterThreeAttempts(): void
    {
        if (test_driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL: SQLite has one writer, no deadlock');
        }
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            file_put_contents($dir . '/home.zip', $server->client()->get('/backup.zip')['body']);
            // The other client never lets go: each attempt waits for the last table it holds,
            // then it writes a table the attempt already locked (the lock order is
            // locations, categories, tags, items), and the attempt loses the deadlock.
            $other = testdb($dir);
            $other->beginTransaction();
            $other->exec("UPDATE items SET name = 'Other' WHERE id = 1");
            $restore = $this->cliStart($dir, ['home.zip', '--yes']);
            $waitsFor = function (string $table) use ($pdo, $restore): bool {
                for ($i = 0; $i < 500; $i++) {
                    if ((int)$pdo->query("SELECT COUNT(*) FROM pg_locks WHERE NOT granted AND relation = '$table'::regclass")->fetchColumn() > 0) {
                        return true;
                    }
                    if (!proc_get_status($restore[0])['running']) {
                        return false;
                    }
                    usleep(20_000);
                }

                return false;
            };
            $writes = [
                'items' => "INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('Red', 'red', 'red', '2026-01-01')",
                'tags' => "INSERT INTO categories (name, name_key, created_at) VALUES ('Tools', 'tools', '2026-01-01')",
                'categories' => "INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Shed', 'shed', 'shed', '2026-01-01')",
            ];
            try {
                foreach ($writes as $held => $sql) {
                    $this->assertTrue($waitsFor($held), 'the restore waits for ' . $held);
                    $other->exec($sql);   // returns once the restore has lost the deadlock
                }
                $this->assertFalse($waitsFor('locations'), 'no fourth attempt');
            } finally {
                $other->rollBack();
            }
            $out = $this->cliFinish($restore);
            $this->assertSame(1, $out['exit'], $out['stdout'] . $out['stderr']);
            $this->assertStringContainsString('Another change was being saved at the same time', $out['stderr']);
            $this->assertSame(['Drill'], $pdo->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'nothing changed');
            $this->assertSame(['Garage'], $pdo->query('SELECT name FROM locations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        });
    }

    /**
     * N-452: an upload moved onto a full disk (DATA_DIR/tmp on another, 1 MB
     * file system, so the move is a copy that stops part way) leaves nothing.
     */
    public function testAnUploadThatFillsTheDiskLeavesNothing(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !is_executable('/usr/bin/sudo')) {
            $this->markTestSkipped('needs Linux and sudo to mount a small tmpfs');
        }
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            @mkdir($dir . '/tmp', 0770, true);
            exec('sudo -n mount -t tmpfs -o size=1m,uid=' . getmyuid() . ',gid=' . getmygid() . ',mode=0770 tmpfs ' . escapeshellarg($dir . '/tmp') . ' 2>&1', $out, $rc);
            if ($rc !== 0) {
                $this->markTestSkipped('cannot mount a tmpfs: ' . implode(' ', $out));
            }
            try {
                [$client, $csrf] = $this->session($server, $dir, null);
                $up = $client->post('/backup/restore', ['csrf' => $csrf], ['backup' => ['name' => 'backup.zip', 'type' => 'application/zip', 'data' => random_bytes(2 * 1024 * 1024)]]);
                $this->assertStringContainsString('The upload could not be stored.', (string)$up['body']);
                $this->assertSame([], glob($dir . '/tmp/restore-*') ?: [], 'no partial upload is left');
            } finally {
                exec('sudo -n umount ' . escapeshellarg($dir . '/tmp'));
            }
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
            // N-452: an honest 30 MB attachment where only 20 MB fit (writes fail, the signal is ignored).
            $pdf = $dir . '/big.pdf';
            file_put_contents($pdf, "%PDF-1.4\n");
            $fh = fopen($pdf, 'r+b');
            ftruncate($fh, 30 * 1024 * 1024);
            fclose($fh);
            $z = new ZipArchive();
            $this->assertTrue($z->open($dir . '/full.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE));
            $z->addFromString('backup.json', $json);
            $z->addFile($pdf, 'files/' . $big);
            $z->close();
            unlink($pdf);
            $before = $pdo->query('SELECT COUNT(*) FROM item_attachments')->fetchColumn();
            foreach (['small.zip' => [null, false], 'big.zip' => [20480, false], 'full.zip' => [20480, true]] as $zip => [$limitKb, $full]) {
                $r = $this->cli($dir, 'restore', [$zip, '--yes'], $limitKb, $full);
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

    /** N-448 and the streamed reader's stricter checks: booleans, a key twice, rows before their columns. */
    public function testBackupJsonIsCheckedStrictly(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $dump = $this->dump($server, $dir);
            $json = static fn (array $d): string => (string)json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $fav = array_search('favourite', $dump['tables']['items']['columns'], true);
            $this->assertIsInt($fav);
            $cases = [];
            foreach ([true, false] as $b) {
                $d = $dump;
                $d['tables']['items']['rows'][0][$fav] = $b;
                $cases[] = [$json($d), 'A value in items is not a plain value.'];
            }
            $cases[] = ['{"driver":"x",' . substr($json($dump), 1), 'backup.json names the same key twice.'];
            $cases[] = [str_replace('"locations":{', '"locations":{"x":1,"x":2,', $json($dump)), 'backup.json names the same key twice.'];
            $d = $dump;
            $d['tables']['locations'] = ['rows' => $dump['tables']['locations']['rows'], 'columns' => $dump['tables']['locations']['columns']];
            $cases[] = [$json($d), 'Table locations is malformed.'];
            $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
            foreach ($cases as $i => [$text, $why]) {
                $this->zip($dir . "/strict$i.zip", ['backup.json' => $text]);
                $r = $this->cli($dir, 'restore', ["strict$i.zip", '--yes']);
                $this->assertSame([1, "Invalid backup: $why\n"], [$r['exit'], $r['stderr']], "case $i: " . $r['stdout']);
            }
            $this->assertSame('Changed', $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn());
        });
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

    public function testARestoreIntoAFreshDataDirMakesEachFolderWithLegacysMode(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $this->zip($dir . '/full.zip', $this->fullBackup($this->dump($server, $dir)));
            $want = [
                0022 => ['files' => 0750, 'images' => 0755, 'originals' => 0750, 'originals/map' => 0750, 'sessions' => 0755, 'thumbs' => 0755, 'tmp' => 0750],
                0002 => ['files' => 0770, 'images' => 0775, 'originals' => 0750, 'originals/map' => 0750, 'sessions' => 0775, 'thumbs' => 0775, 'tmp' => 0770],
            ];
            foreach ($want as $umask => $modes) {
                $seen = [];
                foreach (['legacy', 'ci4'] as $app) {
                    foreach (['images', 'thumbs', 'files', 'originals', 'tmp', 'sessions'] as $sub) {
                        rrmdir($dir . '/' . $sub);
                    }
                    $r = $this->cliAs($dir, $app, $umask, ['full.zip', '--yes']);
                    $this->assertSame([0, ''], [$r['exit'], $r['stderr']], "$app: " . $r['stdout']);
                    $this->assertFileExists($dir . '/files/' . self::FILE, $app);
                    $seen[$app] = self::folderModes($dir);
                }
                $label = sprintf('umask %04o', $umask);
                $this->assertSame(array_map(static fn (int $m): string => sprintf('%04o', $m), $modes), $seen['ci4'], $label);
                // tmp/ comes from the restore lock, which legacy here gets with the v2.6.23 sync (0770, as checked above).
                $this->assertSame($seen['legacy'], array_intersect_key($seen['ci4'], $seen['legacy']), "$label: as legacy");
                $this->assertSame([], array_values(array_diff(array_keys($seen['legacy']), array_keys($seen['ci4']))), $label);
            }
        });
    }

    public function testALinkAtACameraMapNameIsLeftAlone(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $dump = $this->dump($server, $dir);
            $second = str_repeat('7', 32) . '.jpg';
            $secondOriginal = str_repeat('cc', 32) . '.png';
            $photos = &$dump['tables']['item_photos'];
            $photos['rows'][] = array_map(static fn (string $c, mixed $v): mixed => ['id' => 9, 'file' => $second, 'position' => 1][$c] ?? $v, $photos['columns'], $photos['rows'][0]);
            unset($photos);
            $entries = $this->fullBackup($dump);
            $entries['originals/index.json'] = [self::PHOTO => self::ORIGINAL, $second => $secondOriginal];
            $entries += ['images/' . $second => img_jpeg(40, 30), 'thumbs/' . $second => img_jpeg(20, 15), 'originals/' . $secondOriginal => img_png(4, 4)];
            $this->zip($dir . '/full.zip', $entries);
            $map = $dir . '/originals/map/' . substr(self::PHOTO, 0, 32);
            $secondMap = $dir . '/originals/map/' . substr($second, 0, 32);
            foreach (['dangling' => false, 'real target' => true] as $case => $real) {
                $target = $dir . '/target-' . ($real ? 'real' : 'dangling');
                if ($real) {
                    file_put_contents($target, 'keep');
                }
                @unlink($map);
                @unlink($secondMap);
                symlink($target, $map);
                $r = $this->cliAs($dir, 'ci4', 0022, ['full.zip', '--yes']);
                $this->assertSame([0, ''], [$r['exit'], $r['stderr']], "$case: " . $r['stdout']);
                clearstatcache();
                $this->assertTrue(is_link($map), "$case: the link is not replaced");
                $this->assertSame($target, readlink($map), $case);
                if ($real) {
                    $this->assertSame('keep', file_get_contents($target), "$case: nothing is written through it");
                } else {
                    $this->assertFileDoesNotExist($target, "$case: nothing is written through it");
                }
                $this->assertFileExists($dir . '/originals/' . self::ORIGINAL, $case);
                $this->assertSame($secondOriginal, @file_get_contents($secondMap), "$case: the next map entry is still written");
                $this->assertSame([], array_values(array_filter(scandir($dir . '/originals/map') ?: [], static fn (string $f): bool => $f[0] === '.' && $f !== '.' && $f !== '..')), "$case: no temp file is left");
            }
            unlink($map);
            $r = $this->cliAs($dir, 'ci4', 0022, ['full.zip', '--yes']);
            $this->assertSame([0, ''], [$r['exit'], $r['stderr']], $r['stdout']);
            clearstatcache();
            $this->assertFalse(is_link($map));
            $this->assertSame(self::ORIGINAL, file_get_contents($map), 'with the link gone, the map is written');
            $this->assertSame(0640, fileperms($map) & 0777);
        });
    }

    public function testALinkAtACameraFileStopsTheRestore(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $this->zip($dir . '/full.zip', $this->fullBackup($this->dump($server, $dir)));
            $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
            $original = $dir . '/originals/' . self::ORIGINAL;
            foreach (['dangling' => false, 'real target' => true] as $case => $real) {
                $target = $dir . '/camera-' . ($real ? 'real' : 'dangling');
                if ($real) {
                    file_put_contents($target, 'keep');
                    chmod($target, 0604);
                }
                @unlink($original);
                symlink($target, $original);
                $r = $this->cliAs($dir, 'ci4', 0022, ['full.zip', '--yes']);
                $this->assertSame([1, "The backup could not be restored. Nothing was changed.\n"], [$r['exit'], $r['stderr']], "$case: " . $r['stdout']);
                clearstatcache();
                $this->assertTrue(is_link($original), "$case: the link is not replaced");
                $this->assertSame($target, readlink($original), $case);
                if ($real) {
                    $this->assertSame('keep', file_get_contents($target), "$case: nothing is written through it");
                    $this->assertSame(0604, fileperms($target) & 0777, "$case: its mode is unchanged");
                } else {
                    $this->assertFileDoesNotExist($target, "$case: nothing is written through it");
                }
                $this->assertFileDoesNotExist($dir . '/files/' . self::FILE, "$case: the files written are removed");
                $this->assertFileDoesNotExist($dir . '/originals/map/' . substr(self::PHOTO, 0, 32), $case);
                $this->assertSame('Changed', $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn(), "$case: nothing changed");
            }
        });
    }

    /**
     * L-560 (PostgreSQL, where the restore waits for a table lock): a camera
     * file swapped for a link after it was written and before the rows
     * commit is not made 0640 through the link.
     */
    public function testACameraFileSwappedForALinkBeforeTheCommitKeepsItsTargetsMode(): void
    {
        if (test_driver() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL: the restore waits for a held table lock');
        }
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $this->zip($dir . '/full.zip', $this->fullBackup($this->dump($server, $dir)));
            $original = $dir . '/originals/' . self::ORIGINAL;
            $target = $dir . '/camera-real';
            file_put_contents($target, 'keep');
            chmod($target, 0604);
            $pdo->exec('BEGIN');
            $pdo->exec('LOCK TABLE locations IN EXCLUSIVE MODE');
            try {
                $restore = $this->cliStart($dir, ['full.zip', '--yes']);
                $waiting = false;
                for ($i = 0; $i < 500 && !$waiting; $i++) {
                    usleep(20_000);
                    $waiting = (int)$pdo->query("SELECT COUNT(*) FROM pg_locks WHERE NOT granted AND relation = 'locations'::regclass")->fetchColumn() > 0;
                }
                $this->assertTrue($waiting, 'the restore waits for the rows');
                clearstatcache();
                $this->assertFileExists($original, 'the camera file is written before the rows');
                unlink($original);
                symlink($target, $original);
            } finally {
                $pdo->exec('ROLLBACK');
            }
            $r = $this->cliFinish($restore);
            $this->assertSame([0, ''], [$r['exit'], $r['stderr']], $r['stdout']);
            clearstatcache();
            $this->assertSame(0604, fileperms($target) & 0777, 'no chmod through the link');
            $this->assertSame('keep', file_get_contents($target));
            $this->assertTrue(is_link($original));
        });
    }

    public function testAnEntryOverTheFileSizeLimitLeavesNothing(): void
    {
        if (!is_executable('/usr/bin/prlimit')) {
            $this->markTestSkipped('needs prlimit');
        }
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $entries = $this->fullBackup($this->dump($server, $dir));
            // 5 MB of camera file where 4 MiB may be written (writes fail, the signal is ignored).
            $entries['originals/' . self::ORIGINAL] = img_png(8, 8) . random_bytes(5 * 1024 * 1024);
            $this->zip($dir . '/big.zip', $entries);
            $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
            $cmd = ['/usr/bin/prlimit', '--fsize=4194304', '/bin/sh', '-c', 'trap "" XFSZ; exec "$@"', 'sh', PHP_BINARY, ROOT . '/bin/restore.php', 'big.zip', '--yes'];
            $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $this->cliEnv($dir));
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $r = $this->cliFinish([$proc, $pipes[1], $pipes[2]]);
            $this->assertSame([1, "The backup could not be restored. Nothing was changed.\n"], [$r['exit'], $r['stderr']], $r['stdout']);
            $this->assertFileDoesNotExist($dir . '/files/' . self::FILE, 'the files written are removed');
            $this->assertFileDoesNotExist($dir . '/originals/' . self::ORIGINAL);
            $this->assertSame([], glob($dir . '/tmp/restore-*.part') ?: [], 'no temp file is left');
            foreach (['files', 'originals', 'originals/map', 'images', 'thumbs'] as $sub) {
                $this->assertSame([], glob($dir . '/' . $sub . '/.*.tmp-*') ?: [], "no temp file in $sub");
            }
            $this->assertSame('Changed', $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn(), 'nothing changed');
        });
    }

    /**
     * A restore stopped by a fatal error before the rows commit leaves no
     * file it wrote (legacy removes them on shutdown too). The fatal error is
     * raised from a signal handler (a prepended file, through
     * PHP_INI_SCAN_DIR) once the attachment is written and while the camera
     * file is still being unpacked.
     */
    public function testARestoreStoppedByAFatalErrorLeavesNoFiles(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('needs pcntl and posix');
        }
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo): void {
            $entries = $this->fullBackup($this->dump($server, $dir));
            $entries['originals/' . self::ORIGINAL] = str_repeat("\0", 100 * 1024 * 1024);   // time to stop it in
            $this->zip($dir . '/big.zip', $entries);
            $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
            mkdir($dir . '/ini');
            file_put_contents($dir . '/ini/zz-fatal.ini', "memory_limit=128M\nauto_prepend_file=" . $dir . "/ini/fatal.php\n");
            file_put_contents($dir . '/ini/fatal.php', '<?php
if (str_ends_with((string)($_SERVER["argv"][0] ?? ""), "spark")) {
    $d = ' . var_export($dir, true) . ';
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, static function () use ($d): void {
        clearstatcache();
        if (is_file($d . "/files/' . self::FILE . '") && !is_file($d . "/originals/' . self::ORIGINAL . '") && (glob($d . "/originals/.*.tmp-*") ?: []) === []) {
            pcntl_signal(SIGUSR1, SIG_IGN);
            touch($d . "/ini/hit");
            $x = str_repeat("x", 1 << 30);   // over memory_limit: a fatal error
        }
    });
    file_put_contents($d . "/ini/pid", (string)getmypid());
}
');
            $env = array_merge($this->cliEnv($dir), ['PHP_INI_SCAN_DIR' => ':' . $dir . '/ini']);
            $proc = proc_open([PHP_BINARY, ROOT . '/bin/restore.php', 'big.zip', '--yes'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            while (proc_get_status($proc)['running']) {
                $pid = (int)@file_get_contents($dir . '/ini/pid');
                if ($pid > 0 && !is_file($dir . '/ini/hit')) {   // once raised, no more signals (PHP resets the handler as it ends)
                    @posix_kill($pid, SIGUSR1);
                }
                usleep(2000);
            }
            $r = $this->cliFinish([$proc, $pipes[1], $pipes[2]]);
            $this->assertFileExists($dir . '/ini/hit', 'the fatal error was raised: ' . $r['stdout'] . $r['stderr']);
            $this->assertContains($r['exit'], [1, 255], $r['stdout'] . $r['stderr']);   // CodeIgniter's shutdown handler exits 1
            $this->assertStringContainsString('Allowed memory size', $r['stdout'] . $r['stderr']);
            clearstatcache();
            $this->assertFileDoesNotExist($dir . '/files/' . self::FILE, 'the files written are removed');
            $this->assertFileDoesNotExist($dir . '/originals/' . self::ORIGINAL);
            $this->assertFileDoesNotExist($dir . '/originals/map/' . substr(self::PHOTO, 0, 32));
            $this->assertSame('Changed', $pdo->query('SELECT name FROM items WHERE id = 1')->fetchColumn(), 'nothing changed');
        });
    }

    /**
     * L-590: a root command line restore that replaces a camera map entry
     * keeps the entry's owner, group and mode, as legacy does, so the web
     * user can still read it. Runs only where `sudo -n` works.
     */
    public function testARootRestoreKeepsTheOwnerOfAReplacedMapEntry(): void
    {
        exec('sudo -n true 2>/dev/null', $_, $code);
        if ($code !== 0) {
            $this->markTestSkipped('needs passwordless sudo');
        }
        $nobody = posix_getpwnam('nobody');
        if ($nobody === false) {
            $this->markTestSkipped('needs the nobody user');
        }
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo) use ($nobody): void {
            $this->zip($dir . '/full.zip', $this->fullBackup($this->dump($server, $dir)));
            @mkdir($dir . '/tmp', 0770, true);
            $map = $dir . '/originals/map/' . substr(self::PHOTO, 0, 32);
            try {
                foreach (['legacy', 'ci4'] as $app) {
                    @unlink($map);
                    file_put_contents($map, 'old');
                    chmod($map, 0640);
                    $this->sudo(['chown', $nobody['uid'] . ':' . $nobody['gid'], $map]);
                    $env = array_merge($this->cliEnv($dir), ['APOTHEKE_APP' => $app]);
                    $cmd = ['sudo', '-n', '/usr/bin/env', '-i', ...array_map(static fn (string $k, string $v): string => $k . '=' . $v, array_keys($env), $env),
                        PHP_BINARY, ROOT . '/bin/restore.php', 'full.zip', '--yes'];
                    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir);
                    $this->assertIsResource($proc);
                    fclose($pipes[0]);
                    $r = $this->cliFinish([$proc, $pipes[1], $pipes[2]]);
                    $this->assertSame([0, ''], [$r['exit'], $r['stderr']], "$app: " . $r['stdout']);
                    clearstatcache();
                    $this->assertSame(self::ORIGINAL, $this->sudo(['cat', $map]), "$app: the entry is replaced");
                    $this->assertSame([$nobody['uid'], $nobody['gid'], 0640], [fileowner($map), filegroup($map), fileperms($map) & 0777], "$app: owner, group and mode are kept");
                    $this->assertSame([], array_values(array_filter(scandir($dir . '/originals/map') ?: [], static fn (string $f): bool => $f[0] === '.' && $f !== '.' && $f !== '..')), "$app: no temp file is left");
                    $this->giveBack($dir);
                }
            } finally {
                $this->giveBack($dir);
            }
        });
    }

    /** @param list<string> $args a command run with `sudo -n`; its output */
    private function sudo(array $args): string
    {
        $proc = proc_open(['sudo', '-n', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $r = $this->cliFinish([$proc, $pipes[1], $pipes[2]]);
        $this->assertSame(0, $r['exit'], implode(' ', $args) . ': ' . $r['stderr']);

        return $r['stdout'];
    }

    /** The files a root run made under $dir and ci4/writable go back to this user, so they can be removed. */
    private function giveBack(string $dir): void
    {
        $me = posix_geteuid() . ':' . posix_getegid();
        foreach ([$dir, ROOT . '/ci4/writable'] as $root) {
            $this->sudo(['find', $root, '(', '-uid', '0', '-o', '-gid', '0', ')', '-exec', 'chown', '-h', $me, '{}', '+']);
        }
    }

    /**
     * The download's rows plus an attachment and a camera original for the photo.
     *
     * @param array<string,mixed> $dump
     * @return array<string,string|array<mixed>>
     */
    private function fullBackup(array $dump): array
    {
        $files = &$dump['tables']['item_attachments'];
        $files['rows'][] = array_map(static fn (string $c): mixed => ['id' => 1, 'item_id' => 1, 'file' => self::FILE, 'name' => 'm.pdf', 'mime' => 'application/pdf', 'size' => 10, 'created_at' => '2026-01-02 08:00:00'][$c] ?? null, $files['columns']);
        unset($files);

        return ['backup.json' => $dump, 'images/' . self::PHOTO => img_jpeg(64, 48), 'thumbs/' . self::PHOTO => img_jpeg(32, 24),
            'files/' . self::FILE => "%PDF-1.4\n%", 'originals/index.json' => [self::PHOTO => self::ORIGINAL], 'originals/' . self::ORIGINAL => img_png(8, 8)];
    }

    /** @return array<string,string> every folder under $dir, relative, with its mode in octal */
    private static function folderModes(string $dir): array
    {
        clearstatcache();
        $modes = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if ($f->isDir() && !$f->isLink()) {
                $modes[substr($f->getPathname(), strlen($dir) + 1)] = sprintf('%04o', fileperms($f->getPathname()) & 0777);
            }
        }
        ksort($modes);

        return $modes;
    }

    /**
     * bin/restore.php as $app, under $umask.
     *
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cliAs(string $dir, string $app, int $umask, array $args): array
    {
        $cmd = ['/bin/bash', '-c', sprintf('umask %04o && exec "$@"', $umask), 'bash', PHP_BINARY, ROOT . '/bin/restore.php', ...$args];
        $env = array_merge($this->cliEnv($dir), ['APOTHEKE_APP' => $app]);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);

        return $this->cliFinish([$proc, $pipes[1], $pipes[2]]);
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
    private function cli(string $dir, string $form, array $args, ?int $fileLimitKb = null, bool $diskFull = false): array
    {
        $cmd = [PHP_BINARY, ROOT . '/bin/' . $form . '.php', ...$args];
        if ($fileLimitKb !== null) {   // a larger write kills the process (SIGXFSZ), or with $diskFull fails as on a full disk
            $cmd = ['/bin/bash', '-c', ($diskFull ? "trap '' XFSZ && " : '') . 'ulimit -f ' . $fileLimitKb . ' && exec "$@"', 'bash', ...$cmd];
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
