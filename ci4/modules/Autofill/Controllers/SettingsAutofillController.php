<?php

declare(strict_types=1);

namespace Apotheke\Autofill\Controllers;

use Apotheke\Autofill\AutofillService;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\WantsJson;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * POST /settings/autofill/token, /test and /test-photo.
 *
 * The settings HTML shell is not in CI4 yet, so Test connection and Test
 * with a photo always return the JSON result (the same fields legacy
 * returns to fetch, without the settings-page HTML). The token route
 * still redirects, or returns {ok, redirect} when the caller wants JSON.
 */
final class SettingsAutofillController extends Controller
{
    public function token(): ResponseInterface
    {
        /** @var SettingsService $settings */
        $settings = service('settings');
        /** @var AutofillService $autofill */
        $autofill = service('autofill');
        if ((string)$this->request->getPost('action') === 'clear') {
            $settings->clearAutofillToken();

            return $this->done('token-cleared');
        }
        try {
            $settings->setAutofillToken((string)$this->request->getPost('token'));
        } catch (UserError $e) {
            return $this->json(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
        }
        $autofill->primeInfo();

        return $this->done('token-saved');
    }

    public function test(): ResponseInterface
    {
        return $this->runTest(false);
    }

    public function testPhoto(): ResponseInterface
    {
        return $this->runTest(true);
    }

    private function runTest(bool $photo): ResponseInterface
    {
        /** @var SettingsService $settings */
        $settings = service('settings');
        /** @var AutofillService $autofill */
        $autofill = service('autofill');
        $raw = $this->request->getPost('autofill_url');
        $url = is_string($raw) ? $raw : (string)$settings->get('autofill_url');
        $to = (string)($this->request->getPost('autofill_timeout') ?? '');
        $timeout = ctype_digit($to) ? max(3, min(60, (int)$to)) : (int)$settings->get('autofill_timeout');
        $checked = $autofill->tokens()->validatedAddress($url);
        if ($checked['bad']) {
            return $this->json([
                'ok' => false,
                'code' => 'invalid_url',
                'message' => 'That is not a valid service address. Only http:// and https:// addresses are supported, e.g. https://autofill.example.',
            ]);
        }
        $token = $autofill->tokens()->forTest($checked['url'], (string)($this->request->getPost('autofill_token') ?? ''));
        if (!$photo) {
            return $this->json($autofill->test($checked['url'], $timeout, $token));
        }
        $model = $this->request->getPost('autofill_model');
        $sizeRaw = (string)($this->request->getPost('autofill_image_size') ?? '');

        return $this->json($autofill->testPhoto(
            $checked['url'],
            $timeout,
            $token,
            is_string($model) ? $model : null,
            ctype_digit($sizeRaw) ? (int)$sizeRaw : null,
        ));
    }

    private function done(string $done): ResponseInterface
    {
        $to = '/settings/autofill?done=' . $done;
        if ($this->wantsJson()) {
            return $this->json(['ok' => true, 'redirect' => $to]);
        }
        // A relative Location, as legacy sends (#76 L-2). A RedirectResponse is
        // never stored as the previous URL.
        return redirect()->setStatusCode(303)->setHeader('Location', $to)->setHeader('Cache-Control', 'no-store');
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

    private function wantsJson(): bool
    {
        $server = $this->request->getServer();
        $server = is_array($server) ? $server : [];
        if (($server['HTTP_X_REQUESTED_WITH'] ?? '') === '' && $this->request->hasHeader('X-Requested-With')) {
            $server['HTTP_X_REQUESTED_WITH'] = $this->request->getHeaderLine('X-Requested-With');
        }
        if (($server['HTTP_ACCEPT'] ?? '') === '' && $this->request->hasHeader('Accept')) {
            $server['HTTP_ACCEPT'] = $this->request->getHeaderLine('Accept');
        }

        return (new WantsJson())->check($server);
    }
}
