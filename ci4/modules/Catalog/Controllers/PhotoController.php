<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Controllers;

use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Catalog\Items\PhotoRepository;
use Apotheke\Catalog\Views\ItemView;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Media\ImageStore;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * An item's photos (legacy public/index.php): POST /item/{id}/photos (add),
 * /item/{id}/photos/{pid}/(cover|left|right|delete), /item/{id}/photos/order,
 * and GET/POST /item/{id}/photos/{pid}/edit (crop and rotate).
 */
final class PhotoController extends CatalogController
{
    /** POST /item/{id}/photos: add the photos sent in photos[] and photo. */
    public function add(string $id): ResponseInterface
    {
        $iid = $this->item((int)$id);
        $files = $this->uploads(['photos', 'photo']);
        if ($files === []) {
            throw new UserError('Choose a photo to add.', 422);
        }
        $max = (int)$this->settings()->get('max_photos');
        if (count($this->photos()->list($iid)) + count($files) > $max) {
            throw new UserError('An item can have at most ' . $max . ' photos.', 422);
        }
        /** @var ImageStore $images */
        $images = service('imageStore');
        $added = 0;
        foreach ($files as $f) {
            try {
                $name = $images->store($f->getTempName(), (int)$f->getSize(), $f->getError());
            } catch (UserError $e) {
                throw new UserError($e->getMessage(), 422);
            }
            $this->photos()->add($iid, $name, $max);
            $added++;
        }
        $count = count($this->photos()->list($iid));
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'added' => $added, 'count' => $count]);
        }

        return $this->redirectTo('/item/' . $iid . '?p=' . ($count - 1) . '#photos');
    }

    /** POST /item/{id}/photos/{pid}/(cover|left|right|delete). */
    public function act(string $id, string $pid, string $action): ResponseInterface
    {
        $iid = $this->item((int)$id);
        $idx = $this->photos()->action($iid, (int)$pid, $action);
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'index' => $idx, 'count' => count($this->photos()->list($iid))]);
        }

        return $this->redirectTo('/item/' . $iid . '?p=' . max(0, $idx) . '#photos');
    }

    /** POST /item/{id}/photos/order: ids[] or a comma-separated ids. */
    public function order(string $id): ResponseInterface
    {
        $iid = $this->item((int)$id);
        $raw = $this->request->getPost('ids');
        $ids = is_array($raw) ? array_values($raw) : preg_split('/\s*,\s*/', self::str($raw ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $this->photos()->reorder($iid, $ids === false ? [] : $ids);
        if ($this->wantsJson()) {
            return $this->json(['ok' => true]);
        }

        return $this->redirectTo('/item/' . $iid . '?p=0#photos');
    }

    /** GET /item/{id}/photos/{pid}/edit: the crop and rotate page. */
    public function editForm(string $id, string $pid): ResponseInterface
    {
        $it = $this->repo()->get((int)$id) ?? throw new UserError('Item not found.', 404);
        $ph = $this->photos()->find((int)$it['id'], (int)$pid);

        return $this->html($this->editPage($it, $ph, null));
    }

    /** POST /item/{id}/photos/{pid}/edit: save the rotated and cropped photo as a new version. */
    public function edit(string $id, string $pid): ResponseInterface
    {
        $it = $this->repo()->get((int)$id) ?? throw new UserError('Item not found.', 404);
        $ph = $this->photos()->find((int)$it['id'], (int)$pid);
        $post = $this->request->getPost();
        $post = is_array($post) ? $post : [];
        $pct = static fn (string $k, float $d): float => is_numeric($post['crop_' . $k] ?? null) ? (float)$post['crop_' . $k] / 100 : $d;
        try {
            $r = $this->photos()->edit((int)$it['id'], $ph['id'], self::int($post['rotate'] ?? 0), [$pct('x', 0), $pct('y', 0), $pct('w', 1), $pct('h', 1)]);
        } catch (UserError $e) {
            $code = (int)$e->getCode() ?: 422;
            if ($this->wantsJson()) {
                return $this->json(['ok' => false, 'error' => $e->getMessage()], $code);
            }

            return $this->html($this->editPage($it, $ph, $e->getMessage()), $code);
        }
        if ($this->wantsJson()) {
            return $this->json(['ok' => true] + $r);
        }

        return $this->redirectTo('/item/' . $it['id'] . '?p=' . $ph['index'] . '&edited=1#photos');
    }

    /**
     * @param array<string,mixed> $it
     * @param array{id:int,file:string,position:int,index:int} $ph
     */
    private function editPage(array $it, array $ph, ?string $error): string
    {
        /** @var ImageStore $images */
        $images = service('imageStore');
        /** @var ItemView $view */
        $view = service('itemView');

        return $view->photoEdit($it, $ph, count($this->photos()->list((int)$it['id'])), $images->size($ph['file']), $error);
    }

    /** The item's id; a missing item is legacy's 404. */
    private function item(int $id): int
    {
        return (int)($this->repo()->get($id) ?? throw new UserError('Item not found.', 404))['id'];
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

    private function repo(): ItemRepository
    {
        /** @var ItemRepository $r */
        $r = service('itemRepository');

        return $r;
    }

    private function photos(): PhotoRepository
    {
        /** @var PhotoRepository $p */
        $p = service('photoRepository');

        return $p;
    }
}
