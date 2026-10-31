<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * N-1441: the HTML import pages (/import, /import/{id}, /import/{id}/preview and /commit) show an import only to
 * the user who uploaded it (imports.actor) or an admin, as the API does (#184, #185). Anyone else gets the same 404
 * as an unknown id, and the history and the done notice list only their own imports.
 */
final class Ci4ImportHtmlScopeTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private const CSV = "Name;Location;Qty\nLadder;Shed;2\nRope;Garage;1\n";

    private const MAP = ['col' => ['name', 'location', 'quantity'], 'create' => '1'];

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('import-html-scope');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
        $users = testdb(self::$dir)->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (['ed', 'eli'] as $name) {
            $users->execute([$name, $name, password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'editor', 'active', '2026-01-01 00:00:00']);
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

    public function testTheUploaderAndAnAdminGetThrough(): void
    {
        $ed = $this->signedIn('ed');
        $id = $this->upload($ed, 'ed-through.csv');
        foreach ([$ed, $this->signedIn('admin')] as $c) {
            $this->assertSame(200, $c->get('/import/' . $id)['status']);
            $p = $c->post('/import/' . $id . '/preview', ['csrf' => $c->csrf()] + self::MAP);
            $this->assertSame(200, $p['status'], substr($p['body'], 0, 300));
        }
        $admin = $this->signedIn('admin');
        $done = $admin->post('/import/' . $id . '/commit', ['csrf' => $admin->csrf()] + self::MAP);
        $this->assertSame([303, '/import?done=' . $id], [$done['status'], $done['headers']['location'] ?? null]);
        $this->assertStringContainsString('Imported · ', $ed->get('/import?done=' . $id)['body'], 'the uploader sees the done notice');
        $this->assertSame([303, '/import?done=' . $id], [$ed->get('/import/' . $id)['status'], $ed->get('/import/' . $id)['headers']['location'] ?? null]);
    }

    /** Every page and action of another user's import answers as an unknown id does, byte for byte, and changes nothing. */
    public function testAnotherUserGetsTheUnknownIdAnswerEverywhere(): void
    {
        $ed = $this->signedIn('ed');
        $id = $this->upload($ed, 'ed-private.csv');
        $doneId = $this->upload($ed, 'ed-done.csv');
        $commit = $ed->post('/import/' . $doneId . '/commit', ['csrf' => $ed->csrf()] + self::MAP);
        $this->assertSame(303, $commit['status'], substr($commit['body'], 0, 300));
        $before = $this->imports();
        $eli = $this->signedIn('eli');
        $token = $eli->csrf();
        foreach ([$id, $doneId] as $target) {
            foreach ([['GET', ''], ['HEAD', ''], ['POST', '/preview'], ['POST', '/commit'], ['GET', '/preview']] as [$method, $act]) {
                $fields = $method === 'POST' ? ['csrf' => $token] + self::MAP : [];
                $theirs = $eli->req($method, '/import/' . $target . $act, $fields);
                $unknown = $eli->req($method, '/import/999999' . $act, $fields);
                $what = $method . ' /import/' . $target . $act;
                $this->assertSame(404, $theirs['status'], $what);
                $this->assertSame($unknown['status'], $theirs['status'], $what);
                $this->assertSame($unknown['headers']['content-type'] ?? null, $theirs['headers']['content-type'] ?? null, $what);
                $this->assertSame($unknown['body'], $theirs['body'], $what);
            }
        }
        $this->assertSame($before, $this->imports(), 'nothing was saved or imported');
        $this->assertStringNotContainsString('Imported · ', $eli->get('/import?done=' . $doneId)['body'], 'no done notice for another user\'s import');
        // CSRF still comes first: no token is 419 for an unknown id and another user's alike.
        $this->assertSame([419, 419], [$eli->post('/import/' . $id . '/preview', self::MAP)['status'], $eli->post('/import/999999/preview', self::MAP)['status']]);
    }

    public function testTheHistoryListsOnlyYourOwnUnlessYouAreAnAdmin(): void
    {
        $this->upload($this->signedIn('ed'), 'ed-history.csv');
        $this->upload($this->signedIn('eli'), 'eli-history.csv');
        $ed = $this->signedIn('ed')->get('/import')['body'];
        $this->assertStringContainsString('ed-history.csv', $ed);
        $this->assertStringNotContainsString('eli-history.csv', $ed);
        $eli = $this->signedIn('eli')->get('/import')['body'];
        $this->assertStringContainsString('eli-history.csv', $eli);
        $this->assertStringNotContainsString('ed-history.csv', $eli);
        $admin = $this->signedIn('admin')->get('/import')['body'];
        $this->assertStringContainsString('ed-history.csv', $admin);
        $this->assertStringContainsString('eli-history.csv', $admin);
    }

    public function testWritesStillNeedConsent(): void
    {
        $ed = $this->signedIn('ed');
        $id = $this->upload($ed, 'ed-consent.csv');
        $token = $ed->csrf();
        $ed->consent = null;
        $r = $ed->post('/import/' . $id . '/preview', ['csrf' => $token] + self::MAP);
        $this->assertSame(403, $r['status']);
    }

    /** L-1501: a guest (not signed in, guest browsing on, the Guest role with Import) sees no one's imports. */
    public function testAGuestSeesNoImportsAndGetsTheUnknownIdAnswer(): void
    {
        $id = $this->upload($this->signedIn('ed'), 'ed-guest-hidden.csv');
        $this->asGuestWithImport(function (Client $guest) use ($id): void {
            $home = $guest->get('/import');
            $this->assertSame(200, $home['status'], substr($home['body'], 0, 300));
            $this->assertStringNotContainsString('ed-guest-hidden.csv', $home['body']);
            $this->assertStringNotContainsString('Recent imports', $home['body']);
            foreach (['GET', 'HEAD'] as $method) {
                $theirs = $guest->req($method, '/import/' . $id);
                $unknown = $guest->req($method, '/import/999999');
                $this->assertSame([404, $unknown['body']], [$theirs['status'], $theirs['body']], $method);
            }
            $api = $guest->rest('GET', '/api/v1/imports/' . $id);
            $this->assertSame([404, $guest->rest('GET', '/api/v1/imports/999999')['body']], [$api['status'], $api['body']]);
        });
    }

    /** L-1500: a guest may not upload, on the page or through the API; nothing is stored. */
    public function testAGuestCannotUpload(): void
    {
        $this->asGuestWithImport(function (Client $guest): void {
            $before = [$this->imports(), $this->rowsIn('import_rows'), $this->rowsIn('jobs'), $this->stored()];
            $home = $guest->get('/import');
            $this->assertStringNotContainsString('id="imp-upload"', $home['body'], 'no upload form for a guest');
            $this->assertStringContainsString('Imports need a signed-in account.', $home['body']);
            $file = ['csv' => ['name' => 'guest.csv', 'type' => 'text/csv', 'data' => self::CSV]];
            $page = $guest->post('/import', ['csrf' => $guest->csrf()], $file);
            $this->assertSame(403, $page['status'], substr($page['body'], 0, 300));
            $this->assertStringContainsString('Imports need a signed-in account.', $page['body']);
            $this->assertStringNotContainsString('id="imp-upload"', $page['body']);
            $token = $guest->rest('GET', '/api/v1/session')['json']['csrf_token'];
            $api = $guest->req('POST', '/api/v1/imports', [], ['file' => $file['csv']], ['Origin: ' . $guest->base, 'Accept: application/json', 'X-CSRF-Token: ' . $token]);
            $this->assertSame([403, 'permission.denied', 'Imports need a signed-in account. Sign in to import a CSV file.'],
                [$api['status'], $api['json']['code'] ?? null, $api['json']['detail'] ?? null], $api['body']);
            $this->assertSame($before, [$this->imports(), $this->rowsIn('import_rows'), $this->rowsIn('jobs'), $this->stored()], 'nothing stored');
        });
        // A signed-in account still can.
        $this->upload($this->signedIn('ed'), 'ed-after-guest.csv');
    }

    /** Guest browsing on and Import for the Guest role while $run runs; both back as they were after. */
    private function asGuestWithImport(Closure $run): void
    {
        $pdo = testdb(self::$dir);
        $perms = (string)$pdo->query("SELECT perms FROM roles WHERE role_key = 'guest'")->fetchColumn();
        $setting = static function (string $value) use ($pdo): void {
            $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute(['auth_guest_disabled']);
            $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute(['auth_guest_disabled', $value, '2026-01-01']);
        };
        $setting('false');
        $pdo->prepare("UPDATE roles SET perms = ? WHERE role_key = 'guest'")->execute(['["view","import"]']);
        try {
            $this->assertNotNull(self::$server);
            $run(self::$server->client());
        } finally {
            $pdo->prepare("UPDATE roles SET perms = ? WHERE role_key = 'guest'")->execute([$perms]);
            $setting('true');
        }
    }

    private function rowsIn(string $table): int
    {
        return (int)testdb(self::$dir)->query('SELECT count(*) FROM ' . $table)->fetchColumn();
    }

    /** @return list<string> the waiting upload files */
    private function stored(): array
    {
        $files = glob(self::$dir . '/tmp/import-*.csv') ?: [];
        sort($files);

        return $files;
    }

    private function upload(Client $c, string $name): int
    {
        $r = $c->post('/import', ['csrf' => $c->csrf()], ['csv' => ['name' => $name, 'type' => 'text/csv', 'data' => self::CSV]]);
        $this->assertSame(303, $r['status'], substr($r['body'], 0, 300));
        $this->assertMatchesRegularExpression('#^/import/\d+$#', (string)($r['headers']['location'] ?? ''));

        return (int)substr((string)$r['headers']['location'], 8);
    }

    /** @return list<array<string,mixed>> */
    private function imports(): array
    {
        return testdb(self::$dir)->query('SELECT id, status, mapping, options, result FROM imports ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function signedIn(string $user): Client
    {
        $this->assertNotNull(self::$server);
        $c = self::$server->client();
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];
        $in = $c->rest('POST', '/api/v1/session', ['username' => $user, 'password' => self::PASSWORD], ['X-CSRF-Token: ' . $token]);
        $this->assertSame(200, $in['status'], $in['body']);

        return $c;
    }
}
