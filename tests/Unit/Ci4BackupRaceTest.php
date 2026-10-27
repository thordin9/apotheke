<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * A restore on CI4 while another client's write is still open, on the
 * driver of the run: the restore takes its locks first, so it waits for
 * that write, then replaces it with the rest of the catalog. The catalog
 * ends as the backup holds it, with no row of the other write left over
 * (on PostgreSQL that row would otherwise survive the restore's delete).
 */
final class Ci4BackupRaceTest extends TestCase
{
    public function testARestoreWaitsForAnOpenWriteAndReplacesIt(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $dir = tmpdir('backup-race');
        $server = new Server($dir, ['APOTHEKE_CLOCK' => '2026-06-15 21:30:00'], 'ci4');
        try {
            $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
            $pdo = testdb($dir);
            $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute(['ada', 'ada', password_hash('long-enough', PASSWORD_DEFAULT), 'admin', 'active', '2026-01-01 00:00:00']);
            foreach (['auth_enabled' => true, 'auth_guest_disabled' => true] as $k => $v) {
                $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$k]);
                $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$k, json_encode($v), '2026-01-01']);
            }
            $pdo->exec("INSERT INTO items (name, quantity, notes, search, sort_key, created_at, updated_at) VALUES ('Ladder', 1, '', 'ladder', 'ladder', '2026-01-01', '2026-01-01'), ('Rope', 2, '', 'rope', 'rope', '2026-01-01', '2026-01-01')");
            $row = $pdo->query("SELECT id, password_hash FROM users WHERE username = 'ada'")->fetch();
            $token = bin2hex(random_bytes(32));
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, 'uid|i:' . $row['id'] . ';auth_epoch|s:16:"' . substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16)
                . '";csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';');
            $client = $server->client();
            $client->cookies['apothekesid'] = $sid;
            $zip = $client->get('/backup.zip');
            $this->assertSame(200, $zip['status'], substr($zip['body'], 0, 2000));
            $up = $client->post('/backup/restore', ['csrf' => $token], ['backup' => ['name' => 'backup.zip', 'type' => 'application/zip', 'data' => $zip['body']]]);
            $this->assertSame(200, $up['status'], substr($up['body'], 0, 2000));
            $this->assertSame(1, preg_match('/name="token" value="([a-f0-9]{32})"/', $up['body'], $m));
            $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");

            // The other client: a new item, in a transaction that stays open for a while.
            $proc = proc_open([PHP_BINARY, ROOT . '/ci4/tests/_support/hold-transaction.php',
                (string)json_encode([["INSERT INTO items (id, name, quantity, notes, search, sort_key, created_at, updated_at) VALUES (50, 'Late', 1, '', 'late', 'late', '2026-01-01', '2026-01-01')", []]]), '1500'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, array_merge(getenv(), test_server_env($dir)));
            $this->assertIsResource($proc);
            $this->assertSame("held\n", (string)fgets($pipes[1]));
            $t = microtime(true);
            $restore = $client->post('/backup/restore/confirm', ['csrf' => $token, 'token' => $m[1], 'confirm' => '1']);
            $waited = microtime(true) - $t;
            $other = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->assertSame("committed\n", $other);
            $this->assertSame([303, '/backup?restored=1'], [$restore['status'], $restore['headers']['location'] ?? null], substr($restore['body'], 0, 2000));
            $this->assertGreaterThan(0.5, $waited, 'the restore waited for the open write');
            $this->assertSame([[1, 'Ladder'], [2, 'Rope']], array_map(static fn (array $r): array => [(int)$r[0], $r[1]], $pdo->query('SELECT id, name FROM items ORDER BY id')->fetchAll(PDO::FETCH_NUM)), 'the catalog is the backup');
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}
