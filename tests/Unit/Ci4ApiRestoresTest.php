<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * The chunked restore API (POST /api/v1/restores…, backup permission): a backup sent in 64 KiB chunks restores the
 * same catalog as the one-shot upload on the Backup page; each upload is its owner's only; guests, viewers and
 * signed-out clients are refused; finished, cancelled and idle uploads leave no files.
 */
final class Ci4ApiRestoresTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private const TABLES = ['locations', 'categories', 'tags', 'items', 'item_tags', 'item_photos', 'item_attachments', 'saved_filters', 'stocktakes', 'stocktake_lines'];

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-restores');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD, 'RESTORE_CHUNK_KB' => '64'], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
        $pdo = testdb(self::$dir);
        $users = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ada', 'admin'], ['vera', 'viewer']] as [$name, $role]) {
            $users->execute([$name, $name, password_hash(self::PASSWORD, PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
        $item = $pdo->prepare('INSERT INTO items (name, quantity, notes, search, sort_key, created_at, updated_at) VALUES (?, 1, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 20; $i++) {   // notes that do not compress: a zip of several chunks
            $item->execute(['Item ' . $i, base64_encode(random_bytes(12000)), 'item ' . $i, 'item ' . $i, '2026-01-01', '2026-01-01']);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    protected function setUp(): void
    {
        foreach ($this->uploadDirs() as $left) {   // a failed test's upload
            rrmdir(self::$dir . '/uploads/restore/' . $left);
        }
    }

    public function testAChunkedRestoreRestoresWhatTheOneShotUploadDoes(): void
    {
        $admin = $this->signedIn('admin');
        $zip = $admin->get('/backup.zip')['body'];
        $this->assertGreaterThan(3 * 65536, strlen($zip), 'several chunks');
        $before = $this->dump();
        $pdo = testdb(self::$dir);

        $pdo->exec("UPDATE items SET name = 'Changed'");
        $csrf = $admin->csrf();
        $up = $admin->post('/backup/restore', ['csrf' => $csrf], ['backup' => ['name' => 'b.zip', 'type' => 'application/zip', 'data' => $zip]]);
        preg_match('/name="token" value="([a-f0-9]{32})"/', (string)$up['body'], $m);
        $r = $admin->post('/backup/restore/confirm', ['csrf' => $csrf, 'token' => $m[1] ?? '', 'confirm' => '1']);
        $this->assertSame(303, $r['status'], substr((string)$r['body'], 0, 300));
        $oneShot = $this->dump();
        $this->assertSame($before, $oneShot);

        $pdo->exec("UPDATE items SET name = 'Changed again'");
        $pdo->exec('DELETE FROM locations WHERE id NOT IN (SELECT location_id FROM items WHERE location_id IS NOT NULL)');
        $new = $this->send($admin, 'POST', '/api/v1/restores', ['size' => strlen($zip), 'sha256' => hash('sha256', $zip)]);
        $this->assertSame(201, $new['status'], $new['body']);
        ApiContract::assertMatches('POST', '/api/v1/restores', $new);
        $id = $new['json']['id'];
        $this->assertSame(['/api/v1/restores/' . $id, 'uploading', 65536, 0, 0], [$new['headers']['location'], $new['json']['status'], $new['json']['chunk_size'], $new['json']['received'], $new['json']['next_chunk']]);
        $chunks = str_split($zip, 65536);
        $last = count($chunks) - 1;
        foreach ($chunks as $n => $bytes) {
            if ($n === $last) {   // resume as a client that lost its place: ask, then send what is next
                $this->assertSame($last, $admin->rest('GET', '/api/v1/restores/' . $id)['json']['next_chunk']);
                $early = $this->chunk($admin, $id, $n, $bytes);
                $this->assertSame(200, $early['status']);
                break;
            }
            $r = $this->chunk($admin, $id, $n, $bytes);
            $this->assertSame([200, ($n + 1) * 65536], [$r['status'], $r['json']['received'] ?? null], $r['body']);
            ApiContract::assertMatches('POST', "/api/v1/restores/$id/chunks/$n", $r);
        }
        $this->assertSame([409, 'restore.conflict'], $this->code($this->chunk($admin, $id, 0, $chunks[0])), 'complete');
        $this->assertSame([409, 'restore.conflict'], $this->code($this->send($admin, 'POST', "/api/v1/restores/$id/confirm", ['confirm' => true])), 'confirm before finish');
        $dry = $this->send($admin, 'POST', "/api/v1/restores/$id/finish", []);
        $this->assertSame([200, 'ready', null, 20], [$dry['status'], $dry['json']['status'], $dry['json']['next_chunk'], $dry['json']['backup']['tables']['items'] ?? null], $dry['body']);
        ApiContract::assertMatches('POST', "/api/v1/restores/$id/finish", $dry);
        $this->assertSame([422, 'validation.failed'], $this->code($this->send($admin, 'POST', "/api/v1/restores/$id/confirm", [])), 'no confirm flag');
        $this->assertSame('Changed again', $pdo->query('SELECT name FROM items ORDER BY id LIMIT 1')->fetchColumn(), 'the dry run changed nothing');
        $done = $this->send($admin, 'POST', "/api/v1/restores/$id/confirm", ['confirm' => true]);
        $this->assertSame([200, true, 20], [$done['status'], $done['json']['restored'] ?? null, $done['json']['tables']['items'] ?? null], $done['body']);
        ApiContract::assertMatches('POST', "/api/v1/restores/$id/confirm", $done);
        $this->assertSame($oneShot, $this->dump(), 'the same catalog as the one-shot restore');
        $this->assertSame([], $this->uploadDirs(), 'no files left');
        $this->assertSame([404, 'not_found'], $this->code($admin->rest('GET', '/api/v1/restores/' . $id)));
    }

    public function testSignedOutViewerAndGuestAreRefused(): void
    {
        foreach ([[$this->client(), 401, 'auth.required'], [$this->signedIn('vera'), 403, 'permission.denied']] as [$c, $status, $code]) {
            $this->assertSame([$status, $code], $this->code($this->send($c, 'POST', '/api/v1/restores', ['size' => 10])));
            $this->assertSame([$status, $code], $this->code($c->rest('GET', '/api/v1/restores/' . str_repeat('a', 32))));
        }
        $pdo = testdb(self::$dir);
        $perms = (string)$pdo->query("SELECT perms FROM roles WHERE role_key = 'guest'")->fetchColumn();
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute(['auth_guest_disabled']);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute(['auth_guest_disabled', 'false', '2026-01-01']);
        $pdo->prepare("UPDATE roles SET perms = ? WHERE role_key = 'guest'")->execute(['["view","backup"]']);
        try {
            $r = $this->send($this->client(), 'POST', '/api/v1/restores', ['size' => 10]);
            $this->assertSame([403, 'permission.denied', 'Restores need a signed-in account.'], [$r['status'], $r['json']['code'] ?? null, $r['json']['detail'] ?? null], $r['body']);
            ApiContract::assertMatches('POST', '/api/v1/restores', $r);
        } finally {
            $pdo->prepare("UPDATE roles SET perms = ? WHERE role_key = 'guest'")->execute([$perms]);
            $pdo->prepare('UPDATE settings SET value = ? WHERE name = ?')->execute(['true', 'auth_guest_disabled']);
        }
        $this->assertSame([], $this->uploadDirs(), 'nothing stored for a guest');
        $admin = $this->signedIn('admin');
        $noToken = $admin->rest('POST', '/api/v1/restores', ['size' => 10]);
        $this->assertSame([403, 'csrf.invalid'], $this->code($noToken), 'the API CSRF check');
    }

    public function testAnotherUsersOrAMalformedIdIsNotFound(): void
    {
        $admin = $this->signedIn('admin');
        $id = $this->send($admin, 'POST', '/api/v1/restores', ['size' => 10])['json']['id'];
        $ada = $this->signedIn('ada');
        $unknown = str_repeat('0', 32);
        foreach (['GET' => '', 'DELETE' => '', 'POST /chunks/0' => '/chunks/0', 'POST /finish' => '/finish', 'POST /confirm' => '/confirm'] as $what => $tail) {
            $method = explode(' ', $what)[0];
            $call = fn (string $which): array => $tail === '/chunks/0' ? $this->chunk($ada, $which, 0, 'abc') : $this->send($ada, $method, '/api/v1/restores/' . $which . $tail, $method === 'POST' ? ['confirm' => true] : null);
            $theirs = $call($id);
            $none = $call($unknown);
            $this->assertSame([404, 'not_found'], $this->code($theirs), $what);
            $this->assertSame([$none['status'], $none['body']], [$theirs['status'], $theirs['body']], $what . ': the same answer as an unknown id');
        }
        ApiContract::assertMatches('GET', '/api/v1/restores/' . $id, $ada->rest('GET', '/api/v1/restores/' . $id));
        foreach (['..', '%2e%2e', strtoupper($id), $id . 'a', substr($id, 1), '..%2F' . $id] as $bad) {
            $this->assertSame(404, $admin->rest('GET', '/api/v1/restores/' . $bad)['status'], $bad);
        }
        $this->assertSame(0, $admin->rest('GET', '/api/v1/restores/' . $id)['json']['received'], 'the owner still has it');
        $gone = $this->send($admin, 'DELETE', '/api/v1/restores/' . $id, null);
        $this->assertSame(204, $gone['status']);
        ApiContract::assertMatches('DELETE', '/api/v1/restores/' . $id, $gone);
        $this->assertSame([], $this->uploadDirs(), 'cancelled: no files');
    }

    public function testTheCapsOrderAndIdleSweep(): void
    {
        $admin = $this->signedIn('admin');
        $this->assertSame([413, 'upload.too_large'], $this->code($this->send($admin, 'POST', '/api/v1/restores', ['size' => 520 * 1048576 + 1])));
        $this->assertSame([422, 'validation.failed'], $this->code($this->send($admin, 'POST', '/api/v1/restores', ['size' => '10'])));
        $id = $this->send($admin, 'POST', '/api/v1/restores', ['size' => 65536 + 10, 'sha256' => str_repeat('0', 64)])['json']['id'];
        $this->assertSame([409, 'restore.conflict'], $this->code($this->chunk($admin, $id, 1, str_repeat('x', 10))), 'out of order');
        $this->assertSame([413, 'upload.too_large'], $this->code($this->chunk($admin, $id, 0, str_repeat('x', 65537))), 'over the chunk size');
        $this->assertSame([422, 'validation.failed'], $this->code($this->chunk($admin, $id, 0, 'short')), 'short, not the last');
        $this->assertSame(0, $admin->rest('GET', '/api/v1/restores/' . $id)['json']['received'], 'nothing kept');
        $this->chunk($admin, $id, 0, str_repeat('x', 65536));
        $this->assertSame([409, 'restore.conflict'], $this->code($this->send($admin, 'POST', "/api/v1/restores/$id/finish", [])), 'incomplete');
        $this->chunk($admin, $id, 1, str_repeat('x', 10));
        $bad = $this->send($admin, 'POST', "/api/v1/restores/$id/finish", []);
        $this->assertSame([422, 'validation.failed'], $this->code($bad), 'sha256 mismatch');
        ApiContract::assertMatches('POST', "/api/v1/restores/$id/finish", $bad);
        $this->assertSame([], $this->uploadDirs(), 'removed');

        $id = $this->send($admin, 'POST', '/api/v1/restores', ['size' => 3])['json']['id'];
        $this->chunk($admin, $id, 0, 'abc');
        $this->assertSame([422, 'validation.failed'], $this->code($this->send($admin, 'POST', "/api/v1/restores/$id/finish", [])), 'not a backup');
        $this->assertSame([], $this->uploadDirs(), 'a refused backup is not kept');

        $id = $this->send($admin, 'POST', '/api/v1/restores', ['size' => 3])['json']['id'];
        touch(self::$dir . '/uploads/restore/' . $id . '/manifest.json', time() - 3700);
        $this->assertSame([404, 'not_found'], $this->code($admin->rest('GET', '/api/v1/restores/' . $id)), 'idle for over an hour');
        $this->assertSame([], $this->uploadDirs(), 'swept');
    }

    /** @return array{0:int,1:?string} */
    private function code(array $r): array
    {
        return [$r['status'], $r['json']['code'] ?? null];
    }

    /** @param array<string,mixed>|null $data */
    private function send(Client $c, string $method, string $path, ?array $data): array
    {
        return $c->rest($method, $path, $data, ['X-CSRF-Token: ' . $c->rest('GET', '/api/v1/session')['json']['csrf_token']]);
    }

    private function chunk(Client $c, string $id, int $n, string $bytes): array
    {
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];

        return $c->req('POST', "/api/v1/restores/$id/chunks/$n", [], ['chunk' => ['name' => 'chunk', 'type' => 'application/octet-stream', 'data' => $bytes]],
            ['Origin: ' . $c->base, 'Sec-Fetch-Site: same-origin', 'Accept: application/json', 'X-CSRF-Token: ' . $token]);
    }

    /** @return list<string> */
    private function uploadDirs(): array
    {
        return array_values(array_diff(@scandir(self::$dir . '/uploads/restore') ?: [], ['.', '..']));
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function dump(): array
    {
        $pdo = testdb(self::$dir);
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = $pdo->query('SELECT * FROM "' . $t . '" ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        }

        return $out;
    }

    private function client(): Client
    {
        $this->assertNotNull(self::$server);

        return self::$server->client();
    }

    private function signedIn(string $user): Client
    {
        $c = $this->client();
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $in = $c->rest('POST', '/api/v1/session', ['username' => $user, 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);

        return $c;
    }
}
