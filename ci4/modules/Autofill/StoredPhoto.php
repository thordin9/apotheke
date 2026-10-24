<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use CodeIgniter\Database\BaseConnection;

/**
 * The file Analyze should read for a stored photo: the kept original when
 * there is one, otherwise the served JPEG. This class only reads. Media
 * will own the write path; the lookup stays the legacy one until then.
 */
final class StoredPhoto
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly string $imagesDir,
        private readonly string $originalsDir,
    ) {
    }

    /**
     * @return array{ok:true,path:string}|array{ok:false,code:string,message:string}
     */
    public function pathFor(string $photoId): array
    {
        $id = ctype_digit($photoId) ? (int)$photoId : 0;
        $row = $this->db->table('item_photos')->select('file')->where('id', $id)->get()->getRowArray();
        $file = is_array($row) ? (string)($row['file'] ?? '') : '';
        $served = $this->imagesDir . '/' . $file;
        if ($file === '' || !self::servedName($file) || !is_file($served)) {
            return ['ok' => false, 'code' => 'no_image', 'message' => 'No suggestions: that photo no longer exists.'];
        }
        $original = $this->originalPath($file);

        return ['ok' => true, 'path' => $original ?? $served];
    }

    public static function servedName(string $name): bool
    {
        return preg_match('/^[a-f0-9]{32}\.jpg$/D', $name) === 1;
    }

    /** The kept upload for a served photo name, or null when there is none. */
    public function originalPath(string $name): ?string
    {
        if (!self::servedName($name)) {
            return null;
        }
        $map = $this->originalsDir . '/map/' . substr($name, 0, 32);
        if (!is_file($map)) {
            return null;
        }
        $base = trim((string)file_get_contents($map));
        if (!preg_match('/^[a-f0-9]{64}\.(jpg|png|webp|gif|heic)$/D', $base)) {
            return null;
        }
        $path = $this->originalsDir . '/' . $base;

        return is_file($path) ? $path : null;
    }
}
