<?php

declare(strict_types=1);

namespace Apotheke\Backup\Controllers;

use Apotheke\Backup\Backups;
use Apotheke\Backup\Restorer;
use Apotheke\Backup\Views\BackupView;
use Apotheke\Catalog\Controllers\CatalogController;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\StreamedResponse;
use Apotheke\Core\Security\PermissionGate;
use Apotheke\Media\ImageStore;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Generator;

/**
 * Legacy public/index.php: GET /backup (the page), GET /backup.zip (the
 * download), POST /backup/restore (upload and dry run), POST
 * /backup/restore/confirm (restore), all needing "backup", and GET
 * /backup/restore/check, which nginx asks before it accepts a large upload
 * (204 may restore, 401 or 403 otherwise; it never starts a session).
 */
final class BackupController extends CatalogController
{
    /** What legacy's started session sends where it does not call no_store() (PHP's "nocache" limiter). */
    private const SESSION_CACHE = 'no-store, no-cache, must-revalidate';

    /** Bytes per chunk of the download. */
    private const CHUNK = 65536;

    /** GET /backup */
    public function index(): ResponseInterface
    {
        $restored = $this->request->getGet('restored');
        $toast = $restored !== null ? ($restored === 'token-cleared'
            ? 'Backup restored. The AI autofill token was cleared because the service address changed: enter it again under AI autofill.' : 'Backup restored') : null;

        return $this->html($this->pages()->page($this->backups()->counts(), null, null, $toast));
    }

    /** GET /backup.zip: written to DATA_DIR/tmp, sent in chunks, then removed. */
    public function download(): ResponseInterface
    {
        @set_time_limit(300);
        $backups = $this->backups();
        $tmp = $backups->tmpDir() . '/dl-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $backups->create($tmp);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        return StreamedResponse::from($this->response, config(App::class), self::chunks($tmp))->setStatusCode(200)
            ->setHeader('Content-Type', 'application/zip')
            ->setHeader('Content-Disposition', 'attachment; filename="apotheke-backup-' . $this->calendar()->now()->format('Y-m-d-His') . '.zip"')
            ->setHeader('Content-Length', (string)filesize($tmp))
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
    }

    /** POST /backup/restore: keep the upload for an hour and show what it holds. */
    public function upload(): ResponseInterface
    {
        $backups = $this->backups();
        $f = $this->request->getFile('backup');
        if (!$f instanceof UploadedFile || $f->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->fail('Choose a backup file.');
        }
        if ($f->getError() !== UPLOAD_ERR_OK) {
            /** @var ImageStore $images */
            $images = service('imageStore');

            return $this->fail($images->uploadErrorText($f->getError()));
        }
        $token = bin2hex(random_bytes(16));
        $dest = $backups->tmpDir() . '/restore-' . $token . '.zip';
        // N-452: a full disk is this message, with nothing left behind, not a server error.
        if (!is_uploaded_file($f->getTempName()) || !@move_uploaded_file($f->getTempName(), $dest)) {
            @unlink($dest);

            return $this->fail('The upload could not be stored.');
        }
        try {
            $b = $this->restorer()->open($dest);
            $b['zip']->close();
        } catch (UserError $e) {
            @unlink($dest);

            return $this->fail($e->getMessage());
        }

        return $this->html($this->pages()->page($backups->counts(), null, ['token' => $token] + $b));
    }

    /** POST /backup/restore/confirm */
    public function confirm(): ResponseInterface
    {
        $backups = $this->backups();
        $token = self::str($this->request->getPost('token'));
        $file = rtrim($backups->dataDir(), '/') . '/tmp/restore-' . $token . '.zip';
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || !is_file($file)) {
            return $this->sessionPage($this->pages()->page($backups->counts(), 'That restore has expired. Please choose the backup file again.'), 410);
        }
        $restorer = $this->restorer();
        $confirm = $this->request->getPost('confirm');
        if ($confirm === null || $confirm === '' || $confirm === '0' || $confirm === []) {
            $b = $restorer->open($file);
            $b['zip']->close();

            return $this->sessionPage($this->pages()->page($backups->counts(), 'Tick the box to confirm that the catalog will be replaced.', ['token' => $token] + $b), 422);
        }
        @set_time_limit(300);
        try {
            // N-443, stricter than legacy: the backup's settings only for a user who may change settings.
            $restored = $restorer->restore($file, $this->may('settings'));
        } catch (UserError $e) {
            // As legacy: a restore refused while another runs (409, L-450) keeps its upload, so it
            // can be confirmed again; any other failure removes it now, not at the hourly sweep (N-567).
            if ($e->getCode() !== 409) {
                @unlink($file);
            }

            return $this->sessionPage($this->pages()->page($backups->counts(), $e->getMessage()), $e->getCode() === 409 ? 409 : 422);
        } catch (\Throwable $e) {
            @unlink($file);
            throw $e;
        }
        @unlink($file);

        return $this->redirectTo('/backup?restored=' . ($restored['token_cleared'] ? 'token-cleared' : '1'));
    }

    /** GET /backup/restore/check: 204 when the request's user may restore, else 401 (nobody signed in) or 403. */
    public function check(): ResponseInterface
    {
        /** @var PermissionGate $gate */
        $gate = service('permissionGate');
        try {
            $gate->require('backup');
            $status = 204;
        } catch (UserError $e) {
            $status = $e->getMessage() === 'Not signed in' ? 401 : 403;
        }

        return $this->response->setStatusCode($status)->setBody('')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store');
    }

    /** Does the request's user have $permission? */
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

    /** @return Generator<int, string> the file's bytes; the file is removed at the end */
    private static function chunks(string $path): Generator
    {
        $fh = @fopen($path, 'rb');
        try {
            while ($fh !== false && !feof($fh)) {
                $chunk = fread($fh, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                yield $chunk;
            }
        } finally {
            if ($fh !== false) {
                fclose($fh);
            }
            @unlink($path);
        }
    }

    /** Legacy's $fail: 422 and the page with the message. */
    private function fail(string $message): ResponseInterface
    {
        return $this->sessionPage($this->pages()->page($this->backups()->counts(), $message), 422);
    }

    /** A page legacy draws without no_store(): its session's caching header. */
    private function sessionPage(string $html, int $status = 200): ResponseInterface
    {
        return $this->html($html, $status)->removeHeader('Cache-Control')->setHeader('Cache-Control', self::SESSION_CACHE);
    }

    private function backups(): Backups
    {
        /** @var Backups $b */
        $b = service('backups');

        return $b;
    }

    private function restorer(): Restorer
    {
        /** @var Restorer $r */
        $r = service('restorer');

        return $r;
    }

    private function pages(): BackupView
    {
        /** @var BackupView $v */
        $v = service('backupView');

        return $v;
    }
}
