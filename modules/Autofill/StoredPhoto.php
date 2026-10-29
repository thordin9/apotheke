<?php

declare(strict_types=1);

namespace Apotheke\Autofill;

use Apotheke\Media\ImageStore;
use CodeIgniter\Database\BaseConnection;

/**
 * The bytes Analyze reads for a stored photo, through Media: the kept
 * original when there is one, otherwise the served JPEG. The row lookup is
 * legacy's statement; nothing here writes.
 */
final class StoredPhoto
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ImageStore $images,
    ) {
    }

    /**
     * @return array{ok:true,bytes:string}|array{ok:false,code:string,message:string}
     */
    public function bytesFor(string $photoId): array
    {
        $id = ctype_digit($photoId) ? (int)$photoId : 0;
        $row = $this->db->table('item_photos')->select('file')->where('id', $id)->get()->getRowArray();
        $bytes = $this->images->analysisBytes(is_array($row) ? (string)($row['file'] ?? '') : '');
        if ($bytes === null) {
            return ['ok' => false, 'code' => 'no_image', 'message' => 'No suggestions: that photo no longer exists.'];
        }

        return ['ok' => true, 'bytes' => $bytes];
    }
}
