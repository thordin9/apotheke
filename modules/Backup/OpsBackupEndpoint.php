<?php

declare(strict_types=1);

namespace Apotheke\Backup;

use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\OpsSignature;
use Closure;
use Throwable;

/**
 * POST /internal/backup on the host `ops.internal` (operator console C2): the hosting side asks its own container for
 * the backup of a lifecycle job. Answered only on a hosted installation (APOTHEKE_HOSTED), only for exactly that host
 * and path with no forwarding headers (the Worker sets those on every public request it forwards), and only with a
 * valid OpsSignature. Anything else is not this endpoint: handle() returns null and the request goes on as usual
 * (no route serves the path, so 404).
 *
 * Body `{"job_id":"<id>"}`. 200 `{"ok":true,"created":bool,"backup":{…}}`; errors `{"ok":false,"code":…}`.
 */
final class OpsBackupEndpoint
{
    public const HOST = 'ops.internal';

    public const PATH = '/internal/backup';

    private const MAX_BODY = 4096;

    /** Headers the Worker sets on every public request it forwards to the container. */
    private const FORWARDED = ['X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-For', 'X-Apotheke-Client-IP'];

    /** @param Closure():OpsBackups $backups */
    public function __construct(
        private readonly bool $hosted,
        private readonly ?OpsSignature $signature,
        private readonly Closure $backups,
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
            || !$this->signature->verify($lower[strtolower(OpsSignature::HEADER)] ?? null, 'POST', self::PATH, $body, $this->clock->unix())) {
            return self::error('unauthorized', 401);
        }
        $in = json_decode($body, true);
        $jobId = is_array($in) ? ($in['job_id'] ?? null) : null;
        if (!OpsBackups::validJobId($jobId)) {
            return self::error('bad_request', 400);
        }
        try {
            $r = ($this->backups)()->forJob((string)$jobId);
        } catch (OpsBackupError $e) {
            if ($e->status >= 500) {
                error_log('ops backup failed: ' . get_class($e->getPrevious() ?? $e));
            }

            return self::error($e->errorCode, $e->status);
        } catch (Throwable $e) {
            error_log('ops backup failed: ' . get_class($e));

            return self::error('backup_failed', 500);
        }
        $m = $r['backup'];

        return ['status' => 200, 'body' => ['ok' => true, 'created' => $r['created'], 'backup' => [
            'job_id' => (string)$jobId,
            'id' => $m['id'],
            'key' => StoredBackups::PREFIX . $m['id'] . '/manifest.json',
            'prefix' => StoredBackups::PREFIX . $m['id'] . '/',
            'size' => $m['size'],
            'sha256' => $m['sha256'],
            'parts' => $m['parts'],
            'files' => $m['files'] ?? [],
            'created_at' => $m['created_at'],
        ]]];
    }

    /**
     * Whether the request is for this endpoint: hosted, exactly this host and path, and not forwarded by the Worker.
     *
     * @param array<string,string> $headers
     */
    public function applies(string $uri, string $host, array $headers): bool
    {
        if (!$this->hosted || $uri !== self::PATH || strtolower($host) !== self::HOST) {
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
