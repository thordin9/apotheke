<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Sign-in limits, the last admin, and photo writes, over HTTP against `php -S` with workers, on the configured
 * driver. Requests that must overlap are sent at the same time on raw sockets, each in its own session.
 */
final class HardeningHttpTest extends TestCase
{
    private string $dir = '';
    private ?Server $server = null;
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        putenv('TEST_DB_DRIVER=' . db_driver());
        $this->dir = tmpdir('hardening');
        $this->server = new Server($this->dir, ['PHP_CLI_SERVER_WORKERS' => '8']);
        $this->assertSame(200, $this->server->client()->get('/healthz')['status'], (string)@file_get_contents($this->server->log));
        $this->pdo = testdb($this->dir);
        foreach (['ada' => 'admin', 'bea' => 'admin'] as $name => $role) {
            $this->pdo->prepare("INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, 'active', '2026-01-01 00:00:00')")
                ->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role]);
        }
        $this->setting('auth_enabled', true);
        $this->setting('auth_guest_disabled', true);
        $this->pdo->exec("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Box', 1, '', 'box', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->pdo = null;
        testdb_drop($this->dir);
        rrmdir($this->dir);
        @unlink($this->dir . '.server.log');
        putenv('TEST_DB_DRIVER');
    }

    /** Wrong passwords sent at once for one account one below the limit: no more than one gets through. */
    public function testTheSignInLimitHoldsForRequestsAtOnce(): void
    {
        $key = substr(hash('sha256', "127.0.0.1\0ada"), 0, 32);
        for ($round = 1; $round <= 4; $round++) {
            $this->pdo->exec('DELETE FROM auth_attempts');
            $st = $this->pdo->prepare("INSERT INTO auth_attempts (kind, client, at) VALUES ('login', ?, ?)");
            for ($i = 0; $i < 9; $i++) $st->execute([$key, time()]);
            $requests = [];
            for ($i = 0; $i < 8; $i++) {
                $c = $this->server->client();
                $requests[] = [$c, 'POST', '/login', ['csrf' => $this->loginCsrf($c), 'username' => 'ada', 'password' => 'wrong-' . $i]];
            }
            $statuses = array_count_values(array_column($this->parallel($requests), 'status'));
            $this->assertSame(8, ($statuses[401] ?? 0) + ($statuses[429] ?? 0), json_encode($statuses));
            $this->assertLessThanOrEqual(1, $statuses[401] ?? 0, "round $round: " . json_encode($statuses));
            $this->assertLessThanOrEqual(10, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login'")->fetchColumn());
        }
    }

    /** The current-password check on Your account is limited like a sign-in for that account. */
    public function testTheCurrentPasswordCheckIsLimited(): void
    {
        $c = $this->signIn('ada');
        for ($i = 0; $i < 10; $i++) {
            $r = $c->post('/account', ['csrf' => $c->csrf(), 'current' => 'wrong-' . $i, 'new' => 'another-one', 'confirm' => 'another-one']);
            $this->assertSame(403, $r['status'], "try $i");
        }
        $r = $c->post('/account', ['csrf' => $c->csrf(), 'current' => 'long-enough', 'new' => 'another-one', 'confirm' => 'another-one']);
        $this->assertSame(429, $r['status']);
        $this->assertTrue(password_verify('long-enough', (string)$this->pdo->query("SELECT password_hash FROM users WHERE username = 'ada'")->fetchColumn()), 'unchanged');
        $this->pdo->exec('DELETE FROM auth_attempts');
        $r = $c->post('/account', ['csrf' => $c->csrf(), 'current' => 'long-enough', 'new' => 'another-one', 'confirm' => 'another-one']);
        $this->assertSame(303, $r['status']);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM auth_attempts')->fetchColumn(), 'a right password clears the count');
    }

    /** Sign-in attempts for every name from one address have a cap; a successful sign-in takes back only its own try. */
    public function testSignInsFromOneAddressHaveACap(): void
    {
        $key = substr(hash('sha256', '127.0.0.1'), 0, 32);
        $fill = function (int $n) use ($key): void {
            $st = $this->pdo->prepare("INSERT INTO auth_attempts (kind, client, at) VALUES ('login_address', ?, ?)");
            for ($i = 0; $i < $n; $i++) $st->execute([$key, time()]);
        };
        $fill(29);
        $c = $this->server->client();
        $r = $c->post('/login', ['csrf' => $this->loginCsrf($c), 'username' => 'nobody-' . bin2hex(random_bytes(3)), 'password' => 'wrong-pass']);
        $this->assertSame(401, $r['status'], 'the 30th try');
        $c = $this->server->client();
        $r = $c->post('/login', ['csrf' => $this->loginCsrf($c), 'username' => 'ada', 'password' => 'long-enough']);
        $this->assertSame(429, $r['status'], 'the 31st, another name');
        $this->assertStringContainsString('Too many attempts from this address', $r['body']);
        $this->pdo->exec('DELETE FROM auth_attempts');
        $fill(29);
        $this->signIn('ada');
        $this->assertSame(29, (int)$this->pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login_address'")->fetchColumn(), 'the others stay');
        $c = $this->server->client();
        $this->assertSame(401, $c->post('/login', ['csrf' => $this->loginCsrf($c), 'username' => 'bea', 'password' => 'wrong-pass'])['status'], 'the 30th');
        $c = $this->server->client();
        $this->assertSame(429, $c->post('/login', ['csrf' => $this->loginCsrf($c), 'username' => 'ada', 'password' => 'long-enough'])['status'], 'still capped');
    }

    /** Changing your password and turning sign-in off check the current password with the sign-in limit. */
    public function testTheSecurityPasswordFormIsLimited(): void
    {
        foreach (['change' => ['new' => 'another-one', 'confirm' => 'another-one'], 'disable' => []] as $mode => $extra) {
            $this->pdo->exec('DELETE FROM auth_attempts');
            $c = $this->signIn('ada');
            for ($i = 0; $i < 10; $i++) {
                $r = $c->post('/settings/password', ['csrf' => $c->csrf(), 'mode' => $mode, 'current' => 'wrong-' . $i] + $extra);
                $this->assertSame(403, $r['status'], "$mode: try $i");
            }
            $r = $c->post('/settings/password', ['csrf' => $c->csrf(), 'mode' => $mode, 'current' => 'long-enough'] + $extra);
            $this->assertSame(429, $r['status'], $mode);
            $this->assertTrue(password_verify('long-enough', (string)$this->pdo->query("SELECT password_hash FROM users WHERE username = 'ada'")->fetchColumn()), "$mode: unchanged");
            $this->assertSame('true', (string)$this->pdo->query("SELECT value FROM settings WHERE name = 'auth_enabled'")->fetchColumn(), "$mode: still on");
        }
    }

    /** With sign-in off, the admin password that turns it back on is limited like a sign-in. */
    public function testTurningSignInBackOnIsLimited(): void
    {
        $this->setting('auth_enabled', false);
        $c = $this->server->client();
        for ($i = 0; $i < 10; $i++) {
            $r = $c->post('/settings/password', ['csrf' => $c->csrf(), 'mode' => 'change', 'username' => 'ada', 'new' => 'wrong-' . $i]);
            $this->assertSame(403, $r['status'], "try $i");
        }
        $r = $c->post('/settings/password', ['csrf' => $c->csrf(), 'mode' => 'change', 'username' => 'ada', 'new' => 'long-enough']);
        $this->assertSame(429, $r['status']);
        $this->assertSame('false', (string)$this->pdo->query("SELECT value FROM settings WHERE name = 'auth_enabled'")->fetchColumn(), 'still off');
        $this->pdo->exec('DELETE FROM auth_attempts');
        $r = $c->post('/settings/password', ['csrf' => $c->csrf(), 'mode' => 'change', 'username' => 'ada', 'new' => 'long-enough']);
        $this->assertSame(303, $r['status']);
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM auth_attempts')->fetchColumn(), 'a right password clears the count');
    }

    /** Two admins demoting each other at the same time: one admin always stays. */
    public function testTwoAdminsDemotingEachOtherKeepAnAdmin(): void
    {
        $ids = $this->pdo->query('SELECT username, id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
        for ($round = 1; $round <= 8; $round++) {
            $this->pdo->exec("UPDATE users SET role = 'admin'");
            $a = $this->signIn('ada');
            $b = $this->signIn('bea');
            $answers = $this->parallel([
                [$a, 'POST', '/settings/users/' . $ids['bea'] . '/role', ['csrf' => $a->csrf(), 'role' => 'editor']],
                [$b, 'POST', '/settings/users/' . $ids['ada'] . '/role', ['csrf' => $b->csrf(), 'role' => 'editor']],
            ]);
            foreach ($answers as $x) $this->assertContains($x['status'], [303, 403, 409], "round $round: " . substr($x['body'], 0, 200));
            $this->assertGreaterThanOrEqual(1, (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn(), "round $round");
        }
    }

    /** The password checks outside the sign-in form share the per-address cap; a success takes back only its own try. */
    public function testPasswordChecksHaveTheAddressCap(): void
    {
        $key = substr(hash('sha256', '127.0.0.1'), 0, 32);
        $fill = function (int $n) use ($key): void {
            $this->pdo->exec('DELETE FROM auth_attempts');
            $st = $this->pdo->prepare("INSERT INTO auth_attempts (kind, client, at) VALUES ('login_address', ?, ?)");
            for ($i = 0; $i < $n; $i++) $st->execute([$key, time()]);
        };
        $count = fn(): int => (int)$this->pdo->query("SELECT COUNT(*) FROM auth_attempts WHERE kind = 'login_address'")->fetchColumn();
        $c = $this->signIn('ada');
        $fill(30);
        $r = $c->post('/account', ['csrf' => $c->csrf(), 'current' => 'long-enough', 'new' => 'another-one', 'confirm' => 'another-one']);
        $this->assertSame(429, $r['status'], '/account');
        $r = $c->post('/settings/password', ['csrf' => $c->csrf(), 'mode' => 'disable', 'current' => 'long-enough']);
        $this->assertSame(429, $r['status'], 'turning sign-in off');
        $fill(5);
        $r = $c->post('/account', ['csrf' => $c->csrf(), 'current' => 'long-enough', 'new' => 'another-one', 'confirm' => 'another-one']);
        $this->assertSame(303, $r['status']);
        $this->assertSame(5, $count(), 'only its own row is taken back');

        $this->setting('auth_enabled', false);
        $g = $this->server->client();
        $fill(30);
        $r = $g->post('/settings/password', ['csrf' => $g->csrf(), 'mode' => 'change', 'username' => 'ada', 'new' => 'another-one']);
        $this->assertSame(429, $r['status'], 'turning sign-in on');
        $this->assertSame('false', (string)$this->pdo->query("SELECT value FROM settings WHERE name = 'auth_enabled'")->fetchColumn());
        $fill(5);
        $r = $g->post('/settings/password', ['csrf' => $g->csrf(), 'mode' => 'change', 'username' => 'ada', 'new' => 'another-one']);
        $this->assertSame(303, $r['status']);
        $this->assertSame(5, $count());
    }

    /** A sign-in name with a null character anywhere never signs in, and the account's limit still applies. */
    public function testSignInNamesWithANullCharacterAreRefused(): void
    {
        foreach (["ada\0x", "\0ada", "ada\0", "ADA\0"] as $name) {
            $c = $this->server->client();
            $r = $c->post('/login', ['csrf' => $this->loginCsrf($c), 'username' => $name, 'password' => 'long-enough']);
            $this->assertSame(401, $r['status'], json_encode($name));
            $this->assertStringContainsString('Wrong user name or password.', $r['body']);
            $this->assertNotSame(200, $c->get('/')['status'], json_encode($name) . ': not signed in');
        }
        $this->pdo->exec('DELETE FROM auth_attempts');
        $key = substr(hash('sha256', "127.0.0.1\0ada"), 0, 32);
        $st = $this->pdo->prepare("INSERT INTO auth_attempts (kind, client, at) VALUES ('login', ?, ?)");
        for ($i = 0; $i < 10; $i++) $st->execute([$key, time()]);
        $c = $this->server->client();
        $this->assertSame(429, $c->post('/login', ['csrf' => $this->loginCsrf($c), 'username' => "ada\0", 'password' => 'long-enough'])['status'], 'same account limit');
        $this->pdo->exec('DELETE FROM auth_attempts');
        $this->signIn('ada');

        $this->setting('auth_enabled', false);
        $users = (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        foreach (["ada\0x", "\0ada", "ada\0"] as $name) {
            $g = $this->server->client();
            $r = $g->post('/settings/password', ['csrf' => $g->csrf(), 'mode' => 'change', 'username' => $name, 'new' => 'long-enough', 'confirm' => 'long-enough']);
            $this->assertSame(403, $r['status'], 'turning sign-in on as ' . json_encode($name));
        }
        $this->assertSame('false', (string)$this->pdo->query("SELECT value FROM settings WHERE name = 'auth_enabled'")->fetchColumn(), 'still off');
        $this->assertSame($users, (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'no account created');
    }

    /** Two admins deleting each other, or one demoting while the other deletes, at the same time: one admin stays. */
    public function testDeletesAndDemotionsAtOnceKeepAnAdmin(): void
    {
        foreach (['delete', 'role'] as $other) {
            for ($round = 1; $round <= 6; $round++) {
                $this->pdo->exec('DELETE FROM users');
                foreach (['ada', 'bea'] as $name) {
                    $this->pdo->prepare("INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, 'admin', 'active', '2026-01-01 00:00:00')")
                        ->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT)]);
                }
                $ids = $this->pdo->query('SELECT username, id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
                $a = $this->signIn('ada');
                $b = $this->signIn('bea');
                $answers = $this->parallel([
                    [$a, 'POST', '/settings/users/' . $ids['bea'] . '/delete', ['csrf' => $a->csrf()]],
                    [$b, 'POST', '/settings/users/' . $ids['ada'] . '/' . $other, ['csrf' => $b->csrf(), 'role' => 'editor']],
                ]);
                foreach ($answers as $x) $this->assertContains($x['status'], [303, 403, 404, 409], "$other round $round: " . substr($x['body'], 0, 200));
                $this->assertGreaterThanOrEqual(1, (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn(), "$other round $round");
            }
        }
    }

    /** Photo adds to one item at the same time stay within the photo limit. */
    public function testPhotoAddsAtOnceKeepTheLimit(): void
    {
        $this->setting('max_photos', 2);
        $jpg = img_jpeg(64, 48);
        for ($round = 1; $round <= 4; $round++) {
            $this->pdo->exec('DELETE FROM item_photos');
            $requests = [];
            for ($i = 0; $i < 6; $i++) {
                $c = $this->signIn('ada');
                $requests[] = [$c, 'POST', '/item/1/photos', ['csrf' => $c->csrf()], [], ['photo' => jpg_file($jpg)]];
            }
            $answers = $this->parallel($requests);
            foreach ($answers as $x) $this->assertContains($x['status'], [303, 400, 422], "round $round: " . strip_tags(substr($x['body'], -600)));
            $this->assertLessThanOrEqual(2, (int)$this->pdo->query('SELECT COUNT(*) FROM item_photos')->fetchColumn(), "round $round");
        }
    }

    /** A save that fails keeps no stored photo and no new location; a refused photo keeps the new location. */
    public function testAFailedSaveKeepsNoPhotoAndNoNewLocation(): void
    {
        $c = $this->signIn('ada');
        $files = $this->files();
        $this->failActivity(true);
        $r = $c->post('/save', ['csrf' => $c->csrf(), 'name' => 'Lamp', 'new_location' => 'Cellar'], ['photo' => jpg_file(img_jpeg(64, 48))]);
        $this->failActivity(false);
        $this->assertSame(500, $r['status']);
        $this->assertSame($files, $this->files(), 'no files');
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM locations WHERE name = 'Cellar'")->fetchColumn());
        $this->assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM items WHERE name = 'Lamp'")->fetchColumn());

        $r = $c->post('/save', ['csrf' => $c->csrf(), 'name' => 'Lamp', 'new_location' => 'Attic'], ['photo' => jpg_file('not an image', 'x.jpg')]);
        $this->assertSame(422, $r['status']);
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM locations WHERE name = 'Attic'")->fetchColumn(), 'kept, as before');
        $this->assertSame($files, $this->files());

        $r = $c->post('/save', ['csrf' => $c->csrf(), 'name' => 'Lamp', 'new_location' => 'Cellar'], ['photo' => jpg_file(img_jpeg(64, 48))]);
        $this->assertSame(303, $r['status']);
        $this->assertCount(count($files) + 4, $this->files(), 'served copy, thumbnail, original and its map entry');
    }

    /** A photo add whose row cannot be written keeps no files. */
    public function testAFailedPhotoAddKeepsNoFiles(): void
    {
        $c = $this->signIn('ada');
        $files = $this->files();
        $this->failActivity(true);
        $r = $c->post('/item/1/photos', ['csrf' => $c->csrf()], ['photo' => jpg_file(img_jpeg(64, 48))]);
        $this->failActivity(false);
        $this->assertSame(500, $r['status']);
        $this->assertSame($files, $this->files());
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM item_photos')->fetchColumn());
    }

    /** Two crop/rotate saves of one photo at the same time: the photo keeps its original and no file is left over. */
    public function testEditsAtOnceKeepTheOriginal(): void
    {
        $c = $this->signIn('ada');
        $this->assertSame(303, $c->post('/item/1/photos', ['csrf' => $c->csrf()], ['photo' => jpg_file(img_jpeg(1600, 1200))])['status']);
        $pid = (int)$this->pdo->query('SELECT id FROM item_photos')->fetchColumn();
        for ($round = 1; $round <= 6; $round++) {
            $a = $this->signIn('ada');
            $b = $this->signIn('ada');
            $answers = $this->parallel([
                [$a, 'POST', "/item/1/photos/$pid/edit", ['csrf' => $a->csrf(), 'rotate' => '90'], ['X-Requested-With: fetch']],
                [$b, 'POST', "/item/1/photos/$pid/edit", ['csrf' => $b->csrf(), 'rotate' => '90'], ['X-Requested-With: fetch']],
            ]);
            foreach ($answers as $x) $this->assertContains($x['status'], [200, 500], "round $round: " . $x['body']);
            $this->assertContains(200, array_column($answers, 'status'), "round $round");
            $file = (string)$this->pdo->query("SELECT file FROM item_photos WHERE id = $pid")->fetchColumn();
            $this->assertNotNull(test_original($this->dir, $file), "round $round: the photo has its original");
            $this->assertSame([$file], $this->served(), "round $round: no other served file");
            $this->assertCount(1, glob($this->dir . '/originals/*.*') ?: [], "round $round: one original");
        }
    }

    private function signIn(string $name): Client
    {
        $c = $this->server->client();
        $r = $c->post('/login', ['csrf' => $this->loginCsrf($c), 'username' => $name, 'password' => 'long-enough']);
        $this->assertSame(303, $r['status'], $name . ': ' . substr($r['body'], 0, 300));
        return $c;
    }

    private function loginCsrf(Client $c): string
    {
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $c->get('/login')['body'], $m);
        return $m[1] ?? '';
    }

    /**
     * Send the requests at once (each client's own session), then read every answer.
     * @param list<array{0:Client,1:string,2:string,3:array<string,string>,4?:list<string>,5?:array<string,array{name:string,type:string,data:string}>}> $requests
     * @return list<array{status:int,body:string}>
     */
    private function parallel(array $requests): array
    {
        $port = (int)parse_url($this->server->base, PHP_URL_PORT);
        $socks = [];
        foreach ($requests as $req) {
            [$client, $method, $path, $fields] = $req;
            $type = 'application/x-www-form-urlencoded';
            $body = http_build_query($fields);
            if ($req[5] ?? []) {
                $type = 'multipart/form-data; boundary=' . ($bd = 'b' . bin2hex(random_bytes(8)));
                $body = '';
                foreach ($fields as $k => $v) $body .= "--$bd\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
                foreach ($req[5] as $k => $f) $body .= "--$bd\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"{$f['name']}\"\r\nContent-Type: {$f['type']}\r\n\r\n{$f['data']}\r\n";
                $body .= "--$bd--\r\n";
            }
            $head = "$method $path HTTP/1.0\r\nHost: 127.0.0.1:$port\r\nContent-Type: $type\r\nContent-Length: " . strlen($body) . "\r\n";
            if ($client->cookies) $head .= 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($client->cookies), $client->cookies)) . "\r\n";
            foreach ($req[4] ?? [] as $h) $head .= $h . "\r\n";
            $s = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
            $this->assertIsResource($s, $errstr);
            fwrite($s, $head . "Connection: close\r\n\r\n" . $body);
            $socks[] = $s;
        }
        $out = [];
        foreach ($socks as $s) {
            stream_set_timeout($s, 60);
            $raw = (string)stream_get_contents($s);
            fclose($s);
            [$head, $body] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
            $out[] = ['status' => preg_match('#^HTTP/\S+ (\d+)#', $head, $m) ? (int)$m[1] : 0, 'body' => $body];
        }
        return $out;
    }

    /** Make every activity row fail (or stop doing so): the write it belongs to fails with 500. */
    private function failActivity(bool $on): void
    {
        $pg = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($on) {
            if ($pg) $this->pdo->exec("CREATE OR REPLACE FUNCTION hardening_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            $this->pdo->exec($pg ? 'CREATE TRIGGER hardening_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION hardening_fail()'
                : "CREATE TRIGGER hardening_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        } else {
            $this->pdo->exec($pg ? 'DROP TRIGGER hardening_fail ON activity' : 'DROP TRIGGER hardening_fail');
        }
    }

    /** @return list<string> photo files: served copies, thumbnails, originals and map entries */
    private function files(): array
    {
        $out = [];
        foreach (['images/*', 'thumbs/*', 'originals/*.*', 'originals/map/*'] as $glob) {
            foreach (glob($this->dir . '/' . $glob) ?: [] as $f) $out[] = substr($f, strlen($this->dir) + 1);
        }
        sort($out);
        return $out;
    }

    /** @return list<string> served names (each with its thumbnail) */
    private function served(): array
    {
        $full = array_map('basename', glob($this->dir . '/images/*.jpg') ?: []);
        $this->assertSame($full, array_map('basename', glob($this->dir . '/thumbs/*.jpg') ?: []));
        return $full;
    }

    private function setting(string $name, mixed $value): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $this->pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value), '2026-01-01']);
    }
}
