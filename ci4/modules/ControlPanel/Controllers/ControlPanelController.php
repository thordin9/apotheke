<?php

declare(strict_types=1);

namespace Apotheke\ControlPanel\Controllers;

use Apotheke\Auth\Controllers\SecurityController;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Media\ImageStore;
use Apotheke\Settings\Branding\LogoInstaller;
use Apotheke\Settings\SettingsService;
use Apotheke\Taxonomy\Kind;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Control Panel (legacy public/index.php, "control panel"): GET /settings,
 * GET and POST /settings/{section} with /reset for the sections in SECTIONS,
 * POST /settings/logo and /settings/logo/reset, and the settings file
 * (GET /settings/export.json, POST /settings/import). Users & sign-in is the
 * Auth module's SecurityController; Test connection, Test with a photo and
 * the access token are AutofillSettingsController's.
 */
final class ControlPanelController extends ControlPanelPages
{
    /** The sections this controller draws and saves (legacy SETTINGS_SECTIONS but security). */
    public const SECTIONS = ['general', 'appearance', 'locale', 'list', 'items', 'limits', 'labels', 'uploads', 'autofill', 'data', 'system'];

    /** GET /settings */
    public function index(): ResponseInterface
    {
        return $this->page($this->view()->home(null), 200, true);
    }

    /** GET /settings/{section} */
    public function show(string $section): ResponseInterface
    {
        $toast = SecurityController::TOASTS[$this->query('done') ?? ''] ?? null;

        return $this->page($this->sectionPage($section, [], [], $toast), 200, true);
    }

    /** POST /settings/{section}: the section's settings, all or none. */
    public function save(string $section): ResponseInterface
    {
        $settings = $this->settings();
        $raw = [];
        foreach ($settings->schema()->sectionKeys($section) as $key) {
            $raw[$key] = $this->request->getPost($key) ?? '';
        }
        $newToken = $section === 'autofill' ? trim($this->post('autofill_token')) : '';
        if ($newToken !== '' && !preg_match('/^[\x21-\x7E]{1,512}$/D', $newToken)) {
            return $this->page($this->sectionPage($section, $raw, ['autofill_token' => 'Token: 1–512 printable characters, no spaces.'], null), 422);
        }
        // The stored token belongs to the saved address: the store drops it when the address moves, so it can
        // never follow the address to a host of the admin's choosing. A token entered in the same save replaces it.
        $errors = $settings->save($raw, $newToken !== '' ? ['autofill_token' => $newToken] : []);
        if ($errors !== []) {
            return $this->page($this->sectionPage($section, $raw, $errors, null), 422);
        }
        $cleared = $settings->tokenCleared();
        if ($section === 'items') {
            // The default location and category are stored on the lists (★ on their pages); an unknown id is ignored.
            /** @var TaxonomyRepository $tax */
            $tax = service('taxonomy');
            foreach (Kind::KINDS as $kind) {
                $value = $this->post('default_' . $kind);
                $current = $tax->defaultId($kind);
                if ($value === '' && $current !== null) {
                    $tax->setDefault($kind, $current, false);
                } elseif (ctype_digit($value) && $tax->get($kind, (int)$value) !== null && (int)$value !== $current) {
                    $tax->setDefault($kind, (int)$value, true);
                }
            }
        }
        if ($section === 'autofill') {
            $this->autofill()->primeInfo();   // never fails the save; at most 5 s
        }

        return $this->redirectTo('/settings/' . $section . '?done=' . ($cleared ? 'saved-token-cleared' : 'saved'));
    }

    /** POST /settings/{section}/reset: back to the defaults from config.php. */
    public function reset(string $section): ResponseInterface
    {
        $settings = $this->settings();
        $settings->resetSection($section);

        return $this->redirectTo('/settings/' . $section . '?done=' . ($settings->tokenCleared() ? 'reset-token-cleared' : 'reset'));
    }

    /** GET /settings/export.json: every setting but the secret and internal ones, as a download. */
    public function export(): ResponseInterface
    {
        /** @var Calendar $calendar */
        $calendar = service('calendar');

        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setHeader('Content-Disposition', 'attachment; filename="apotheke-settings-' . $calendar->today() . '.json"')
            ->setBody((string)json_encode($this->settings()->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** POST /settings/import: a settings file (an uploaded file, or the `json` field); invalid values are skipped and listed. */
    public function import(): ResponseInterface
    {
        $file = $this->request->getFile('file');
        $tmp = $file !== null && $file->getError() === UPLOAD_ERR_OK ? $file->getTempName() : '';
        $json = $tmp !== '' ? SettingsService::readImportFile($tmp) : $this->post('json');   // bounded read (L-740)
        try {
            $r = $this->settings()->import($json);
        } catch (UserError $e) {
            return $this->page($this->sectionPage('data', [], ['_' => $e->getMessage()], null), 422);
        }
        $notes = [];
        foreach ($r['errors'] as $key => $message) {
            $notes[] = "Skipped $key: $message";
        }
        if ($r['ignored'] !== []) {
            $notes[] = 'Ignored unknown or protected keys: ' . implode(', ', $r['ignored']);
        }
        if ($r['token_cleared']) {
            $notes[] = 'The AI autofill token was cleared because the service address changed: enter it again under AI autofill.';
        }

        return $this->page($this->sectionPage('data', [], [], 'Imported ' . $r['applied'] . ' setting' . ($r['applied'] === 1 ? '' : 's'), $notes));
    }

    /** POST /settings/logo: SVG, PNG, JPEG or WebP, at most 2 MB. */
    public function logo(): ResponseInterface
    {
        $file = $this->request->getFile('logo');
        /** @var ImageStore $images */
        $images = service('imageStore');
        try {
            if ($file === null) {
                throw new UserError($images->uploadErrorText(UPLOAD_ERR_NO_FILE));
            }
            $tmp = $file->getTempName();
            $this->installer()->install($file->getError(), $tmp, (int)$file->getSize(), $tmp !== '' && is_uploaded_file($tmp), $images->uploadErrorText(...));
        } catch (UserError $e) {
            return $this->page($this->sectionPage('appearance', [], [], null, [$e->getMessage()]), 422);
        }

        return $this->redirectTo('/settings/appearance?done=logo');
    }

    /** POST /settings/logo/reset: the bundled logo again. */
    public function logoReset(): ResponseInterface
    {
        $this->installer()->reset();

        return $this->redirectTo('/settings/appearance?done=logo-reset');
    }

    private function installer(): LogoInstaller
    {
        /** @var LogoInstaller $installer */
        $installer = service('logoInstaller');

        return $installer;
    }
}
