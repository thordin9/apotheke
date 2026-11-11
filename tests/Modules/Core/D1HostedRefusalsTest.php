<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Backup\Controllers\Api\RestoresController;
use Apotheke\Backup\Controllers\BackupController;
use Apotheke\BulkMerge\Controllers\BulkMergeController;
use Apotheke\Catalog\Controllers\ListController;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\PlainHandler;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\ImportExport\Controllers\Api\ImportsController;
use Apotheke\ImportExport\Controllers\ImportController;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * What the hosted database (the D1 driver) refuses for now, end to end through the real controllers, services and
 * views over the bridge: bulk editing, CSV import and backup restore answer 501 (on /api `hosted.unavailable`)
 * before anything is stored, and their pages show a notice instead of the actions. The default install keeps them.
 */
final class D1HostedRefusalsTest extends CIUnitTestCase
{
    private const NOTICE = 'Not available on hosted yet.';

    private ?TestDatabase $t = null;

    /** @var array<string,mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 3);
        $ok = trim((string)shell_exec("node -e \"try{require('node:sqlite');console.log('y')}catch{}\" 2>/dev/null")) === 'y';
        if (!$ok || !is_dir($root . '/edge/node_modules')) {
            $this->markTestSkipped('needs node with node:sqlite and edge/node_modules');
        }
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        Services::reset(true);
        $this->t?->drop();
        $this->t = null;
        parent::tearDown();
    }

    private function site(string $driver): void
    {
        $this->t = TestDatabase::create($driver);
        Services::reset(true);
        Services::injectMock('apothekeDb', $this->t->db);
    }

    /**
     * @template C of Controller
     * @param class-string<C> $class
     * @param array<string,mixed> $post
     * @return C
     */
    private function controller(string $class, string $method = 'GET', array $post = [], string $body = '', array $get = []): Controller
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $request = Services::request(null, false);
        $request = $request->withMethod($method);
        $request->setGlobal('post', $post);
        $request->setGlobal('get', $get);
        $request->setBody($body);
        Services::injectMock('request', $request);
        $c = new $class();
        $c->initController($request, Services::response(null, false), Services::logger());

