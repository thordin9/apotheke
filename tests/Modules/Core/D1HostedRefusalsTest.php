<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Backup\Controllers\BackupController;
use Apotheke\BulkMerge\Controllers\BulkMergeController;
use Apotheke\Catalog\Controllers\ListController;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\ImportExport\Controllers\Api\ImportsController;
use Apotheke\ImportExport\Controllers\ImportController;
use CodeIgniter\Controller;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Tests\Support\TestDatabase;

/**
 * The hosted database (the D1 driver), end to end through the real controllers, services and views over the
 * bridge: the pages that once showed a notice instead of their actions (CSV import, bulk editing, backup restore)
 * now keep every action, and their first steps answer as on the default install.
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



    public function testHostedPagesKeepTheirActions(): void
    {
        $this->site('d1');
        // CSV import runs on the hosted database (D1HostedImportTest): the upload form and no notice.
        $import = (string)$this->controller(ImportController::class)->index()->getBody();
        $this->assertStringContainsString('id="imp-upload"', $import);
        $this->assertStringNotContainsString(self::NOTICE, $import);

        // Restoring a backup runs on the hosted database (D1RestoreTest): the restore form, no notice and no
        // command-line hint; an upload step answers as it does on PDO.
        $backup = (string)$this->controller(BackupController::class)->index()->getBody();
        foreach (['id="restore-form"', 'action="/backup/restore"', 'id="backup-dl"'] as $needle) {
            $this->assertStringContainsString($needle, $backup);
        }
        $this->assertStringNotContainsString('restore-hosted', $backup);
        $this->assertStringNotContainsString('bin/restore.php', $backup);
        $this->assertSame(422, $this->controller(BackupController::class, 'POST')->upload()->getStatusCode(), 'no file chosen');
        $this->assertSame(410, $this->controller(BackupController::class, 'POST', ['token' => str_repeat('a', 32), 'confirm' => '1'])->confirm()->getStatusCode(), 'no such upload');
        // The preview names the database the backup was made on: a hosted backup is not shown as SQLite.
        foreach (['d1' => 'hosted database', 'pgsql' => 'PostgreSQL', 'sqlite' => 'SQLite'] as $driver => $label) {
            $preview = (string)service('backupView')->page(['items' => 0], null, ['token' => str_repeat('b', 32), 'tables' => ['items' => 1],
                'photos' => 0, 'created_at' => '', 'driver' => $driver, 'warnings' => []]);
            $this->assertStringContainsString('(' . $label . ')', $preview, $driver);
        }
        $this->assertStringNotContainsString('(SQLite)', (string)service('backupView')->page([], null, ['token' => str_repeat('b', 32), 'tables' => [],
            'photos' => 0, 'created_at' => '', 'driver' => 'd1', 'warnings' => []]));

        // Bulk editing runs on the hosted database (D1HostedBulkTest): the list keeps every action and no notice.
        $list = (string)$this->controller(ListController::class, 'GET', [], '', ['select' => '1'])->index()->getBody();
        foreach (['id="bulk-move"', 'id="bulk-category"', 'id="bulk-tag-add"', 'id="bulk-delete"', 'id="bulk-labels"'] as $id) {
            $this->assertStringContainsString($id, $list);
        }
        $this->assertStringNotContainsString(self::NOTICE, $list);
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
}
