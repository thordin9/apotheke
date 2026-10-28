<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * A restore that fails leaves the files as they were, on the configured driver. The backup adds a photo, an
 * attachment and a camera original, and links an existing photo to the new original. It fails at four points:
 * backup.json no longer valid when the confirm reads the waiting upload, a damaged entry late in the zip (after the
 * photo and the attachment are written), a file that cannot be written (after the photo), and a database error after
 * every file is written (web and CLI). Also a thumbnail that cannot be written, and a database error after a missing
 * thumbnail is drawn again. Each time the files (served copies, thumbnails, attachments, originals and
 * their links, by content and mode) and every catalog table are what they were. Then the same backup restores, so
 * each failure had something to undo. One restore runs at a time: a second one is refused at once (409, exit 1),
 * and a failing and a good restore started together never leave the good one's photos missing.
 */
final class BackupFailureTest extends TestCase
{
    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';
    private const NEW_PHOTO = '11112222333344445555666677778888.jpg';
    private const ORIGINAL = 'aa00000000000000000000000000000000000000000000000000000000000001.png';
    private const NEW_ORIGINAL = 'bb00000000000000000000000000000000000000000000000000000000000002.png';
    private const NEW_FILE = '99998888777766665555444433332222.pdf';
    private const FAILED = 'The backup could not be restored. Nothing was changed.';
    private const BUSY = 'Another restore is running. Try again when it has finished.';

