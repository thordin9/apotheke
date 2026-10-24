<?php

declare(strict_types=1);

namespace Apotheke\Autofill\Controllers;

use Apotheke\Autofill\AutofillService;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * POST /autofill/suggest. JSON, no-store. The stored photo is not rewritten.
 * Permission checks arrive with the Auth module; until then this matches
 * legacy with sign-in off.
 */
final class SuggestController extends Controller
{
    public function suggest(): ResponseInterface
    {
        /** @var AutofillService $autofill */
        $autofill = service('autofill');
        $photoId = (string)$this->request->getPost('photo_id');
        if ($photoId !== '') {
            $r = $autofill->suggestPhotoId($photoId);

            return $this->json($r + ['error' => $r['ok'] ? null : ($r['message'] ?? '')]);
        }
        $file = $this->request->getFile('image');
        if ($file === null || !$file->isValid() || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json(['ok' => false, 'code' => 'no_image', 'message' => 'No photo.']);
        }
        /** @var SettingsService $settings */
        $settings = service('settings');
        $max = (int)$settings->get('max_upload_mb') * 1024 * 1024;
        $size = (int)$file->getSize();
        if ($size > $max) {
            $limit = min($max, $autofill->limits()->target(false));

            return $this->json(['ok' => false, 'code' => 'too_large', 'message' => $autofill->limits()->tooLargeMessage($limit)]);
        }
        $r = $autofill->suggestPath($file->getTempName());

        return $this->json($r + ['error' => $r['ok'] ? null : ($r['message'] ?? '')]);
    }

    /** @param array<string,mixed> $data */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode($status);
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->setBody(is_string($json) ? $json : '');

        return $response;
    }
}
