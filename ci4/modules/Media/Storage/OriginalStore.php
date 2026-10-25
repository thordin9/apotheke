<?php

declare(strict_types=1);

namespace Apotheke\Media\Storage;

use Apotheke\Core\Storage\Storage;
use Apotheke\Media\ImageStore;
use Throwable;

/**
 * The byte-identical camera file, under ORIGINALS_DIR (layout as main, PLAN
 * §3.5): `<64hex>.<ext>` plus `map/<32hex>` pointing a served name at it,
 * files 0640. The 64-hex name is not the served name, so a media URL never
 * reveals it, and nothing here is served over HTTP.
 */
final class OriginalStore
{
    /** $files is rooted at ORIGINALS_DIR. */
    public function __construct(private readonly Storage $files)
    {
    }

    /** Keep $bytes as the original of served photo $name. False when it could not be stored. */
    public function save(string $name, string $bytes, string $ext): bool
    {
        if (!ImageStore::validName($name) || preg_match('/^(jpg|png|webp|gif|heic)$/D', $ext) !== 1) {
            return false;
        }
        $base = bin2hex(random_bytes(32)) . '.' . $ext;
        try {
            $this->files->write($base, $bytes);
        } catch (Throwable) {
            return false;
        }
        try {
            $this->files->write($this->mapKey($name), $base);
        } catch (Throwable) {
            $this->files->delete($base);

            return false;
        }

        return true;
    }

    /** The kept bytes for a served name, or null (a photo from before originals were kept has none). */
    public function read(string $name): ?string
    {
        $base = $this->base($name);

        return $base === null ? null : $this->files->read($base);
    }

    /**
     * Give served name $to its own copy of $from's original (legacy
     * image_original_copy()). True when there is nothing to copy (a photo
     * from before originals were kept).
     */
    public function copy(string $from, string $to): bool
    {
        $base = $this->base($from);
        if ($base === null) {
            return true;
        }
        $bytes = $this->files->read($base);

        return $bytes !== null && $this->save($to, $bytes, (string)pathinfo($base, PATHINFO_EXTENSION));
    }

    /** Drop the original and its map entry. */
    public function forget(string $name): void
    {
        if (!ImageStore::validName($name)) {
            return;
        }
        $base = $this->base($name);
        if ($base !== null) {
            $this->files->delete($base);
        }
        $this->files->delete($this->mapKey($name));
    }

    private function base(string $name): ?string
    {
        if (!ImageStore::validName($name)) {
            return null;
        }
        $base = trim((string)$this->files->read($this->mapKey($name)));

        return preg_match('/^[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$/D', $base) === 1 ? $base : null;
    }

    private function mapKey(string $name): string
    {
        return 'map/' . substr($name, 0, 32);
    }
}
