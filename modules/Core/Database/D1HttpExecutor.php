<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use Apotheke\Core\Exceptions\WriteFailed;
use CodeIgniter\Database\BaseConnection;
use JsonException;
use RuntimeException;

/**
 * The D1 (and DO SQLite relay) executor over HTTP (ADR-002, P4-3): POSTs to
 * `$base/batch`, `/query` and `/exec` the way the container talks to
 * `http://d1.internal`. connection() is null, so a unit of work runs as one
 * batch. Statements are checked by D1Limits first; a guard failure is
 * recognised from D1's error text (N-1640, M-1783). No secrets: the base URL is a
 * loopback or internal name only.
 *
 * The Worker serves these paths on http://d1.internal via D1Outbound
 * (edge/src); tools/d1-harness mirrors them locally. Tests use a stub transport
 * or that harness.
 */
final class D1HttpExecutor implements SqlExecutor
{
    /** Default in the container: the outbound handler's loopback name. */
    public const DEFAULT_BASE = 'http://d1.internal';

    /**
     * @param string $base no trailing slash; must be http(s) to a host the
     *                     container is allowed to reach (d1.internal or a
     *                     local harness). Never a public URL with credentials.
     * @param null|callable(string,string,string):string $transport
     *        (method, url, jsonBody) → raw response body; null uses file_get_contents
     */
    public function __construct(
        private readonly string $base = self::DEFAULT_BASE,
        private readonly mixed $transport = null,
    ) {
        if (preg_match('#^https?://[A-Za-z0-9._-]+(?::\d+)?$#', $this->base) !== 1) {
            throw new RuntimeException('D1 HTTP base must be an http(s) host with no path or userinfo.');
        }
    }

    public function dialect(): Dialect
    {
        return Dialect::sqlite();
    }

    public function connection(): ?BaseConnection
    {
        return null;
    }

    public function run(array $statements): array
    {
        foreach ($statements as $s) {
            D1Limits::refuse($s['sql'], count($s['params']));
        }
        $r = $this->post('/batch', $statements);
        /** @var list<array{rows?:list<array<string,mixed>>}> $results */
        $results = $r['results'] ?? [];

        return array_map(static fn (array $x): array => $x['rows'] ?? [], $results);
    }

    public function query(string $sql, array $params = []): array
    {
        D1Limits::refuse($sql, count($params));
        $r = $this->post('/query', ['sql' => $sql, 'params' => $params]);
        /** @var list<array<string,mixed>> $rows */
        $rows = $r['rows'] ?? [];

        return $rows;
    }

    /**
     * D1's exec(): several statements in one string, no bindings. Used by the
     * D1 migration runner for multi-statement heredocs (spike §3c).
     *
     * @return array{count:int}
     */
    public function exec(string $sql): array
    {
        $r = $this->post('/exec', ['sql' => $sql]);

        return ['count' => (int)($r['count'] ?? 0)];
    }

    /**
     * @param list<array{sql:string,params:list<scalar|null>}>|array{sql:string,params?:list<scalar|null>}|array{sql:string} $body
     * @return array<string,mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WriteFailed('Database write failed: a bound value is not valid UTF-8');
        }
        $url = $this->base . $path;
        $raw = $this->transport !== null
            ? ($this->transport)('POST', $url, $json)
            : $this->filePost($url, $json);
        try {
            /** @var array<string,mixed> $r */
            $r = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new WriteFailed('Database write failed: the batch');
        }
        // L-1784: only a JSON boolean true is success (a string "false" must fail closed).
        if (($r['ok'] ?? null) !== true) {
            $error = is_string($r['error'] ?? null) ? $r['error']
                : (is_string($r['cause'] ?? null) ? $r['cause'] : 'the batch');
            $guard = D1Limits::isGuardFailure($error);
            // Never pass the database message on (it may hold a value).
            throw new WriteFailed(
                'Database write failed: ' . ($guard ? 'a guard in the unit' : 'the batch'),
                $guard ? BatchExecutor::GUARD_FAILED : 0,
            );
        }

        return $r;
    }

    private function filePost(string $url, string $json): string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "content-type: application/json\r\n",
                'content' => $json,
                'ignore_errors' => true,
                'follow_location' => 0, // L-1785: never follow redirects
                'timeout' => 60,
            ],
        ]);
        $out = @file_get_contents($url, false, $ctx);
        if ($out === false) {
            throw new WriteFailed('Database write failed: the batch');
        }

        return $out;
    }
}
