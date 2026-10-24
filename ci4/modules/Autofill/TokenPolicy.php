<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;

/**
 * Which token a connection test may send. The stored token goes only to
 * the saved address. SettingsService is the only reader of the token.
 */
final class TokenPolicy
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function normalise(string $url): string
    {
        $url = rtrim(trim($url), '/');

        return (string)preg_replace_callback(
            '#^([a-z][a-z0-9+.-]*://)([^/?\#@]+)#i',
            static fn (array $m): string => strtolower($m[1] . $m[2]),
            $url,
        );
    }

    public function isSavedUrl(string $url): bool
    {
        $saved = (string)$this->settings->get('autofill_url');

        return $saved !== '' && $this->normalise($url) === $this->normalise($saved);
    }

    /** A token typed in the form wins. Otherwise the stored token, and only for the saved address. */
    public function forTest(string $url, string $typed = ''): string
    {
        $typed = trim($typed);
        if ($typed !== '') {
            return $typed;
        }

        return $this->isSavedUrl($url) ? $this->settings->autofillToken() : '';
    }

    /**
     * The address a Test button may use. `bad` is an address that was typed
     * but is not http(s). An empty address is not bad: the test reports
     * "not configured".
     *
     * @return array{url:string,bad:bool}
     */
    public function validatedAddress(string $raw): array
    {
        try {
            return ['url' => (string)(new SettingsSchema())->validate('autofill_url', $raw), 'bad' => false];
        } catch (UserError) {
            return ['url' => '', 'bad' => trim($raw) !== ''];
        }
    }
}
