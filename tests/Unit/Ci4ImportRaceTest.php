<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * A double-submitted CSV import on legacy and CI4, on the driver of the run:
 * while the first submit's transaction is still open, the second waits for
 * it, finds the import done and answers as a later submit (303 to
 * /import?done=…), importing nothing. Legacy waits the same way since #130
 * (N-424). The interleavings of the run itself are in
 * tests/Modules/ImportExport/ImportsTest.php.
 *
 * A preview that waits for a submit the same way shows the done page on CI4
 * (N-373); legacy answers 410, because the submit has taken the file, until
 * the N-373 port to `main`.
 */
final class Ci4ImportRaceTest extends TestCase
{
    public function testASecondSubmitWaitsAndImportsNothing(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        foreach (['legacy', 'ci4'] as $app) {
            $this->race($app);
        }
    }

    private function race(string $app): void
    {
        $dir = tmpdir('import-race-' . $app);
        $server = new Server($dir, ['APOTHEKE_CLOCK' => '2026-06-15 21:30:00'], $app);
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
            $up = $client->post('/import', ['csrf' => $token], ['csv' => ['name' => 'items.csv', 'type' => 'text/csv', 'data' => "name;qty\nLadder;1\nRope;2\n"]]);
            $this->assertSame([303, '/import/1'], [$up['status'], $up['headers']['location'] ?? null], $up['body']);

            // The first submit: done in a transaction that stays open for a while.
            $summary = '{"created":2,"updated":0,"unchanged":0,"skipped":0,"made":{"location":[],"category":[],"tag":[]}}';
            $proc = proc_open([PHP_BINARY, ROOT . '/tests/Modules/_support/hold-transaction.php', (string)json_encode([["UPDATE imports SET status = 'done', result = ? WHERE id = 1", [$summary]]]), '1500'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, array_merge(getenv(), test_server_env($dir)));
            $this->assertIsResource($proc);
            $held = (string)fgets($pipes[1]);   // the other client's error output comes with its end, below
            $this->assertSame("held\n", $held);
            $t = microtime(true);
            $second = $client->post('/import/1/commit', ['csrf' => $token, 'col' => ['name', 'quantity']]);
            $waited = microtime(true) - $t;
            $other = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->assertSame("committed\n", $other);
            $this->assertSame([303, '/import?done=1'], [$second['status'], $second['headers']['location'] ?? null], $app . ': ' . substr($second['body'], 0, 2000));
            $this->assertGreaterThan(0.5, $waited, $app . ': the second submit waited for the first');
            $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM items')->fetchColumn(), $app . ': nothing imported twice');
            $this->assertStringContainsString('Imported · 2 added · 0 updated', $client->get('/import?done=1')['body']);

            // N-373: a preview that waits for a submit, which takes the file once it is done.
            $up = $client->post('/import', ['csrf' => $token], ['csv' => ['name' => 'more.csv', 'type' => 'text/csv', 'data' => "name\nHook\n"]]);
            $this->assertSame([303, '/import/2'], [$up['status'], $up['headers']['location'] ?? null], $up['body']);
            $proc = proc_open([PHP_BINARY, ROOT . '/tests/Modules/_support/hold-transaction.php', (string)json_encode([["UPDATE imports SET status = 'done', result = ? WHERE id = 2", [$summary]]]), '1500'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, array_merge(getenv(), test_server_env($dir)));
            $this->assertIsResource($proc);
            $this->assertSame("held\n", (string)fgets($pipes[1]));
            $preview = curl_init($server->base . '/import/2/preview');
            curl_setopt_array($preview, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['csrf' => $token, 'col' => ['name']]),
                CURLOPT_COOKIE => 'apothekesid=' . $sid, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
            $multi = curl_multi_init();
            curl_multi_add_handle($multi, $preview);
            // Once the preview has read the file and waits for the lock, the submit's file goes.
            $until = microtime(true) + 0.6;
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.05);
            } while (microtime(true) < $until);
            $this->assertTrue(unlink($dir . '/tmp/import-2.csv'));
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.1);
            } while ($running > 0);
            $status = (int)curl_getinfo($preview, CURLINFO_RESPONSE_CODE);
            $location = (string)curl_getinfo($preview, CURLINFO_REDIRECT_URL);
            $body = (string)curl_multi_getcontent($preview);
            curl_multi_remove_handle($multi, $preview);
            curl_multi_close($multi);
            $other = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $this->assertSame("committed\n", $other);
            $this->assertSame($app === 'ci4' ? [303, $server->base . '/import?done=2'] : [410, ''], [$status, $location], $app . ': ' . substr($body, 0, 2000));
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }
}
