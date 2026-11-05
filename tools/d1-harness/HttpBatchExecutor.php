<?php

declare(strict_types=1);

use Apotheke\Core\Database\BatchExecutor;

/** BatchExecutor over the local harness Worker (env.DB.batch()); local wrangler dev only. */
final class HttpBatchExecutor implements BatchExecutor
{
    public int $calls = 0;

    public function __construct(private readonly string $base = 'http://127.0.0.1:8799')
    {
    }

    public function run(array $statements): array
    {
        $r = $this->post('/batch', $statements);

        return array_map(static fn (array $x): array => $x['rows'], $r['results']);
    }

    /** @return array<string,mixed> the raw response (rows, changes, last_row_id per statement) */
    public function raw(array $statements): array
    {
        return $this->post('/batch', $statements);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->post('/query', ['sql' => $sql, 'params' => $params])['rows'];
    }

    /** env.DB.exec(): several statements in one string, no bindings. @return array<string,mixed> */
    public function exec(string $sql): array
    {
        return $this->post('/exec', ['sql' => $sql]);
    }

    /** @return array<string,mixed> */
    private function post(string $path, array $body): array
    {
        $this->calls++;
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "content-type: application/json\r\n",
            'content' => json_encode($body, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'timeout' => 60]]);
        $out = file_get_contents($this->base . $path, false, $ctx);
        $r = json_decode((string)$out, true, 512, JSON_THROW_ON_ERROR);
        if (!($r['ok'] ?? false)) {
            throw new RuntimeException((string)($r['error'] ?? 'harness error'));
        }

        return $r;
    }
}
