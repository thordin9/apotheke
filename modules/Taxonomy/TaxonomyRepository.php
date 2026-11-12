<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\ReadCache;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Core\Libraries\Text;
use CodeIgniter\Database\BaseConnection;
use Closure;

/**
 * Locations and categories (legacy tax_*() and location_*()). Both drivers
 * run the same statements. Errors the user can fix are UserError with the
 * legacy wording and status.
 *
 * @phpstan-type Entry array{id:int,name:string,is_default:int,n:int,parent_id:?int,depth:int,path:string,total:int}
 */
final class TaxonomyRepository
{
    private readonly UnitsOfWork $units;

    private readonly ReadCache $cache;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock, private readonly ItemHistory $activity, ?UnitsOfWork $units = null, ?ReadCache $cache = null)
    {
        $this->units = UnitsOfWork::forSite($db, $units);
        $this->cache = $cache ?? ReadCache::off();
    }

    /**
     * All entries, naturally sorted, with item counts. Locations come in tree
     * order with depth, path and total; categories are flat.
     *
     * @return list<Entry>
     */
    public function all(string $kind): array
    {
        /** @var list<Entry> */
        return $this->cache->through($this->db, $this->allRead($kind));
    }

    /**
     * all()'s read, remembered for the request until the next write to the table or to items (the counts), for
     * ReadCache::prefetch().
     *
     * @return array{key:string,tables:list<string>,sql:string,shape:Closure(list<array<string,mixed>>):list<Entry>}
     */
    public function allRead(string $kind): array
    {
        $t = Kind::get($kind);
        $isLoc = $kind === 'location';

        return ['key' => 'taxonomy.all.' . $kind, 'tables' => [$t['table'], 'items'],
            'sql' => 'SELECT x.id, x.name, x.is_default, ' . ($isLoc ? 'x.parent_id' : 'NULL') . " AS parent_id, COUNT(i.id) AS n
            FROM {$t['table']} x LEFT JOIN items i ON i.{$t['col']} = x.id
            GROUP BY x.id, x.name, x.is_default, x.sort_key" . ($isLoc ? ', x.parent_id' : '') . '
            ORDER BY x.sort_key, x.id',
            'shape' => static fn (array $rows): array => LocationTree::order($rows)];
    }

    /** @return array<int,Entry> locations keyed by id */
    public function locationsById(): array
    {
        $out = [];
        foreach ($this->all('location') as $l) {
            $out[$l['id']] = $l;
        }

        return $out;
    }

    /** "Garage › Shelf 2", '' when the location does not exist. */
    public function locationPath(?int $id): string
    {
        return $id === null ? '' : (string)($this->locationsById()[$id]['path'] ?? '');
    }

    /** @return array{id:int,name:string,is_default:int,n:int}|null */
    public function get(string $kind, int $id): ?array
    {
        $t = Kind::get($kind);
        $row = $this->rows("SELECT x.id, x.name, x.is_default, (SELECT COUNT(*) FROM items WHERE {$t['col']} = x.id) AS n FROM {$t['table']} x WHERE x.id = ?", [$id])[0] ?? null;

        return $row === null ? null : ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'is_default' => (int)$row['is_default'], 'n' => (int)$row['n']];
    }

    /** @return array{id:int,name:string,is_default:int}|null same name, ignoring case and accents */
    public function find(string $kind, string $name): ?array
    {
        $row = $this->rows('SELECT id, name, is_default FROM ' . Kind::get($kind)['table'] . ' WHERE name_key = ?', [Text::fold(Text::cleanLine($name, 120))])[0] ?? null;

        return $row === null ? null : ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'is_default' => (int)$row['is_default']];
    }

    public function defaultId(string $kind): ?int
    {
        $id = $this->rows('SELECT id FROM ' . Kind::get($kind)['table'] . ' WHERE is_default = 1 LIMIT 1')[0]['id'] ?? null;

        return $id === null ? null : (int)$id;
    }

    /** Items with no location (or no category). */
    public function noneCount(string $kind): int
    {
        return (int)($this->rows('SELECT COUNT(*) AS n FROM items WHERE ' . Kind::get($kind)['col'] . ' IS NULL')[0]['n'] ?? 0);
    }

    /**
     * Create an entry, or return the existing one with the same name.
     * One unit of work (ADR-002): a race on the name still returns the
     * stored row (ON CONFLICT DO NOTHING, then read by name_key). created
     * is true only when this call inserted the row (N-1757).
     *
     * @return array{id:int,name:string,created:bool}
     */
    public function create(string $kind, string $name): array
    {
        $name = $this->validName($kind, $name);
        $ex = $this->find($kind, $name);
        if ($ex !== null) {
            return ['id' => $ex['id'], 'name' => $ex['name'], 'created' => false];
        }
        $table = Kind::get($kind)['table'];
        $key = Text::fold($name);
        $created = $this->insertName($table, $name, $key);

        return $this->createdRow($kind, $name, $created);
    }

    /**
     * Create (or find) an entry and, for a location, put it under $parent, in
     * one unit of work (joins a caller's, as the item form does). A name race
     * returns the stored row (N-1756); the depth check runs inside the unit.
     *
     * @return array{id:int,name:string,created:bool}
     */
    public function createUnder(string $kind, string $name, ?int $parent): array
    {
        $name = $this->validName($kind, $name);
        $ex = $this->find($kind, $name);
        if ($ex !== null) {
            return ['id' => $ex['id'], 'name' => $ex['name'], 'created' => false];
        }
        $table = Kind::get($kind)['table'];
        $fold = Text::fold($name);
        if ($kind !== 'location' || $parent === null) {
            return $this->createdRow($kind, $name, $this->insertName($table, $name, $fold));
        }
        $created = true;
        try {
            $this->units->run($table, function (UnitOfWork $uow) use ($name, $fold, $parent): void {
                $uow->assert('EXISTS (SELECT 1 FROM locations WHERE id = ?)', [$parent], 'parent exists',
                    static fn (): UserError => new UserError('That parent location no longer exists.', 422));
                // Depth of the parent inside the unit (N-1756): reject when a
                // child would sit past MAX_DEPTH (parent chain length > MAX).
                $uow->assert(
                    '(SELECT COUNT(*) FROM (WITH RECURSIVE chain(id, parent_id, n) AS ('
                    . ' SELECT id, parent_id, 0 FROM locations WHERE id = ?'
                    . ' UNION ALL SELECT l.id, l.parent_id, chain.n + 1 FROM locations l'
                    . ' JOIN chain ON chain.parent_id = l.id WHERE chain.n < 50'
                    . ') SELECT 1 FROM chain) AS d) <= ?',
                    [$parent, LocationTree::MAX_DEPTH],
                    'parent depth',
                    static fn (): UserError => new UserError(
                        'Locations can be nested at most ' . LocationTree::MAX_DEPTH . ' levels deep.',
                        422,
                    ),
                );
                $uow->write(
                    'INSERT INTO locations (name, name_key, sort_key, created_at, parent_id) VALUES (?, ?, ?, ?, ?) ON CONFLICT (name_key) DO NOTHING',
                    [$name, $fold, Text::natkey($name), $this->clock->now(), $parent],
                    'add',
                    1,
                    static fn (): UserError => new UserError('name conflict', 409),
                );
            });
        } catch (UserError $e) {
            if ($e->getCode() !== 409) {
                throw $e;
            }
            $created = false;
        }

        return $this->createdRow($kind, $name, $created);
    }

    /**
     * Insert a top-level name, or report a name race (expect 1 on ON CONFLICT DO NOTHING).
     *
     * @return bool true when this call inserted the row
     */
    private function insertName(string $table, string $name, string $key): bool
    {
        try {
            $this->units->run($table, function (UnitOfWork $uow) use ($table, $name, $key): void {
                $uow->write(
                    'INSERT INTO ' . $table . ' (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?) ON CONFLICT (name_key) DO NOTHING',
                    [$name, $key, Text::natkey($name), $this->clock->now()],
                    'add',
                    1,
                    static fn (): UserError => new UserError('name conflict', 409),
                );
            });

            return true;
        } catch (UserError $e) {
            if ($e->getCode() !== 409) {
                throw $e;
            }

            return false;
        }
    }

    /**
     * @return array{id:int,name:string,created:bool}
     */
    private function createdRow(string $kind, string $name, bool $created): array
    {
        $ex = $this->find($kind, $name) ?? throw new UserError(Kind::get($kind)['One'] . ' could not be created.', 500);

        return ['id' => $ex['id'], 'name' => $ex['name'], 'created' => $created];
    }

    public function rename(string $kind, int $id, string $name): void
    {
        $name = $this->validName($kind, $name);
        $ex = $this->find($kind, $name);
        if ($ex !== null && $ex['id'] !== $id) {
            throw new UserError('A ' . Kind::get($kind)['one'] . ' called “' . $ex['name'] . '” already exists.', 409);
        }
        Writes::check($this->db->table(Kind::get($kind)['table'])->where('id', $id)
            ->update(['name' => $name, 'name_key' => Text::fold($name), 'sort_key' => Text::natkey($name)]), 'rename');
    }

    /** Make $id the only default, or clear the default on $id (other rows untouched). */
    public function setDefault(string $kind, int $id, bool $on): void
    {
        $tb = Kind::get($kind)['table'];
        // Table-wide unit (empty key): clear-then-set must not race another default (PostgreSQL).
        $this->units->run($tb, function (UnitOfWork $uow) use ($tb, $id, $on): void {
            if ($on) {
                $uow->write('UPDATE ' . $tb . ' SET is_default = 0 WHERE is_default = 1', [], 'clear default');
                $uow->write('UPDATE ' . $tb . ' SET is_default = 1 WHERE id = ?', [$id], 'set default');
            } else {
                $uow->write('UPDATE ' . $tb . ' SET is_default = 0 WHERE id = ?', [$id], 'clear default');
            }
        });
    }

    /** Delete an entry; its items move to $moveTo or are left unassigned. Returns the number of items moved. */
    public function delete(string $kind, int $id, ?int $moveTo): int
    {
        $t = Kind::get($kind);

        return $this->units->run($t['table'], function (UnitOfWork $uow) use ($t, $kind, $id, $moveTo): int {
            $row = $this->get($kind, $id);
            if ($row === null) {
                return 0;
            }
            $from = (string)$row['name'];
            $to = $moveTo !== null ? (string)($this->get($kind, $moveTo)['name'] ?? '') : '';
            $now = $this->clock->now();
            // Re-read under the unit: N-1770 returns this count (rows still on the entry).
            $items = $this->rows("SELECT id, name FROM items WHERE {$t['col']} = ?", [$id]);
            $n = count($items);
            // L-1766: history queued with the unit; expect-1 on delete rolls back a loser.
            foreach ($items as $it) {
                $kind === 'location'
                    ? $this->activity->queue($uow, (int)$it['id'], (string)$it['name'], 'moved', ['from' => $from, 'to' => $to, 'reason' => 'location deleted'])
                    : $this->activity->queue($uow, (int)$it['id'], (string)$it['name'], 'edited', ['changes' => ['category' => [$from, $to]], 'reason' => 'category deleted']);
            }
            $uow->write(
                'UPDATE items SET ' . $t['col'] . ' = ?, updated_at = ? WHERE ' . $t['col'] . ' = ?'
                . ' AND EXISTS (SELECT 1 FROM ' . $t['table'] . ' WHERE id = ?)',
                [$moveTo, $now, $id, $id],
                'move items',
            );
            if ($kind === 'location') {
                $up = $this->rows('SELECT parent_id FROM locations WHERE id = ?', [$id])[0]['parent_id'] ?? null;
                $uow->write('UPDATE locations SET parent_id = ? WHERE parent_id = ?', [$up === null ? null : (int)$up, $id], 'move sub-locations');
            }
            $uow->write(
                'DELETE FROM ' . $t['table'] . ' WHERE id = ?',
                [$id],
                'delete',
                1,
                static fn (): UserError => new UserError(Kind::get($kind)['One'] . ' not found.', 404),
            );

            return $n;
        });
    }

    /** Put location $id inside $parent (null = top level). Rejects cycles and trees deeper than MAX_DEPTH. True when it changed. */
    public function setParent(int $id, ?int $parent): bool
    {
        return $this->units->run('locations', function (UnitOfWork $uow) use ($id, $parent): bool {
            $row = $this->rows('SELECT id, parent_id FROM locations WHERE id = ?', [$id])[0] ?? null;
            if ($row === null) {
                throw new UserError('Location not found.', 404);
            }
            $current = $row['parent_id'] === null ? null : (int)$row['parent_id'];
            if ($current === $parent) {
                return false;
            }
            if ($parent === null) {
                $uow->write(
                    'UPDATE locations SET parent_id = NULL WHERE id = ? AND parent_id IS NOT NULL',
                    [$id],
                    'move',
                    1,
                    static fn (): UserError => new UserError('Location not found.', 404),
                );

                return true;
            }
            $uow->assert(
                'EXISTS (SELECT 1 FROM locations WHERE id = ?)',
                [$parent],
                'parent exists',
                static fn (): UserError => new UserError('That parent location no longer exists.', 422),
            );
            // M-1765: parent is not this node or a descendant (cycle guard in-batch).
            $uow->assert(
                'NOT EXISTS ('
                . ' WITH RECURSIVE sub(id, n) AS ('
                . '   SELECT ? AS id, 0'
                . '   UNION ALL SELECT l.id, sub.n + 1 FROM locations l JOIN sub ON l.parent_id = sub.id WHERE sub.n < 50'
                . ' ) SELECT 1 FROM sub WHERE id = ?'
                . ')',
                [$id, $parent],
                'not a descendant',
                static fn (): UserError => new UserError(
                    'A location cannot be put inside itself or one of its own sub-locations.',
                    422,
                ),
            );
            // Depth of parent + 1 + height of moved subtree <= MAX_DEPTH.
            $uow->assert(
                '('
                . ' (SELECT COUNT(*) FROM ('
                . '   WITH RECURSIVE chain(id, parent_id, n) AS ('
                . '     SELECT id, parent_id, 0 FROM locations WHERE id = ?'
                . '     UNION ALL SELECT l.id, l.parent_id, chain.n + 1 FROM locations l'
                . '     JOIN chain ON chain.parent_id = l.id WHERE chain.n < 50'
                . '   ) SELECT 1 FROM chain'
                . ' ) AS d)'
                . ' + 1'
                . ' + COALESCE((SELECT MAX(n) FROM ('
                . '   WITH RECURSIVE sub(id, n) AS ('
                . '     SELECT id, 0 FROM locations WHERE id = ?'
                . '     UNION ALL SELECT l.id, sub.n + 1 FROM locations l'
                . '     JOIN sub ON l.parent_id = sub.id WHERE sub.n < 50'
                . '   ) SELECT n FROM sub'
                . ' ) AS h), 0)'
                . ') <= ?',
                [$parent, $id, LocationTree::MAX_DEPTH],
                'parent depth',
                static fn (): UserError => new UserError(
                    'Locations can be nested at most ' . LocationTree::MAX_DEPTH . ' levels deep.',
                    422,
                ),
            );
            // Conditional write: same guards in the WHERE so a raced batch cannot land a cycle.
            $uow->write(
                'UPDATE locations SET parent_id = ? WHERE id = ?'
                . ' AND EXISTS (SELECT 1 FROM locations WHERE id = ?)'
                . ' AND NOT EXISTS ('
                . '   WITH RECURSIVE sub(id, n) AS ('
                . '     SELECT ? AS id, 0'
                . '     UNION ALL SELECT l.id, sub.n + 1 FROM locations l JOIN sub ON l.parent_id = sub.id WHERE sub.n < 50'
                . '   ) SELECT 1 FROM sub WHERE id = ?'
                . ' )',
                [$parent, $id, $parent, $id, $parent],
                'move',
                1,
                static fn (): UserError => new UserError(
                    'A location cannot be put inside itself or one of its own sub-locations.',
                    422,
                ),
            );

            return true;
        });
    }

    /** @return list<int> $id and every location below it */
    public function subtreeIds(int $id): array
    {
        $byParent = [];
        foreach ($this->rows('SELECT id, parent_id FROM locations WHERE parent_id IS NOT NULL') as $r) {
            $byParent[(int)$r['parent_id']][] = (int)$r['id'];
        }
        $out = [$id];
        $seen = [$id => true];
        for ($i = 0; $i < count($out); $i++) {
            foreach ($byParent[$out[$i]] ?? [] as $c) {
                if (!isset($seen[$c])) {
                    $seen[$c] = true;
                    $out[] = $c;
                }
            }
        }

        return $out;
    }

    private function validName(string $kind, string $name): string
    {
        $name = Text::cleanLine($name, 120);
        if ($name === '') {
            throw new UserError(Kind::get($kind)['One'] . ' name cannot be empty.');
        }

        return $name;
    }


    /**
     * A SELECT's rows; a failed statement throws.
     *
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        return Writes::rows($this->db, $sql, $binds);
    }
}
