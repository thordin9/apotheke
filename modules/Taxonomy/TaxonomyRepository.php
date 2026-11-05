<?php

declare(strict_types=1);

namespace Apotheke\Taxonomy;

use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Core\Libraries\Text;
use CodeIgniter\Database\BaseConnection;

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

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock, private readonly ItemHistory $activity, ?UnitsOfWork $units = null)
    {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    /**
     * All entries, naturally sorted, with item counts. Locations come in tree
     * order with depth, path and total; categories are flat.
     *
     * @return list<Entry>
     */
    public function all(string $kind): array
    {
        $t = Kind::get($kind);
        $isLoc = $kind === 'location';

        return LocationTree::order($this->rows('SELECT x.id, x.name, x.is_default, ' . ($isLoc ? 'x.parent_id' : 'NULL') . " AS parent_id, COUNT(i.id) AS n
            FROM {$t['table']} x LEFT JOIN items i ON i.{$t['col']} = x.id
            GROUP BY x.id, x.name, x.is_default, x.sort_key" . ($isLoc ? ', x.parent_id' : '') . '
            ORDER BY x.sort_key, x.id'));
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
     * stored row (ON CONFLICT DO NOTHING, then read by name_key).
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
        $this->units->run($table, function (UnitOfWork $uow) use ($table, $name, $key): void {
            $uow->write(
                'INSERT INTO ' . $table . ' (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?) ON CONFLICT (name_key) DO NOTHING',
                [$name, $key, Text::natkey($name), $this->clock->now()],
                'add',
            );
        });
        $ex = $this->find($kind, $name) ?? throw new UserError(Kind::get($kind)['One'] . ' could not be created.', 500);

        return ['id' => $ex['id'], 'name' => $ex['name'], 'created' => true];
    }

    /**
     * Create (or find) an entry and, for a location, put it under $parent, in
     * one unit of work (joins a caller's, as the item form does).
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
            $this->units->run($table, function (UnitOfWork $uow) use ($table, $name, $fold): void {
                $uow->write(
                    'INSERT INTO ' . $table . ' (name, name_key, sort_key, created_at) VALUES (?, ?, ?, ?) ON CONFLICT (name_key) DO NOTHING',
                    [$name, $fold, Text::natkey($name), $this->clock->now()],
                    'add',
                );
            });
            $ex = $this->find($kind, $name) ?? throw new UserError(Kind::get($kind)['One'] . ' could not be created.', 500);

            return ['id' => $ex['id'], 'name' => $ex['name'], 'created' => true];
        }
        $byId = $this->locationsById();
        if (!isset($byId[$parent])) {
            throw new UserError('That parent location no longer exists.', 422);
        }
        if ($byId[$parent]['depth'] + 1 > LocationTree::MAX_DEPTH) {
            throw new UserError('Locations can be nested at most ' . LocationTree::MAX_DEPTH . ' levels deep.', 422);
        }
        $ref = $this->units->run($table, function (UnitOfWork $uow) use ($name, $fold, $parent): Ref {
            $uow->assert('EXISTS (SELECT 1 FROM locations WHERE id = ?)', [$parent], 'parent exists',
                static fn (): UserError => new UserError('That parent location no longer exists.', 422));
            return $uow->insert('locations', [
                'name' => $name,
                'name_key' => $fold,
                'sort_key' => Text::natkey($name),
                'created_at' => $this->clock->now(),
                'parent_id' => $parent,
            ], 'add');
        });

        return ['id' => $ref->id(), 'name' => $name, 'created' => true];
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
        $this->units->run($tb, function (UnitOfWork $uow) use ($tb, $id, $on): void {
            if ($on) {
                $uow->write('UPDATE ' . $tb . ' SET is_default = 0 WHERE is_default = 1', [], 'clear default');
                $uow->write('UPDATE ' . $tb . ' SET is_default = 1 WHERE id = ?', [$id], 'set default');
            } else {
                $uow->write('UPDATE ' . $tb . ' SET is_default = 0 WHERE id = ?', [$id], 'clear default');
            }
        }, (string)$id);
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
            $items = $this->rows("SELECT id, name FROM items WHERE {$t['col']} = ?", [$id]);
            foreach ($items as $it) {
                $kind === 'location'
                    ? $this->activity->queue($uow, (int)$it['id'], (string)$it['name'], 'moved', ['from' => $from, 'to' => $to, 'reason' => 'location deleted'])
                    : $this->activity->queue($uow, (int)$it['id'], (string)$it['name'], 'edited', ['changes' => ['category' => [$from, $to]], 'reason' => 'category deleted']);
            }
            $n = count($items);
            $uow->write('UPDATE items SET ' . $t['col'] . ' = ?, updated_at = ? WHERE ' . $t['col'] . ' = ?', [$moveTo, $this->clock->now(), $id], 'move items');
            if ($kind === 'location') {
                // sub-locations move up one level instead of disappearing from the tree
                $up = $this->rows('SELECT parent_id FROM locations WHERE id = ?', [$id])[0]['parent_id'] ?? null;
                $uow->write('UPDATE locations SET parent_id = ? WHERE parent_id = ?', [$up === null ? null : (int)$up, $id], 'move sub-locations');
            }
            $uow->write('DELETE FROM ' . $t['table'] . ' WHERE id = ?', [$id], 'delete');

            return $n;
        }, (string)$id);
    }

    /** Put location $id inside $parent (null = top level). Rejects cycles and trees deeper than MAX_DEPTH. True when it changed. */
    public function setParent(int $id, ?int $parent): bool
    {
        return $this->units->run('locations', function (UnitOfWork $uow) use ($id, $parent): bool {
            $byId = $this->locationsById();
            if (!isset($byId[$id])) {
                throw new UserError('Location not found.', 404);
            }
            if ($byId[$id]['parent_id'] === $parent) {
                return false;
            }
            if ($parent !== null) {
                if (!isset($byId[$parent])) {
                    throw new UserError('That parent location no longer exists.', 422);
                }
                $sub = $this->subtreeIds($id);
                if (in_array($parent, $sub, true)) {
                    throw new UserError('A location cannot be put inside itself or one of its own sub-locations.', 422);
                }
                // depth of the new position + height of the moved subtree must stay within the limit
                $height = 0;
                foreach ($sub as $s) {
                    if (isset($byId[$s])) {
                        $height = max($height, $byId[$s]['depth'] - $byId[$id]['depth']);
                    }
                }
                if ($byId[$parent]['depth'] + 1 + $height > LocationTree::MAX_DEPTH) {
                    throw new UserError('Locations can be nested at most ' . LocationTree::MAX_DEPTH . ' levels deep.', 422);
                }
                $uow->assert('EXISTS (SELECT 1 FROM locations WHERE id = ?)', [$parent], 'parent exists',
                    static fn (): UserError => new UserError('That parent location no longer exists.', 422));
            }
            $uow->write('UPDATE locations SET parent_id = ? WHERE id = ?', [$parent, $id], 'move', 1);

            return true;
        }, (string)$id);
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
