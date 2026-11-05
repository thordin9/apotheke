<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy;

use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
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
    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock, ?UnitsOfWork $units = null)
    {
        $this->units = UnitsOfWork::forSite($db, $units);
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
     * uses any more, in one unit of work (joins the item save's). A failed
     * statement rolls back and throws (WriteFailed). New tags use
     * ON CONFLICT (name_key) so the batch path needs no last-insert id
     * (ADR-002); item_tags rows bind the tag by name_key. Pass the item
     * save's unit as $uow so a Ref from that unit is flushed in order.
     *
     * @param list<string> $names clean names (Text::tags())
     */
    public function set(int|Ref $itemId, array $names, ?UnitOfWork $uow = null): void
    {
        $write = function (UnitOfWork $uow) use ($itemId, $names): void {
            $now = $this->clock->now();
            $keys = [];
            foreach ($names as $n) {
                $fold = Text::fold($n);
                $keys[$fold] = $n;
                $uow->write(
                    'INSERT INTO tags (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?) ON CONFLICT (name_key) DO NOTHING',
                    [$n, $fold, Text::natkey($n), $now],
                    'add tag',
                );
            }
            $uow->write('DELETE FROM item_tags WHERE item_id = ?', [$itemId], 'clear item tags');
            foreach ($keys as $fold => $_n) {
                $uow->write(
                    'INSERT INTO item_tags (item_id, tag_id) SELECT ?, id FROM tags WHERE name_key = ?',
                    [$itemId, $fold],
                    'tag item',
                );
            }
            $uow->write('DELETE FROM tags WHERE id NOT IN (SELECT tag_id FROM item_tags)', [], 'drop unused tags');
        };
        if ($uow !== null) {
            $write($uow);

            return;
        }
        $key = $itemId instanceof Ref ? ($itemId->ulid !== '' ? $itemId->ulid : 'ref') : (string)$itemId;
        $this->units->run('tags', $write, $key);
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
