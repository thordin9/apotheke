<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Items;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Core\Storage\Storage;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * File attachments (legacy attachments.php): PDF and images, stored as
 * `files/<32 hex>.<ext>` and only ever sent as a download. The real type
 * comes from the content, never from the browser or the file name. Adding
 * is all or nothing: a failed write removes the files it stored.
 */
final class Attachments
{
    public const TYPES = [
        'pdf' => ['application/pdf', 'PDF'],
        'jpg' => ['image/jpeg', 'JPEG'],
        'png' => ['image/png', 'PNG'],
        'webp' => ['image/webp', 'WebP'],
    ];

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly ItemHistory $history,
        private readonly ItemRepository $items,
        private readonly Storage $files,
        private readonly string $types,
        private readonly int $maxMb,
        private readonly int $maxPerItem,
    ) {
    }

    public static function validName(?string $n): bool
    {
        return is_string($n) && preg_match('/^[a-f0-9]{32}\.(pdf|jpg|png|webp)$/D', $n) === 1;
    }

    /** Legacy attach_sniff(): the type by its first bytes. */
    public static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, '%PDF-') => 'pdf',
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($head, "\x89PNG\r\n\x1a\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    /** Legacy attach_display_name(): no path or control characters, at most 110 + extension. */
    public static function displayName(string $name, string $ext): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1f\x7f"<>|:*?\/\\\\]+/u', '', $name) ?? '');
        $base = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?: 'file';

        return mb_substr($base, 0, 110) . '.' . $ext;
    }

    /** @return list<string> the extensions the Control Panel allows */
    public function allowed(): array
    {
        return match ($this->types) {
            'pdf' => ['pdf'],
            'images' => ['jpg', 'png', 'webp'],
            default => array_keys(self::TYPES),
        };
    }

    /** @return array{id:int,item_id:int,file:string,name:string,mime:string,size:int}|null */
    public function get(int $id): ?array
    {
        $r = Writes::rows($this->db, 'SELECT id, item_id, file, name, mime, size FROM item_attachments WHERE id = ?', [$id])[0] ?? null;

        return $r === null ? null : ['id' => (int)$r['id'], 'item_id' => (int)$r['item_id'], 'file' => (string)$r['file'], 'name' => (string)$r['name'], 'mime' => (string)$r['mime'], 'size' => (int)$r['size']];
    }

    public function count(int $itemId): int
    {
        return count($this->items->attachments($itemId));
    }

    /** The stored bytes, or null when the name is not a stored one or the file is gone. */
    public function read(string $file): ?string
    {
        return self::validName($file) ? $this->files->read('files/' . $file) : null;
    }

    /**
     * Validate and store uploaded files for an item, all or nothing (legacy
     * attachments_add()).
     *
     * @param list<array{name:string,tmp:string,error:int}> $uploads
     * @param callable(int):string $errorText legacy upload_error_text()
     */
    public function add(int $itemId, array $uploads, callable $errorText): int
    {
        $it = $this->items->get($itemId) ?? throw new UserError('Item not found.', 404);
        if ($uploads === []) {
            throw new UserError('Choose a file to attach.', 422);
        }
        if ($this->count($itemId) + count($uploads) > $this->maxPerItem) {
            throw new UserError('An item can have at most ' . $this->maxPerItem . ' attachments.', 422);
        }
        $allowed = $this->allowed();
        $ready = [];
        foreach ($uploads as $f) {
            if ($f['error'] !== UPLOAD_ERR_OK) {
                throw new UserError($errorText($f['error']), 422);
            }
            $size = (int)@filesize($f['tmp']);
            if ($size > $this->maxMb * 1024 * 1024) {
                throw new UserError('“' . self::displayName($f['name'], 'x') . '” is larger than ' . $this->maxMb . ' MB.', 422);
            }
            if ($size === 0) {
                throw new UserError('That file is empty.', 422);
            }
            $ext = self::sniff((string)@file_get_contents($f['tmp'], false, null, 0, 16));
            if ($ext === null || !in_array($ext, $allowed, true)) {
                throw new UserError('Only ' . implode(', ', array_map(static fn (string $e): string => self::TYPES[$e][1], $allowed)) . ' files can be attached.', 422);
            }
            $ready[] = [$f, $ext, $size];
        }
        $stored = [];
        try {
            foreach ($ready as [$f, $ext, $size]) {
                $bytes = @file_get_contents($f['tmp']);
                if (!is_string($bytes)) {
                    throw new RuntimeException('could not store attachment');
                }
                $file = bin2hex(random_bytes(16)) . '.' . $ext;
                $this->files->write('files/' . $file, $bytes);
                $stored[] = [$file, self::displayName($f['name'], $ext), self::TYPES[$ext][0], $size];
            }
            Writes::transaction($this->db, function () use ($itemId, $it, $stored): void {
                foreach ($stored as [$file, $name, $mime, $size]) {
                    Writes::check($this->db->table('item_attachments')->insert(['item_id' => $itemId, 'file' => $file, 'name' => $name, 'mime' => $mime, 'size' => $size, 'created_at' => $this->clock->now()]), 'add attachment');
                    $this->history->log($itemId, (string)$it['name'], 'attachment', ['change' => 'added', 'file' => $name]);
                }
                Writes::check($this->db->table('items')->where('id', $itemId)->update(['updated_at' => $this->clock->now()]), 'touch item');
            });
        } catch (Throwable $e) {
            foreach ($stored as [$file]) {
                $this->files->delete('files/' . $file);
            }
            throw $e;
        }

        return count($stored);
    }

    /** Legacy attachment_delete(): the row and its history entry, then the file. */
    public function delete(int $itemId, int $id): void
    {
        $a = $this->get($id);
        if ($a === null || $a['item_id'] !== $itemId) {
            throw new UserError('Attachment not found.', 404);
        }
        $it = $this->items->get($itemId);
        Writes::transaction($this->db, function () use ($a, $it): void {
            Writes::check($this->db->table('item_attachments')->where('id', $a['id'])->delete(), 'delete attachment');
            $this->history->log($a['item_id'], (string)($it['name'] ?? ''), 'attachment', ['change' => 'removed', 'file' => $a['name']]);
        });
        $this->items->deleteAttachmentFile($a['file']);
    }
}
