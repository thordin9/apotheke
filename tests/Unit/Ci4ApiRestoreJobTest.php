<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Batch-only confirm through the HTTP stack. APOTHEKE_BATCH_EXECUTOR=1 makes Services::stagedExecutor()
 * return LocalBatchExecutor on this process's database. The response is the real controller's 202.
 */
final class Ci4ApiRestoreJobTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-restore-job');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD, 'APOTHEKE_BATCH_EXECUTOR' => '1'], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');
        $pdo = testdb(self::$dir);
        $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['ada', 'ada', password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'admin', 'active', '2026-01-01 00:00:00']);
        $pdo->prepare('INSERT INTO items (name, quantity, notes, search, sort_key, created_at, updated_at) VALUES (?, 1, ?, ?, ?, ?, ?)')
            ->execute(['Saw', '', 'saw', 'saw', '2026-01-01', '2026-01-01']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    public function testConfirmReturnsTheJobAndASecondConfirmDoesNotRestoreAgain(): void
    {
        $ada = $this->signedIn('ada');
        $zip = $ada->get('/backup.zip')['body'];
        $this->assertNotSame('', $zip);
        testdb(self::$dir)->exec("UPDATE items SET name = 'Changed'");
        $started = $this->send($ada, 'POST', '/api/v1/restores', ['size' => strlen($zip), 'sha256' => hash('sha256', $zip)]);
        $this->assertSame(201, $started['status'], $started['body']);
        $id = $started['json']['id'];
        $chunk = (int)$started['json']['chunk_size'];
        foreach (str_split($zip, $chunk) as $n => $bytes) {
            $part = $this->chunk($ada, $id, $n, $bytes);
            $this->assertSame(200, $part['status'], $part['body']);
        }
        $dry = $this->send($ada, 'POST', '/api/v1/restores/' . $id . '/finish', []);
        $this->assertSame(200, $dry['status'], $dry['body']);
        $confirm = $this->send($ada, 'POST', '/api/v1/restores/' . $id . '/confirm', ['confirm' => true]);
        $this->assertSame(202, $confirm['status'], $confirm['body']);
        $job = (int)($confirm['json']['id'] ?? 0);
        $this->assertSame(['restore.confirm', 'queued'], [$confirm['json']['type'] ?? null, $confirm['json']['status'] ?? null]);
        $this->assertSame('/api/v1/jobs/' . $job, $confirm['headers']['location'] ?? null);
        $again = $this->send($ada, 'POST', '/api/v1/restores/' . $id . '/confirm', ['confirm' => true]);
        $this->assertSame([202, $job], [$again['status'], (int)($again['json']['id'] ?? 0)], $again['body']);
        $this->assertSame(1, (int)testdb(self::$dir)->query('SELECT COUNT(*) FROM jobs')->fetchColumn());

        $this->work();
        $seen = $ada->rest('GET', '/api/v1/jobs/' . $job);
        $this->assertSame(200, $seen['status'], $seen['body']);
        $this->assertSame('done', $seen['json']['status'] ?? null);
        $this->assertSame(['done' => 1, 'total' => 1], $seen['json']['progress'] ?? null);
        $this->assertSame(true, $seen['json']['result']['restored'] ?? null);
        $this->assertNull($seen['json']['error'] ?? null);
        $this->assertSame('Saw', testdb(self::$dir)->query('SELECT name FROM items ORDER BY id LIMIT 1')->fetchColumn());

        testdb(self::$dir)->exec("UPDATE items SET name = 'After'");
        $kept = $this->send($ada, 'POST', '/api/v1/restores/' . $id . '/confirm', ['confirm' => true]);
        $this->assertSame(200, $kept['status'], $kept['body']);
        $this->assertSame(true, $kept['json']['restored'] ?? null);
        $this->assertSame('After', testdb(self::$dir)->query('SELECT name FROM items ORDER BY id LIMIT 1')->fetchColumn(), 'the stored confirm does not restore again');
        $this->assertSame(1, (int)testdb(self::$dir)->query("SELECT COUNT(*) FROM jobs WHERE type = 'restore.confirm'")->fetchColumn());
    }

    private function work(): void
    {
        $proc = proc_open(
            [PHP_BINARY, ROOT . '/spark', 'jobs:work', '--once'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            ROOT,
            array_merge(getenv() ?: [], test_server_env(self::$dir, ['APP_PASSWORD' => self::PASSWORD, 'APOTHEKE_BATCH_EXECUTOR' => '1'])),
        );
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), $out);
    }

    /** @param array<string,mixed>|null $data */
    private function send(Client $client, string $method, string $path, ?array $data): array
    {
        return $client->rest($method, $path, $data, ['X-CSRF-Token: ' . $client->rest('GET', '/api/v1/session')['json']['csrf_token']]);
    }

    private function chunk(Client $client, string $id, int $n, string $bytes): array
    {
        $token = $client->rest('GET', '/api/v1/session')['json']['csrf_token'];

        return $client->req('POST', "/api/v1/restores/$id/chunks/$n", [], ['chunk' => ['name' => 'chunk', 'type' => 'application/octet-stream', 'data' => $bytes]],
            ['Origin: ' . $client->base, 'Sec-Fetch-Site: same-origin', 'Accept: application/json', 'X-CSRF-Token: ' . $token]);
    }

    private function signedIn(string $user): Client
    {
        $this->assertNotNull(self::$server);
        $client = self::$server->client();
        $token = $client->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $in = $client->rest('POST', '/api/v1/session', ['username' => $user, 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);

        return $client;
    }
}
