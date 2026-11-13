<?php

declare(strict_types=1);

namespace Apotheke\Backup\Controllers\Api;

use Apotheke\Backup\StoredBackups;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\StreamedResponse;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * GET /api/v1/backups (the backups the installation keeps, newest first) and GET /api/v1/backups/{id} (one of
 * them, as the zip). The backup permission, as downloading a backup: each is the whole installation's catalog.
 */
final class BackupsController extends Controller
{
    public function index(): ResponseInterface
    {
        $stored = $this->stored();
        $list = array_map(static fn (array $m): array => [
            'id' => $m['id'], 'kind' => $m['kind'], 'created_at' => $m['created_at'], 'size' => $m['size'], 'sha256' => $m['sha256'],
            'by' => $m['by'], 'download' => '/api/v1/backups/' . $m['id'],
        ], $stored->all());

        return ApiResponse::json($this->response, ['backups' => $list, 'keep' => $stored->keep()])
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
    }

    public function show(string $id): ResponseInterface
    {
        $stored = $this->stored();
        $m = $stored->get($id);
        if ($m === null) {
            return ApiResponse::problem($this->response, 404, 'not_found', 'Backup not found.');
        }
        @set_time_limit(300);

        return StreamedResponse::from($this->response, config(App::class), $stored->bytes($m))->setStatusCode(200)
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="apotheke-backup-' . $m['id'] . '.zip"')
            ->setHeader('Content-Length', (string)$m['size'])
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
    }

    private function stored(): StoredBackups
    {
        /** @var StoredBackups $s */
        $s = service('storedBackups');

        return $s;
    }
}
