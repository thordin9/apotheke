<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * L-440: a CI4 restore that fails leaves the files as they were, on the
 * configured driver. The backup adds a photo, an attachment and a camera
 * original, and links an existing photo to the new original. It fails at
 * four points: backup.json no longer valid when the restore reads the
 * waiting upload, a damaged entry late in the zip (after the photo and the
 * attachment are written), a file that cannot be written (after the photo),
 * and a database error after every file is written (web and CLI). Each time the files (served copies, thumbnails,
 * attachments, originals and their links, by content) and every catalog
 * table are what they were. Then the same backup restores, so each failure
 * had something to undo. Legacy keeps the files (Ci4BackupParityTest).
 */
final class Ci4BackupFailureTest extends TestCase
{
    private const CLOCK = '2026-06-15 21:30:00';

    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    private const NEW_PHOTO = '11112222333344445555666677778888.jpg';

    private const ORIGINAL = 'aa00000000000000000000000000000000000000000000000000000000000001.png';

    private const NEW_ORIGINAL = 'bb00000000000000000000000000000000000000000000000000000000000002.png';

    private const NEW_FILE = '99998888777766665555444433332222.pdf';

    public function testAFailedRestoreLeavesTheFilesAndRowsAsTheyWere(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $dir = tmpdir('backup-failure');
        $server = new Server($dir, ['APOTHEKE_CLOCK' => self::CLOCK], 'ci4');
        try {
            $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
            $pdo = testdb($dir);
            $this->seed($pdo, $dir);
            $download = $this->client($server, $dir)[0]->get('/backup.zip');
            $this->assertSame(200, $download['status']);
            $good = $this->bigger((string)$download['body']);
            $before = $this->state($pdo, $dir);
            $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';

            // 1. backup.json is no longer valid when the confirm reads the waiting upload.
            $this->confirmFails($server, $dir, $good, $this->rezip($good, ['backup.json' => '{"format":']), 'backup.json is not valid JSON.');
            $this->assertSame($before, $this->state($pdo, $dir), 'bad JSON');

            // 2. A damaged entry after the photo and the attachment are written.
            $this->confirmFails($server, $dir, $good, self::damage($good, 'originals/' . self::NEW_ORIGINAL), 'The backup could not be restored. Nothing was changed.');
            $this->assertSame($before, $this->state($pdo, $dir), 'damaged entry');

            // 3. A file that cannot be written (a folder holds its name), after the photo is written.
            mkdir($dir . '/files/' . self::NEW_FILE . '/in-the-way', 0775, true);
            touch($dir . '/files/' . self::NEW_FILE . '/in-the-way/keep');
            $blocked = $this->state($pdo, $dir);
            $this->confirmFails($server, $dir, $good, $good, 'The backup could not be restored. Nothing was changed.');
            $this->assertSame($blocked, $this->state($pdo, $dir), 'unwritable file');
            rrmdir($dir . '/files/' . self::NEW_FILE);
            $this->assertSame($before, $this->state($pdo, $dir));

            // 4. The database refuses a row after every file is written: web, then CLI.
            if ($pg) {
                $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            }
            $pdo->exec($pg ? 'CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()'
                : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $this->confirmFails($server, $dir, $good, $good, 'The backup could not be restored. Nothing was changed.');
            $this->assertSame($before, $this->state($pdo, $dir), 'database error (web)');
            file_put_contents($dir . '/cli.zip', $good);
            $cli = $this->cli($dir, ['cli.zip', '--yes']);
            $this->assertSame([1, "The backup could not be restored. Nothing was changed.\n"], [$cli['exit'], $cli['stderr']], $cli['stdout']);
            unlink($dir . '/cli.zip');
            $this->assertSame($before, $this->state($pdo, $dir), 'database error (CLI)');
            $pdo->exec($pg ? 'DROP TRIGGER apotheke_test_fail ON activity' : 'DROP TRIGGER apotheke_test_fail');

            // The same backup restores: new files, and the photo linked to the new original.
            [$client, $csrf] = $this->client($server, $dir);
            $token = self::token($client->post('/backup/restore', ['csrf' => $csrf], self::upload($good))['body']);
            $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
            $this->assertSame([303, '/backup?restored=1'], [$r['status'], $r['headers']['location'] ?? null], preg_match('/<p[^>]*class="[^"]*err[^"]*"[^>]*>(.*?)<\/p>/s', (string)$r['body'], $m) === 1 ? $m[1] : substr((string)$r['body'], 0, 3000));
            $after = $this->state($pdo, $dir);
            $this->assertSame(self::NEW_ORIGINAL, $after['files']['originals/map/' . substr(self::PHOTO, 0, 32)]);
            foreach (['images/' . self::NEW_PHOTO, 'thumbs/' . self::NEW_PHOTO, 'files/' . self::NEW_FILE, 'originals/' . self::NEW_ORIGINAL] as $f) {
                $this->assertArrayHasKey($f, $after['files']);
                $this->assertArrayNotHasKey($f, $before['files']);
            }
            $this->assertNotSame($before['tables']['item_photos'], $after['tables']['item_photos']);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /**
     * L-450: while one restore runs (here the test holds its lock), another
     * is refused at once: 409 on the web, exit 1 on the CLI, nothing changed.
     * The waiting upload stays, so the same confirm works once the lock is free.
     */
    public function testARestoreWhileAnotherRunsIsRefused(): void
    {
        $this->withCatalog(function (Server $server, string $dir, PDO $pdo, string $good): void {
            $before = $this->state($pdo, $dir);
            $busy = 'Another restore is running. Try again when it has finished.';
            [$client, $csrf] = $this->client($server, $dir);
            $token = self::token((string)$client->post('/backup/restore', ['csrf' => $csrf], self::upload($good))['body']);
            $this->assertNotSame('', $token);
            $lock = fopen($dir . '/tmp/restore.lock', 'c');
            $this->assertIsResource($lock);
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            try {
                $t = microtime(true);
                $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
                $this->assertSame(409, $r['status']);
                $this->assertStringContainsString($busy, (string)$r['body']);
                file_put_contents($dir . '/cli.zip', $good);
                $cli = $this->cli($dir, ['cli.zip', '--yes']);
                $this->assertSame([1, $busy . "\n"], [$cli['exit'], $cli['stderr']], $cli['stdout']);
                $this->assertLessThan(5.0, microtime(true) - $t, 'refused at once, not after waiting');
                $this->assertSame($before, $this->state($pdo, $dir));
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
            $this->assertSame([303, '/backup?restored=1'], [$r['status'], $r['headers']['location'] ?? null]);
            // The lock is free again after a restore, also after a failed one.
            $cli = $this->cli($dir, ['cli.zip', '--yes']);
            $this->assertSame([0, ''], [$cli['exit'], $cli['stderr']], $cli['stdout']);
            $lock = fopen($dir . '/tmp/restore.lock', 'c');
            $this->assertIsResource($lock);
            $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB), 'released');
            flock($lock, LOCK_UN);
            fclose($lock);
        });
    }

    /**
     * L-450: a failing and a good restore of the same new photo at once,
     * round after round. The failing one never takes away a file the good
     * one relies on: after the good one succeeds every photo and thumbnail
     * its rows name exists; else it was refused (BUSY) or failed whole.
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
            $busy = "Another restore is running. Try again when it has finished.\n";
            $failed = "The backup could not be restored. Nothing was changed.\n";
            $seen = [];
            for ($round = 0; $round < 12; $round++) {
                $runs = $this->cliTogether($dir, [['bad.zip', '--yes'], ['good.zip', '--yes']]);
                [$bad, $ok] = $runs;
                $this->assertContains([$bad['exit'], $bad['stderr']], [[1, $failed], [1, $busy]], "round $round: failing restore");
                $this->assertContains([$ok['exit'], $ok['stderr']], [[0, ''], [1, $busy]], "round $round: good restore");
                $state = $this->state($pdo, $dir);
                if ($ok['exit'] === 0) {
                    foreach ($state['tables']['item_photos'] as $row) {
                        foreach (['images/', 'thumbs/'] as $sub) {
                            $this->assertArrayHasKey($sub . $row['file'], $state['files'], "round $round: the good restore's $sub" . $row['file']);
                        }
                    }
                } else {
                    $this->assertSame($baseline['tables'], $state['tables'], "round $round");
                }
                $seen[$bad['exit'] . '/' . $ok['exit']] = true;
                // Back to the start: the catalog without the new photo, and none of its files.
                $this->restoreTables($pdo, $baseline['tables']);
                foreach ($state['files'] as $f => $bytes) {
                    if (!isset($baseline['files'][$f])) {
                        unlink($dir . '/' . $f);
                    } elseif ($baseline['files'][$f] !== $bytes) {
                        file_put_contents($dir . '/' . $f, $baseline['files'][$f]);   // the photo's original link
                    }
                }
                $this->assertSame($baseline, $this->state($pdo, $dir), "round $round: reset");
            }
            $this->assertNotEmpty($seen);
        });
    }

    /** @param callable(Server, string, PDO, string): void $fn with the seeded catalog and the bigger backup */
    private function withCatalog(callable $fn): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $dir = tmpdir('backup-failure');
        $server = new Server($dir, ['APOTHEKE_CLOCK' => self::CLOCK], 'ci4');
        try {
            $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
            $pdo = testdb($dir);
            $this->seed($pdo, $dir);
            $download = $this->client($server, $dir)[0]->get('/backup.zip');
            $this->assertSame(200, $download['status']);
            @mkdir($dir . '/tmp', 0770, true);
            $fn($server, $dir, $pdo, $this->bigger((string)$download['body']));
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /** @param array<string,list<array<string,mixed>>> $tables back into the database, as they were */
    private function restoreTables(PDO $pdo, array $tables): void
    {
        $pdo->beginTransaction();
        foreach (['item_photos', 'item_attachments'] as $t) {
            $pdo->exec("DELETE FROM $t");
            foreach ($tables[$t] as $row) {
                $cols = array_keys($row);
                $pdo->prepare("INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
            }
        }
        $pdo->commit();
    }

    /**
     * bin/restore.php runs started together.
     *
     * @param list<list<string>> $runs
     * @return list<array{stdout:string,stderr:string,exit:int}>
     */
    private function cliTogether(string $dir, array $runs): array
    {
        $env = array_merge(getenv(), test_server_env($dir), ['APOTHEKE_CLOCK' => self::CLOCK, 'APOTHEKE_APP' => 'ci4', 'CI_ENVIRONMENT' => 'production']);
        unset($env['APP_PASSWORD']);
        $procs = [];
        foreach ($runs as $i => $args) {
            $procs[$i] = proc_open([PHP_BINARY, ROOT . '/bin/restore.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, array_map('strval', $env));
            $this->assertIsResource($procs[$i]);
            fclose($pipes[0]);
            $procs[$i] = [$procs[$i], $pipes[1], $pipes[2]];
        }
        $out = [];
        foreach ($procs as $i => [$proc, $o, $e]) {
            $stdout = (string)stream_get_contents($o);
            $stderr = (string)stream_get_contents($e);
            fclose($o);
            fclose($e);
            $out[$i] = ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($proc)];
        }

        return $out;
    }

    /** Upload $upload, swap the waiting file for $confirm, confirm: 422 with $message. */
    private function confirmFails(Server $server, string $dir, string $upload, string $confirm, string $message): void
    {
        [$client, $csrf] = $this->client($server, $dir);
        $r = $client->post('/backup/restore', ['csrf' => $csrf], self::upload($upload));
        $this->assertSame(200, $r['status'], substr((string)$r['body'], 0, 2000));
        $token = self::token((string)$r['body']);
        file_put_contents($dir . '/tmp/restore-' . $token . '.zip', $confirm);
        $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $token, 'confirm' => '1']);
        $this->assertSame(422, $r['status'], $message);
        $this->assertStringContainsString(htmlspecialchars($message), (string)$r['body']);
        @unlink($dir . '/tmp/restore-' . $token . '.zip');   // kept for the hourly sweep, as on legacy
    }

    /** @return array{0:Client,1:string} a client with a session that holds a CSRF token (sign-in is off) */
    private function client(Server $server, string $dir): array
    {
        $client = $server->client();
        $token = bin2hex(random_bytes(32));
        $sid = bin2hex(random_bytes(13));
        @mkdir($dir . '/sessions', 0700, true);
        file_put_contents($dir . '/sessions/sess_' . $sid, 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';');
        $client->cookies['apothekesid'] = $sid;

        return [$client, $token];
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

    /**
     * The download plus a photo, an attachment and a camera original, with
     * the existing photo linked to the new original. Stored, not deflated,
     * so an entry's bytes can be damaged in place.
     */
    private function bigger(string $zip): string
    {
        $dump = json_decode((string)self::read($zip)['backup.json'], true, 64, JSON_THROW_ON_ERROR);
        $photos = &$dump['tables']['item_photos'];
        // A copy of the existing photo's row, as the second photo.
        $photos['rows'][] = array_map(static fn (string $c, mixed $v): mixed => ['id' => 2, 'file' => self::NEW_PHOTO, 'position' => 1][$c] ?? $v, $photos['columns'], $photos['rows'][0]);
        unset($photos);
        $files = &$dump['tables']['item_attachments'];
        $files['rows'][] = array_map(static fn (string $c): mixed => ['id' => 1, 'item_id' => 1, 'file' => self::NEW_FILE, 'name' => 'manual.pdf', 'mime' => 'application/pdf', 'size' => 9, 'created_at' => '2026-01-02 08:00:00'][$c] ?? null, $files['columns']);
        unset($files);

        return $this->rezip($zip, [
            'backup.json' => (string)json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'images/' . self::NEW_PHOTO => img_jpeg(72, 40),
            'thumbs/' . self::NEW_PHOTO => img_jpeg(36, 20),
            'files/' . self::NEW_FILE => "%PDF-1.4\n%",
            'originals/index.json' => (string)json_encode([self::PHOTO => self::NEW_ORIGINAL, self::NEW_PHOTO => self::NEW_ORIGINAL]),
            'originals/' . self::NEW_ORIGINAL => img_png(72, 40),
        ]);
    }

    /** @return array<string,string> name => bytes, in the zip's order */
    private static function read(string $zip): array
    {
        $path = tempnam(sys_get_temp_dir(), 'coder2-s14b-');
        file_put_contents($path, $zip);
        $z = new ZipArchive();
        $z->open($path, ZipArchive::RDONLY);
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $out[(string)$z->getNameIndex($i)] = (string)$z->getFromIndex($i);
        }
        $z->close();
        unlink($path);

        return $out;
    }

    /** @param array<string,string> $entries replaced or added, after the zip's own */
    private function rezip(string $zip, array $entries): string
    {
        $all = array_merge(self::read($zip), $entries);
        $path = tempnam(sys_get_temp_dir(), 'coder2-s14b-');
        $z = new ZipArchive();
        $this->assertTrue($z->open($path, ZipArchive::OVERWRITE));
        foreach ($all as $name => $bytes) {
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

    /** @return array{tables:array<string,list<array<string,mixed>>>,files:array<string,string>} */
    private function state(PDO $pdo, string $dir): array
    {
        $tables = [];
        foreach (['locations', 'categories', 'tags', 'items', 'item_photos', 'item_tags', 'item_attachments', 'activity', 'saved_filters', 'stocktakes', 'stocktake_lines', 'settings'] as $t) {
            $tables[$t] = $pdo->query("SELECT * FROM $t ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC);
        }
        $files = [];
        foreach (['images', 'thumbs', 'files', 'originals'] as $sub) {
            if (!is_dir($dir . '/' . $sub)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/' . $sub, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $files[substr((string)$f, strlen($dir) + 1)] = (string)file_get_contents((string)$f);
            }
        }
        ksort($files);

        return ['tables' => $tables, 'files' => $files];
    }

    /**
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cli(string $dir, array $args): array
    {
        $env = array_merge(getenv(), test_server_env($dir), ['APOTHEKE_CLOCK' => self::CLOCK, 'APOTHEKE_APP' => 'ci4', 'CI_ENVIRONMENT' => 'production']);
        unset($env['APP_PASSWORD']);
        $proc = proc_open([PHP_BINARY, ROOT . '/bin/restore.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, array_map('strval', $env));
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($proc)];
    }

    private function seed(PDO $pdo, string $dir): void
    {
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage', 'garage', 'garage', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO items (name, location_id, quantity, notes, image, search, sort_key, created_at, updated_at) VALUES ('Drill', 1, 1, '', '" . self::PHOTO . "', 'drill', 'drill', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
        $pdo->exec("INSERT INTO item_photos (item_id, file, position, created_at) VALUES (1, '" . self::PHOTO . "', 0, '2026-01-02 08:00:00')");
        $pdo->exec("INSERT INTO activity (item_id, item_name, action, details, actor, created_at) VALUES (1, 'Drill', 'created', '{}', 'ada', '2026-01-02 08:00:00')");
        foreach (['images', 'thumbs', 'files', 'originals/map'] as $sub) {
            @mkdir($dir . '/' . $sub, 0775, true);
        }
        file_put_contents($dir . '/images/' . self::PHOTO, img_jpeg(64, 48));
        file_put_contents($dir . '/thumbs/' . self::PHOTO, img_jpeg(32, 24));
        file_put_contents($dir . '/originals/' . self::ORIGINAL, img_png(64, 48));
        file_put_contents($dir . '/originals/map/' . substr(self::PHOTO, 0, 32), self::ORIGINAL);
    }
}
