<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\OpsSignature;
use Closure;
use Throwable;

/**
 * POST /internal/accounts/claim and POST /internal/accounts/reset-admins on the host `ops.internal` (operator console):
 * the hosting side asks its own container for a new first-admin claim (FirstAdminClaim::create) or to remove the
 * hosted admins (HostedAdminReset). Answered like /internal/backup (OpsBackupEndpoint): only on a hosted installation,
 * only for exactly that host and path with no forwarding headers, and only with a valid OpsSignature for that path.
 * Anything else is not this endpoint: handle() returns null and the request goes on as usual (404).
 *
 * claim: 200 `{"ok":true,"token":…,"expires_at":…}`, 409 `active_admin_exists`. reset-admins: 200 `{"ok":true}`.
 * Errors `{"ok":false,"code":…}`. The token is in the answer only: never logged or put in an error.
 */
final class OpsAccountsEndpoint
{
    public const HOST = 'ops.internal';

    public const CLAIM_PATH = '/internal/accounts/claim';

    public const RESET_PATH = '/internal/accounts/reset-admins';

    private const MAX_BODY = 4096;

    /** Headers the Worker sets on every public request it forwards to the container. */
    private const FORWARDED = ['X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-For', 'X-Apotheke-Client-IP'];

    /**
     * @param Closure():FirstAdminClaim $claim
     * @param Closure():HostedAdminReset $reset
     */
    public function __construct(
        private readonly bool $hosted,
        private readonly ?OpsSignature $signature,
        private readonly Closure $claim,
        private readonly Closure $reset,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,string> $headers request headers (any case)
     * @return array{status:int, body:array<string,mixed>, allow?:string}|null null: not this endpoint
     */
    public function handle(string $method, string $uri, string $host, array $headers, string $body): ?array
    {
        if (!$this->applies($uri, $host, $headers)) {
            return null;
        }
        $lower = array_change_key_case($headers, CASE_LOWER);
        if (strtoupper($method) !== 'POST') {
            return ['status' => 405, 'body' => ['ok' => false, 'code' => 'method_not_allowed'], 'allow' => 'POST'];
        }
        if ($this->signature === null) {
            return self::error('not_configured', 503);
        }
        if (strlen($body) > self::MAX_BODY
            || !$this->signature->verify($lower[strtolower(OpsSignature::HEADER)] ?? null, 'POST', $uri, $body, $this->clock->unix())) {
            return self::error('unauthorized', 401);
        }
        try {
            if ($uri === self::RESET_PATH) {
                ($this->reset)()->run();

                return ['status' => 200, 'body' => ['ok' => true]];
            }
            $made = ($this->claim)()->create();

            return ['status' => 200, 'body' => ['ok' => true, 'token' => $made['token'], 'expires_at' => $made['expires_at']]];
        } catch (UserError $e) {
            if ($e->getCode() === 409) {
                return self::error('active_admin_exists', 409);
            }
            error_log('ops accounts failed: ' . get_class($e));
        } catch (Throwable $e) {
            error_log('ops accounts failed: ' . get_class($e));
        }

        return self::error('accounts_failed', 500);
    }

    /**
     * Whether the request is for this endpoint: hosted, exactly this host and one of its paths, and not forwarded by
     * the Worker.
     *
     * @param array<string,string> $headers
     */
    public function applies(string $uri, string $host, array $headers): bool
    {
        if (!$this->hosted || ($uri !== self::CLAIM_PATH && $uri !== self::RESET_PATH) || strtolower($host) !== self::HOST) {
            return false;
        }
        $lower = array_change_key_case($headers, CASE_LOWER);
        foreach (self::FORWARDED as $h) {
            if (isset($lower[strtolower($h)])) {
                return false;
            }
        }

        return true;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function error(string $code, int $status): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'code' => $code]];
    }
}
