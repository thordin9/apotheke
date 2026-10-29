<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Apotheke\Core\Storage\Storage;
use RuntimeException;

/**
 * Temporary analysis JPEGs. Only a key this process created under
 * `autofill/tmp/` is deleted, never a stored photo.
 */
final class TempFile
{
    public function __construct(private readonly Storage $storage)
    {
    }

    /** Store the JPEG and return its key. */
    public function putJpeg(string $jpeg): string
    {
        $key = 'autofill/tmp/autofill-' . bin2hex(random_bytes(8)) . '.jpg';
        try {
            $this->storage->write($key, $jpeg);
        } catch (\Throwable $e) {
            $this->release($key);
            throw new RuntimeException('Could not store the analysis copy.', 0, $e);
        }

        return $key;
    }

    /** Delete only an analysis copy. Any other key is left alone. */
    public function release(?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }
        $base = basename($key);
        if (!preg_match('/^autofill-[0-9a-f]{16}\.jpg$/D', $base)) {
            return;
        }
        if ($key !== 'autofill/tmp/' . $base) {
            return;
        }
        $this->storage->delete($key);
    }
}
