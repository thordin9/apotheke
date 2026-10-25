<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\Text;
use CodeIgniter\Database\BaseConnection;

/**
 * Item tags (legacy tags_for(), tags_all(), tags_set()). Tags have no pages
 * of their own: the item form and the list (Catalog, PLAN step 9 and 11a)
 * use this. Names come from Text::tags() (legacy tags_parse()).
 */
final class TagRepository
{
    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock)
    {
    }

    /** @return list<array{id:int,name:string}> an item's tags, naturally sorted */
    public function forItem(int $itemId): array
    {
        $rows = $this->rows('SELECT t.id, t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = ? ORDER BY t.sort_key, t.id', [$itemId]);

        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $rows);
    }

    /** @return list<array{id:int,name:string,n:int}> the tags in use, with item counts */
    public function inUse(): array
    {
        $rows = $this->rows('SELECT t.id, t.name, COUNT(it.item_id) AS n FROM tags t JOIN item_tags it ON it.tag_id = t.id GROUP BY t.id, t.name, t.sort_key ORDER BY t.sort_key, t.id');

        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'n' => (int)$r['n']], $rows);
    }

    /**
     * Replace an item's tags: creates missing tags and drops tags no item
     * uses any more, in one transaction (joins the item save's). A failed
     * statement rolls back and throws (WriteFailed).
     *
     * @param list<string> $names clean names (Text::tags())
     */
    public function set(int $itemId, array $names): void
    {
        Writes::transaction($this->db, fn () => $this->replace($itemId, $names));
    }

    /** @param list<string> $names */
    private function replace(int $itemId, array $names): void
    {
        $ids = [];
        foreach ($names as $n) {
            $tid = $this->rows('SELECT id FROM tags WHERE name_key = ?', [Text::fold($n)])[0]['id'] ?? null;
            if ($tid === null) {
                Writes::check($this->db->table('tags')->insert(['name' => $n, 'name_key' => Text::fold($n), 'sort_key' => Text::natkey($n), 'created_at' => $this->clock->now()]), 'add tag');
                $tid = $this->db->insertID();
            }
            $ids[] = (int)$tid;
        }
        Writes::check($this->db->table('item_tags')->where('item_id', $itemId)->delete(), 'clear item tags');
        foreach (array_unique($ids) as $tid) {
            Writes::check($this->db->table('item_tags')->insert(['item_id' => $itemId, 'tag_id' => $tid]), 'tag item');
        }
        Writes::check($this->db->query('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)'), 'drop unused tags');
    }

    /**
     * @param list<int|string> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db, $sql, $binds);
    }
}
