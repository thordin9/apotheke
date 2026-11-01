<?php

declare(strict_types=1);

namespace Apotheke\Backup\Controllers\Api;

use Apotheke\Backup\Restorer;
use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Security\PermissionGate;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The chunked restore API (backup permission, each upload its owner's only): POST /api/v1/restores {"size", "sha256"}
 * starts an upload; POST /api/v1/restores/{id}/chunks/{n} (multipart `chunk`) sends the chunks in order; GET
 * /api/v1/restores/{id} says how far it is; POST /api/v1/restores/{id}/finish checks it and answers the dry run
 * (Restorer::open, as the page's upload step); POST /api/v1/restores/{id}/confirm {"confirm": true} restores it
 * (Restorer::restore, as the page's confirm step); DELETE /api/v1/restores/{id} cancels it. The storage is
 * RestoreUploads; the backup itself is checked and applied only by Restorer.
 */
final class RestoresController extends Controller
{
    /** HTTP status of a refusal => its problem code */
    private const CODES = [403 => 'permission.denied', 404 => 'not_found', 409 => 'restore.conflict', 413 => 'upload.too_large', 422 => 'validation.failed'];

    /** POST /api/v1/restores */
    public function create(): ResponseInterface
    {
        $body = $this->json();
        if ($body === null) {
            return ApiResponse::problem($this->response, 400, 'bad_request', 'The body is not valid JSON.');
        }

        return $this->answer(function () use ($body): ResponseInterface {
            [$id, $m] = $this->uploads()->create($body['size'] ?? null, $body['sha256'] ?? null);

            return $this->view($id, $m, 201)->setHeader('Location', '/api/v1/restores/' . $id);
        });
    }

    /** GET /api/v1/restores/{id} */
    public function show(string $id): ResponseInterface
    {
        return $this->answer(fn (): ResponseInterface => $this->view($id, $this->uploads()->get($id)));
    }

    /** POST /api/v1/restores/{id}/chunks/{n} */
    public function chunk(string $id, string $index): ResponseInterface
    {
        return $this->answer(function () use ($id, $index): ResponseInterface {
            $f = $this->request->getFile('chunk');
            $uploads = $this->uploads();
            $uploads->get($id);   // not found before anything about the body
            if (!$f instanceof UploadedFile || $f->getError() !== UPLOAD_ERR_OK || !is_uploaded_file($f->getTempName())) {
                $size = $f instanceof UploadedFile && in_array($f->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
                throw new UserError($size ? 'This chunk is larger than the server accepts.' : 'Send the chunk as the multipart field "chunk".', $size ? 413 : 422);
            }
            $n = strlen($index) > 9 ? -1 : (int)$index;

            return $this->view($id, $uploads->append($id, $n, $f->getTempName(), (int)@filesize($f->getTempName())));
        });
    }

    /** POST /api/v1/restores/{id}/finish: the upload is checked, then the backup (a dry run, nothing is changed) */
    public function finish(string $id): ResponseInterface
    {
        return $this->answer(function () use ($id): ResponseInterface {
            $restorer = $this->restorer();
            $dry = $this->uploads()->finish($id, static function (string $zip) use ($restorer): array {
                $b = $restorer->open($zip);
                $b['zip']->close();
                unset($b['zip']);

                return $b;
            });

            return $this->view($id, $this->uploads()->get($id), 200, ['backup' => ['created_at' => $dry['created_at'], 'driver' => $dry['driver'],
                'tables' => (object)$dry['tables'], 'photos' => $dry['photos'], 'warnings' => $dry['warnings']]]);
        }, true);
    }

    /** POST /api/v1/restores/{id}/confirm {"confirm": true} */
    public function confirm(string $id): ResponseInterface
    {
        $body = $this->json();
        if ($body === null) {
            return ApiResponse::problem($this->response, 400, 'bad_request', 'The body is not valid JSON.');
        }

        return $this->answer(function () use ($id, $body): ResponseInterface {
            $this->uploads()->get($id);
            if (($body['confirm'] ?? null) !== true) {
                throw new UserError('Set "confirm": true to replace the catalog with this backup.', 422);
            }
            @set_time_limit(300);
            $restorer = $this->restorer();
            $settings = $this->may('settings');   // N-443: the backup's settings only for a user who may change them
            $done = $this->uploads()->confirm($id, static fn (string $zip): array => $restorer->restore($zip, $settings));

            return ApiResponse::json($this->response, ['restored' => true, 'tables' => (object)$done['tables'], 'photos' => $done['photos'],
                'warnings' => $done['warnings'], 'token_cleared' => $done['token_cleared']]);
        });
    }

    /** DELETE /api/v1/restores/{id} */
    public function delete(string $id): ResponseInterface
    {
        return $this->answer(function () use ($id): ResponseInterface {
            $this->uploads()->delete($id);

            return $this->response->setStatusCode(204)->setBody('')->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
        });
    }

    /**
     * @param \Closure():ResponseInterface $run
     * @param bool $backup a refusal of the backup itself (422) is reported on the field "backup", else "upload"
     */
    private function answer(\Closure $run, bool $backup = false): ResponseInterface
    {
        try {
            return $run();
        } catch (UserError $e) {
            $status = $e->getCode();
            if ($status >= 500) {
                throw $e;   // the API's fixed 500 server_error
            }
            $status = isset(self::CODES[$status]) ? $status : 422;   // the backup's own refusals (Restorer) are 422, as on the page

            return ApiResponse::problem($this->response, $status, self::CODES[$status], $e->getMessage(), $status === 422 ? [$backup ? 'backup' : 'upload' => $e->getMessage()] : []);
        }
    }

    /**
     * @param array{size:int,chunk_size:int,received:int,next:int,status:string} $m
     * @param array<string,mixed> $extra
     */
    private function view(string $id, array $m, int $status = 200, array $extra = []): ResponseInterface
    {
        return ApiResponse::json($this->response, ['id' => $id, 'status' => $m['status'], 'size' => $m['size'], 'chunk_size' => $m['chunk_size'],
            'received' => $m['received'], 'next_chunk' => $m['status'] === 'uploading' ? $m['next'] : null, 'idle_seconds' => RestoreUploads::IDLE] + $extra, $status);
    }

    /** @return array<string,mixed>|null the JSON object body ([] for none), null when it is not JSON */
    private function json(): ?array
    {
        $raw = (string)$this->request->getBody();
        try {
            $body = $raw === '' ? [] : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($body) ? $body : [];
    }

    private function may(string $permission): bool
    {
        /** @var PermissionGate $gate */
        $gate = service('permissionGate');
        try {
            $gate->require($permission);

            return true;
        } catch (UserError) {
            return false;
        }
    }

    private function uploads(): RestoreUploads
    {
        /** @var RestoreUploads $u */
        $u = service('restoreUploads');

        return $u;
    }

    private function restorer(): Restorer
    {
        /** @var Restorer $r */
        $r = service('restorer');

        return $r;
    }
}
