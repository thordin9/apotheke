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
        // A keeper may back up and restore, but not change settings (N-443).
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('keeper', 'Keeper', '[\"view\",\"backup\"]', 0, '2026-01-01 00:00:00')");
        foreach ([['ada', 'admin'], ['vera', 'viewer'], ['kim', 'keeper']] as [$name, $role]) {
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
        foreach ([1, 'true', 'yes', [true]] as $loose) {   // N-1586: only the JSON true counts
            $this->assertSame([422, 'validation.failed'], $this->code($this->send($admin, 'POST', "/api/v1/restores/$id/confirm", ['confirm' => $loose])), (string)json_encode($loose));
        }
        $this->assertSame('Changed again', $pdo->query('SELECT name FROM items ORDER BY id LIMIT 1')->fetchColumn(), 'the dry run changed nothing');
        $done = $this->send($admin, 'POST', "/api/v1/restores/$id/confirm", ['confirm' => true]);
        $this->assertSame([200, true, 20], [$done['status'], $done['json']['restored'] ?? null, $done['json']['tables']['items'] ?? null], $done['body']);
        ApiContract::assertMatches('POST', "/api/v1/restores/$id/confirm", $done);
        $this->assertSame($oneShot, $this->dump(), 'the same catalog as the one-shot restore');
        $this->assertSame([$id], $this->uploadDirs());
        $this->assertSame(['manifest.json'], array_values(array_diff(scandir(self::$dir . '/uploads/restore/' . $id) ?: [], ['.', '..'])), 'only the outcome is kept');
        // N-1588: a client that lost the answer (a proxy timeout) reads it again; a second confirm does not restore again.
        $seen = $admin->rest('GET', '/api/v1/restores/' . $id);
        $this->assertSame([200, 'restored', 20], [$seen['status'], $seen['json']['status'] ?? null, $seen['json']['result']['tables']['items'] ?? null], $seen['body']);
        ApiContract::assertMatches('GET', '/api/v1/restores/' . $id, $seen);
        $pdo->exec("UPDATE items SET name = 'After the restore'");
        $again = $this->send($admin, 'POST', "/api/v1/restores/$id/confirm", ['confirm' => true]);
        $this->assertSame([$done['status'], $done['json']], [$again['status'], $again['json']], 'the same answer');
        $this->assertSame('After the restore', $pdo->query('SELECT name FROM items ORDER BY id LIMIT 1')->fetchColumn(), 'not restored twice');
        $this->assertSame([409, 'restore.conflict'], $this->code($this->send($admin, 'POST', "/api/v1/restores/$id/finish", [])), 'finish after confirm');
        $this->assertSame(204, $this->send($admin, 'DELETE', '/api/v1/restores/' . $id, null)['status']);
        $this->assertSame([], $this->uploadDirs(), 'no files left');
        $this->assertSame([404, 'not_found'], $this->code($admin->rest('GET', '/api/v1/restores/' . $id)));
    }

    /** N-1587: the chunk size follows PHP's upload limits (here the plain PHP and cPanel defaults, 2M and 8M). */
    public function testTheChunkSizeFollowsThePhpUploadLimits(): void
    {
        $dir = tmpdir('api-restores-2m');
        $server = new Server($dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4', null, ['upload_max_filesize' => '2M', 'post_max_size' => '8M']);
        try {
            $c = $server->client();
            $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
            $this->assertSame(200, $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token])['status']);
            $new = $this->send($c, 'POST', '/api/v1/restores', ['size' => 2097152 + 10]);
            $this->assertSame([201, 2097152], [$new['status'], $new['json']['chunk_size'] ?? null], $new['body']);
            $id = $new['json']['id'];
            $r = $this->chunk($c, $id, 0, str_repeat('x', 2097152));
            $this->assertSame([200, 2097152], [$r['status'], $r['json']['received'] ?? null], $r['body']);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
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

    /** N-1595: a full cap is 503 restore.busy with Retry-After; too little disk beside what open uploads expect (N-1593) is 507. */
    public function testAFullCapOrDiskIsRefusedWithItsCode(): void
    {
        $admin = $this->signedIn('admin');
        for ($i = 0; $i < 8; $i++) {
            $this->plant('user:999:' . $i, ['size' => 3]);
        }
        $busy = $this->send($admin, 'POST', '/api/v1/restores', ['size' => 3]);
        $this->assertSame([503, 'restore.busy', '60'], [...$this->code($busy), $busy['headers']['retry-after'] ?? null], $busy['body']);
        ApiContract::assertMatches('POST', '/api/v1/restores', $busy);
        foreach ($this->uploadDirs() as $d) {
            rrmdir(self::$dir . '/uploads/restore/' . $d);
        }
        $this->plant('user:999:big', ['size' => PHP_INT_MAX >> 1]);   // more than any disk, still expected
        $full = $this->send($admin, 'POST', '/api/v1/restores', ['size' => 3]);
        $this->assertSame([507, 'storage.insufficient', null], [...$this->code($full), $full['headers']['retry-after'] ?? null], $full['body']);
        ApiContract::assertMatches('POST', '/api/v1/restores', $full);
        $this->assertCount(1, $this->uploadDirs(), 'nothing made');
    }

    /** N-1591: a create.lock that is a link is the API's fixed 500, without the lock's path. */
    public function testALinkedCreateLockIsAServerError(): void
    {
        $admin = $this->signedIn('admin');
        $lock = self::$dir . '/uploads/restore/create.lock';
        $this->assertSame(201, $this->send($admin, 'POST', '/api/v1/restores', ['size' => 3])['status']);
        rename($lock, $lock . '.real');
        symlink($lock . '.real', $lock);
        try {
            $r = $this->send($admin, 'POST', '/api/v1/restores', ['size' => 3]);
            $this->assertSame([500, 'server_error'], $this->code($r), $r['body']);
            $this->assertStringNotContainsString('lock', $r['body']);
            $this->assertStringNotContainsString('DATA_DIR', $r['body']);
        } finally {
            unlink($lock);
            rename($lock . '.real', $lock);
        }
    }

    /** N-1594: an upload is the account's, not only its id's: the same id with another creation time is not the owner. */
    public function testTheOwnerIsTheAccountNotOnlyItsId(): void
    {
        $kim = $this->signedIn('kim');
        $id = $this->send($kim, 'POST', '/api/v1/restores', ['size' => 3])['json']['id'];
        $pdo = testdb(self::$dir);
        $pdo->exec("UPDATE users SET created_at = '2026-02-02 00:00:00' WHERE username = 'kim'");
        try {
            $this->assertSame([404, 'not_found'], $this->code($kim->rest('GET', '/api/v1/restores/' . $id)), 'another account with the same id');
            $this->assertSame([404, 'not_found'], $this->code($this->send($kim, 'DELETE', '/api/v1/restores/' . $id, null)));
        } finally {
            $pdo->exec("UPDATE users SET created_at = '2026-01-01 00:00:00' WHERE username = 'kim'");
        }
        $this->assertSame(200, $kim->rest('GET', '/api/v1/restores/' . $id)['status'], 'its own account again');
        $this->assertSame(204, $this->send($kim, 'DELETE', '/api/v1/restores/' . $id, null)['status']);
    }

    /** N-1594: a confirm that stopped while applying (status applying, nobody on it) reads as interrupted and is not restored again. */
    public function testAStoppedConfirmReadsAsInterrupted(): void
    {
        $admin = $this->signedIn('admin');
        $id = $this->plant($this->owner('admin'), ['size' => 3, 'received' => 3, 'next' => 1, 'status' => 'applying', 'fingerprint' => str_repeat('a', 64)]);
        file_put_contents(self::$dir . '/uploads/restore/' . $id . '/backup.zip', 'abc');
        $r = $admin->rest('GET', '/api/v1/restores/' . $id);
        $this->assertSame([200, 'interrupted', str_repeat('a', 64)], [$r['status'], $r['json']['status'] ?? null, $r['json']['result']['sha256'] ?? null], $r['body']);
        ApiContract::assertMatches('GET', '/api/v1/restores/' . $id, $r);
        $again = $this->send($admin, 'POST', "/api/v1/restores/$id/confirm", ['confirm' => true]);
        $this->assertSame([409, 'restore.conflict'], $this->code($again), $again['body']);
        ApiContract::assertMatches('POST', "/api/v1/restores/$id/confirm", $again);
        $this->assertFileDoesNotExist(self::$dir . '/uploads/restore/' . $id . '/backup.zip');
        $this->assertSame(204, $this->send($admin, 'DELETE', '/api/v1/restores/' . $id, null)['status']);
    }

    public function testTheBackupsSettingsNeedTheSettingsPermission(): void
    {
        $pdo = testdb(self::$dir);
        $setting = static function (string $value) use ($pdo): void {
            $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute(['app_name']);
            $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute(['app_name', (string)json_encode($value), '2026-01-01']);
        };
        $name = static fn (): string => (string)$pdo->query("SELECT value FROM settings WHERE name = 'app_name'")->fetchColumn();
        $saved = 'Shed ' . bin2hex(random_bytes(4));
        $setting($saved);
        $zip = $this->signedIn('admin')->get('/backup.zip')['body'];
        $setting('Changed');
        $warning = 'The settings were not restored: that needs the permission to change settings.';

        $kept = $this->restoreInChunks($this->signedIn('kim'), $zip);
        $this->assertSame([200, true], [$kept['status'], $kept['json']['restored'] ?? null], $kept['body']);
        $this->assertContains($warning, $kept['json']['warnings'], 'a keeper: the catalog, not the settings');
        $this->assertSame((string)json_encode('Changed'), $name());

        $all = $this->restoreInChunks($this->signedIn('ada'), $zip);
        $this->assertSame(200, $all['status'], $all['body']);
        $this->assertNotContains($warning, $all['json']['warnings']);
        $this->assertSame((string)json_encode($saved), $name(), 'an admin: the settings too');
        $this->assertSame([], $this->uploadDirs());
    }

    /** @return array<string,mixed> the confirm answer of $zip sent through the chunked API */
    private function restoreInChunks(Client $c, string $zip): array
    {
        $id = $this->send($c, 'POST', '/api/v1/restores', ['size' => strlen($zip)])['json']['id'];
        foreach (str_split($zip, 65536) as $n => $bytes) {
            $this->assertSame(200, $this->chunk($c, $id, $n, $bytes)['status']);
        }
        $this->assertSame(200, $this->send($c, 'POST', "/api/v1/restores/$id/finish", [])['status']);
        $done = $this->send($c, 'POST', "/api/v1/restores/$id/confirm", ['confirm' => true]);
        $this->assertSame(204, $this->send($c, 'DELETE', '/api/v1/restores/' . $id, null)['status']);

        return $done;
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

    /**
     * An upload folder with this manifest, as another request would leave it.
     *
     * @param array<string,mixed> $m
     */
    private function plant(string $owner, array $m): string
    {
        $id = bin2hex(random_bytes(16));
        @mkdir(self::$dir . '/uploads/restore', 0770, true);
        mkdir(self::$dir . '/uploads/restore/' . $id, 0770);
        file_put_contents(self::$dir . '/uploads/restore/' . $id . '/manifest.json', (string)json_encode($m + ['owner' => $owner, 'size' => 3, 'sha256' => null,
            'chunk_size' => 65536, 'received' => 0, 'next' => 0, 'status' => 'uploading']));

        return $id;
    }

    /** the owner key of account $name, as Services::restoreUploads() makes it */
    private function owner(string $name): string
    {
        $row = testdb(self::$dir)->query('SELECT id, created_at FROM users WHERE username = ' . testdb(self::$dir)->quote($name))->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return 'user:' . $row['id'] . ':' . hash('sha256', (string)$row['created_at']);
    }

    /** @return list<string> */
    private function uploadDirs(): array
    {
        return array_values(preg_grep('/^[a-f0-9]{32}$/D', @scandir(self::$dir . '/uploads/restore') ?: []) ?: []);
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
