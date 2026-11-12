<?php

declare(strict_types=1);

use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Migrations\D1Bootstrap;
use Apotheke\Migrations\D1MigrationRunner;
use Apotheke\Migrations\PdoMigrationRunner;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * How many database round trips a request makes, signed in with sign-in on (APP_PASSWORD, as hosted):
 * - on the D1 driver against the real Installation handler (edge/tests/do-bridge-server.mjs), counted at the
 *   driver's transport hook: every /query, /batch and /exec is one round trip to the bridge;
 * - on SQLite, every statement CodeIgniter runs (the DBQuery event).
 * The router is tests/round-trip-router.php. A budget is the most a request may use; it was the number measured
 * when it was set. The signed-in budgets include the session handler's reads and writes (six round trips on D1),
 * which DatabaseSessionHandler makes as before.
 */
final class RoundTripBudgetTest extends TestCase
{
    /** @var array<string,int> request => most round trips to the D1 bridge */
    private const D1 = [
        'GET /healthz' => 1,
        'GET /api/v1/me' => 8,
        'GET /' => 12,
        'GET /item/{id}' => 14,
        'POST /save (new)' => 9,
        'POST /save (edit)' => 13,
    ];

    /** @var array<string,int> request => most statements on SQLite */
    private const SQLITE = [
        'GET /healthz' => 3,
        'GET /api/v1/me' => 8,
        'GET /' => 36,
        'GET /item/{id}' => 19,
        'POST /save (new)' => 17,
        'POST /save (edit)' => 34,
    ];

    private ?string $dir = null;

    /** @var resource|null */
    private $bridge = null;

    protected function tearDown(): void
    {
        if (is_resource($this->bridge)) {
            proc_terminate($this->bridge);
            proc_close($this->bridge);
        }
        $this->bridge = null;
        if ($this->dir !== null) {
            rrmdir($this->dir);
            @unlink($this->dir . '.server.log');
            @unlink($this->dir . '.trips');
        }
        $this->dir = null;
    }

