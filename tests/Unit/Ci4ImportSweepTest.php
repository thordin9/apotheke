<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * N-374 on CI4: an upload left waiting for more than a day loses its file
 * on the next listing or upload; a newer one keeps it. Legacy keeps every
 * waiting file, so this is CI4 only (ci4/tests/ImportExport/ImportsTest.php
 * has the rules).
 */
final class Ci4ImportSweepTest extends TestCase
{
    public function testTheListingAndAnUploadSweepStaleUploads(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $dir = tmpdir('import-sweep');
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
            $row = $pdo->query("SELECT id, password_hash FROM users WHERE username = 'ada'")->fetch();
            $token = bin2hex(random_bytes(32));
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, 'uid|i:' . $row['id'] . ';auth_epoch|s:16:"' . substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16)
                . '";csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';');
            $client = $server->client();
            $client->cookies['apothekesid'] = $sid;
            $upload = function (string $name) use ($client, $token): void {
                $r = $client->post('/import', ['csrf' => $token], ['csv' => ['name' => $name, 'type' => 'text/csv', 'data' => "name\nRope\n"]]);
                $this->assertSame(303, $r["status"], $r["body"]);
            };
            $upload('a.csv');
            $upload('b.csv');
            $file = static fn (int $id): string => $dir . '/tmp/import-' . $id . '.csv';
            // The server's clock is 2026-06-15 21:30:00 (UTC).
            $pdo->exec("UPDATE imports SET created_at = '2026-06-14 21:29:00' WHERE id = 1");
            $pdo->exec("UPDATE imports SET created_at = '2026-06-14 21:31:00' WHERE id = 2");

            $this->assertSame(200, $client->get('/import')['status']);
            clearstatcache();
            $this->assertSame([false, true], [is_file($file(1)), is_file($file(2))], 'the listing swept only the day-old upload');
            $this->assertSame(410, $client->get('/import/1')['status'], 'its row stays; it asks for the file again');

            $pdo->exec("UPDATE imports SET created_at = '2026-06-13 08:00:00' WHERE id = 2");
            $upload('c.csv');
            clearstatcache();   // the server deleted it
            $this->assertSame([false, true], [is_file($file(2)), is_file($file(3))], 'an upload sweeps too');
            $this->assertSame(3, (int)$pdo->query('SELECT COUNT(*) FROM imports')->fetchColumn());
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}
