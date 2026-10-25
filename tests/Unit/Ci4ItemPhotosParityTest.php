<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/src/items.php';

/**
 * Golden (PLAN step 11a, part 2): an item's photos on CI4 against legacy.
 * Adding (one or several, fetch or form), cover, left/right, delete, the
 * order, and the crop and rotate page and save; the Undo file lifecycle of
 * a deleted item with photos (L-80); and failed writes. Besides the answers
 * and the tables, the photo files are compared: served copy, thumbnail and
 * original, by content, with the random names numbered in order of
 * appearance. A failed write keeps no new files and no new location (L-81).
 */
final class Ci4ItemPhotosParityTest extends TestCase
{
    public function testThePhotoRoutesGetTheSameAnswers(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('photos-' . $app);
            $server = new Server($dir, ['APOTHEKE_CLOCK' => '2026-06-15 21:30:00'], $app);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $answers[$app] = $this->visit($server, $dir);
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
        $l = $answers['legacy'];
        $c = $answers['ci4'];
        // The cases are what they claim to be.
        $this->assertSame([303, '/item/1?p=0#photos'], [$l['add one']['status'], $l['add one']['location']]);
        $this->assertSame(['ok' => true, 'added' => 2, 'count' => 3], $l['add two fetch']['json']);
        $this->assertSame(422, $l['add none']['status']);
        $this->assertSame(422, $l['add over the limit']['status']);
        $this->assertSame(422, $l['add bad image']['status']);
        $this->assertSame(404, $l['add to a missing item']['status']);
        $this->assertSame(404, $l['cover a missing photo']['status']);
        $this->assertSame(['ok' => true, 'index' => 0, 'count' => 3], $l['cover fetch']['json']);
        $this->assertSame(['ok' => true], $l['order fetch']['json']);
        $this->assertSame(422, $l['order a wrong list']['status']);
        $this->assertSame(200, $l['GET edit']['status']);
        $this->assertStringContainsString('200×150 px', (string)$l['GET edit']['body']);
        $this->assertSame(404, $l['GET edit of a missing photo']['status']);
        $this->assertMatchesRegularExpression('#^/item/1\?p=\d&edited=1\#photos$#', (string)$l['rotate 90']['location']);
        $this->assertSame(['ok' => true, 'id' => $l['crop fetch']['json']['id'], 'file' => $l['crop fetch']['json']['file'], 'version' => 3], $l['crop fetch']['json']);
        foreach (['edit nothing', 'edit rotate 45', 'edit crop outside', 'edit crop tiny', 'edit crop under 16 px'] as $case) {
            $this->assertSame(422, $l[$case]['status'], $case);
        }
        $this->assertSame(403, $l['viewer adds']['status']);
        // L-80: a deleted item keeps its files for Undo; Undo brings them back; an expired token releases them.
        $this->assertSame($l['files before delete'], $l['files after delete']);
        $this->assertSame($l['files before delete'], $l['files after undo']);
        $this->assertSame(410, $l['undo expired']['status']);
        $this->assertNotSame($l['files before delete']['body'], $l['files after prune']['body']);
        $this->assertSame($l['files after the photo delete'], $l['files after prune'], 'the pruned token released all of Box’s files');
        foreach (['fail add', 'fail cover', 'fail delete photo', 'fail order', 'fail edit', 'fail save with photo and location'] as $case) {
            $this->assertSame([500, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
            $this->assertSame([500, true], [$c[$case]['status'], $c[$case]['unchanged']], $case);
        }
        // L-81: a failed save keeps no files and no new location (legacy since #106).
        $this->assertTrue($l['fail save with photo and location']['files unchanged']);
        $this->assertTrue($l['fail save with photo and location']['locations unchanged']);
        $this->assertSame($l['files before the failures'], $l['files at the end']);
        foreach (['fail add', 'fail cover', 'fail delete photo', 'fail order', 'fail edit'] as $case) {
            $this->assertTrue($l[$case]['files unchanged'], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ada', 'admin'], ['vera', 'viewer']] as [$name, $role]) {
            $user->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
        $item = $pdo->prepare('INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES (?, 1, ?, ?, ?, ?)');
        foreach (['Lamp <b>', 'Box', 'Spare'] as $name) {
            $item->execute([$name, '', mb_strtolower($name), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
            $ids[$u['username']] = (int)$u['id'];
        }
        $signIn = function (?string $account) use ($server, $pdo, $ids, $dir): array {
            $client = $server->client();
            $token = bin2hex(random_bytes(32));
            $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';';
            if ($account !== null) {
                $row = $pdo->query('SELECT id, password_hash FROM users WHERE id = ' . $ids[$account])->fetch();
                $epoch = substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $session = 'uid|i:' . $ids[$account] . ';auth_epoch|s:16:"' . $epoch . '";' . $session;
            }
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, $session);
            $client->cookies['apothekesid'] = $sid;

            return [$client, $token];
        };
        // Random names (photos, originals, undo tokens) numbered in order of appearance.
        $names = [];
        $hide = static function (string $s, bool $number = true) use (&$names): string {
            return (string)preg_replace_callback('/(?<![0-9a-f])[0-9a-f]{32}(?:[0-9a-f]{32})?(?![0-9a-f])/', static function (array $m) use (&$names, $number): string {
                if (isset($names[$m[0]])) {
                    return 'H' . $names[$m[0]];
                }

                return $number ? 'H' . ($names[$m[0]] = count($names) + 1) : 'unseen';
            }, $s);
        };
        // The photo files: served copy, thumbnail and original (via its map entry), by content.
        $files = static function () use ($dir, $hide): array {
            $out = [];
            foreach (['images', 'thumbs'] as $kind) {
                foreach (glob($dir . '/' . $kind . '/*.jpg') ?: [] as $f) {
                    $out[] = $kind . ' ' . $hide(basename($f), false) . ' ' . hash_file('sha256', $f);
                }
            }
            foreach (glob($dir . '/originals/map/*') ?: [] as $f) {
                $base = trim((string)file_get_contents($f));
                $out[] = 'original ' . $hide(basename($f), false) . ' ' . (is_file($dir . '/originals/' . $base) ? hash_file('sha256', $dir . '/originals/' . $base) : 'missing');
            }
            $out[] = 'originals ' . count(glob($dir . '/originals/*.*') ?: []);
            sort($out);

            return $out;
        };
        $out = [];
        $last = [];
        $record = function (string $case, array $r, string $token) use (&$out, &$last, $hide): void {
            $last = $r;
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $cache = $r['headers']['cache-control'] ?? '';
            $cache = $r['status'] === 200 || $r['json'] !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache);
            $json = $r['json'];
            if (is_array($json)) {
                array_walk_recursive($json, static function (&$v) use ($token, $hide): void {
                    $v = is_string($v) ? $hide(str_replace($token, 'SESSION-TOKEN', $v)) : $v;
                });
            }
            $location = $r['headers']['location'] ?? null;
            $out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $location === null ? null : $hide($location),
                'cache-control' => $cache, 'json' => $json, 'body' => $json === null ? $hide(str_replace($token, 'SESSION-TOKEN', (string)$body)) : null];
        };
        $fetch = ['X-Requested-With: fetch'];
        foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens', 'max_photos' => 4, 'undo_seconds' => 30] as $k => $v) {
            $this->setting($pdo, $k, $v);
        }
        $as = function (string $case, string $method, string $path, array $fields = [], array $headers = [], ?string $who = 'ada', array $upload = []) use ($signIn, $record): array {
            [$client, $token] = $signIn($who);
            if ($method === 'POST') {
                $fields = ['csrf' => $token] + $fields;
            }
            $r = $client->req($method, $path, $fields, $upload, $headers);
            $record($case, $r, $token);

            return $r;
        };
        $snap = function (string $case) use (&$out, $files): void {
            $out[$case] = ['body' => json_encode($files())];
        };
        $png = ['name' => 'a.png', 'type' => 'image/png', 'data' => $this->image(200, 150, 'png', 200)];
        $jpg = ['name' => 'b.jpg', 'type' => 'image/jpeg', 'data' => $this->image(120, 160, 'jpeg', 40)];
        $gif = ['name' => 'c.gif', 'type' => 'image/gif', 'data' => $this->image(64, 64, 'gif', 120)];
        $photoIds = static fn (int $item): array => array_map('intval', $pdo->query("SELECT id FROM item_photos WHERE item_id = $item ORDER BY position")->fetchAll(PDO::FETCH_COLUMN));

        // Adding.
        $as('add one', 'POST', '/item/1/photos', [], [], 'ada', ['photo' => $png]);
        $as('GET /item/1 one photo', 'GET', '/item/1');
        $as('add two fetch', 'POST', '/item/1/photos', [], $fetch, 'ada', ['photos[0]' => $jpg, 'photos[1]' => $gif]);
        $as('GET /item/1 three photos', 'GET', '/item/1');
        $snap('files after adding');
        $as('add none', 'POST', '/item/1/photos', ['x' => '1']);
        $as('add none fetch', 'POST', '/item/1/photos', ['x' => '1'], $fetch);
        $as('add over the limit', 'POST', '/item/1/photos', [], [], 'ada', ['photos[0]' => $png, 'photos[1]' => $png]);
        $as('add bad image', 'POST', '/item/2/photos', [], [], 'ada', ['photo' => ['name' => 'x.png', 'type' => 'image/png', 'data' => 'not an image']]);
        $as('add bad image fetch', 'POST', '/item/2/photos', [], $fetch, 'ada', ['photo' => ['name' => 'x.png', 'type' => 'image/png', 'data' => 'not an image']]);
        $as('add to a missing item', 'POST', '/item/99/photos', [], [], 'ada', ['photo' => $png]);
        $as('viewer adds', 'POST', '/item/1/photos', [], [], 'vera', ['photo' => $png]);
        $as('signed-out adds fetch', 'POST', '/item/1/photos', [], $fetch, null, ['photo' => $png]);
        $snap('files after the refusals');
        // Cover, left, right, delete.
        [$p1, $p2, $p3] = $photoIds(1);
        $as('cover the third', 'POST', "/item/1/photos/$p3/cover");
        $as('left of the first', 'POST', "/item/1/photos/$p3/left");
        $as('right of the first', 'POST', "/item/1/photos/$p3/right");
        $as('right of the last', 'POST', "/item/1/photos/$p2/right");
        $as('cover fetch', 'POST', "/item/1/photos/$p1/cover", [], $fetch);
        $as('cover a missing photo', 'POST', '/item/1/photos/999/cover');
        $as('cover a photo of another item', 'POST', "/item/2/photos/$p1/cover");
        $as('cover on a missing item', 'POST', "/item/99/photos/$p1/cover");
        $as('GET /item/1 reordered', 'GET', '/item/1?p=1&reordered=1');
        // The order.
        $now = $photoIds(1);
        $as('order reversed', 'POST', '/item/1/photos/order', ['ids' => array_reverse($now)]);
        $as('order unchanged', 'POST', '/item/1/photos/order', ['ids' => implode(', ', array_reverse($now))]);
        $as('order fetch', 'POST', '/item/1/photos/order', ['ids' => implode(',', $now)], $fetch);
        $as('order a wrong list', 'POST', '/item/1/photos/order', ['ids' => [$now[0], $now[0], $now[1]]]);
        $as('order nothing', 'POST', '/item/1/photos/order');
        $as('order on a missing item', 'POST', '/item/99/photos/order', ['ids' => '1']);
        // Crop and rotate (the PNG, 200×150).
        $pngId = (int)$pdo->query('SELECT id FROM item_photos WHERE item_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
        $as('GET edit', 'GET', "/item/1/photos/$pngId/edit");
        $as('HEAD edit', 'HEAD', "/item/1/photos/$pngId/edit");
        $as('GET edit of a missing photo', 'GET', '/item/1/photos/999/edit');
        $as('GET edit on a missing item', 'GET', "/item/99/photos/$pngId/edit");
        $as('viewer GET edit', 'GET', "/item/1/photos/$pngId/edit", [], [], 'vera');
        foreach ([
            'edit nothing' => [],
            'edit rotate 45' => ['rotate' => '45'],
            'edit crop outside' => ['crop_x' => '60', 'crop_w' => '50'],
            'edit crop tiny' => ['crop_w' => '1'],
            'edit crop under 16 px' => ['crop_w' => '5', 'crop_h' => '50'],
            'edit crop negative' => ['crop_y' => '-1'],
        ] as $case => $fields) {
            $as($case, 'POST', "/item/1/photos/$pngId/edit", $fields);
        }
        $as('edit nothing fetch', 'POST', "/item/1/photos/$pngId/edit", [], $fetch);
        $as('rotate 90', 'POST', "/item/1/photos/$pngId/edit", ['rotate' => '90']);
        $as('crop fetch', 'POST', "/item/1/photos/$pngId/edit", ['crop_x' => '10', 'crop_y' => '12.5', 'crop_w' => '50', 'crop_h' => '60'], $fetch);
        $as('GET /item/1 edited', 'GET', '/item/1?p=0&edited=1');
        $as('GET edit after the edits', 'GET', "/item/1/photos/$pngId/edit");
        $snap('files after the edits');
        $gifId = (int)$pdo->query('SELECT id FROM item_photos WHERE item_id = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
        $as('delete the GIF', 'POST', "/item/1/photos/$gifId/delete");
        $as('delete the GIF again fetch', 'POST', "/item/1/photos/$gifId/delete", [], $fetch);
        $snap('files after the photo delete');

        // L-80: Box with two photos is deleted (Undo on), undone, deleted again; its token expires and is pruned.
        $as('add to Box', 'POST', '/item/2/photos', [], [], 'ada', ['photos[0]' => $png, 'photos[1]' => $jpg]);
        $as('GET /item/2', 'GET', '/item/2');
        $snap('files before delete');
        $as('delete Box', 'POST', '/item/2/delete');
        $snap('files after delete');
        $token = $this->token($last);
        $as('undo Box', 'POST', '/undo', ['token' => $token]);
        $snap('files after undo');
        $as('GET /item/2 restored', 'GET', '/item/2');
        $as('delete Box again', 'POST', '/item/2/delete');
        $token = $this->token($last);
        $pdo->exec("UPDATE undo_tokens SET expires_at = '2026-06-15 18:00:00'");
        $as('undo expired', 'POST', '/undo', ['token' => $token]);
        $snap('files after prune');
        // A failing statement (a trigger) is a 500 and changes no table; CI4 also keeps no new file.
        $snap('files before the failures');
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        $tables = static function () use ($pdo): string {
            $o = [];
            foreach (['items', 'item_photos', 'activity', 'undo_tokens', 'locations', 'categories'] as $t) {
                $o[] = $pdo->query("SELECT * FROM $t ORDER BY 1, 2")->fetchAll();
            }

            return json_encode($o);
        };
        $now = $photoIds(1);
        foreach ([['fail add', 'POST', '/item/3/photos', [], ['photo' => $png]],
            ['fail cover', 'POST', '/item/1/photos/' . $now[1] . '/cover', [], []],
            ['fail delete photo', 'POST', '/item/1/photos/' . $now[1] . '/delete', [], []],
            ['fail order', 'POST', '/item/1/photos/order', ['ids' => implode(',', array_reverse($now))], []],
            ['fail edit', 'POST', '/item/1/photos/' . $now[0] . '/edit', ['rotate' => '180'], []],
            ['fail save with photo and location', 'POST', '/save', ['name' => 'Failing', 'new_location' => 'Cellar'], ['photo' => $jpg]]] as [$case, $method, $path, $fields, $upload]) {
            $pdo->exec($pg ? 'CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()'
                : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = [$tables(), $files(), $pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn()];
            $as($case, $method, $path, $fields, [], 'ada', $upload);
            // Legacy creates the location before its transaction; compare the other tables then.
            $after = [$tables(), $files(), $pdo->query('SELECT COUNT(*) FROM locations')->fetchColumn()];
            $out[$case]['unchanged'] = $before[0] === $after[0] || ($case === 'fail save with photo and location' && $this->withoutLocations($before[0]) === $this->withoutLocations($after[0]));
            $out[$case]['files unchanged'] = $before[1] === $after[1];
            $out[$case]['locations unchanged'] = $before[2] === $after[2];
            $pdo->exec($pg ? 'DROP TRIGGER apotheke_test_fail ON activity' : 'DROP TRIGGER apotheke_test_fail');
        }
        $snap('files at the end');

        $rows = [];
        foreach (['SELECT id, name, image, updated_at FROM items ORDER BY id', 'SELECT id, item_id, file, position, version, edited_at FROM item_photos ORDER BY item_id, position',
            'SELECT item_id, item_name, action, details, actor, created_at FROM activity ORDER BY id', 'SELECT action, label, files, created_at, expires_at FROM undo_tokens ORDER BY created_at, label'] as $sql) {
            // (No locations: legacy keeps the failed save's new one, L-81.)
            $set = $pdo->query($sql)->fetchAll();
            foreach ($set as &$row) {
                $row = array_map(static fn ($v) => $v === null ? null : $hide((string)$v), $row);
            }
            unset($row);
            $rows[] = $set;
        }
        $out['tables'] = ['body' => json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)];

        return $out;
    }

    private function withoutLocations(string $tables): string
    {
        $t = json_decode($tables, true);
        unset($t[4]);

        return json_encode($t);
    }

    /** @param array<string,mixed> $r the undo token a redirect carries */
    private function token(array $r): string
    {
        $this->assertSame(1, preg_match('/undo=([0-9a-f]{32})/', (string)($r['headers']['location'] ?? ''), $m), 'an undo token');

        return $m[1];
    }

    /** A small picture with a gradient, so a crop or a turn changes its bytes. */
    private function image(int $w, int $h, string $type, int $red): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            imageline($im, $x, 0, $x, $h - 1, (int)imagecolorallocate($im, $red, (int)(255 * $x / $w), 80));
        }
        imagefilledrectangle($im, 0, 0, (int)($w / 4), (int)($h / 4), (int)imagecolorallocate($im, 0, 0, 0));
        ob_start();
        match ($type) {
            'png' => imagepng($im),
            'jpeg' => imagejpeg($im, null, 90),
            default => imagegif($im),
        };

        return (string)ob_get_clean();
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