    public function testD1RequestsStayWithinTheirRoundTrips(): void
    {
        $ok = trim((string)shell_exec("node -e \"try{require('node:sqlite');console.log('y')}catch{}\" 2>/dev/null")) === 'y';
        if (!$ok || !is_dir(ROOT . '/edge/node_modules')) {
            $this->markTestSkipped('needs node with node:sqlite and edge/node_modules');
        }
        $password = bin2hex(random_bytes(12));
        $url = $this->startBridge();
        // The first migration stores "sign-in on" from APP_PASSWORD, as the hosted app's first start does.
        $was = getenv('APP_PASSWORD');
        putenv('APP_PASSWORD=' . $password);
        try {
            (new D1Bootstrap(new D1HttpExecutor($url), new D1MigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations')))->migrate();
        } finally {
            putenv($was === false ? 'APP_PASSWORD' : 'APP_PASSWORD=' . $was);
        }
        $this->assertWithin(self::D1, $this->measure($password, ['DB_DRIVER' => 'd1', 'STORAGE_BACKEND' => 'r2', 'ROUND_TRIP_D1_URL' => $url]));
    }

    public function testSqliteRequestsStayWithinTheirStatements(): void
    {
        if (test_driver() !== 'sqlite') {
            $this->markTestSkipped('the statement budgets are SQLite\'s');
        }
        $this->assertWithin(self::SQLITE, $this->measure(bin2hex(random_bytes(12)), []));
    }

    /**
     * @param array<string,int> $budgets
     * @param array<string,int> $measured
     */
    private function assertWithin(array $budgets, array $measured): void
    {
        $this->assertSame(array_keys($budgets), array_keys($measured));
        $report = json_encode($measured);
        foreach ($budgets as $request => $most) {
            $this->assertGreaterThan(0, $measured[$request], $request . ' ' . $report);
            $this->assertLessThanOrEqual($most, $measured[$request], $request . ' ' . $report);
        }
    }

    /**
     * Sign in, add three items, then count each budgeted request's round trips.
     *
     * @param array<string,string> $env
     * @return array<string,int>
     */
    private function measure(string $password, array $env): array
    {
        $this->dir = tmpdir('round-trips');
        $log = $this->dir . '.trips';
        touch($log);
        $server = new Server($this->dir, $env + ['APP_PASSWORD' => $password, 'APP_KEY' => base64_encode(random_bytes(32)),
            'ALLOWED_HOSTS' => '127.0.0.1', 'ROUND_TRIP_LOG' => $log], null, ROOT . '/tests/round-trip-router.php');
        try {
            $c = $server->client();
            $count = static function (callable $request) use ($log): array {
                clearstatcache();
                $before = count(file($log) ?: []);
                $r = $request();
                clearstatcache();

                return [$r, count(file($log) ?: []) - $before];
            };
            $out = [];
            [$r, $out['GET /healthz']] = $count(static fn (): array => (new Client($server->base))->get('/healthz'));
            $this->assertSame(200, $r['status'], $r['body']);

            $token = $c->rest('GET', '/api/v1/session')['json']['csrf_token'] ?? '';
            $in = $c->rest('POST', '/api/v1/session', ['username' => 'admin', 'password' => $password], ['X-CSRF-Token: ' . $token]);
            $this->assertSame(200, $in['status'], $in['body'] . file_get_contents($server->log));
            $csrf = $c->csrf();
            $this->assertNotSame('', $csrf);
            $save = static fn (array $fields): array => $c->post('/save', $fields + ['csrf' => $csrf, 'quantity' => '1', 'notes' => 'n'], [],
                ['Origin: ' . $server->base, 'Accept: application/json', 'X-Requested-With: fetch']);
            foreach (['Budget one', 'Budget two'] as $name) {
                $this->assertSame(200, $save(['name' => $name, 'tags' => 'alpha, beta'])['status']);
            }

            [$r, $out['POST /save (new)']] = $count(static fn (): array => $save(['name' => 'Budget three', 'tags' => 'alpha']));
            $this->assertSame(200, $r['status'], $r['body']);
            $this->assertMatchesRegularExpression('/saved=(\d+)/', (string)($r['json']['redirect'] ?? ''));
            preg_match('/saved=(\d+)/', (string)$r['json']['redirect'], $m);
            $id = $m[1];
            [$r, $out['POST /save (edit)']] = $count(static fn (): array => $save(['id' => $id, 'name' => 'Budget three, edited', 'tags' => 'alpha, gamma']));
            $this->assertSame(200, $r['status'], $r['body']);
            [$r, $out['GET /item/{id}']] = $count(static fn (): array => $c->get('/item/' . $id));
            $this->assertSame(200, $r['status']);
            $this->assertStringContainsString('Budget three, edited', $r['body']);
            [$r, $out['GET /']] = $count(static fn (): array => $c->get('/'));
            $this->assertSame(200, $r['status']);
            $this->assertStringContainsString('Budget two', $r['body']);
            [$r, $out['GET /api/v1/me']] = $count(static fn (): array => $c->rest('GET', '/api/v1/me'));
            $this->assertSame(200, $r['status'], $r['body']);
            $this->assertSame('admin', $r['json']['username'] ?? null);

            return [
                'GET /healthz' => $out['GET /healthz'],
                'GET /api/v1/me' => $out['GET /api/v1/me'],
                'GET /' => $out['GET /'],
                'GET /item/{id}' => $out['GET /item/{id}'],
                'POST /save (new)' => $out['POST /save (new)'],
                'POST /save (edit)' => $out['POST /save (edit)'],
            ];
        } finally {
            $server->stop();
        }
    }

    private function startBridge(): string
    {
        $proc = proc_open(['node', '--experimental-strip-types', '--no-warnings', ROOT . '/edge/tests/do-bridge-server.mjs'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, ROOT);
        $port = is_resource($proc) ? (int)trim((string)fgets($pipes[1])) : 0;
        $this->assertGreaterThan(0, $port, 'the D1 bridge test server did not start');
        $this->bridge = $proc;

        return 'http://127.0.0.1:' . $port;
    }
}
