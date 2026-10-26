<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * L-370: CI4 streams backup.json into the zip row by row. 8,000 items with
 * 3 KB notes make a 24 MB backup.json, which a server or a bin/backup.php
 * limited to 24 MB can only write that way. Legacy builds it in memory, so
 * it runs at the usual limit, and CI4's backup.json must be its bytes.
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
        mkdir($dir . '/ini');
        file_put_contents($dir . '/ini/memory.ini', "memory_limit=24M\n");
        $env = array_merge(getenv(), test_server_env($dir), [
            'APOTHEKE_CLOCK' => self::CLOCK,
            'APOTHEKE_APP' => 'ci4',
            'CI_ENVIRONMENT' => 'production',
            'PHP_INI_SCAN_DIR' => ':' . $dir . '/ini',   // after the usual scan dir: its extensions stay
        ]);
        unset($env['APP_PASSWORD']);
        $proc = proc_open([PHP_BINARY, ROOT . '/bin/backup.php', 'cli.zip'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $dir, array_map('strval', $env));
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame([0, ''], [proc_close($proc), $stderr], $stdout);
        $this->assertSame(hash('sha256', $expected), hash('sha256', $this->backupJson((string)file_get_contents($dir . '/cli.zip'), $dir . '/cli-copy.zip')), 'cli');
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
