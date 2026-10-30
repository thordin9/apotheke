<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * API P3, CSV import (API plan §3, Import and export row): POST /api/v1/imports, GET /api/v1/imports/{id} and GET
 * /api/v1/imports/{id}/preview, for the import permission. The preview writes nothing; the HTML pages are unchanged.
 */
final class Ci4ApiImportsTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private const CSV = "Name;Location;Tags;Qty\nLadder;Shed › Wall;red;2\nRope;Garage;;\nBox;Attic;;x\n";

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-imports');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
        testdb(self::$dir)->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute(['vera', 'vera', password_hash(self::PASSWORD, PASSWORD_DEFAULT), 'viewer', 'active', '2026-01-01 00:00:00']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        testdb_drop(self::$dir);
        rrmdir(self::$dir);
        @unlink(self::$dir . '.server.log');
        self::$server = null;
    }

    public function testSignedOutIs401AndAViewerIs403(): void
    {
        $id = $this->upload($this->signedIn('admin'))['json']['id'];
        foreach ([[$this->client(), 401, 'auth.required'], [$this->signedIn('vera'), 403, 'permission.denied']] as [$c, $status, $code]) {
            foreach (['/api/v1/imports/' . $id, '/api/v1/imports/' . $id . '/preview'] as $path) {
                $r = $c->rest('GET', $path);
                $this->assertSame([$status, $code], [$r['status'], $r['json']['code'] ?? null], $path);
                ApiContract::assertMatches('GET', $path, $r);
            }
            $r = $this->upload($c);
            $this->assertSame([$status, $code], [$r['status'], $r['json']['code'] ?? null], 'upload');
        }
    }

    public function testAnUploadAndItsColumns(): void
    {
        $admin = $this->signedIn('admin');
        $r = $this->upload($admin);
        $this->assertSame(201, $r['status'], $r['body']);
        ApiContract::assertMatches('POST', '/api/v1/imports', $r);
        $id = $r['json']['id'];
        $this->assertSame('/api/v1/imports/' . $id, $r['headers']['location']);
        $this->assertSame(['uploaded', 3, 'items.csv'], [$r['json']['status'], $r['json']['total_rows'], $r['json']['file_name']]);
        $this->assertSame([['index' => 0, 'header' => 'Name', 'field' => 'name'], ['index' => 1, 'header' => 'Location', 'field' => 'location'],
            ['index' => 2, 'header' => 'Tags', 'field' => 'tags'], ['index' => 3, 'header' => 'Qty', 'field' => 'quantity']], $r['json']['columns']);
        $this->assertSame(['Ladder', 'Shed › Wall', 'red', '2'], $r['json']['sample'][0]);
        $this->assertSame('name', $r['json']['fields'][1]['key']);
        $show = $admin->rest('GET', '/api/v1/imports/' . $id);
        $this->assertSame(200, $show['status']);
        ApiContract::assertMatches('GET', '/api/v1/imports/' . $id, $show);
        $this->assertSame($r['json'], $show['json']);

        $none = $admin->req('POST', '/api/v1/imports', [], ['other' => ['name' => 'a.csv', 'type' => 'text/csv', 'data' => self::CSV]], $this->writeHeaders($admin));
        $this->assertSame([422, 'validation.failed', 'Choose a CSV file to import.'], [$none['status'], $none['json']['code'] ?? null, $none['json']['errors']['file'] ?? null]);
        ApiContract::assertMatches('POST', '/api/v1/imports', $none);
        $noToken = $admin->req('POST', '/api/v1/imports', [], ['file' => ['name' => 'a.csv', 'type' => 'text/csv', 'data' => self::CSV]], ['Origin: ' . $admin->base, 'Accept: application/json']);
        $this->assertSame([403, 'csrf.invalid'], [$noToken['status'], $noToken['json']['code'] ?? null]);
        foreach (['/api/v1/imports/999999', '/api/v1/imports/999999/preview'] as $path) {
            $this->assertSame([404, 'not_found'], [$admin->rest('GET', $path)['status'], $admin->rest('GET', $path)['json']['code'] ?? null], $path);
        }
    }

    public function testThePreviewPagesAndWritesNothing(): void
    {
        $admin = $this->signedIn('admin');
        $id = $this->upload($admin)['json']['id'];
        $before = $this->counts();
        $path = '/api/v1/imports/' . $id . '/preview';
        $r = $admin->rest('GET', $path . '?create=1');
        $this->assertSame(200, $r['status'], $r['body']);
        ApiContract::assertMatches('GET', $path, $r);
        $this->assertSame(['rows' => 3, 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'errors' => 1,
            'made' => ['location' => ['Shed', 'Wall', 'Garage'], 'category' => [], 'tag' => ['red']]], $r['json']['summary']);
        $this->assertSame(['line' => 4, 'state' => 'error', 'name' => 'Box', 'error' => 'Quantity “x” is not a whole number from 0 to 1,000,000.'], $r['json']['data'][2]);
        $this->assertSame(['create' => true, 'skip_errors' => false], $r['json']['options']);
        $known = $admin->rest('GET', $path . '?create=0');
        $this->assertSame(3, $known['json']['summary']['errors'], 'without create, unknown places and tags are errors');

        $page = $admin->rest('GET', $path . '?map=name,,,&per_page=1&page=2');
        $this->assertSame(200, $page['status'], $page['body']);
        ApiContract::assertMatches('GET', $path, $page);
        $this->assertSame(['name', '', '', ''], $page['json']['mapping']);
        $this->assertSame([['line' => 3, 'state' => 'new', 'name' => 'Rope', 'error' => '']], $page['json']['data']);
        $this->assertSame(['page' => 2, 'per_page' => 1, 'total' => 3], $page['json']['meta']);
        $this->assertStringEndsWith('page=3', (string)$page['json']['links']['next']);
        $this->assertStringEndsWith('page=1', (string)$page['json']['links']['prev']);
        $this->assertStringContainsString('rel="next"', $page['headers']['link'] ?? '');
        $saved = array_column($admin->rest('GET', '/api/v1/imports/' . $id)['json']['columns'], 'field');
        $this->assertSame(['name', 'location', 'tags', 'quantity'], $saved, 'a mapping in the query is not saved');

        foreach (['map=,location,,' => 'Choose which column holds the item name.', 'map=name,name,,' => '“Name” is chosen for two columns.'] as $q => $why) {
            $bad = $admin->rest('GET', $path . '?' . $q);
            $this->assertSame([422, 'validation.failed', $why], [$bad['status'], $bad['json']['code'] ?? null, $bad['json']['errors']['map'] ?? null], $q);
            ApiContract::assertMatches('GET', $path, $bad);
        }
        $this->assertSame($before, $this->counts(), 'the previews wrote nothing');
    }

    public function testADoneImport(): void
    {
        $admin = $this->signedIn('admin');
        $id = $this->upload($admin)['json']['id'];
        testdb(self::$dir)->prepare("UPDATE imports SET status = 'done', result = ?, finished_at = created_at WHERE id = ?")->execute(['{"created":2}', $id]);
        $show = $admin->rest('GET', '/api/v1/imports/' . $id);
        $this->assertSame([200, 'done', [], ['created' => 2]], [$show['status'], $show['json']['status'], $show['json']['columns'], $show['json']['result']]);
        ApiContract::assertMatches('GET', '/api/v1/imports/' . $id, $show);
        $preview = $admin->rest('GET', '/api/v1/imports/' . $id . '/preview');
        $this->assertSame([409, 'import.done'], [$preview['status'], $preview['json']['code'] ?? null]);
        ApiContract::assertMatches('GET', '/api/v1/imports/' . $id . '/preview', $preview);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $pdo = testdb(self::$dir);
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $tables = $pdo->query($sqlite ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            : 'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()')->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($tables as $t) {
            $out[(string)$t] = (int)$pdo->query('SELECT COUNT(*) FROM "' . $t . '"')->fetchColumn();
        }
        ksort($out);

        return $out;
    }

    /** @return array{status:int,headers:array<string,string>,body:string,json:mixed} */
    private function upload(Client $c): array
    {
        return $c->req('POST', '/api/v1/imports', [], ['file' => ['name' => 'items.csv', 'type' => 'text/csv', 'data' => self::CSV]], $this->writeHeaders($c));
    }

    /** @return list<string> */
    private function writeHeaders(Client $c): array
    {
        $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'];

        return ['Origin: ' . $c->base, 'Sec-Fetch-Site: same-origin', 'Accept: application/json', 'X-CSRF-Token: ' . $token];
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
