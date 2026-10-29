<?php

declare(strict_types=1);

namespace Apotheke\ControlPanel\Controllers;

use Apotheke\ControlPanel\Views\AutofillView;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\WantsJson;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Control Panel → AI autofill: POST /settings/autofill/token, /test and
 * /test-photo (legacy public/index.php). The token is write-only: a refused
 * one shows the page with the message, never the value. A Test answers fetch
 * with the result and its HTML, and a plain form post with the page, the
 * typed values kept. The stored token only ever goes to the stored address
 * (TokenPolicy); a typed, unsaved address gets the token typed in the form or
 * none.
 */
final class AutofillSettingsController extends ControlPanelPages
{
    public function token(): ResponseInterface
    {
        $settings = $this->settings();
        if ((string)$this->request->getPost('action') === 'clear') {
            $settings->clearAutofillToken();

            return $this->done('token-cleared');
        }
        try {
            $settings->setAutofillToken($this->post('token'));
        } catch (UserError $e) {
            return $this->page($this->sectionPage('autofill', [], ['autofill_token' => $e->getMessage()], null), 422);
        }
        $this->autofill()->primeInfo();   // a token-protected service: cache its /v1/info now (at most 5 s, failures ignored)

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
        $settings = $this->settings();
        $autofill = $this->autofill();
        $raw = $this->request->getPost('autofill_url');
        $url = is_string($raw) ? $raw : (string)$settings->get('autofill_url');
        $to = (string)($this->request->getPost('autofill_timeout') ?? '');
        $timeout = ctype_digit($to) ? max(3, min(60, (int)$to)) : (int)$settings->get('autofill_timeout');
        $checked = $autofill->tokens()->validatedAddress($url);
        if ($checked['bad']) {
            $r = [
                'ok' => false,
                'code' => 'invalid_url',
                'message' => 'That is not a valid service address. Only http:// and https:// addresses are supported, e.g. https://autofill.example.',
            ];
        } else {
            $token = $autofill->tokens()->forTest($checked['url'], (string)($this->request->getPost('autofill_token') ?? ''));
            if (!$photo) {
                $r = $autofill->test($checked['url'], $timeout, $token);
            } else {
                $model = $this->request->getPost('autofill_model');
                $sizeRaw = (string)($this->request->getPost('autofill_image_size') ?? '');
                $r = $autofill->testPhoto($checked['url'], $timeout, $token, is_string($model) ? $model : null, ctype_digit($sizeRaw) ? (int)$sizeRaw : null);
            }
        }
        if ($this->wantsJsonHere()) {
            return $this->json($r + ['html' => (new AutofillView($autofill, null))->resultHtml($r)]);
        }
        // A plain form post: the page again, with the values as typed and the result.
        $values = [];
        foreach ($settings->schema()->sectionKeys('autofill') as $key) {
            $v = $this->request->getPost($key);
            if ($v !== null) {
                $values[$key] = $v;
            }
        }
        if (is_array($values['autofill_enabled'] ?? null)) {
            unset($values['autofill_enabled']);
        }

        return $this->page($this->sectionPage('autofill', $values, [], null, [], $r), 200, true);
    }

    private function done(string $done): ResponseInterface
    {
        $to = '/settings/autofill?done=' . $done;
        if ($this->wantsJsonHere()) {
            return $this->json(['ok' => true, 'redirect' => $to]);
        }
        // A relative Location, as legacy sends (#76 L-2). A RedirectResponse is
        // never stored as the previous URL.
        return redirect()->setStatusCode(303)->setHeader('Location', $to)->setHeader('Cache-Control', 'no-store');
    }

    /** Legacy wants_json(), also when the header only reached CI4's header list. */
    private function wantsJsonHere(): bool
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
