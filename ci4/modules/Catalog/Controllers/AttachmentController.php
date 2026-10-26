<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Controllers;

use Apotheke\Catalog\Items\Attachments;
use Apotheke\Media\ImageStore;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * An item's attachments (legacy public/index.php): POST
 * /item/{id}/attachments (add), POST /item/{id}/attachments/{aid}/delete,
 * and GET /attachment/{id}, always a download in a sandbox.
 */
final class AttachmentController extends CatalogController
{
    /** POST /item/{id}/attachments: the files sent in files[] and file. */
    public function add(string $id): ResponseInterface
    {
        $iid = (int)$id;
        /** @var ImageStore $images */
        $images = service('imageStore');
        $uploads = array_map(static fn (UploadedFile $f): array => ['name' => $f->getClientName(), 'tmp' => $f->getTempName(), 'error' => $f->getError()], $this->uploads(['files', 'file']));
        $n = $this->attachments()->add($iid, $uploads, $images->uploadErrorText(...));
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'added' => $n, 'count' => $this->attachments()->count($iid)]);
        }

        return $this->redirectTo('/item/' . $iid . '#attachments');
    }

    /** POST /item/{id}/attachments/{aid}/delete. */
    public function remove(string $id, string $aid): ResponseInterface
    {
        $iid = (int)$id;
        $this->attachments()->delete($iid, (int)$aid);
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'count' => $this->attachments()->count($iid)]);
        }

        return $this->redirectTo('/item/' . $iid . '#attachments');
    }

    /** GET /attachment/{id}: a download that the browser never renders in the app's origin. */
    public function download(string $id): ResponseInterface
    {
        $a = $this->attachments()->get((int)$id);
        if ($a === null) {
            return $this->response->setStatusCode(404)->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody('Not found');
        }
        $bytes = $this->attachments()->read($a['file']);
        if ($bytes === null) {
            return $this->response->setStatusCode(404)->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody('');
        }
        $ascii = (string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', $a['name']);

        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', in_array($a['mime'], array_column(Attachments::TYPES, 0), true) ? $a['mime'] : 'application/octet-stream')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($a['name']))
            ->setHeader('Content-Length', (string)strlen($bytes))
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'private, no-store')
            ->setBody($bytes);
    }

    /**
     * Legacy pick_uploads(): every file sent in the multi-file and single fields.
     *
     * @param list<string> $fields
     * @return list<UploadedFile>
     */
    private function uploads(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $multi = $this->request->getFileMultiple($f);
            $list = $multi ?? (($one = $this->request->getFile($f)) !== null ? [$one] : []);
            foreach ($list as $file) {
                if ($file->getError() !== UPLOAD_ERR_NO_FILE) {
                    $out[] = $file;
                }
            }
        }

        return $out;
    }

    private function attachments(): Attachments
    {
        /** @var Attachments $a */
        $a = service('attachments');

        return $a;
    }
}
