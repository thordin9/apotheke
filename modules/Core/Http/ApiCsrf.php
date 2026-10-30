<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * CSRF for /api (API plan §2.1). Every non-safe method needs all of: a
 * Sec-Fetch-Site of same-origin or same-site when the browser sends one, an
 * Origin that is the app's own or one in CORS_ALLOWED_ORIGINS, a JSON or
 * multipart body (or none), and the session's token in X-CSRF-Token. The
 * form field the HTML pages use does not count here.
 */
final class ApiCsrf
{
    public const DETAIL = 'The request was refused: reload and try again.';

    /**
     * @param array<string,mixed> $server
     * @param list<string> $ownOrigins normalised (SameOrigin::ownOrigins())
     * @param list<string> $corsOrigins
     * @return ?array{status:int,code:string,detail:string} null when it passes
     */
    public function refusal(string $method, array $server, string $sessionToken, array $ownOrigins, array $corsOrigins): ?array
    {
        $check = new CsrfCheck();
        if (!$check->applies($method) || strtoupper($method) === 'OPTIONS') {
            return null;
        }
        $get = static fn (string $key): string => is_string($server[$key] ?? null) ? trim($server[$key]) : '';
        $refused = ['status' => 403, 'code' => 'csrf.invalid', 'detail' => self::DETAIL];
        $site = strtolower($get('HTTP_SEC_FETCH_SITE'));
        if ($site !== '' && $site !== 'same-origin' && $site !== 'same-site') {
            return $refused;
        }
        $origin = SameOrigin::normalise($get('HTTP_ORIGIN'));
        if ($origin === '' || !(in_array($origin, $ownOrigins, true) || (new Cors($corsOrigins, ENVIRONMENT === 'development'))->allows($origin))) {
            return $refused;
        }
        $type = strtolower(trim(explode(';', $get('CONTENT_TYPE'))[0]));
        $hasBody = ((int)$get('CONTENT_LENGTH')) > 0 || $get('HTTP_TRANSFER_ENCODING') !== '';
        if (($hasBody || $type !== '') && !in_array($type, ['application/json', 'multipart/form-data'], true)) {
            return ['status' => 415, 'code' => 'unsupported_media_type', 'detail' => 'Send application/json, or multipart/form-data for a file upload.'];
        }
        if (!$check->passes($sessionToken, [], $get('HTTP_X_CSRF_TOKEN'))) {
            return $refused;
        }

        return null;
    }
}
