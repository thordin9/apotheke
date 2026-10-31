<?php

declare(strict_types=1);

namespace Apotheke\ImportExport\Controllers;

use Apotheke\Catalog\Controllers\CatalogController;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\StreamedResponse;
use Apotheke\ImportExport\CsvExport;
use Apotheke\ImportExport\Imports;
use Apotheke\ImportExport\Views\ImportView;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * Legacy public/index.php: GET/POST /import (upload and history),
 * /import/{id}(/preview|commit) (mapping, dry run and import) and GET
 * /export.csv. Every route needs "import", the export "export"; an import and the
 * history are its uploader's or an admin's (N-1441, Imports::mine()).
 */
final class ImportController extends CatalogController
{
    /** What legacy's started session sends where it does not call no_store() (PHP's "nocache" limiter). */
    private const SESSION_CACHE = 'no-store, no-cache, must-revalidate';

    /** GET /import (the upload form and the history), POST /import (upload) */
    public function index(): ResponseInterface
    {
        $imports = $this->imports();
        $imports->sweep();   // N-374: on every upload and listing
        if ($this->request->getMethod() === 'POST') {
            $f = $this->request->getFile('csv');
            $file = $f instanceof UploadedFile
                ? ['error' => $f->getError(), 'size' => (int)$f->getSize(), 'tmp_name' => $f->getTempName(), 'name' => $f->getClientName()] : null;
            try {
                $iid = $imports->upload($file);
            } catch (UserError $e) {
                return $this->sessionPage($this->pages()->home($e->getMessage(), null, $imports->recent()), $e->getCode() ?: 422);
            }

            return $this->redirectTo('/import/' . $iid);
        }
        $done = $this->request->getGet('done');
        $d = $done !== null ? $imports->get(self::int($done)) : null;
        $toast = $d !== null && $imports->mine($d) && $d['result'] ? 'Imported · ' . Imports::summaryText($d['result']) : null;

        return $this->html($this->pages()->home(null, $toast, $imports->recent()));
    }

    /** /import/{id} and /import/{id}/(preview|commit), in legacy's order of checks */
    public function import(string $id, string $act = ''): ResponseInterface
    {
        $imports = $this->imports();
        $imp = $imports->get((int)$id);
        // N-1441: only its uploader or an admin; anyone else gets the unknown id's 404 (Imports::mine(), as the API).
        if ($imp === null || !$imports->mine($imp)) {
            throw new UserError('Import not found.', 404);
        }
        if ($imp['status'] === 'done') {
            return $this->redirectTo('/import?done=' . $imp['id']);
        }
        [$header, $rows] = $imports->rows($imp);
        $method = $this->request->getMethod();   // HEAD is GET here (HeadAsGet)
        $post = $method === 'POST';
        if ($act === '' && $method === 'GET') {
            return $this->html($this->pages()->map($imp, $header, $rows, null, null));
        }
        if ($act === '' || !$post) {
            throw new UserError('Page not found.', 404);
        }
        $fields = $this->request->getPost();
        try {
            [$imp['mapping'], $imp['options']] = $imports->setMapping($imp['id'], $header, is_array($fields) ? $fields : []);
            if ($act === 'commit') {
                $imports->commit($imp);   // null: another submit imported it first; it shows the same answer

                return $this->redirectTo('/import?done=' . $imp['id']);
            }

            $preview = $imports->run($imp, false);
            if ($preview['done_before']) {   // N-373: it waited for a submit that imported it
                return $this->redirectTo('/import?done=' . $imp['id']);
            }

            return $this->sessionPage($this->pages()->map($imp, $header, $rows, $preview, null));
        } catch (UserError $e) {
            return $this->sessionPage($this->pages()->map($imp, $header, $rows, $act === 'commit' ? $imports->run($imp, false) : null, $e->getMessage()), $e->getCode() ?: 422);
        }
    }

    /** GET /export.csv */
    public function export(): ResponseInterface
    {
        /** @var CsvExport $export */
        $export = service('csvExport');
        // Streamed as it is sent (L-370): legacy writes each row to php://output. The items
        // query runs before the first line, so a failing read is still a 500 page.
        return StreamedResponse::from($this->response, config(App::class), $export->lines((string)$this->settings()->get('currency')))->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', self::SESSION_CACHE)
            ->setHeader('Content-Disposition', 'attachment; filename="apotheke-' . $this->calendar()->today() . '.csv"');
    }

    /** A page legacy draws without no_store(): its session's caching header. */
    private function sessionPage(string $html, int $status = 200): ResponseInterface
    {
        return $this->html($html, $status)->removeHeader('Cache-Control')->setHeader('Cache-Control', self::SESSION_CACHE);
    }

    private function imports(): Imports
    {
        /** @var Imports $i */
        $i = service('imports');

        return $i;
    }

    private function pages(): ImportView
    {
        /** @var ImportView $v */
        $v = service('importView');

        return $v;
    }
}
