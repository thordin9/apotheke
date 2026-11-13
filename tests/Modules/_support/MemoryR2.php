<?php

declare(strict_types=1);

namespace Tests\Support;

use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;

/**
 * r2.internal in memory, as edge/src/r2.ts answers it: reads, conditional and multipart writes, deletes and
 * listing, for tests that need object storage that keeps what was written.
 */
final class MemoryR2
{
    /** @var array<string,array{bytes:string,etag:string,modified:int}> */
    public array $objects = [];

    /** @var array<string,array<int,string>> multipart uploads by id */
    private array $uploads = [];

    /** @var list<string> "METHOD key" of each request, in order */
    public array $log = [];

    private int $n = 0;

    /** @param array<string,string> $objects */
    public function __construct(array $objects = [])
    {
        foreach ($objects as $k => $b) {
            $this->set($k, $b);
        }
    }

    public function storage(): R2Storage
    {
        return new R2Storage(new R2Bridge(transport: $this->transport(...)));
    }

    public function set(string $key, string $bytes): void
    {
        $this->objects[$key] = ['bytes' => $bytes, 'etag' => 'e' . (++$this->n), 'modified' => 1_700_000_000 + $this->n];
    }

    /** @return list<string> the keys under $prefix, sorted */
    public function keys(string $prefix = ''): array
    {
        $out = array_values(array_filter(array_keys($this->objects), static fn (string $k): bool => str_starts_with($k, $prefix)));
        sort($out);

        return $out;
    }

    /**
     * @param resource|null $body
     * @return array{status:int,body:string}
     */
    public function transport(string $method, string $url, $body = null): array
    {
        $key = rawurldecode(substr((string)parse_url($url, PHP_URL_PATH), 1));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $action = (string)($q['action'] ?? '');
        $this->log[] = $method . ' ' . $key . ($action !== '' ? ' ' . $action : '');
        $bytes = is_resource($body) ? (string)stream_get_contents($body) : '';
        $found = $this->objects[$key] ?? null;
        if ($method === 'GET' && $action === 'list') {
            $prefix = (string)($q['prefix'] ?? '');
            $base = str_ends_with($key, 'index') ? substr($key, 0, -5) : '';

            return self::ok(['objects' => array_map(static fn (string $k): string => substr($k, strlen($base)), $this->keys($base . $prefix)), 'cursor' => null]);
        }
        if ($method === 'GET') {
            if ($found === null) {
                return self::ok(['found' => false]);
            }

            return self::ok(['found' => true, 'etag' => $found['etag'], 'modified' => $found['modified']]
                + ($action === 'head' ? [] : ['bytes' => base64_encode($found['bytes'])]));
        }
        if ($method === 'DELETE') {
            unset($this->objects[$key]);

            return self::ok();
        }
        if ($method !== 'PUT') {
            return ['status' => 405, 'body' => '{}'];
        }
        if ($action === 'begin') {
            $id = 'up' . (++$this->n);
            $this->uploads[$id] = [];

            return self::ok(['upload_id' => $id]);
        }
        if ($action === 'part') {
            $this->uploads[(string)$q['upload_id']][(int)$q['part']] = $bytes;

            return self::ok(['bytes' => strlen($bytes), 'part_number' => (int)$q['part'], 'etag' => 'p' . (int)$q['part']]);
        }
        if ($action === 'complete') {
            $parts = $this->uploads[(string)$q['upload_id']] ?? [];
            ksort($parts);
            $this->set($key, implode('', $parts));
            unset($this->uploads[(string)$q['upload_id']]);

            return self::ok();
        }
        if ($action === 'abort') {
            unset($this->uploads[(string)$q['upload_id']]);

            return self::ok();
        }
        if ((($q['if_none_match'] ?? null) === '*' && $found !== null) || (isset($q['if_match']) && ($found === null || $found['etag'] !== $q['if_match']))) {
            return ['status' => 412, 'body' => '{"ok":false}'];
        }
        $this->set($key, $bytes);

        return self::ok();
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{status:int,body:string}
     */
    private static function ok(array $extra = []): array
    {
        return ['status' => 200, 'body' => (string)json_encode(['ok' => true] + $extra)];
    }
}
