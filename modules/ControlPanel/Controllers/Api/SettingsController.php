<?php

declare(strict_types=1);

namespace Apotheke\ControlPanel\Controllers\Api;

use Apotheke\ControlPanel\Controllers\ControlPanelPages;
use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Http\ApiResponse;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /api/v1/settings and GET /api/v1/settings/{section} (API plan §3, P2 settings read): what the Control Panel's
 * section forms show, with each field's schema, for the settings permission. Only the keys the generic forms edit
 * (SettingsSchema::sectionKeys) are read, so a write-only secret or an internal key is never in an answer; autofill
 * adds only whether a token is set. Users & sign-in is the users permission's (its own API), and System is not
 * settings. Each section has an ETag over its answer; If-None-Match with it answers 304.
 */
final class SettingsController extends ControlPanelPages
{
    /** The sections this API reads (the Control Panel's forms but System). */
    public const SECTIONS = ['general', 'appearance', 'locale', 'list', 'items', 'limits', 'labels', 'uploads', 'autofill', 'data'];

    /** GET /api/v1/settings */
    public function index(): ResponseInterface
    {
        return ApiResponse::json($this->response, ['sections' => array_map(fn (string $s): array => $this->section($s), self::SECTIONS)]);
    }

    /** GET /api/v1/settings/{section} */
    public function show(string $section): ResponseInterface
    {
        $body = $this->section($section);
        if (self::matches($this->request->getHeaderLine('If-None-Match'), $body['etag'])) {
            return $this->response->setStatusCode(304)->setHeader('ETag', $body['etag'])
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')->setBody('');
        }

        return ApiResponse::json($this->response, $body)->setHeader('ETag', $body['etag']);
    }

    /** Whether an If-None-Match header names $etag (weak comparison, RFC 9110 §13.1.2). */
    public static function matches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        foreach (explode(',', $header) as $tag) {
            $tag = trim($tag);
            if ((str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag) === $etag) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function section(string $section): array
    {
        $settings = $this->settings();
        $schema = $settings->schema();
        $definitions = $schema->definitions();
        /** @var ApothekeConfig $config */
        $config = service('apothekeConfig');
        $values = [];
        $fields = [];
        foreach ($schema->sectionKeys($section) as $key) {
            [, $label, $type, , $help, $rules] = $definitions[$key];
            $values[$key] = $settings->get($key);
            $fields[] = ['key' => $key, 'label' => $label, 'type' => $type, 'default' => $schema->default($key, $config->values()),
                'help' => $help, 'rules' => (object)$rules];
        }
        [$title, $description] = $schema::SECTIONS[$section];
        $body = ['section' => $section, 'title' => $title, 'description' => $description, 'values' => (object)$values, 'fields' => $fields];
        if ($section === 'autofill') {
            $body['token_set'] = $settings->autofillToken() !== '';
        }
        $body['etag'] = '"' . hash('sha256', (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';

        return $body;
    }
}
