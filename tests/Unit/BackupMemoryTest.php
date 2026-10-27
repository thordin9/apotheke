<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * L-370: CI4 streams backup.json into the zip row by row. 8,000 items with
 * 3 KB notes make a 24 MB backup.json, which a server or a bin/backup.php
 * limited to 24 MB can only write that way. Legacy builds it in memory, so
 * it runs at the usual limit, and CI4's backup.json must be its bytes.
 *
 * L-441, N-454: the restore streams too. That backup restores at 24 MB, on
 * the web (upload, then confirm) and on the CLI, so neither backup.json nor
 * its rows are ever whole in memory. One value larger than the memory left
 * is refused before it is read whole, with nothing changed; L-451: one
 * 48 MB value restores at 128 MB, as on legacy.
 */
final class BackupMemoryTest extends TestCase
{
    private const ITEMS = 8000;
    private const CLOCK = '2026-06-15 21:30:00';

    public function testTheBackupStreamsUnderALowMemoryLimit(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 needs PHP 8.4 and ci4/vendor');
        }
        $json = [];
        foreach (['legacy' => [], 'ci4' => ['memory_limit' => '24M']] as $app => $ini) {
            $dir = tmpdir('backup-memory-' . $app);
            $server = new Server($dir, ['APOTHEKE_CLOCK' => self::CLOCK], $app, null, $ini);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $this->seed(testdb($dir));
                $r = $server->client()->get('/backup.zip');
                $this->assertSame(200, $r['status'], $app . ': ' . substr((string)$r['body'], 0, 300));
                $this->assertSame('application/zip', $r['headers']['content-type'] ?? null, $app);
                $json[$app] = $this->backupJson((string)$r['body'], $dir . '/web.zip');
                if ($app === 'ci4') {
                    $this->assertSame(hash('sha256', $json['legacy']), hash('sha256', $json['ci4']), 'web');
                    $this->assertCliBackup($dir, $json['legacy']);
                    $this->assertRestoreStreams($server, $dir, (string)$r['body']);
                }
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
    }

    /** bin/backup.php, and the spark command it starts, under the same 24 MB. */
    private function assertCliBackup(string $dir, string $expected): void
    {
        $r = $this->cli($dir, 'backup', ['cli.zip'], '24M');
        $this->assertSame([0, ''], [$r['exit'], $r['stderr']], $r['stdout']);
        $this->assertSame(hash('sha256', $expected), hash('sha256', $this->backupJson((string)file_get_contents($dir . '/cli.zip'), $dir . '/cli-copy.zip')), 'cli');
    }

