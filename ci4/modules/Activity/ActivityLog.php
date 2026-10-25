<?php

declare(strict_types=1);

namespace Apotheke\Activity;

use Apotheke\Core\Database\Writes;
use CodeIgniter\Database\BaseConnection;

/** Reading the item history (legacy activity_list()): newest first, a page at a time. */
final class ActivityLog
{
    public const PAGE = 50;

    public const PAGE_MAX = 100000;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function clampPage(int $page): int
    {
        return max(1, min(self::PAGE_MAX, $page));
    }

    /**
     * Up to $per + 1 entries (one more tells there is a next page).
     *
     * @return list<array{id:int,item_id:?int,item_name:string,action:string,details:array<string,mixed>,created_at:string,actor:?string,exists:bool}>
     */
    public function list(?int $itemId = null, int $page = 1, int $per = self::PAGE): array
    {
        $page = self::clampPage($page);
        $sql = 'SELECT a.id, a.item_id, a.item_name, a.action, a.details, a.created_at, a.actor, CASE WHEN i.id IS NULL THEN 0 ELSE 1 END AS item_exists
            FROM activity a LEFT JOIN items i ON i.id = a.item_id' . ($itemId !== null ? ' WHERE a.item_id = ?' : '')
            . ' ORDER BY a.id DESC LIMIT ' . ($per + 1) . ' OFFSET ' . (($page - 1) * $per);

        return array_map(static function (array $r): array {
            $details = json_decode((string)$r['details'], true);

            return ['id' => (int)$r['id'], 'item_id' => $r['item_id'] === null ? null : (int)$r['item_id'], 'item_name' => (string)$r['item_name'],
                'action' => (string)$r['action'], 'details' => is_array($details) ? $details : [], 'created_at' => (string)$r['created_at'],
                'actor' => $r['actor'] === null ? null : (string)$r['actor'], 'exists' => (int)$r['item_exists'] === 1];
        }, Writes::rows($this->db, $sql, $itemId !== null ? [$itemId] : []));
    }

    /** @return array{id:int,name:string}|null the item, or its last known name once it is deleted */
    public function item(int $id): ?array
    {
        $row = Writes::rows($this->db, 'SELECT name FROM items WHERE id = ?', [$id])[0] ?? null;
        if ($row !== null) {
            return ['id' => $id, 'name' => (string)$row['name']];
        }
        $last = $this->list($id, 1, 1)[0] ?? null;

        return $last === null ? null : ['id' => $id, 'name' => $last['item_name']];
    }
}
