<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/ApiContract.php';

/**
 * API P3: POST /api/v1/imports/{id}/commit starts the import.commit job, `php spark jobs:work` runs it, and GET
 * /api/v1/jobs/{id} shows it only to a user with the import permission who started it or is an admin (404 for anyone
 * else, the same as an id that is not there).
 */
final class Ci4ApiJobsTest extends TestCase
{
    private const PASSWORD = 'long-enough';

    private const CSV = "Name;Location;Qty\nLadder;Shed;2\nRope;Garage;\nBox;Attic;x\n";

    private static ?Server $server = null;

    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = tmpdir('api-jobs');
        self::$server = new Server(self::$dir, ['APP_PASSWORD' => self::PASSWORD], 'ci4');
        self::$server->client()->rest('GET', '/api/v1/session');   // migrates and creates admin
        $users = testdb(self::$dir)->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ([['ed', 'editor'], ['eli', 'editor'], ['vera', 'viewer']] as [$name, $role]) {
            $users->execute([$name, $name, password_hash(self::PASSWORD, PASSWORD_DEFAULT), $role, 'active', '2026-01-01 00:00:00']);
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

    public function testACommitRunsAsAJobAndOnlyItsStarterOrAnAdminSeesIt(): void
    {
        $ed = $this->signedIn('ed');
        $id = $this->upload($ed);
        $path = '/api/v1/imports/' . $id . '/commit';
        $errors = $ed->rest('POST', $path, ['create' => true], $this->writeHeaders($ed));
        $this->assertSame([422, 'validation.failed', '1 row has errors. Fix the file, or set skip_errors.'], [$errors['status'], $errors['json']['code'] ?? null, $errors['json']['detail'] ?? null]);
        ApiContract::assertMatches('POST', $path, $errors);
        $this->assertSame(0, $this->jobs(), 'no job for a file with errors');

        $r = $ed->rest('POST', $path, ['create' => true, 'skip_errors' => true], $this->writeHeaders($ed));
        $this->assertSame(202, $r['status'], $r['body']);
        ApiContract::assertMatches('POST', $path, $r);
        $job = $r['json']['id'];
        $this->assertSame(['import.commit', 'queued', null, null, null], [$r['json']['type'], $r['json']['status'], $r['json']['progress'], $r['json']['result'], $r['json']['error']]);
        $this->assertSame('/api/v1/jobs/' . $job, $r['headers']['location']);
        $again = $ed->rest('POST', $path, [], $this->writeHeaders($ed));
        $this->assertSame([202, $job], [$again['status'], $again['json']['id'] ?? null], 'a second submit follows the same job');
        $this->assertSame(1, $this->jobs());

        $this->work();
        $mine = $ed->rest('GET', '/api/v1/jobs/' . $job);
        $this->assertSame(200, $mine['status'], $mine['body']);
        ApiContract::assertMatches('GET', '/api/v1/jobs/' . $job, $mine);
        $this->assertSame(['done', ['done' => 3, 'total' => 3], ['created' => 2, 'updated' => 0, 'unchanged' => 0, 'skipped' => 1,
            'made' => ['location' => ['Shed', 'Garage'], 'category' => [], 'tag' => []]], null], [$mine['json']['status'], $mine['json']['progress'], $mine['json']['result'], $mine['json']['error']]);
        $this->assertSame(200, $this->signedIn('admin')->rest('GET', '/api/v1/jobs/' . $job)['status'], 'an admin');
        $done = $ed->rest('GET', '/api/v1/imports/' . $id);
        $this->assertSame('done', $done['json']['status']);
        $this->assertSame([409, 'import.done'], [($c = $ed->rest('POST', $path, [], $this->writeHeaders($ed)))['status'], $c['json']['code'] ?? null]);

        foreach (['eli' => 'another user with the import permission', 'vera' => 'a viewer'] as $user => $who) {
            $other = $this->signedIn($user)->rest('GET', '/api/v1/jobs/' . $job);
            $this->assertSame([404, 'not_found', 'Job not found.'], [$other['status'], $other['json']['code'] ?? null, $other['json']['detail'] ?? null], $who);
            ApiContract::assertMatches('GET', '/api/v1/jobs/' . $job, $other);
        }
        $unknown = $ed->rest('GET', '/api/v1/jobs/999999');
        $this->assertSame([404, 'not_found', 'Job not found.'], [$unknown['status'], $unknown['json']['code'] ?? null, $unknown['json']['detail'] ?? null]);
        $this->assertSame([401, 'auth.required'], [($o = $this->client()->rest('GET', '/api/v1/jobs/' . $job))['status'], $o['json']['code'] ?? null]);
    }

    public function testAJobOfATypeWithoutAPermissionIsNeverShownAndErrorsStayGeneric(): void
    {
        $pdo = testdb(self::$dir);
        $insert = $pdo->prepare("INSERT INTO jobs (type, status, payload, error, attempts, max_attempts, actor, run_after, created_at, updated_at)
            VALUES (?, 'failed', '{}', ?, 3, 3, 'admin', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
        $insert->execute(['backup.restore', 'The job stopped on an internal error. The server log has the details.']);
        $other = (int)$pdo->lastInsertId();
        $insert->execute(['import.commit', 'The job stopped on an internal error. The server log has the details.']);
        $failed = (int)$pdo->lastInsertId();
        $admin = $this->signedIn('admin');
        $this->assertSame(404, $admin->rest('GET', '/api/v1/jobs/' . $other)['status'], 'a type with no permission listed');
        $r = $admin->rest('GET', '/api/v1/jobs/' . $failed);
        $this->assertSame(['failed', 'The job stopped on an internal error. The server log has the details.'], [$r['json']['status'], $r['json']['error']]);
        $this->assertSame(['id', 'type', 'status', 'progress', 'result', 'error'], array_keys($r['json']));
    }

    public function testCommitNeedsTheImportPermissionAndCsrf(): void
    {
        $id = $this->upload($this->signedIn('ed'));
        $path = '/api/v1/imports/' . $id . '/commit';
        $vera = $this->signedIn('vera');
        $this->assertSame([403, 'permission.denied'], [($r = $vera->rest('POST', $path, [], $this->writeHeaders($vera)))['status'], $r['json']['code'] ?? null]);
        $ed = $this->signedIn('ed');
        $this->assertSame([403, 'csrf.invalid'], [($r = $ed->rest('POST', $path, [], ['Origin: ' . $ed->base]))['status'], $r['json']['code'] ?? null]);
        $this->assertSame([404, 'not_found'], [($r = $ed->rest('POST', '/api/v1/imports/999999/commit', [], $this->writeHeaders($ed)))['status'], $r['json']['code'] ?? null]);
        $bad = $ed->rest('POST', $path, ['map' => ['', 'location', 'quantity']], $this->writeHeaders($ed));
        $this->assertSame([422, 'Choose which column holds the item name.'], [$bad['status'], $bad['json']['errors']['map'] ?? null]);
    }

    private function work(): void
    {
        $proc = proc_open([PHP_BINARY, ROOT . '/spark', 'jobs:work', '--once'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT,
            array_merge(getenv(), test_server_env(self::$dir, ['APP_PASSWORD' => self::PASSWORD])));
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $this->assertSame(0, proc_close($proc), $out);
    }

    private function jobs(): int
    {
        return (int)testdb(self::$dir)->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
    }

    private function upload(Client $c): int
    {
        $r = $c->req('POST', '/api/v1/imports', [], ['file' => ['name' => 'items.csv', 'type' => 'text/csv', 'data' => self::CSV]], $this->writeHeaders($c));
        $this->assertSame(201, $r['status'], $r['body']);

        return (int)$r['json']['id'];
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
