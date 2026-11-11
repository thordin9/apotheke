<?php

declare(strict_types=1);

namespace Apotheke\Settings;

use Apotheke\Core\Storage\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** BrandStore on the selected storage, under the `brand/` prefix (legacy brand_dir()). */
final class LocalBrandStore implements BrandStore
{
    public function __construct(private readonly Storage $files)
    {
    }

    public function has(string $name): bool
    {
        $key = $this->key($name);

        return $key !== null && $this->files->exists($key);
    }

    public function read(string $name): ?string
    {
        $key = $this->key($name);

        return $key === null ? null : $this->files->read($key);
    }

    public function size(string $name): ?int
    {
        $bytes = $this->read($name);

        return $bytes === null ? null : strlen($bytes);
    }

    public function modifiedAt(string $name): ?int
    {
        $key = $this->key($name);

        return $key === null ? null : $this->files->modifiedAt($key);
    }

    public function put(string $name, string $bytes): void
    {
        $key = $this->key($name);
        if ($key === null) {
            throw new InvalidArgumentException('Not a brand file name.');
        }
        try {
            $this->files->write($key, $bytes);
        } catch (Throwable) {
            throw new RuntimeException('Server could not save the logo.');
        }
    }

    public function clear(): void
    {
        foreach (array_keys(self::FILES) as $name) {
            $this->files->delete('brand/' . $name);
        }
    }

    private function key(string $name): ?string
    {
        return isset(self::FILES[$name]) ? 'brand/' . $name : null;
    }
}
