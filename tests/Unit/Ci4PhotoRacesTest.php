<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Photo writes under concurrent requests (php -S with workers), on the
 * driver of the run:
 * - M-91: a write that commits while /save decodes a large photo does not
 *   fail the save (303, as legacy).
 * - P6: adds sent at once never pass max_photos; the others get the limit's 422.
 * - M-90: a double-submitted crop/rotate never loses the original and leaves
 *   no stray files.
 * The interleavings that must happen in one order are in
 * tests/Modules/Catalog/PhotoRepositoryTest.php.
 */
final class Ci4PhotoRacesTest extends TestCase
{
    private string $dir = '';

    private ?Server $server = null;

    private ?PDO $pdo = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        if ($this->dir !== '') {
            testdb_drop($this->dir);
            rrmdir($this->dir);
            @unlink($this->dir . '.server.log');
        }
    }

    /** M-91: legacy and CI4 both save while another request commits during the decode. */
    public function testASaveWithAPhotoSurvivesAWriteDuringTheDecode(): void
    {
        $big = ['name' => 'big.jpg', 'type' => 'image/jpeg', 'data' => $this->image(4000, 3000, 'jpeg')];
        foreach (['legacy', 'ci4'] as $app) {
            if ($app === 'ci4' && !$this->ci4()) {
                continue;
            }
            $this->start($app);
            $this->pdo->exec("INSERT INTO locations (name, name_key, created_at) VALUES ('Shelf', 'shelf', '2026-01-02 08:00:00')");
            $loc = (string)$this->pdo->query("SELECT id FROM locations WHERE name = 'Shelf'")->fetchColumn();
            $form = static fn (string $name): array => ['name' => $name, 'location_id' => $loc];
            // How long a save with this photo takes here; the other write commits halfway through.
            $t = microtime(true);
            [$r] = $this->parallel([['POST', '/save', $form('Timing'), ['photo' => $big]]]);
            $this->assertSame(303, $r['status'], $app . ' ' . $r['body']);
            $half = (int)((microtime(true) - $t) * 500000);
            for ($round = 1; $round <= 3; $round++) {
                [$save] = $this->parallel([['POST', '/save', $form('Lamp ' . $round), ['photo' => $big]]], function () use ($half): void {
                    usleep($half);
                    $this->pdo->exec("UPDATE items SET updated_at = '2026-06-15 10:00:00' WHERE name = 'Timing'");
                });
                $this->assertSame(303, $save['status'], "$app round $round: " . substr($save['body'], 0, 300));
            }
            $this->assertSame(4, (int)$this->pdo->query('SELECT COUNT(*) FROM items WHERE image IS NOT NULL')->fetchColumn(), $app);
            $this->stop();
        }
    }

    /** P6: four adds at once to an item with three photos and a limit of four: one gets in. */
    public function testAddsAtOnceNeverPassTheLimit(): void
    {
        if (!$this->ci4()) {
            return;
        }
        $this->start('ci4');
        $this->setting('max_photos', 4);
        $photo = ['name' => 'a.png', 'type' => 'image/png', 'data' => $this->image(120, 90, 'png')];
        for ($i = 0; $i < 3; $i++) {
            [$r] = $this->parallel([['POST', '/item/1/photos', [], ['photo' => $photo]]]);
            $this->assertSame(303, $r['status']);
        }
        for ($round = 1; $round <= 4; $round++) {
            $answers = $this->parallel(array_fill(0, 4, ['POST', '/item/1/photos', [], ['photo' => $photo], ['X-Requested-With: fetch']]));
            $this->assertEquals([200 => 1, 'limit' => 3], array_count_values(array_map(
                // The limit is checked before the store (422) and again in the add's transaction (400, as legacy photo_add()).
                static fn (array $a): int|string => in_array($a['status'], [400, 422], true)
                    && (json_decode($a['body'], true)['error'] ?? null) === 'An item can have at most 4 photos.' ? 'limit' : $a['status'],
                $answers
            )), "round $round: " . json_encode($answers));
            $this->assertSame(4, $this->photoCount());
            $this->assertNoStrayFiles();
            $last = (int)$this->pdo->query('SELECT id FROM item_photos WHERE item_id = 1 ORDER BY position DESC LIMIT 1')->fetchColumn();
            [$r] = $this->parallel([['POST', "/item/1/photos/$last/delete", [], []]]);
            $this->assertSame(303, $r['status']);
        }
    }

    /** M-90: two edits of one photo at once: the photo keeps an original, and no file is left over. */
    public function testADoubleSubmittedEditKeepsTheOriginal(): void
    {
        if (!$this->ci4()) {
            return;
        }
        $this->start('ci4');
        $photo = ['name' => 'a.jpg', 'type' => 'image/jpeg', 'data' => $this->image(1600, 1200, 'jpeg')];
        [$r] = $this->parallel([['POST', '/item/1/photos', [], ['photo' => $photo]]]);
        $this->assertSame(303, $r['status']);
        $pid = (int)$this->pdo->query('SELECT id FROM item_photos WHERE item_id = 1')->fetchColumn();
        for ($round = 1; $round <= 6; $round++) {
            $edit = ['POST', "/item/1/photos/$pid/edit", ['rotate' => '90'], [], ['X-Requested-With: fetch']];
            $answers = $this->parallel([$edit, $edit]);
            foreach ($answers as $a) {
                $this->assertContains($a['status'], [200, 500], "round $round: " . $a['body']);
                if ($a['status'] === 500) {
                    $this->assertSame('Could not read that photo.', json_decode($a['body'], true)['error'] ?? null);
                }
            }
            $this->assertContains(200, array_column($answers, 'status'), "round $round");
            $file = (string)$this->pdo->query("SELECT file FROM item_photos WHERE id = $pid")->fetchColumn();
            $map = $this->dir . '/originals/map/' . substr($file, 0, 32);
            $this->assertFileExists($map, "round $round: the photo has an original");
            $this->assertFileExists($this->dir . '/originals/' . trim((string)file_get_contents($map)), "round $round");
            $this->assertNoStrayFiles();
        }
    }

    private function ci4(): bool
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }

        return true;
    }

    private function start(string $app): void
    {
        $this->dir = tmpdir('races-' . $app);
        $this->server = new Server($this->dir, ['APOTHEKE_CLOCK' => '2026-06-15 21:30:00', 'PHP_CLI_SERVER_WORKERS' => '4'], $app);
        $this->assertSame(200, $this->server->client()->get('/healthz')['status'], (string)@file_get_contents($this->server->log));
        $this->pdo = testdb($this->dir);
        $this->pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['ada', 'ada', password_hash('long-enough', PASSWORD_DEFAULT), 'admin', 'active', '2026-01-01 00:00:00']);
        $this->pdo->exec("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Box', 1, '', 'box', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");
        foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens'] as $k => $v) {
            $this->setting($k, $v);
        }
    }

    private function stop(): void
    {
        $this->server?->stop();
        $this->server = null;
        testdb_drop($this->dir);
        rrmdir($this->dir);
        @unlink($this->dir . '.server.log');
        $this->dir = '';
        $this->pdo = null;
    }

    /**
     * Send the requests at once, each in its own signed-in session (a shared
     * session file would make them wait for each other), run $meanwhile, then
     * read every answer.
     *
     * @param list<array{0:string,1:string,2:array<string,string>,3:array<string,array{name:string,type:string,data:string}>,4?:list<string>}> $requests
     * @return list<array{status:int,body:string}>
     */
    private function parallel(array $requests, ?Closure $meanwhile = null): array
    {
        $uid = (int)$this->pdo->query("SELECT id FROM users WHERE username = 'ada'")->fetchColumn();
        $hash = (string)$this->pdo->query("SELECT password_hash FROM users WHERE id = $uid")->fetchColumn();
        $epoch = substr(hash('sha256', $hash . '|' . $uid), 0, 16);
        $port = (int)parse_url($this->server->base, PHP_URL_PORT);
        $socks = [];
        foreach ($requests as $request) {
            [$method, $path, $fields, $files] = $request;
            $headers = $request[4] ?? [];
            $token = bin2hex(random_bytes(32));
            $sid = bin2hex(random_bytes(13));
            file_put_contents($this->dir . '/sessions/sess_' . $sid, 'uid|i:' . $uid . ';auth_epoch|s:16:"' . $epoch . '";csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';');
            $b = '----apotheke' . bin2hex(random_bytes(8));
            $body = '';
            foreach (['csrf' => $token] + $fields as $k => $v) {
                $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n";
            }
            foreach ($files as $k => $f) {
                $body .= "--$b\r\nContent-Disposition: form-data; name=\"$k\"; filename=\"{$f['name']}\"\r\nContent-Type: {$f['type']}\r\n\r\n{$f['data']}\r\n";
            }
            $body .= "--$b--\r\n";
            $head = "$method $path HTTP/1.0\r\nHost: 127.0.0.1:$port\r\nCookie: apothekesid=$sid\r\nContent-Type: multipart/form-data; boundary=$b\r\nContent-Length: " . strlen($body) . "\r\n";
            foreach ($headers as $h) {
                $head .= $h . "\r\n";
            }
            $s = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 5);
            $this->assertIsResource($s, $errstr);
            fwrite($s, $head . "Connection: close\r\n\r\n" . $body);
            $socks[] = $s;
        }
        if ($meanwhile !== null) {
            $meanwhile();
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

    private function photoCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM item_photos WHERE item_id = 1')->fetchColumn();
    }

    /** Every served file, thumbnail and original belongs to a photo row. */
    private function assertNoStrayFiles(): void
    {
        $rows = $this->pdo->query('SELECT file FROM item_photos UNION SELECT image FROM items WHERE image IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
        sort($rows);
        foreach (['images', 'thumbs'] as $kind) {
            $names = array_map('basename', glob($this->dir . '/' . $kind . '/*.jpg') ?: []);
            sort($names);
            $this->assertSame($rows, $names, $kind);
        }
        $this->assertCount(count($rows), glob($this->dir . '/originals/*.*') ?: [], 'originals');
    }

    private function setting(string $name, string|int|bool $value): void
    {
        $this->pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $this->pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value), '2026-01-01']);
    }

    private function image(int $w, int $h, string $type): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x += 4) {
            imagefilledrectangle($im, $x, 0, $x + 3, $h - 1, (int)imagecolorallocate($im, ($x * 7) % 256, (int)(255 * $x / $w), 80));
        }
        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im, null, 85);

        return (string)ob_get_clean();
    }
}
