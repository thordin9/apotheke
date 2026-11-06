<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

/** Explicit selection only: objects and local manifests are never mixed. */
final class StorageFactory
{
    /** @param array<string,mixed> $config */
    public static function create(array $config): Storage
    {
        $backend = strtolower((string)($config['storage']['backend'] ?? 'local'));
        if ($backend === 'local') {
            return new LocalStorage((string)($config['data_dir'] ?? 'data'));
        }
        if ($backend !== 'r2') {
            throw new \InvalidArgumentException('Unsupported storage backend.');
        }
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('R2 storage requires optional PHP extension ext-curl.');
        }
        $bytes = $config['storage']['max_write_bytes'] ?? 104857600;
        if (!is_int($bytes) || $bytes < 1) {
            throw new \InvalidArgumentException('Object storage write limit must be a positive integer.');
        }

        return new R2Storage(new R2Bridge((string)($config['storage']['r2_base_url'] ?? 'http://r2.internal')), maxBytes: $bytes);
    }
}
