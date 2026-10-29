<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * Golden (PLAN step 14, second part): backup and restore on CI4 against
 * legacy, on the configured driver. Besides the answers (status, type,
 * Location, disposition, caching, security headers and the pages), every
 * download is compared entry by entry (names in order, compression, sizes
 * and the bytes, backup.json included), and after every step the catalog
 * tables, the settings, the undo tokens and the files under images/,
 * thumbs/, files/ and originals/ (map entries included) by content.
 *
 * Then the cross restore: each app restores the other app's download and a
 * backup from before originals were kept, into a changed catalog, and ends
 * with the same tables and files as legacy.
 */
final class Ci4BackupParityTest extends TestCase
{
    private const CLOCK = '2026-06-15 21:30:00';

    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    private const PHOTO2 = '0123456789abcdef0123456789abcdef.jpg';

    private const ORIGINAL = 'aa00000000000000000000000000000000000000000000000000000000000001.png';

    private const FILE = 'fedcba9876543210fedcba9876543210.pdf';

    /** @var array<string,string> app => its download */
    private array $zips = [];

    /** @var array<string,array<string,mixed>> case => what matters in the answer */
    private array $out = [];

    /** @var array<string,bool> case => the page showed the database's error text */
    private array $details = [];

    /** @var array<string,bool> per failed restore: were the files left as they were (L-440) */
    private array $kept = [];

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    public function testBackupAndRestoreMatchLegacy(): void
    {
        $answers = [];
        $details = [];
        $kept = [];
        foreach (['legacy', 'ci4'] as $app) {
            $this->details = [];
            $this->kept = [];
            $answers[$app] = $this->run_($app, fn (Server $s, string $dir): array => $this->visit($s, $dir, $app));
            $details[$app] = array_keys(array_filter($this->details));
            $kept[$app] = $this->kept;
        }
        $this->assertSame(['confirm failing write', 'confirm broken rows'], $details['legacy']);
        $this->assertSame([], $details['ci4'], 'CI4 shows no database error text');
        // L-440: since v2.6.23 (#135) both apps put a failed restore's files back as they were.
        $this->assertSame(['confirm failing write' => true, 'confirm broken rows' => true], $kept['legacy'], 'legacy restores the files of a failed restore since v2.6.23 (#135)');
        $this->assertSame(['confirm failing write' => true, 'confirm broken rows' => true], $kept['ci4']);
        $this->compare($answers);
        $l = $answers['legacy'];
        // The cases are what they claim to be.
        $this->assertSame(['nosniff', 'text/html; charset=UTF-8'], [$l['page']['nosniff'], $l['page']['type']]);
        $this->assertStringContainsString("default-src 'self'", (string)$l['page']['csp']);
        $this->assertStringContainsString('Download backup (.zip)', (string)$l['page']['body']);
        $this->assertSame([200, 'application/zip', 'no-store'], [$l['download']['status'], $l['download']['type'], $l['download']['cache-control']]);
        $this->assertSame('attachment; filename="apotheke-backup-2026-06-16-003000.zip"', $l['download']['disposition']);
        $this->assertSame(['backup.json', 'images/' . self::PHOTO2, 'thumbs/' . self::PHOTO2, 'images/' . self::PHOTO, 'thumbs/' . self::PHOTO, 'originals/index.json', 'originals/' . self::ORIGINAL, 'files/' . self::FILE],
            array_column($l['download']['zip'], 'name'));
        $this->assertSame([204, 204, 403, 401, 204], [$l['check admin']['status'], $l['check keeper']['status'], $l['check viewer']['status'], $l['check signed out']['status'], $l['check HEAD']['status']]);
        $this->assertSame('no-store', $l['check admin']['cache-control']);
        $this->assertSame(403, $l['viewer page']['status']);
        $this->assertSame(403, $l['viewer download']['status']);
        $this->assertSame(200, $l['keeper page']['status']);
        $this->assertSame(419, $l['upload without CSRF']['status']);
        $this->assertSame(422, $l['upload nothing']['status']);
        $this->assertStringContainsString('Choose a backup file.', (string)$l['upload nothing']['body']);
        $this->assertStringContainsString('That file is not a zip archive.', (string)$l['upload not a zip']['body']);
        $this->assertStringContainsString('Unexpected file in backup: ../evil.php', (string)$l['upload traversal']['body']);
        $this->assertStringContainsString('Unexpected file in backup: images/x.php', (string)$l['upload other name']['body']);
        $this->assertStringContainsString('backup.json is missing', (string)$l['upload no json']['body']);
        $this->assertStringContainsString('backup.json is not valid JSON.', (string)$l['upload bad json']['body']);
        $this->assertStringContainsString('This backup needs a newer version of Apotheke (schema 9999)', (string)$l['upload newer schema']['body']);
        $this->assertStringContainsString('Unknown column items.evil', (string)$l['upload unknown column']['body']);
        $this->assertStringContainsString('Unknown table in backup: users', (string)$l['upload unknown table']['body']);
        $this->assertSame(200, $l['upload']['status']);
        $this->assertStringContainsString('Restore this backup?', (string)$l['upload']['body']);
        $this->assertSame(410, $l['confirm bad token']['status']);
        $this->assertSame(422, $l['confirm without the box']['status']);
        $this->assertSame([303, '/backup?restored=1'], [$l['confirm']['status'], $l['confirm']['location']]);
        $this->assertSame(['ok' => true, 'redirect' => '/backup?restored=token-cleared'], $l['confirm token cleared']['json']);
        $this->assertSame(self::catalog($l['before restore']['tables']), self::catalog($l['confirm']['tables']), 'the restore puts back what the backup holds');
        $this->assertSame(0, $l['confirm']['tables']['undo_tokens'], 'undo tokens are pruned');
        foreach (['confirm failing write', 'confirm broken rows'] as $case) {
            $this->assertSame([422, true], [$l[$case]['status'], $l[$case]['unchanged']], $case);
        }
        $this->assertStringContainsString('Photo ' . self::PHOTO . ' is missing from the archive.', (string)$l['upload old backup']['body']);

        // Cross restore: each app restores the other's download and the old backup.
        $cross = [];
        foreach (['legacy' => 'ci4', 'ci4' => 'legacy'] as $app => $from) {
            $cross[$app] = $this->run_($app, fn (Server $s, string $dir): array => $this->crossRestore($s, $dir, $this->zips[$from]));
        }
        $this->compare($cross);
        $this->assertSame([303, '/backup?restored=1'], [$cross['legacy']['restore']['status'], $cross['legacy']['restore']['location']]);
        $this->assertSame(self::catalog($l['before restore']['tables']), self::catalog($cross['legacy']['restore']['tables']), 'legacy restores the CI4 download whole');
    }

