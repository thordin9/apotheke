<?php

declare(strict_types=1);

namespace Apotheke\ControlPanel\Controllers;

use Apotheke\Auth\Controllers\AuthPages;
use Apotheke\Autofill\AutofillService;
use Apotheke\ControlPanel\SystemInfo;
use Apotheke\ControlPanel\Views\AutofillView;
use Apotheke\ControlPanel\Views\ControlPanelView;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Settings\Branding\Branding;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared answers of the Control Panel pages: a section page drawn as legacy
 * view_settings_section() does (re-submitted values first, then the stored
 * ones), and legacy json_out(). Every route needs the `settings` permission
 * (the Permission filter, legacy route_perm()) and the global CSRF filter
 * checks a POST after it, as legacy checks route_perm() and then check_csrf().
 */
abstract class ControlPanelPages extends AuthPages
{
    /**
     * Legacy view_settings_section().
     *
     * @param array<string,mixed> $values re-submitted values by key (null or absent = the stored value)
     * @param array<string,string> $errors
     * @param list<string> $notes
     * @param array<string,mixed>|null $afTest a Test result shown on the AI autofill page
     */
    protected function sectionPage(string $section, array $values, array $errors, ?string $toast, array $notes = [], ?array $afTest = null): string
    {
        $settings = $this->settings();
        $schema = $settings->schema();
        $definitions = $schema->definitions();
        /** @var ApothekeConfig $config */
        $config = service('apothekeConfig');
        $fields = [];
        foreach ($schema->sectionKeys($section) as $key) {
            [, $label, $type, , $help, $options] = $definitions[$key];
            $fields[] = ['key' => $key, 'label' => $label, 'type' => $type, 'default' => $schema->default($key, $config->values()), 'help' => $help,
                'options' => $options, 'value' => $values[$key] ?? $settings->get($key)];
        }
        $extra = [];
        if ($section === 'appearance') {
            /** @var Branding $branding */
            $branding = service('branding');
            $extra['logo'] = (string)$settings->get('logo');
            $extra['logoUrl'] = $branding->logoUrl();
        } elseif ($section === 'system') {
            /** @var SystemInfo $system */
            $system = service('systemInfo');
            $applied = $system->migrations();
            $extra += ['system' => $system->rows(), 'version' => $system->version(), 'engine' => $system->engine(),
                'engineLabel' => $system->engineLabel(), 'schema' => (string)($applied === [] ? '' : $applied[count($applied) - 1]), 'migrations' => count($applied)];
        } elseif ($section === 'autofill') {
            $autofill = $this->autofill();
            $extra['autofill'] = new AutofillView($autofill, isset($afTest['info']) && is_array($afTest['info']) ? $afTest['info'] : $autofill->knownInfo());
            $extra['afTest'] = $afTest;
            $extra['tokenSet'] = $settings->autofillToken() !== '';
            $extra['secretWarning'] = $settings->secretStorageWarning();
        }

        return $this->view()->section($section, $fields, $errors, $toast, $notes, $extra);
    }

    /** @param array<string,mixed> $data legacy json_out() */
    protected function json(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
            ->setBody((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function view(): ControlPanelView
    {
        /** @var ControlPanelView $view */
        $view = service('controlPanelView');

        return $view;
    }

    protected function autofill(): AutofillService
    {
        /** @var AutofillService $autofill */
        $autofill = service('autofill');

        return $autofill;
    }
}