    public function testAFailedRestoreLeavesTheFilesAndRowsAsTheyWere(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo, string $good): void {
            $before = $this->state($pdo, $dir);
            $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

            // 1. backup.json is no longer valid when the confirm reads the waiting upload.
            $this->confirmFails($server, $dir, $good, $this->rezip($good, ['backup.json' => '{"format":']), 'backup.json is not valid JSON.');
            $this->assertSame($before, $this->state($pdo, $dir), 'bad JSON');

            // 2. A damaged entry after the photo and the attachment are written.
            $this->confirmFails($server, $dir, $good, self::damage($good, 'originals/' . self::NEW_ORIGINAL), self::FAILED);
            $this->assertSame($before, $this->state($pdo, $dir), 'damaged entry');

            // 3. A file that cannot be written (a folder holds its name), after the photo is written.
            mkdir($dir . '/files/' . self::NEW_FILE . '/in-the-way', 0775, true);
            touch($dir . '/files/' . self::NEW_FILE . '/in-the-way/keep');
            $blocked = $this->state($pdo, $dir);
            $this->confirmFails($server, $dir, $good, $good, self::FAILED);
            $this->assertSame($blocked, $this->state($pdo, $dir), 'unwritable file');
            rrmdir($dir . '/files/' . self::NEW_FILE);
            $this->assertSame($before, $this->state($pdo, $dir));

            // 3b. A thumbnail that cannot be written, after its photo is written.
            mkdir($dir . '/thumbs/' . self::NEW_PHOTO . '/in-the-way', 0775, true);
            $blocked = $this->state($pdo, $dir);
            $this->confirmFails($server, $dir, $good, $good, self::FAILED);
            $this->assertSame($blocked, $this->state($pdo, $dir), 'unwritable thumbnail');
            rrmdir($dir . '/thumbs/' . self::NEW_PHOTO);
            $this->assertSame($before, $this->state($pdo, $dir));

            // 4. The database refuses a row after every file is written: web, then CLI.
            if ($pg) {
                $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            }
            $pdo->exec($pg ? 'CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()'
                : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $this->confirmFails($server, $dir, $good, $good, 'Nothing was changed.');
            $this->assertSame($before, $this->state($pdo, $dir), 'database error (web)');
            $thumbless = array_diff_key(self::read($good), ['thumbs/' . self::NEW_PHOTO => true]);
            $this->confirmFails($server, $dir, $good, $this->zipOf($thumbless), 'Nothing was changed.');
            $this->assertSame($before, $this->state($pdo, $dir), 'database error after a thumbnail is drawn again');
            file_put_contents($dir . '/cli.zip', $good);
            $cli = $this->cli($dir, [$dir . '/cli.zip', '--yes']);
            $this->assertSame(1, $cli['exit'], $cli['stdout'] . $cli['stderr']);
            if (getenv('APOTHEKE_APP') === 'ci4') {
                // CI4 prints a sanitized line, never the database's error text.
                $this->assertStringContainsString('The backup could not be restored. Nothing was changed.', $cli['stderr']);
                $this->assertStringNotContainsString('test failure', $cli['stderr']);
            } else {
                $this->assertStringContainsString('test failure', $cli['stderr']);
            }
            $this->assertStringEndsWith("Nothing was changed.\n", $cli['stderr']);
            unlink($dir . '/cli.zip');
            $this->assertSame($before, $this->state($pdo, $dir), 'database error (CLI)');
            $pdo->exec($pg ? 'DROP TRIGGER apotheke_test_fail ON activity' : 'DROP TRIGGER apotheke_test_fail');

            // The same backup restores: new files, and the photo linked to the new original.
            $c = $server->client();
            $csrf = $c->csrf();
            $token = self::token((string)$c->post('/backup/restore', ['csrf' => $csrf], self::upload($good))['body']);
            $r = $c->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
            $this->assertSame([303, '/backup?restored=1'], [$r['status'], $r['headers']['location'] ?? null], substr((string)$r['body'], 0, 2000));
            $after = $this->state($pdo, $dir);
            $this->assertSame(self::NEW_ORIGINAL, $after['files']['originals/map/' . substr(self::PHOTO, 0, 32)][0] ?? null);
            $this->assertSame(self::ORIGINAL, $after['files']['originals/map/' . substr(self::NEW_PHOTO, 0, 32)][0] ?? null);
            $this->assertSame([$before['files']['originals/' . self::ORIGINAL][0], '640'], $after['files']['originals/' . self::ORIGINAL], 'a linked original gets its mode once the rows are in');
            foreach (['images/' . self::NEW_PHOTO, 'thumbs/' . self::NEW_PHOTO, 'files/' . self::NEW_FILE, 'originals/' . self::NEW_ORIGINAL] as $f) {
                $this->assertArrayHasKey($f, $after['files']);
                $this->assertArrayNotHasKey($f, $before['files']);
            }
            $this->assertNotSame($before['tables']['item_photos'], $after['tables']['item_photos']);
        });
    }

    /**
     * While one restore runs (here the test holds its lock), another is refused at once: 409 on the web, exit 1 on
     * the CLI, nothing changed. The waiting upload stays, so the same confirm works once the lock is free.
     */
    public function testARestoreWhileAnotherRunsIsRefused(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo, string $good): void {
            $before = $this->state($pdo, $dir);
            $c = $server->client();
            $csrf = $c->csrf();
            $token = self::token((string)$c->post('/backup/restore', ['csrf' => $csrf], self::upload($good))['body']);
            $this->assertNotSame('', $token);
            file_put_contents($dir . '/cli.zip', $good);
            $lock = fopen($dir . '/tmp/restore.lock', 'c');
            $this->assertIsResource($lock);
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            try {
                $t = microtime(true);
                $r = $c->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
                $this->assertSame(409, $r['status']);
                $this->assertStringContainsString(self::BUSY, (string)$r['body']);
                $cli = $this->cli($dir, [$dir . '/cli.zip', '--yes']);
                $this->assertSame([1, self::BUSY . "\n"], [$cli['exit'], $cli['stderr']], $cli['stdout']);
                $this->assertLessThan(5.0, microtime(true) - $t, 'refused at once, not after waiting');
                $this->assertSame($before, $this->state($pdo, $dir));
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $r = $c->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
            $this->assertSame([303, '/backup?restored=1'], [$r['status'], $r['headers']['location'] ?? null]);
            $cli = $this->cli($dir, [$dir . '/cli.zip', '--yes']);
            $this->assertSame([0, ''], [$cli['exit'], $cli['stderr']], $cli['stdout']);
            $lock = fopen($dir . '/tmp/restore.lock', 'c');
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'released after a restore');
            flock($lock, LOCK_UN);
            fclose($lock);
        });
    }

    /**
     * A failing and a good restore of the same new photo at once, round after round. The failing one never takes
     * away a file the good one relies on: after the good one succeeds every photo and thumbnail its rows name exists;
     * else it was refused (busy) and the catalog is as it was.
     */
    public function testAFailingAndAGoodRestoreAtOnceLeaveAWholeCatalog(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo, string $good): void {
            $dump = json_decode((string)self::read($good)['backup.json'], true, 64, JSON_THROW_ON_ERROR);
            $items = &$dump['tables']['items'];
            $items['rows'][0][array_search('location_id', $items['columns'], true)] = 999;   // breaks a key: fails at the rows
            unset($items);
            file_put_contents($dir . '/good.zip', $good);
            file_put_contents($dir . '/bad.zip', $this->rezip($good, ['backup.json' => (string)json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]));
            $baseline = $this->state($pdo, $dir);
            $baseBytes = $this->bytes($dir);
            $seen = [];
            for ($round = 0; $round < 16; $round++) {
                [$bad, $ok] = $this->cliTogether($dir, [[$dir . '/bad.zip', '--yes'], [$dir . '/good.zip', '--yes']]);
                $this->assertSame(1, $bad['exit'], "round $round: failing restore " . $bad['stdout']);
                $this->assertTrue($bad['stderr'] === self::BUSY . "\n" || str_ends_with($bad['stderr'], "Nothing was changed.\n"), "round $round: " . $bad['stderr']);
                $this->assertContains([$ok['exit'], $ok['stderr']], [[0, ''], [1, self::BUSY . "\n"]], "round $round: good restore");
                $state = $this->state($pdo, $dir);
                if ($ok['exit'] === 0) {
                    foreach ($state['tables']['item_photos'] as $row) {
                        foreach (['images/', 'thumbs/'] as $sub) {
                            $this->assertArrayHasKey($sub . $row['file'], $state['files'], "round $round: the good restore's $sub" . $row['file']);
                        }
                    }
                } else {
                    $this->assertSame($baseline, $state, "round $round");
                }
                $seen[($bad['stderr'] === self::BUSY . "\n" ? 'busy' : 'failed') . '/' . ($ok['exit'] === 0 ? 'ok' : 'busy')] = true;
                // Back to the start: the catalog without the new photo, and none of its files.
                $this->restoreTables($pdo, $baseline['tables']);
                foreach ($state['files'] as $f => $_) {
                    if (!isset($baseBytes[$f])) unlink($dir . '/' . $f);
                    elseif ($baseline['files'][$f] !== $state['files'][$f]) {   // the photo's original link, the linked original's mode
                        file_put_contents($dir . '/' . $f, $baseBytes[$f]);
                        chmod($dir . '/' . $f, (int)octdec($baseline['files'][$f][1]));
                    }
                }
                $this->assertSame($baseline, $this->state($pdo, $dir), "round $round: reset");
            }
            $this->assertNotEmpty($seen);
            fwrite(STDERR, '[concurrent restores: ' . implode(', ', array_keys($seen)) . "]\n");
        });
    }

    /** @param callable(Server, string, PDO, string): void $fn with the seeded catalog and the bigger backup */
    private function withCatalog(callable $fn): void
    {
        $was = getenv('TEST_DB_DRIVER');
        putenv('TEST_DB_DRIVER=' . db_driver());   // the server and the CLI use the driver under test
        $dir = tmpdir('apotheke-restore-fail');
        $server = new Server($dir);
        try {
            $c = $server->client();
            $this->assertSame(200, $c->get('/healthz')['status'], (string)@file_get_contents($server->log));
            $pdo = testdb($dir);
            $this->seed($pdo, $dir);
            $download = $c->get('/backup.zip');
            $this->assertSame(200, $download['status']);
            @mkdir($dir . '/tmp', 0770, true);
            $fn($server, $dir, $pdo, $this->bigger((string)$download['body']));
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
            putenv($was === false ? 'TEST_DB_DRIVER' : 'TEST_DB_DRIVER=' . $was);
        }
    }

    private function seed(PDO $pdo, string $dir): void
    {
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage', 'garage', 'garage', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO items (name, location_id, quantity, notes, image, search, sort_key, created_at, updated_at) VALUES ('Drill', 1, 1, '', '" . self::PHOTO . "', 'drill', 'drill', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
        $pdo->exec("INSERT INTO item_photos (item_id, file, position, created_at) VALUES (1, '" . self::PHOTO . "', 0, '2026-01-02 08:00:00')");
        $pdo->exec("INSERT INTO activity (item_id, item_name, action, details, actor, created_at) VALUES (1, 'Drill', 'created', '{}', 'ada', '2026-01-02 08:00:00')");
        foreach (['images', 'thumbs', 'files', 'originals/map'] as $sub) @mkdir($dir . '/' . $sub, 0775, true);
        file_put_contents($dir . '/images/' . self::PHOTO, img_jpeg(64, 48));
        file_put_contents($dir . '/thumbs/' . self::PHOTO, img_jpeg(32, 24));
        file_put_contents($dir . '/originals/' . self::ORIGINAL, img_png(64, 48));
        file_put_contents($dir . '/originals/map/' . substr(self::PHOTO, 0, 32), self::ORIGINAL);
        chmod($dir . '/originals/' . self::ORIGINAL, 0644);   // made before originals were 0640: a restore sets that only once it succeeds
        chmod($dir . '/originals/map/' . substr(self::PHOTO, 0, 32), 0640);
        // as the first camera upload leaves it
        file_put_contents($dir . '/originals/.htaccess', originals_htaccess());
        chmod($dir . '/originals/.htaccess', 0640);
    }

    /**
     * The download plus a photo, an attachment and a camera original, with the existing photo linked to the new
     * original. Stored, not deflated, so an entry's bytes can be damaged in place.
     */
    private function bigger(string $zip): string
    {
        $dump = json_decode((string)self::read($zip)['backup.json'], true, 64, JSON_THROW_ON_ERROR);
        $photos = &$dump['tables']['item_photos'];
        $photos['rows'][] = array_map(static fn(string $c, mixed $v): mixed => ['id' => 2, 'file' => self::NEW_PHOTO, 'position' => 1][$c] ?? $v, $photos['columns'], $photos['rows'][0]);
        unset($photos);
        $files = &$dump['tables']['item_attachments'];
        $files['rows'][] = array_map(static fn(string $c): mixed => ['id' => 1, 'item_id' => 1, 'file' => self::NEW_FILE, 'name' => 'manual.pdf', 'mime' => 'application/pdf', 'size' => 9, 'created_at' => '2026-01-02 08:00:00'][$c] ?? null, $files['columns']);
        unset($files);
        return $this->rezip($zip, [
            'backup.json' => (string)json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'images/' . self::NEW_PHOTO => img_jpeg(72, 40),
            'thumbs/' . self::NEW_PHOTO => img_jpeg(36, 20),
            'files/' . self::NEW_FILE => "%PDF-1.4\n%",
            'originals/index.json' => (string)json_encode([self::PHOTO => self::NEW_ORIGINAL, self::NEW_PHOTO => self::ORIGINAL]),
            'originals/' . self::NEW_ORIGINAL => img_png(72, 40),
        ]);
    }

    /** Upload $upload, swap the waiting file for $confirm, confirm: 422 with $message. */
    private function confirmFails(Server $server, string $dir, string $upload, string $confirm, string $message): void
    {
        $c = $server->client();
        $csrf = $c->csrf();
        $r = $c->post('/backup/restore', ['csrf' => $csrf], self::upload($upload));
        $this->assertSame(200, $r['status'], substr((string)$r['body'], 0, 2000));
        $token = self::token((string)$r['body']);
        file_put_contents($dir . '/tmp/restore-' . $token . '.zip', $confirm);
        $r = $c->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
        $this->assertSame(422, $r['status'], $message . ' ' . substr((string)$r['body'], 0, 500));
        $this->assertStringContainsString(htmlspecialchars($message), (string)$r['body']);
        clearstatcache();
        $this->assertFileDoesNotExist($dir . '/tmp/restore-' . $token . '.zip', 'a failed restore removes its upload at once (N-567)');
    }

    /** @return array<string,array{name:string,type:string,data:string}> */
    private static function upload(string $zip): array
    {
        return ['backup' => ['name' => 'backup.zip', 'type' => 'application/zip', 'data' => $zip]];
    }

    private static function token(string $html): string
    {
        return preg_match('/name="token" value="([a-f0-9]{32})"/', $html, $m) === 1 ? $m[1] : '';
    }

    /** @return array<string,string> name => bytes, in the zip's order */
    private static function read(string $zip): array
    {
        $path = tempnam(sys_get_temp_dir(), 'coder2-l440-');
        file_put_contents($path, $zip);
        $z = new ZipArchive();
        $z->open($path, ZipArchive::RDONLY);
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) $out[(string)$z->getNameIndex($i)] = (string)$z->getFromIndex($i);
        $z->close();
        unlink($path);
        return $out;
    }

    /** @param array<string,string> $entries replaced or added, after the zip's own */
    private function rezip(string $zip, array $entries): string
    {
        return $this->zipOf(array_merge(self::read($zip), $entries));
    }

    /** @param array<string,string> $entries a stored zip of exactly these */
    private function zipOf(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'coder2-l440-');
        $z = new ZipArchive();
        $this->assertTrue($z->open($path, ZipArchive::OVERWRITE));
        foreach ($entries as $name => $bytes) {
            $z->addFromString($name, $bytes);
            $z->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $z->close();
        $bytes = (string)file_get_contents($path);
        unlink($path);
        return $bytes;
    }

    /** The zip with one byte of $name's stored data changed (its checksum no longer matches). */
    private static function damage(string $zip, string $name): string
    {
        $data = self::read($zip)[$name];
        $at = strpos($zip, $data);
        self::assertIsInt($at);
        $mid = $at + intdiv(strlen($data), 2);
        $zip[$mid] = chr(ord($zip[$mid]) ^ 0xff);
        return $zip;
    }

    /** @return array<string,string> every file under the data folders, by bytes */
    private function bytes(string $dir): array
    {
        $out = [];
        foreach (['images', 'thumbs', 'files', 'originals'] as $sub) {
            if (!is_dir($dir . '/' . $sub)) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/' . $sub, FilesystemIterator::SKIP_DOTS)) as $f) {
                $out[substr((string)$f, strlen($dir) + 1)] = (string)file_get_contents((string)$f);
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Every catalog table, and every file (images, thumbs, files, originals and their links): a link by its
     * content, any other file by its sha256; with its mode.
     * @return array{tables:array<string,list<array<string,mixed>>>,files:array<string,array{0:string,1:string}>}
     */
    private function state(PDO $pdo, string $dir): array
    {
        $tables = [];
        foreach (['locations', 'categories', 'tags', 'items', 'item_photos', 'item_tags', 'item_attachments', 'activity', 'saved_filters', 'stocktakes', 'stocktake_lines', 'settings'] as $t) {
            $tables[$t] = $pdo->query("SELECT * FROM $t ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        }
        $files = [];
        foreach ($this->bytes($dir) as $f => $bytes) {
            clearstatcache(true, $dir . '/' . $f);
            $files[$f] = [str_starts_with($f, 'originals/map/') ? $bytes : hash('sha256', $bytes), sprintf('%o', fileperms($dir . '/' . $f) & 0777)];
        }
        return ['tables' => $tables, 'files' => $files];
    }

    /** @param array<string,list<array<string,mixed>>> $tables back into the database, as they were */
    private function restoreTables(PDO $pdo, array $tables): void
    {
        $pdo->beginTransaction();
        foreach (['item_photos', 'item_attachments', 'settings'] as $t) {   // a good restore rewrites the settings' times
            $pdo->exec("DELETE FROM $t");
            foreach ($tables[$t] as $row) {
                $cols = array_keys($row);
                $pdo->prepare("INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
            }
        }
        $pdo->commit();
    }

    /** @return array<string,string> */
    private static function env(string $dir): array
    {
        $env = array_merge(getenv(), ['DATA_DIR' => $dir, 'TZ' => 'Europe/Athens', 'APP_PASSWORD' => '', 'APP_KEY' => '', 'APP_KEY_FILE' => '', 'APP_KEY_NEW' => '',
            'SEED_DEFAULTS' => '0', 'APOTHEKE_CONFIG' => '/nonexistent'], test_db_env($dir));
        return array_map('strval', $env);
    }

    /**
     * bin/restore.php runs started together.
     * @param list<list<string>> $runs
     * @return list<array{stdout:string,stderr:string,exit:int}>
     */
    private function cliTogether(string $dir, array $runs): array
    {
        $procs = [];
        foreach ($runs as $i => $args) {
            $p = proc_open([PHP_BINARY, ROOT . '/bin/restore.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, self::env($dir));
            $this->assertIsResource($p);
            fclose($pipes[0]);
            $procs[$i] = [$p, $pipes[1], $pipes[2]];
        }
        $out = [];
        foreach ($procs as $i => [$p, $o, $e]) {
            $stdout = (string)stream_get_contents($o);
            $stderr = (string)stream_get_contents($e);
            fclose($o);
            fclose($e);
            $out[$i] = ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($p)];
        }
        return $out;
    }

    /**
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cli(string $dir, array $args): array
    {
        return $this->cliTogether($dir, [$args])[0];
    }
}