        return $c;
    }

    private function refusal(string $what, callable $call): UserError
    {
        try {
            $call();
            $this->fail($what . ' must be refused on the hosted database');
        } catch (UserError $e) {
            $this->assertSame([501, $what . ' is not available on the hosted database yet.'], [$e->getCode(), $e->getMessage()]);

            return $e;
        }
    }

    /** @return array<string,mixed> the problem body /api sends for $e */
    private function problem(UserError $e, string $uri): array
    {
        $r = (new PlainHandler())->render($e, Services::response(null, false), ['REQUEST_URI' => $uri]);
        $this->assertSame(501, $r->getStatusCode());

        return json_decode((string)$r->getBody(), true, 8, JSON_THROW_ON_ERROR);
    }

    private function rowCount(string $table): int
    {
        return (int)(Writes::rows($this->t?->db, 'SELECT COUNT(*) AS n FROM ' . $table)[0]['n'] ?? -1);
    }

    public function testTheImportApiAnswersHostedUnavailableBeforeReadingTheUpload(): void
    {
        $this->site('d1');
        $e = $this->refusal('CSV import', fn () => $this->controller(ImportsController::class, 'POST')->create());
        $this->assertSame(['detail' => 'CSV import is not available on the hosted database yet.', 'code' => 'hosted.unavailable'],
            array_intersect_key($this->problem($e, '/api/v1/imports'), ['code' => 1, 'detail' => 1]));
        $this->assertSame(0, $this->rowCount('imports'));
    }

    public function testTheRestoreApiRefusesEveryUploadStepUpFront(): void
    {
        $this->site('d1');
        // Any use of the uploads would fail differently: nothing may be created, appended or finished.
        Services::injectMock('restoreUploads', (new \ReflectionClass(\Apotheke\Backup\RestoreUploads::class))->newInstanceWithoutConstructor());
        $steps = [
            'create' => fn (RestoresController $c) => $c->create(),
            'chunk' => fn (RestoresController $c) => $c->chunk('abc', '0'),
            'finish' => fn (RestoresController $c) => $c->finish('abc'),
            'confirm' => fn (RestoresController $c) => $c->confirm('abc'),
        ];
        foreach ($steps as $step => $run) {
            $e = $this->refusal('Restoring a backup', fn () => $run($this->controller(RestoresController::class, 'POST', [], '{"size":1024,"confirm":true}')));
            $this->assertSame('hosted.unavailable', $this->problem($e, '/api/v1/restores')['code'], $step);
        }
    }

    public function testBulkEditingIs501NotAValidationErrorAndLabelsStillWork(): void
    {
        $this->site('d1');
        foreach (['move', 'tag_add', 'delete'] as $action) {
            $this->refusal('Bulk editing', fn () => $this->controller(BulkMergeController::class, 'POST', ['ids' => ['1'], 'action' => $action, 'tags' => 'x'])->bulk());
        }
        $r = $this->controller(BulkMergeController::class, 'POST', ['ids' => ['1', '2'], 'action' => 'labels'])->bulk();
        $this->assertSame('/labels?ids=1,2', parse_url($r->getHeaderLine('Location'), PHP_URL_PATH) . '?' . parse_url($r->getHeaderLine('Location'), PHP_URL_QUERY));
    }

    public function testHostedPagesShowTheNoticeInsteadOfTheActions(): void
    {
        $this->site('d1');
        $import = $this->controller(ImportController::class)->index();
        $this->assertNotice($import, 'imp-hosted', ['id="imp-upload"']);
        $post = $this->controller(ImportController::class, 'POST')->index();
        $this->assertSame(501, $post->getStatusCode());
        $this->assertStringContainsString('CSV import is not available on the hosted database yet.', (string)$post->getBody());

        $backup = $this->controller(BackupController::class)->index();
        $this->assertNotice($backup, 'restore-hosted', ['id="restore-form"', 'action="/backup/restore"']);
        $this->assertStringContainsString('id="backup-dl"', (string)$backup->getBody(), 'downloading a backup stays');
        foreach (['upload', 'confirm'] as $step) {
            $r = $this->controller(BackupController::class, 'POST', ['token' => str_repeat('a', 32), 'confirm' => '1'])->{$step}();
            $this->assertSame(501, $r->getStatusCode(), $step);
            $this->assertStringContainsString('Restoring a backup is not available on the hosted database yet.', (string)$r->getBody(), $step);
            $this->assertStringNotContainsString('id="restore-preview"', (string)$r->getBody(), $step);
        }

        $list = $this->controller(ListController::class, 'GET', [], '', ['select' => '1'])->index();
        $this->assertNotice($list, 'bulk-hosted', ['id="bulk-move"', 'id="bulk-category"', 'id="bulk-tag-add"', 'id="bulk-delete"']);
        $this->assertStringContainsString('<button class="btn btn-wide" name="action" value="labels" id="bulk-labels">', (string)$list->getBody(), 'QR labels only read');
    }

    public function testTheDefaultInstallKeepsTheActions(): void
    {
        $this->site('sqlite');
        $body = (string)$this->controller(ImportController::class)->index()->getBody();
        $this->assertStringContainsString('id="imp-upload"', $body);
        $backup = (string)$this->controller(BackupController::class)->index()->getBody();
        $this->assertStringContainsString('id="restore-form"', $backup);
        $list = (string)$this->controller(ListController::class, 'GET', [], '', ['select' => '1'])->index()->getBody();
        foreach (['id="bulk-move"', 'id="bulk-delete"', 'id="bulk-labels"'] as $id) {
            $this->assertStringContainsString($id, $list);
        }
        foreach ([$body, $backup, $list] as $page) {
            $this->assertStringNotContainsString(self::NOTICE, $page);
        }
        try {
            $this->controller(BulkMergeController::class, 'POST', ['ids' => [], 'action' => 'move'])->bulk();
            $this->fail('no items selected');
        } catch (UserError $e) {
            $this->assertSame([422, 'Select at least one item.'], [$e->getCode(), $e->getMessage()]);
        }
        $r = $this->controller(ImportsController::class, 'POST')->create();
        $this->assertSame(422, $r->getStatusCode(), 'no file: the usual validation answer');
    }

    /** @param list<string> $absent */
    private function assertNotice(ResponseInterface $r, string $id, array $absent): void
    {
        $body = (string)$r->getBody();
        $this->assertSame(200, $r->getStatusCode());
        $this->assertStringContainsString('<p class="hint" id="' . $id . '" role="status">' . self::NOTICE . '</p>', $body);
        foreach ($absent as $a) {
            $this->assertStringNotContainsString($a, $body);
        }
    }
}
