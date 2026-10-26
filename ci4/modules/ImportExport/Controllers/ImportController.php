<?php

declare(strict_types=1);

namespace Apotheke\ImportExport\Controllers;

use Apotheke\Catalog\Controllers\CatalogController;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\ImportExport\CsvExport;
use Apotheke\ImportExport\Imports;
use Apotheke\ImportExport\Views\ImportView;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Legacy public/index.php: GET/POST /import (upload and history),
 * /import/{id}(/preview|commit) (mapping, dry run and import) and GET
 * /export.csv. Every route needs "import", the export "export".
 */
final class ImportController extends CatalogController
{
    /** What legacy's started session sends where it does not call no_store() (PHP's "nocache" limiter). */
    private const SESSION_CACHE = 'no-store, no-cache, must-revalidate';

    /** GET /import (the upload form and the history), POST /import (upload) */
    public function index(): ResponseInterface
    {
        $imports = $this->imports();
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
        $toast = $d !== null && $d['result'] ? 'Imported · ' . Imports::summaryText($d['result']) : null;

        return $this->html($this->pages()->home(null, $toast, $imports->recent()));
    }

    /** /import/{id} and /import/{id}/(preview|commit), in legacy's order of checks */
    public function import(string $id, string $act = ''): ResponseInterface
    {
        $imports = $this->imports();
        $imp = $imports->get((int)$id) ?? throw new UserError('Import not found.', 404);
        if ($imp['status'] === 'done') {
            return $this->redirectTo('/import?done=' . $imp['id']);
        }
        [$header, $rows] = $imports->rows($imp);
        $post = $this->request->getMethod() === 'POST';
        if ($act === '' && !$post) {
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

            return $this->sessionPage($this->pages()->map($imp, $header, $rows, $imports->run($imp, false), null));
        } catch (UserError $e) {
            return $this->sessionPage($this->pages()->map($imp, $header, $rows, $act === 'commit' ? $imports->run($imp, false) : null, $e->getMessage()), $e->getCode() ?: 422);
        }
    }

    /** GET /export.csv */
    public function export(): ResponseInterface
    {
        /** @var CsvExport $export */
        $export = service('csvExport');

        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', self::SESSION_CACHE)
            ->setHeader('Content-Disposition', 'attachment; filename="apotheke-' . $this->calendar()->today() . '.csv"')
            ->setBody($export->csv((string)$this->settings()->get('currency')));
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
