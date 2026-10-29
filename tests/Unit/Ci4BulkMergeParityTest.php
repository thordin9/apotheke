<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Golden (PLAN step 11b): quantity +/-, duplicate, merge, the list's bulk
 * actions (with Undo) and attachments on CI4 against legacy, on the
 * configured driver. Besides the answers (status, type, Location, JSON and
 * the page), the tables and the files (photos, originals, attachments, by
 * content; random names numbered in order of appearance) are compared after
 * every step. A failing statement (a trigger on activity) is a 500 and
 * changes no table and no file on both.
 */
final class Ci4BulkMergeParityTest extends TestCase
{
    public function testBulkMergeDuplicateQuantityAndAttachmentsMatchLegacy(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('bulk-' . $app);
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
        // The cases are what they claim to be.
        $this->assertSame(['ok' => true, 'quantity' => 0, 'low' => true], $l['qty fetch -5']['json']);
        $this->assertSame([303, '/item/1'], [$l['qty +1']['status'], $l['qty +1']['location']]);
        $this->assertSame(404, $l['qty missing item']['status']);
        $this->assertSame(403, $l['viewer qty']['status']);
        $this->assertMatchesRegularExpression('#^/item/\d+/edit\?copied=1$#', (string)$l['duplicate with photos']['location']);
        $this->assertStringContainsString('Copy created', (string)$l['GET the copy']['body']);
        $this->assertSame(200, $l['GET merge pick']['status']);
        $this->assertStringContainsString('merge-list', (string)$l['GET merge pick']['body']);
        $this->assertStringContainsString('merge-changes', (string)$l['GET merge confirm']['body']);
        $this->assertSame(422, $l['GET merge into itself']['status']);
        $this->assertSame(404, $l['GET merge into a missing item']['status']);
        $this->assertSame(['ok' => true, 'id' => 3, 'quantity' => 7], $l['merge fetch']['json']);
        $this->assertSame(403, $l['viewer GET merge']['status']);
        $this->assertSame(200, $l['bulk delete asks']['status']);
        $this->assertMatchesRegularExpression('#bulk_done=3&bulk_act=delete&undo=H\d+$#', (string)$l['bulk delete confirmed']['location']);
        $this->assertSame(422, $l['bulk tag_add nothing']['status']);
        $this->assertSame(422, $l['bulk unknown action']['status']);
        $this->assertSame(422, $l['bulk nothing selected']['status']);
        $this->assertSame([303, '/labels?ids=4,5'], [$l['bulk labels']['status'], $l['bulk labels']['location']]);
        $this->assertSame(403, $l['viewer bulk']['status']);
        foreach (['sorter bulk move', 'sorter bulk delete', 'sorter bulk labels'] as $case) {
            $this->assertSame(403, $l[$case]['status'], $case);
        }
        $this->assertSame(303, $l['sorter bulk category']['status']);
        $this->assertSame([404, ''], [$l['download an odd stored name']['status'], $l['download an odd stored name']['body']]);
        $this->assertSame([404, ''], [$l['download a file that is not ours']['status'], $l['download a file that is not ours']['body']]);
        $this->assertSame('application/octet-stream', $l['download with an odd stored type']['type']);
        $this->assertStringContainsString('images ', $l['bulk delete asks']['files']);
        $this->assertSame($l['bulk delete asks']['files'], $l['bulk delete confirmed']['files'], 'the token keeps the deleted photos for Undo');
        $this->assertSame($l['bulk delete asks']['files'], $l['undo the delete']['files']);
        $this->assertSame(422, $l['attach a PNG with PDF only']['status']);
        $this->assertStringContainsString('Only PDF files can be attached.', (string)$l['attach a PNG with PDF only']['body']);
        $this->assertStringContainsString('Only JPEG, PNG, WebP files can be attached.', (string)$l['attach a PDF with images only']['body']);
        $this->assertSame(['ok' => true, 'added' => 2, 'count' => 3], $l['attach two fetch']['json']);
        $this->assertSame(422, $l['attach a GIF']['status']);
        $this->assertSame(422, $l['attach an empty file']['status']);
        $this->assertSame(422, $l['attach over the limit']['status']);
        $this->assertSame(422, $l['attach too large']['status']);
        $this->assertSame(404, $l['attach to a missing item']['status']);
        $this->assertSame([200, 'attachment; filename="Receipt _1_.pdf"; filename*=UTF-8\'\'Receipt%20%C2%BB1%C2%AB.pdf'], [$l['download']['status'], $l['download']['disposition']]);
        $this->assertSame(404, $l['download a missing attachment']['status']);
        // N-150: the download itself says nosniff and is sandboxed (legacy and CI4 compared above).
        foreach (['download', 'download with an odd stored type'] as $case) {
            $this->assertSame(['nosniff', "default-src 'none'; sandbox"], [$l[$case]['nosniff'] ?? null, $l[$case]['csp'] ?? null], $case);
        }
        $this->assertSame(404, $l['delete an attachment of another item']['status']);
        foreach (['fail qty', 'fail duplicate with photos', 'fail merge', 'fail bulk move', 'fail bulk delete', 'fail attach', 'fail attachment delete'] as $case) {
            $this->assertSame([500, true, true], [$l[$case]['status'], $l[$case]['unchanged'], $l[$case]['files unchanged']], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        // A sorter may use bulk actions and edit, but neither move nor delete nor print labels.
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('sorter', 'Sorter', '[\"view\",\"edit\",\"bulk\"]', 0, '2026-01-01 00:00:00')");
        foreach ([['ada', 'admin'], ['vera', 'viewer'], ['sam', 'sorter']] as [$name, $role]) {
            $user->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage', 'garage', 'garage', '2026-01-01 00:00:00'), ('Attic', 'attic', 'attic', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO categories (name, name_key, sort_key, created_at) VALUES ('Tools', 'tools', 'tools', '2026-01-01 00:00:00')");
        $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, quantity, min_qty, notes, value_cents, serial, barcode, lent_to, lent_at, favourite, search, sort_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            ['Lamp <b>', 1, null, 2, 1, 'Desk lamp', null, '', null, null, null, 0],
            ['Drill', 1, 1, 3, null, 'Cordless & strong', 4999, 'SN-1', '4006381333931', 'Nikos', '2026-05-01', 1],
            ['drill', null, null, 4, null, '', null, '', null, null, null, 0],
            ['Box', 2, null, 1, null, '', null, '', null, null, null, 0],
            ['Spare', null, null, 5, null, '', null, '', null, null, null, 0],
            ['Old', null, null, 1, null, '', null, '', null, null, null, 0],
        ] as $r) {
            $item->execute([...$r, mb_strtolower($r[0] . "\n" . $r[5]), mb_strtolower($r[0]), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);
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
        $names = [];
        $hide = static function (string $s, bool $number = true) use (&$names): string {
            return (string)preg_replace_callback('/(?<![0-9a-f])[0-9a-f]{32}(?:[0-9a-f]{32})?(?![0-9a-f])/', static function (array $m) use (&$names, $number): string {
                if (isset($names[$m[0]])) {
                    return 'H' . $names[$m[0]];
                }

                return $number ? 'H' . ($names[$m[0]] = count($names) + 1) : 'unseen';
            }, $s);
        };
        $files = static function () use ($dir, $hide): string {
            $out = [];
            foreach (['images', 'thumbs', 'files'] as $kind) {
                foreach (glob($dir . '/' . $kind . '/*.*') ?: [] as $f) {
                    $out[] = $kind . ' ' . $hide(basename($f), false) . ' ' . hash_file('sha256', $f);
                }
            }
            foreach (glob($dir . '/originals/map/*') ?: [] as $f) {
                $base = trim((string)file_get_contents($f));
                $out[] = 'original ' . $hide(basename($f), false) . ' ' . (is_file($dir . '/originals/' . $base) ? hash_file('sha256', $dir . '/originals/' . $base) : 'missing');
            }
            $out[] = 'originals ' . count(glob($dir . '/originals/*.*') ?: []);
            sort($out);

            return implode("\n", $out);
        };
        $tables = static function () use ($pdo, $hide): string {
            $o = [];
            foreach (['SELECT * FROM items ORDER BY id', 'SELECT item_id, file, position, version FROM item_photos ORDER BY item_id, position', 'SELECT item_id, file, name, mime, size, created_at FROM item_attachments ORDER BY id',
                'SELECT item_id, item_name, action, details, actor, created_at FROM activity ORDER BY id', 'SELECT action, label, payload, files, created_at, expires_at FROM undo_tokens ORDER BY created_at, label',
                'SELECT it.item_id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id ORDER BY it.item_id, t.name', 'SELECT name FROM tags ORDER BY name', 'SELECT item_id FROM recent_views ORDER BY item_id'] as $sql) {
                $set = $pdo->query($sql)->fetchAll();
                foreach ($set as &$row) {
                    if (isset($row['payload'])) {
                        // The snapshot's numbers are strings on CI4's PostgreSQL driver; Undo casts them back (11a).
                        $payload = json_decode((string)$row['payload'], true);
                        array_walk_recursive($payload, static function (&$v): void {
                            $v = $v === null ? null : (string)$v;
                        });
                        $row['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                    $row = array_map(static fn ($v) => $v === null ? null : $hide((string)$v, false), $row);
                }
                unset($row);
                $o[] = $set;
            }

            return json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        };
        $out = [];
        $last = [];
        $record = function (string $case, array $r, string $token) use (&$out, &$last, $hide, $tables, $files): void {
            $last = $r;
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $json = $r['json'];
            if (is_array($json)) {
                array_walk_recursive($json, static function (&$v) use ($token, $hide): void {
                    $v = is_string($v) ? $hide(str_replace($token, 'SESSION-TOKEN', $v)) : $v;
                });
            }
            $location = $r['headers']['location'] ?? null;
            $type = $r['headers']['content-type'] ?? null;
            $download = $type !== null && !str_starts_with($type, 'text/html') && !str_starts_with($type, 'application/json');
            $cache = $r['headers']['cache-control'] ?? '';
            $out[$case] = ['status' => $r['status'], 'type' => $type, 'location' => $location === null ? null : $hide($location),
                'cache-control' => $r['status'] === 200 || $json !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache),
                'json' => $download ? null : $json,
                'body' => $download ? hash('sha256', (string)$r['body']) : ($json === null ? $hide(str_replace($token, 'SESSION-TOKEN', (string)$body)) : null),
                'tables' => $tables(), 'files' => $files()];
            if ($download || isset($r['headers']['content-disposition'])) {
                foreach (['content-disposition' => 'disposition', 'content-length' => 'length', 'x-content-type-options' => 'nosniff', 'content-security-policy' => 'csp'] as $h => $k) {
                    $out[$case][$k] = $r['headers'][$h] ?? null;
                }
            }
        };
        $fetch = ['X-Requested-With: fetch'];
        foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens', 'undo_seconds' => 30, 'attach_max_per_item' => 3, 'attach_max_mb' => 1] as $k => $v) {
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
        $png = ['name' => 'a.png', 'type' => 'image/png', 'data' => $this->image(200, 150, 200)];
        $jpg = ['name' => 'b.jpg', 'type' => 'image/jpeg', 'data' => $this->image(120, 160, 40)];
        $pdf = ['name' => 'C:\\scans\\Receipt »1«.pdf', 'type' => 'application/pdf', 'data' => "%PDF-1.4\n% a receipt\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n"];
        $pdf2 = ['name' => 'manual.PDF', 'type' => 'application/octet-stream', 'data' => "%PDF-1.7\nmanual\n%%EOF\n"];

        // Quantity.
        $as('qty +1', 'POST', '/item/1/qty', ['delta' => '1']);
        $as('qty fetch -5', 'POST', '/item/1/qty', ['delta' => '-5'], $fetch);
        $as('qty unchanged at 0', 'POST', '/item/1/qty', ['delta' => '-1'], $fetch);
        $as('qty big delta', 'POST', '/item/5/qty', ['delta' => '5000'], $fetch);
        $as('qty no delta', 'POST', '/item/5/qty');
        $as('qty array delta', 'POST', '/item/5/qty', ['delta' => ['3']], $fetch);
        $as('qty missing item', 'POST', '/item/99/qty', ['delta' => '1']);
        $as('qty missing item fetch', 'POST', '/item/99/qty', ['delta' => '1'], $fetch);
        $as('viewer qty', 'POST', '/item/1/qty', ['delta' => '1'], [], 'vera');
        $as('GET /item/1 after qty', 'GET', '/item/1');

        // Duplicate.
        $as('photos for Drill', 'POST', '/item/2/photos', [], [], 'ada', ['photos[0]' => $png, 'photos[1]' => $jpg]);
        $as('duplicate without photos', 'POST', '/item/1/duplicate');
        $as('duplicate with photos', 'POST', '/item/2/duplicate', ['photos' => '1']);
        $as('GET the copy', 'GET', (string)$last['headers']['location']);
        $as('duplicate missing', 'POST', '/item/99/duplicate', ['photos' => '1']);
        $as('viewer duplicate', 'POST', '/item/1/duplicate', [], [], 'vera');

        // Attachments.
        $as('attach a PDF', 'POST', '/item/2/attachments', [], [], 'ada', ['file' => $pdf]);
        $as('attach two fetch', 'POST', '/item/2/attachments', [], $fetch, 'ada', ['files[0]' => $pdf2, 'files[1]' => $png]);
        $as('attach over the limit', 'POST', '/item/2/attachments', [], [], 'ada', ['file' => $pdf]);
        $as('attach a GIF', 'POST', '/item/1/attachments', [], [], 'ada', ['file' => ['name' => 'x.gif', 'type' => 'image/gif', 'data' => "GIF89a\x01\x00\x01\x00"]]);
        $as('attach an empty file', 'POST', '/item/1/attachments', [], $fetch, 'ada', ['file' => ['name' => 'e.pdf', 'type' => 'application/pdf', 'data' => '']]);
        $as('attach too large', 'POST', '/item/1/attachments', [], [], 'ada', ['file' => ['name' => 'big.pdf', 'type' => 'application/pdf', 'data' => '%PDF-' . str_repeat('x', 1100000)]]);
        $as('attach nothing', 'POST', '/item/1/attachments', ['x' => '1']);
        $as('attach to a missing item', 'POST', '/item/99/attachments', [], [], 'ada', ['file' => $pdf]);
        $as('viewer attaches', 'POST', '/item/1/attachments', [], [], 'vera', ['file' => $pdf]);
        $att = array_map('intval', $pdo->query('SELECT id FROM item_attachments ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        $as('GET /item/2 with attachments', 'GET', '/item/2');
        $as('download', 'GET', '/attachment/' . $att[0]);
        $as('viewer download', 'GET', '/attachment/' . $att[2], [], [], 'vera');
        $as('download a missing attachment', 'GET', '/attachment/999');
        $as('delete an attachment of another item', 'POST', '/item/1/attachments/' . $att[1] . '/delete');
        $as('delete an attachment fetch', 'POST', '/item/2/attachments/' . $att[1] . '/delete', [], $fetch);
        $as('delete a deleted attachment', 'POST', '/item/2/attachments/' . $att[1] . '/delete');
        $as('download a deleted attachment', 'GET', '/attachment/' . $att[1]);
        // A stored name that is not one of ours is never read.
        $pdo->exec("INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (1, '../config.php', 'x.pdf', 'application/pdf', 3, '2026-01-01 00:00:00')");
        $odd = (int)$pdo->query("SELECT id FROM item_attachments WHERE file = '../config.php'")->fetchColumn();
        $as('download an odd stored name', 'GET', '/attachment/' . $odd);
        $pdo->exec("DELETE FROM item_attachments WHERE file = '../config.php'");
        file_put_contents($dir . '/files/odd.pdf', "%PDF-1.4\nodd\n");
        $pdo->exec("INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (1, 'odd.pdf', 'odd.pdf', 'application/pdf', 3, '2026-01-01 00:00:00')");
        $odd = (int)$pdo->query("SELECT id FROM item_attachments WHERE file = 'odd.pdf'")->fetchColumn();
        $as('download a file that is not ours', 'GET', '/attachment/' . $odd);
        $pdo->exec("DELETE FROM item_attachments WHERE file = 'odd.pdf'");
        unlink($dir . '/files/odd.pdf');
        // A stored type that is not one of ours is sent as application/octet-stream.
        $pdo->exec("UPDATE item_attachments SET mime = 'text/html' WHERE id = " . $att[0]);
        $as('download with an odd stored type', 'GET', '/attachment/' . $att[0]);
        $pdo->exec("UPDATE item_attachments SET mime = 'application/pdf' WHERE id = " . $att[0]);
        $this->setting($pdo, 'attach_types', 'pdf');
        $as('attach a PNG with PDF only', 'POST', '/item/1/attachments', [], [], 'ada', ['file' => $png]);
        $this->setting($pdo, 'attach_types', 'images');
        $as('attach a PDF with images only', 'POST', '/item/1/attachments', [], [], 'ada', ['file' => $pdf]);
        $this->setting($pdo, 'attach_types', 'pdf_images');

        // Merge: Drill (photos, attachments, tags, a loan, the star) into drill.
        $as('tag Drill', 'POST', '/bulk', ['ids' => [2], 'action' => 'tag_add', 'tags' => 'Power, #Garage', 'back' => '/?q=x']);
        $as('GET merge pick', 'GET', '/item/2/merge');
        $as('GET merge search', 'GET', '/item/2/merge?q=box');
        $as('GET merge search nothing', 'GET', '/item/2/merge?q=zzz');
        $as('GET merge confirm', 'GET', '/item/2/merge?into=3');
        $as('GET merge into itself', 'GET', '/item/2/merge?into=2');
        $as('GET merge into a missing item', 'GET', '/item/2/merge?into=99');
        $as('GET merge of a missing item', 'GET', '/item/99/merge');
        $as('viewer GET merge', 'GET', '/item/2/merge', [], [], 'vera');
        $as('merge without confirm', 'POST', '/item/2/merge', ['into' => '3']);
        $as('merge fetch', 'POST', '/item/2/merge', ['into' => '3', 'confirm' => '1'], $fetch);
        $as('GET /item/3 merged', 'GET', '/item/3?merged=Drill');
        $as('merge again', 'POST', '/item/2/merge', ['into' => '3', 'confirm' => '1']);
        $as('merge Box into Lamp', 'POST', '/item/4/merge', ['into' => '1', 'confirm' => '1']);

        // Bulk actions on the list.
        $as('bulk move', 'POST', '/bulk', ['ids' => [5, 6, 99], 'action' => 'move', 'location_id' => '2', 'back' => '/?select=1&bulk_done=3&undo=x']);
        $token = $this->token($last);
        $as('undo the move', 'POST', '/undo', ['token' => $token]);
        $as('bulk move fetch to no location', 'POST', '/bulk', ['ids' => [5], 'action' => 'move', 'location_id' => '', 'back' => 'https://example.org/'], $fetch);
        $as('bulk move to a gone location', 'POST', '/bulk', ['ids' => [5], 'action' => 'move', 'location_id' => '77']);
        $as('bulk category', 'POST', '/bulk', ['ids' => ['5', '6'], 'action' => 'category', 'category_id' => '1']);
        $as('bulk tag_add', 'POST', '/bulk', ['ids' => [5, 6], 'action' => 'tag_add', 'tags' => 'Winter; Garage']);
        $as('bulk tag_add nothing', 'POST', '/bulk', ['ids' => [5], 'action' => 'tag_add', 'tags' => ' , ']);
        $tagId = (int)$pdo->query("SELECT id FROM tags WHERE name = 'Winter'")->fetchColumn();
        $as('bulk tag_remove', 'POST', '/bulk', ['ids' => [5, 6], 'action' => 'tag_remove', 'tag_id' => (string)$tagId]);
        $as('bulk unknown action', 'POST', '/bulk', ['ids' => [5], 'action' => 'frob']);
        $as('bulk nothing selected', 'POST', '/bulk', ['action' => 'move']);
        $as('bulk too many', 'POST', '/bulk', ['ids' => range(1, 501), 'action' => 'category']);
        $as('bulk labels', 'POST', '/bulk', ['ids' => [4, 5, 5, 0], 'action' => 'labels']);
        $as('bulk labels nothing', 'POST', '/bulk', ['ids' => [0], 'action' => 'labels']);
        $as('viewer bulk', 'POST', '/bulk', ['ids' => [5], 'action' => 'move'], [], 'vera');
        $as('sorter bulk move', 'POST', '/bulk', ['ids' => [5], 'action' => 'move', 'location_id' => '1'], [], 'sam');
        $as('sorter bulk delete', 'POST', '/bulk', ['ids' => [5], 'action' => 'delete', 'confirm' => '1'], [], 'sam');
        $as('sorter bulk labels', 'POST', '/bulk', ['ids' => [5], 'action' => 'labels'], [], 'sam');
        $as('sorter bulk category', 'POST', '/bulk', ['ids' => [5], 'action' => 'category', 'category_id' => ''], [], 'sam');
        $as('bulk delete asks', 'POST', '/bulk', ['ids' => [5, 6, 99], 'action' => 'delete', 'back' => '/?q=s']);
        $as('bulk delete asks about nothing', 'POST', '/bulk', ['ids' => [99], 'action' => 'delete']);
        $copy = (int)$pdo->query("SELECT id FROM items WHERE name = 'Drill (copy)'")->fetchColumn();
        $as('bulk delete confirmed', 'POST', '/bulk', ['ids' => [5, 6, $copy], 'action' => 'delete', 'confirm' => '1', 'back' => '/?q=s']);
        $token = $this->token($last);
        $as('undo the delete', 'POST', '/undo', ['token' => $token]);
        $as('GET the list after undo', 'GET', '/?q=s&undone=2');

        // A failing statement (a trigger on activity) is a 500 and changes no table and no file.
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        $attId = (int)$pdo->query('SELECT id FROM item_attachments ORDER BY id LIMIT 1')->fetchColumn();
        $attItem = (int)$pdo->query('SELECT item_id FROM item_attachments ORDER BY id LIMIT 1')->fetchColumn();
        foreach ([['fail qty', '/item/5/qty', ['delta' => '2'], []],
            ['fail duplicate with photos', '/item/' . $copy . '/duplicate', ['photos' => '1'], []],
            ['fail merge', '/item/6/merge', ['into' => '5', 'confirm' => '1'], []],
            ['fail bulk move', '/bulk', ['ids' => [5, 6], 'action' => 'move', 'location_id' => '1'], []],
            ['fail bulk delete', '/bulk', ['ids' => [$copy], 'action' => 'delete', 'confirm' => '1'], []],
            ['fail attach', '/item/5/attachments', [], ['file' => $pdf]],
            ['fail attachment delete', '/item/' . $attItem . '/attachments/' . $attId . '/delete', [], []]] as [$case, $path, $fields, $upload]) {
            $pdo->exec($pg ? 'CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()'
                : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
            $before = [$tables(), $files()];
            $as($case, 'POST', $path, $fields, [], 'ada', $upload);
            $pdo->exec($pg ? 'DROP TRIGGER apotheke_test_fail ON activity' : 'DROP TRIGGER apotheke_test_fail');
            $out[$case]['unchanged'] = $before[0] === $out[$case]['tables'];
            $out[$case]['files unchanged'] = $before[1] === $out[$case]['files'];
            unset($out[$case]['body']);   // the 500 page names the request's own trace id on neither, but keep it out
        }

        return $out;
    }

    /** @param array<string,mixed> $r the undo token a redirect carries */
    private function token(array $r): string
    {
        $this->assertSame(1, preg_match('/undo=([0-9a-f]{32})/', (string)($r['headers']['location'] ?? ''), $m), 'an undo token');

        return $m[1];
    }

    /** A small PNG or JPEG with a gradient. */
    private function image(int $w, int $h, int $red): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            imageline($im, $x, 0, $x, $h - 1, (int)imagecolorallocate($im, $red, (int)(255 * $x / $w), 80));
        }
        ob_start();
        $red > 100 ? imagepng($im) : imagejpeg($im, null, 90);

        return (string)ob_get_clean();
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