    /** L-441, L-451, N-454: restored within the memory limit, web and CLI; a value too large for it is refused. */
    private function assertRestoreStreams(Server $server, string $dir, string $zip): void
    {
        $pdo = testdb($dir);
        $pdo->exec('DELETE FROM items WHERE id > 100');
        $this->assertSame(100, $this->catalog($pdo, $dir)['items']);

        $client = $server->client();
        $csrf = bin2hex(random_bytes(32));
        $sid = bin2hex(random_bytes(13));
        @mkdir($dir . '/sessions', 0700, true);
        file_put_contents($dir . '/sessions/sess_' . $sid, 'csrf|s:64:"' . $csrf . '";last_activity|i:' . time() . ';');
        $client->cookies['apothekesid'] = $sid;
        $r = $client->post('/backup/restore', ['csrf' => $csrf], ['backup' => ['name' => 'backup.zip', 'type' => 'application/zip', 'data' => $zip]]);
        $this->assertSame(200, $r['status'], substr((string)$r['body'], 0, 500));
        $this->assertSame(1, preg_match('/name="token" value="([a-f0-9]{32})"/', (string)$r['body'], $m));
        $r = $client->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $m[1], 'confirm' => '1']);
        $this->assertSame([303, '/backup?restored=1'], [$r['status'], $r['headers']['location'] ?? null], substr((string)$r['body'], 0, 500));
        $this->assertSame(['items' => self::ITEMS, 'notes' => self::ITEMS * 3000], array_slice($this->catalog($pdo, $dir), 0, 2), 'web');

        $pdo->exec('DELETE FROM items WHERE id > 100');
        $r = $this->cli($dir, 'restore', ['cli.zip', '--yes'], '24M');
        $this->assertSame([0, ''], [$r['exit'], $r['stderr']], $r['stdout']);
        $this->assertSame(['items' => self::ITEMS, 'notes' => self::ITEMS * 3000], array_slice($this->catalog($pdo, $dir), 0, 2), 'CLI');

        // One 30 MB value at 24 MB: refused before it is read whole; nothing changed.
        $pdo->exec('DELETE FROM items WHERE id > 100');
        $before = $this->catalog($pdo, $dir);
        $refused = 'This backup is too large to restore within the memory limit. Nothing was changed.';
        $this->oneLargeNote($zip, $dir . '/large.zip', 30 * 1024 * 1024);
        $r = $this->cli($dir, 'restore', ['large.zip', '--yes'], '24M');
        $this->assertSame([1, 'Invalid backup: ' . $refused . "\n"], [$r['exit'], $r['stderr']], $r['stdout']);   // the CLI's wording for a backup it will not open
        $this->assertSame($before, $this->catalog($pdo, $dir), 'large value');

        // A small backup.json that would decode to far more: no longer decoded whole, so only "not a backup".
        $path = $dir . '/dense.zip';
        $z = new ZipArchive();
        $this->assertTrue($z->open($path, ZipArchive::CREATE));
        $z->addFromString('backup.json', '[' . str_repeat('[1,2],', 1_000_000) . '[1,2]]');
        $z->close();
        $r = $this->cli($dir, 'restore', ['dense.zip', '--yes'], '24M');
        $this->assertSame([1, "Invalid backup: This is not an Apotheke backup.\n"], [$r['exit'], $r['stderr']], $r['stdout']);
        $this->assertSame($before, $this->catalog($pdo, $dir), 'dense');

        // L-451: one 48 MB value restores at 128 MB.
        $this->oneLargeNote($zip, $dir . '/large.zip', 48 * 1024 * 1024);
        $r = $this->cli($dir, 'restore', ['large.zip', '--yes'], '128M');
        $this->assertSame([0, ''], [$r['exit'], $r['stderr']], $r['stdout']);
        $this->assertSame([1, 48 * 1024 * 1024], array_map('intval', $pdo->query('SELECT COUNT(*), MAX(LENGTH(notes)) FROM items')->fetch(PDO::FETCH_NUM)));
        foreach (glob($dir . '/tmp/restore-*.part') ?: [] as $f) {
            $this->fail('a temp entry was left: ' . basename($f));
        }
    }

    /** $zip's backup with its items replaced by one item whose notes are $bytes long, written to $path. */
    private function oneLargeNote(string $zip, string $path, int $bytes): void
    {
        $json = $this->backupJson($zip, $path);
        $dump = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        unset($json);
        $items = &$dump['tables']['items'];
        $row = $items['rows'][0];
        $row[array_search('notes', $items['columns'], true)] = '@NOTES@';
        $items['rows'] = [$row];
        unset($items);
        [$head, $tail] = explode('"@NOTES@"', json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 2);
        $z = new ZipArchive();
        $this->assertTrue($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $z->addFromString('backup.json', $head . '"' . str_repeat('n', $bytes) . '"' . $tail);
        $z->close();
    }

    /** @return array{items:int,notes:int,files:list<string>} */
    private function catalog(PDO $pdo, string $dir): array
    {
        $files = [];
        foreach (['images', 'thumbs', 'files', 'originals', 'tmp'] as $sub) {
            foreach (glob($dir . '/' . $sub . '/*') ?: [] as $f) {
                $files[] = $sub . '/' . basename($f);
            }
        }
        sort($files);

        return ['items' => (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn(),
            'notes' => (int)$pdo->query('SELECT SUM(LENGTH(notes)) FROM items')->fetchColumn(), 'files' => $files];
    }

    /**
     * bin/<form>.php, and the spark command it starts, at $memory.
     *
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cli(string $dir, string $form, array $args, string $memory): array
    {
        $ini = $dir . '/ini-' . $memory;
        if (!is_dir($ini)) {
            mkdir($ini);
            file_put_contents($ini . '/memory.ini', "memory_limit=$memory\n");
        }
        $env = array_merge(getenv(), test_server_env($dir), [
            'APOTHEKE_CLOCK' => self::CLOCK,
            'APOTHEKE_APP' => 'ci4',
            'CI_ENVIRONMENT' => 'production',
            'PHP_INI_SCAN_DIR' => ':' . $ini,   // after the usual scan dir: its extensions stay
        ]);
        unset($env['APP_PASSWORD']);
        $proc = proc_open([PHP_BINARY, ROOT . '/bin/' . $form . '.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, array_map('strval', $env));
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($proc)];
    }

    private function backupJson(string $zipBytes, string $path): string
    {
        file_put_contents($path, $zipBytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::RDONLY));
        $json = $zip->getFromName('backup.json');
        $zip->close();
        unlink($path);
        $this->assertIsString($json);
        $this->assertGreaterThan(self::ITEMS * 3000, strlen($json));

        return $json;
    }

    private function seed(PDO $pdo): void
    {
        $pdo->beginTransaction();
        $item = $pdo->prepare('INSERT INTO items (id, name, quantity, notes, search, sort_key, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?, ?, ?)');
        $notes = str_repeat('n', 3000);
        for ($i = 1; $i <= self::ITEMS; $i++) {
            $name = sprintf('Item %05d', $i);
            $item->execute([$i, $name, $notes, strtolower($name), strtolower($name), '2026-01-01', '2026-01-01']);
        }
        $pdo->commit();
    }
}