    /**
     * @param callable(Server, string): array<string,array<string,mixed>> $fn
     * @return array<string,array<string,mixed>>
     */
    private function run_(string $app, callable $fn): array
    {
        $dir = tmpdir('backup-' . $app);
        $server = new Server($dir, ['APOTHEKE_CLOCK' => self::CLOCK], $app);
        try {
            $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));

            return $fn($server, $dir);
        } finally {
            $server->stop();
            testdb_drop($dir);
            rrmdir($dir);
            @unlink($dir . '.server.log');
        }
    }

    /** @param array<string,array<string,array<string,mixed>>> $answers */
    private function compare(array $answers): void
    {
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            if ($case === 'upload tmp files') continue;   // gap N-567, as below
            foreach ($legacy as $key => $value) {
                $ci4 = $answers['ci4'][$case][$key] ?? null;
                // legacy removes a failed restore's upload at once (N-567); CI4 keeps it until the sweep: gap N-567 (#155)
                if (is_array($value) && is_array($ci4)) unset($value['waiting uploads'], $ci4['waiting uploads']);
                $this->assertSame($value, $ci4, $case . ' › ' . $key);
            }
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function visit(Server $server, string $dir, string $app): array
    {
        $pdo = testdb($dir);
        [$as, $state] = $this->harness($server, $dir, $pdo);
        $this->seed($pdo, $dir);

        // The page and the download.
        $as('page', 'GET', '/backup');
        $as('page HEAD', 'HEAD', '/backup');
        $as('page restored', 'GET', '/backup?restored=1');
        $as('page token cleared', 'GET', '/backup?restored=token-cleared');
        $as('page restored array', 'GET', '/backup?restored[]=token-cleared');
        $as('viewer page', 'GET', '/backup', [], [], [], 'vera');
        $as('keeper page', 'GET', '/backup', [], [], [], 'kim');
        $as('signed out page', 'GET', '/backup', [], [], [], null);
        $as('page trailing slash', 'GET', '/backup/');
        $as('POST page', 'POST', '/backup');
        $as('GET restore', 'GET', '/backup/restore');
        $r = $as('download', 'GET', '/backup.zip');
        $zip = $r['body'];
        $this->zips[$app] = $zip;
        $as('download HEAD', 'HEAD', '/backup.zip');
        $as('viewer download', 'GET', '/backup.zip', [], [], [], 'vera');
        $as('keeper download', 'GET', '/backup.zip', [], [], [], 'kim');
        $as('signed out download', 'GET', '/backup.zip', [], [], [], null);
        $as('download fetch', 'GET', '/backup.zip', [], [], ['X-Requested-With: fetch']);

        // The probe nginx asks before a large upload.
        foreach (['ada' => 'admin', 'kim' => 'keeper', 'vera' => 'viewer', '' => 'signed out'] as $who => $label) {
            $as('check ' . $label, 'GET', '/backup/restore/check', [], [], [], $who === '' ? null : $who);
        }
        $as('check HEAD', 'HEAD', '/backup/restore/check');
        $as('check POST', 'POST', '/backup/restore/check');

        // Uploads that are refused.
        $file = static fn (string $data, string $name = 'backup.zip'): array => ['backup' => ['name' => $name, 'type' => 'application/zip', 'data' => $data]];
        $dump = json_decode((string)self::entry($zip, 'backup.json'), true);
        $crafted = static function (array $entries): string {
            $path = tempnam(sys_get_temp_dir(), 'apotheke-bk');
            $z = new ZipArchive();
            $z->open($path, ZipArchive::OVERWRITE);
            foreach ($entries as $name => $data) {
                $z->addFromString($name, is_array($data) ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $data);
            }
            $z->close();
            $bytes = (string)file_get_contents($path);
            unlink($path);

            return $bytes;
        };
        $as('upload nothing', 'POST', '/backup/restore');
        $as('upload an array', 'POST', '/backup/restore', [], ['backup[]' => ['name' => 'a.zip', 'type' => 'application/zip', 'data' => $zip]]);
        $as('upload without CSRF', 'POST', '/backup/restore', ['csrf' => 'nope'], $file($zip));
        $as('viewer upload', 'POST', '/backup/restore', [], $file($zip), [], 'vera');
        $as('upload not a zip', 'POST', '/backup/restore', [], $file('not a zip'));
        $as('upload traversal', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $dump, '../evil.php' => '<?php'])));
        $as('upload other name', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $dump, 'images/x.php' => '<?php'])));
        $as('upload key file', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $dump, 'app.key' => 'k'])));
        $as('upload no json', 'POST', '/backup/restore', [], $file($crafted(['images/' . self::PHOTO => 'x'])));
        // Entries that say they unpack to 3 × 4 GB - 1 (the central directory's sizes, patched after zipping).
        $large = $crafted(['backup.json' => $dump, 'images/' . self::PHOTO => 'x', 'images/' . self::PHOTO2 => 'y']);
        for ($at = strpos($large, "PK\x01\x02"); $at !== false; $at = strpos($large, "PK\x01\x02", $at + 4)) {
            $large = substr_replace($large, "\xff\xff\xff\xff", $at + 24, 4);
        }
        $as('upload too large unpacked', 'POST', '/backup/restore', [], $file($large));
        $as('upload bad json', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => '{"format":'])));
        $as('upload deep json', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => str_repeat('[', 70) . str_repeat(']', 70)])));
        $as('upload other format', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => ['format' => 'apotheke-settings'] + $dump])));
        $as('upload newer version', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => ['version' => 2] + $dump])));
        $as('upload string version', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => ['version' => '1'] + $dump])));
        $as('upload no tables', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => ['tables' => 'x'] + $dump])));
        $as('upload newer schema', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => ['schema' => [...$dump['schema'], '9999']] + $dump])));
        $as('upload unknown table', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => ['tables' => $dump['tables'] + ['users' => ['columns' => ['id'], 'rows' => []]]] + $dump])));
        $bad = $dump;
        $bad['tables']['items']['columns'][] = 'evil';
        $as('upload unknown column', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $bad])));
        $bad = $dump;
        $bad['tables']['tags']['rows'][] = [1];
        $as('upload short row', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $bad])));
        $bad = $dump;
        $bad['tables']['tags']['rows'][0][1] = ['x'];
        $as('upload nested value', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $bad])));
        $bad = $dump;
        $bad['tables']['tags'] = 'x';
        $as('upload malformed table', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $bad])));
        $bad = $dump;
        unset($bad['tables']['categories']);
        $as('upload no categories', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $bad])));

        // Restore: change the catalog, check the download, confirm.
        $before = $state();
        $pdo->exec("UPDATE items SET name = 'Changed' WHERE id = 1");
        $pdo->exec("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('new', 'new', 'new', '2026-06-01 00:00:00')");
        $pdo->exec('DELETE FROM item_attachments');
        @unlink($dir . '/files/' . self::FILE);
        @unlink($dir . '/thumbs/' . self::PHOTO);
        $this->setting($pdo, 'app_name', 'Other name');
        $pdo->exec("INSERT INTO undo_tokens (token, action, label, payload, files, created_at, expires_at) VALUES ('t1', 'delete', 'x', '{}', '[]', '2026-06-15 21:00:00', '2099-01-01 00:00:00')");
        $this->out['before restore'] = ['tables' => $before];
        $r = $as('upload', 'POST', '/backup/restore', [], $file($zip, 'my <backup>.zip'));
        $token = self::token($r['body']);
        $as('upload fetch', 'POST', '/backup/restore', [], $file($zip), ['X-Requested-With: fetch']);
        $as('confirm without CSRF', 'POST', '/backup/restore/confirm', ['csrf' => 'nope', 'token' => $token, 'confirm' => '1']);
        $as('viewer confirm', 'POST', '/backup/restore/confirm', ['token' => $token, 'confirm' => '1'], [], [], 'vera');
        $as('confirm bad token', 'POST', '/backup/restore/confirm', ['token' => '../../x', 'confirm' => '1']);
        $as('confirm unknown token', 'POST', '/backup/restore/confirm', ['token' => str_repeat('0', 32), 'confirm' => '1']);
        $as('confirm token array', 'POST', '/backup/restore/confirm', ['token[]' => $token, 'confirm' => '1']);
        $as('confirm without the box', 'POST', '/backup/restore/confirm', ['token' => $token]);
        $as('confirm box 0', 'POST', '/backup/restore/confirm', ['token' => $token, 'confirm' => '0']);

        // A failing statement (a trigger) is a 422 and changes no table.
        $pg = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        if ($pg) {
            $pdo->exec("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
        }
        $pdo->exec($pg ? 'CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()'
            : "CREATE TRIGGER apotheke_test_fail BEFORE INSERT ON activity BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        $unchanged = $state();
        $files = self::fileTree($dir);
        $as('confirm failing write', 'POST', '/backup/restore/confirm', ['token' => $token, 'confirm' => '1']);
        $this->filesKept('confirm failing write', $dir, $files);
        $pdo->exec($pg ? 'DROP TRIGGER apotheke_test_fail ON activity' : 'DROP TRIGGER apotheke_test_fail');
        $this->out['confirm failing write']['unchanged'] = self::withoutFiles($unchanged) === self::withoutFiles($this->out['confirm failing write']['tables']);
        // Rows that break a foreign key: nothing is changed either.
        $bad = $dump;
        $bad['tables']['items']['rows'][0][array_search('location_id', $bad['tables']['items']['columns'], true)] = 999;
        $r = $as('upload broken rows', 'POST', '/backup/restore', [], $file($crafted(['backup.json' => $bad])));
        $unchanged = $state();
        $files = self::fileTree($dir);
        $as('confirm broken rows', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1']);
        $this->filesKept('confirm broken rows', $dir, $files);
        $this->out['confirm broken rows']['unchanged'] = self::withoutFiles($unchanged) === self::withoutFiles($this->out['confirm broken rows']['tables']);

        $r = $as('upload again', 'POST', '/backup/restore', [], $file($zip));
        $as('confirm', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1']);
        $as('page after restore', 'GET', '/backup?restored=1');
        $as('confirm again', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1']);

        // A restore that moves the autofill address clears the stored token.
        $this->setting($pdo, 'autofill_url', 'https://autofill.example/other');
        $this->setting($pdo, 'autofill_token', 'placeholder-token');
        $r = $as('upload for the token', 'POST', '/backup/restore', [], $file($zip));
        $as('confirm token cleared', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1'], [], ['X-Requested-With: fetch']);

        // A keeper (backup, not admin) restores, but not the sign-in settings.
        $this->setting($pdo, 'auth_guest_disabled', false);
        $r = $as('keeper upload', 'POST', '/backup/restore', [], $file($zip), [], 'kim');
        $as('keeper confirm', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1'], [], [], 'kim');
        $this->setting($pdo, 'auth_guest_disabled', true);

        // A backup missing a thumbnail and a photo, with files that are not what they say.
        $old = $crafted(['backup.json' => $dump, 'images/' . self::PHOTO2 => (string)self::entry($zip, 'images/' . self::PHOTO2),
            'files/' . self::FILE => 'not a pdf', 'originals/index.json' => '{"' . self::PHOTO2 . '":"../../x"}']);
        @unlink($dir . '/thumbs/' . self::PHOTO2);
        @unlink($dir . '/images/' . self::PHOTO);
        @unlink($dir . '/files/' . self::FILE);
        $r = $as('upload old backup', 'POST', '/backup/restore', [], $file($old));
        $as('confirm old backup', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1']);
        $this->out['upload tmp files'] = ['count' => count(glob($dir . '/tmp/restore-*.zip') ?: [])];

        return $this->out;
    }

    /** @return array<string,array<string,mixed>> */
    private function crossRestore(Server $server, string $dir, string $zip): array
    {
        $pdo = testdb($dir);
        [$as] = $this->harness($server, $dir, $pdo);
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Elsewhere', 'elsewhere', 'elsewhere', '2026-01-01 00:00:00')");
        $file = static fn (string $data): array => ['backup' => ['name' => 'backup.zip', 'type' => 'application/zip', 'data' => $data]];
        $r = $as('upload', 'POST', '/backup/restore', [], $file($zip));
        $as('restore', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1']);
        $as('page', 'GET', '/backup');

        // A backup from before originals were kept, the stocktakes or the attachments: it restores the same.
        $dump = json_decode((string)self::entry($zip, 'backup.json'), true);
        unset($dump['tables']['stocktakes'], $dump['tables']['stocktake_lines'], $dump['tables']['item_attachments'], $dump['settings']);
        $dump['schema'] = array_slice($dump['schema'], 0, 10);
        $path = tempnam(sys_get_temp_dir(), 'apotheke-bk');
        $z = new ZipArchive();
        $z->open($path, ZipArchive::OVERWRITE);
        $z->addFromString('backup.json', (string)json_encode($dump, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $z->addEmptyDir('images');
        foreach ([self::PHOTO, self::PHOTO2] as $f) {
            $z->addFromString('images/' . $f, (string)self::entry($zip, 'images/' . $f));
        }
        $z->close();
        $old = (string)file_get_contents($path);
        unlink($path);
        rrmdir($dir . '/originals');
        $r = $as('upload pre-originals backup', 'POST', '/backup/restore', [], $file($old));
        $as('restore pre-originals backup', 'POST', '/backup/restore/confirm', ['token' => self::token($r['body']), 'confirm' => '1']);

        return $this->out;
    }

    /**
     * Accounts, the request helper, the state reader and the answers.
     *
     * @return array{0:Closure, 1:Closure}
     */
    private function harness(Server $server, string $dir, PDO $pdo): array
    {
        $user = $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        // A keeper may look and back up or restore, but is not an admin.
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('keeper', 'Keeper', '[\"view\",\"backup\"]', 0, '2026-01-01 00:00:00')");
        foreach ([['ada', 'admin'], ['vera', 'viewer'], ['kim', 'keeper']] as [$name, $role]) {
            $user->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
        }
        $ids = [];
        foreach ($pdo->query('SELECT id, username FROM users')->fetchAll() as $u) {
            $ids[$u['username']] = (int)$u['id'];
        }
        foreach (['auth_enabled' => true, 'auth_guest_disabled' => true, 'timezone' => 'Europe/Athens'] as $k => $v) {
            $this->setting($pdo, $k, $v);
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
        $state = static function () use ($pdo, $dir): array {
            $o = [];
            foreach (array_merge(['locations', 'categories', 'tags', 'items', 'item_photos', 'item_tags', 'item_attachments', 'activity', 'saved_filters', 'stocktakes', 'stocktake_lines']) as $t) {
                $o[$t] = $pdo->query("SELECT * FROM $t ORDER BY 1, 2")->fetchAll();
            }
            $o['settings'] = $pdo->query("SELECT name, value FROM settings WHERE name NOT IN ('auth_enabled') ORDER BY name")->fetchAll();
            $o['undo_tokens'] = (int)$pdo->query('SELECT COUNT(*) FROM undo_tokens')->fetchColumn();
            // The next id of each table, so a restore must reset the sequences.
            $o['sequences'] = [];
            foreach (['locations', 'categories', 'tags', 'items', 'item_photos', 'item_attachments', 'activity', 'saved_filters'] as $t) {
                $o['sequences'][$t] = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
                    ? $pdo->query('SELECT last_value, is_called FROM ' . $pdo->query("SELECT pg_get_serial_sequence('$t', 'id')")->fetchColumn())->fetch(PDO::FETCH_NUM)
                    : $pdo->query("SELECT seq FROM sqlite_sequence WHERE name = '$t'")->fetchColumn();
            }
            $files = [];
            foreach (['images', 'thumbs', 'files', 'originals', 'originals/map'] as $sub) {
                foreach (glob($dir . '/' . $sub . '/*') ?: [] as $f) {
                    if (is_file($f)) {
                        $files[$sub . '/' . basename($f)] = hash_file('sha256', $f);
                    }
                }
            }
            ksort($files);
            $o['files'] = $files;
            $o['waiting uploads'] = count(glob($dir . '/tmp/restore-*.zip') ?: []);

            return $o;
        };
        $this->out = [];
        $as = function (string $case, string $method, string $path, array $fields = [], array $files = [], array $headers = [], ?string $who = 'ada') use ($signIn, $state): array {
            [$client, $token] = $signIn($who);
            if ($method === 'POST') {
                $fields += ['csrf' => $token];
                $fields = array_filter($fields, static fn ($v): bool => $v !== null);
            }
            $r = $client->req($method, $path, $fields, $files, $headers);
            $body = preg_replace('#(/assets/[a-z0-9.-]+)\?v=[0-9a-f]{8}#', '$1?v=V', $r['body']);
            $type = (string)($r['headers']['content-type'] ?? '');
            $json = str_starts_with($type, 'application/json') ? $r['json'] : null;
            $zip = $type === 'application/zip' ? self::entries($r['body']) : null;
            $cache = $r['headers']['cache-control'] ?? '';
            $body = str_replace($token, 'SESSION-TOKEN', (string)$body);
            $body = (string)preg_replace('/name="token" value="[a-f0-9]{32}"/', 'name="token" value="TOKEN"', $body);
            // Legacy adds the database's own error text; CI4 does not show it (HANDOFF Tracked).
            $detail = preg_match('/The backup could not be restored \(.*?\)\. Nothing was changed\./', $body) === 1;
            $body = (string)preg_replace('/The backup could not be restored \(.*?\)\. Nothing was changed\./', 'The backup could not be restored. Nothing was changed.', $body);
            $this->details[$case] = $detail;
            $this->out[$case] = ['status' => $r['status'], 'type' => $r['headers']['content-type'] ?? null, 'location' => $r['headers']['location'] ?? null,
                'disposition' => $r['headers']['content-disposition'] ?? null, 'length' => $zip !== null ? (($r['headers']['content-length'] ?? null) === (string)strlen($r['body'])) : null,
                'cache-control' => $r['status'] < 300 || $json !== null ? $cache : (str_contains($cache, 'no-store') ? 'no-store …' : $cache),
                'nosniff' => $r['headers']['x-content-type-options'] ?? null, 'csp' => $r['headers']['content-security-policy'] ?? null,
                'json' => $json, 'zip' => $zip, 'body' => $json === null && $zip === null ? $body : null, 'tables' => $state()];

            return $r;
        };

        return [$as, $state];
    }

    private function seed(PDO $pdo, string $dir): void
    {
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Garage & <i', 'garage & <i', 'garage & <i', '2026-01-01 00:00:00'), ('Attic', 'attic', 'attic', '2026-01-01 00:00:00')");
        // A parent with a higher id than its child: the restore inserts both before it checks the key.
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Shed', 'shed', 'shed', '2026-01-01 00:00:00')");
        $pdo->exec('UPDATE locations SET parent_id = 1 WHERE id = 2');
        $pdo->exec('UPDATE locations SET parent_id = 3 WHERE id = 1');
        $pdo->exec("INSERT INTO categories (name, name_key, sort_key, created_at) VALUES ('Tools', 'tools', 'tools', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('red', 'red', 'red', '2026-01-01 00:00:00'), ('Big', 'big', 'big', '2026-01-01 00:00:00')");
        $item = $pdo->prepare('INSERT INTO items (name, location_id, category_id, quantity, notes, value_cents, image, search, sort_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([['Drill', 1, 1, 2, "Line one\n\"two\" / three", 4990, self::PHOTO], ['Ελιά <b>', 2, null, 0, '', null, self::PHOTO2], ['Lamp', null, null, 1, '1.50', 12, null]] as $r) {
            $item->execute([...$r, mb_strtolower($r[0]), mb_strtolower($r[0]), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);
        }
        $pdo->exec("INSERT INTO item_photos (item_id, file, position, created_at) VALUES (1, '" . self::PHOTO . "', 0, '2026-01-02 08:00:00'), (2, '" . self::PHOTO2 . "', 0, '2026-01-02 08:00:00'), (2, 'not-a-name.jpg', 1, '2026-01-02 08:00:00')");
        $pdo->exec('INSERT INTO item_tags (item_id, tag_id) VALUES (1, 1), (1, 2), (3, 1)');
        $pdo->exec("INSERT INTO item_attachments (item_id, file, name, mime, size, created_at) VALUES (1, '" . self::FILE . "', 'manual.pdf', 'application/pdf', 9, '2026-01-02 08:00:00')");
        $pdo->exec("INSERT INTO activity (item_id, item_name, action, details, actor, created_at) VALUES (1, 'Drill', 'created', '{}', 'ada', '2026-01-02 08:00:00'), (7, 'Gone', 'deleted', '{}', null, '2026-01-03 08:00:00')");
        $pdo->exec("INSERT INTO saved_filters (name, name_key, query, sort_key, created_at) VALUES ('Red', 'red', 'tag=red', 'red', '2026-01-02 08:00:00')");
        $this->setting($pdo, 'app_name', 'My <stuff>');
        $this->setting($pdo, 'autofill_url', 'https://autofill.example');
        foreach (['images', 'thumbs', 'files', 'originals/map'] as $sub) {
            @mkdir($dir . '/' . $sub, 0775, true);
        }
        file_put_contents($dir . '/images/' . self::PHOTO, img_jpeg(64, 48));
        file_put_contents($dir . '/thumbs/' . self::PHOTO, img_jpeg(32, 24));
        file_put_contents($dir . '/images/' . self::PHOTO2, img_jpeg(80, 60));
        file_put_contents($dir . '/thumbs/' . self::PHOTO2, img_jpeg(40, 30));
        file_put_contents($dir . '/originals/' . self::ORIGINAL, img_png(64, 48));
        file_put_contents($dir . '/originals/map/' . substr(self::PHOTO, 0, 32), self::ORIGINAL);
        file_put_contents($dir . '/files/' . self::FILE, "%PDF-1.4\n%");
    }

    /** @return list<array{name:string,method:int,size:int,compressed:int,sha256:string}>|null */
    private static function entries(string $zip): ?array
    {
        $path = tempnam(sys_get_temp_dir(), 'apotheke-bk');
        file_put_contents($path, $zip);
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::RDONLY) !== true) {
            unlink($path);

            return null;
        }
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $s = $z->statIndex($i);
            $out[] = ['name' => (string)$s['name'], 'method' => (int)$s['comp_method'], 'size' => (int)$s['size'], 'compressed' => (int)$s['comp_size'], 'sha256' => hash('sha256', (string)$z->getFromIndex($i))];
        }
        $z->close();
        unlink($path);

        return $out;
    }

    private static function entry(string $zip, string $name): string|false
    {
        $path = tempnam(sys_get_temp_dir(), 'apotheke-bk');
        file_put_contents($path, $zip);
        $z = new ZipArchive();
        $z->open($path, ZipArchive::RDONLY);
        $bytes = $z->getFromName($name);
        $z->close();
        unlink($path);

        return $bytes;
    }

    private static function token(string $html): string
    {
        return preg_match('/name="token" value="([a-f0-9]{32})"/', $html, $m) === 1 ? $m[1] : '';
    }

    /** @return array<string,string> every file of the served, thumbnail, attachment and original folders, with its bytes */
    private static function fileTree(string $dir): array
    {
        $out = [];
        foreach (['images', 'thumbs', 'files', 'originals', 'originals/map'] as $sub) {
            foreach (glob($dir . '/' . $sub . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    $out[$sub . '/' . basename($f)] = (string)file_get_contents($f);
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * After a failed restore: were the files left as they were? The answer is
     * recorded, then the tree is put back (legacy keeps the zip's files), so
     * the two apps go on from the same files and the case compares without them.
     *
     * @param array<string,string> $before
     */
    private function filesKept(string $case, string $dir, array $before): void
    {
        $this->kept[$case] = self::fileTree($dir) === $before;
        foreach (self::fileTree($dir) as $name => $bytes) {
            if (!isset($before[$name])) {
                unlink($dir . '/' . $name);
            } elseif ($before[$name] !== $bytes) {
                file_put_contents($dir . '/' . $name, $before[$name]);
            }
        }
        $this->out[$case]['tables'] = self::withoutFiles($this->out[$case]['tables']);
    }

    /**
     * The catalog tables and files of a state.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function catalog(array $state): array
    {
        // A restored sequence names the next id, the original the last one: equal in use, not in value.
        unset($state['settings'], $state['undo_tokens'], $state['waiting uploads'], $state['sequences']);

        return $state;
    }

    /** @param array<string,mixed> $state */
    private static function withoutFiles(array $state): array
    {
        unset($state['files'], $state['waiting uploads']);   // a failed restore removes its upload on legacy (N-567)

        return $state;
    }

    private function setting(PDO $pdo, string $name, string|int|bool $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE), '2026-01-01']);
    }
}
