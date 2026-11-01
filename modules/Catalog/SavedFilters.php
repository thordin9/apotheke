<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

use Apotheke\Core\Database\Ref;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\Text;
use CodeIgniter\Database\BaseConnection;

/**
 * Saved filters (legacy saved_filter_*()): a named list query. Writes are
 * checked (M-25); errors the user can fix are UserError with legacy's
 * wording and status.
 *
 * @phpstan-type Saved array{id:int,name:string,query:string}
 */
final class SavedFilters
{
    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(private readonly BaseConnection $db, private readonly Clock $clock, private readonly int $max, ?UnitsOfWork $units = null)
    {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    /** @return list<Saved> */
    public function all(): array
    {
        return array_map(self::row(...), Writes::rows($this->db, 'SELECT id, name, query FROM saved_filters ORDER BY sort_key, id'));
    }

    /** @return Saved|null */
    public function get(int $id): ?array
    {
        $r = Writes::rows($this->db, 'SELECT id, name, query FROM saved_filters WHERE id = ?', [$id])[0] ?? null;

        return $r === null ? null : self::row($r);
    }

    /** Save a list query under a name; returns the new id. */
    public function create(string $name, string $query): int
    {
        // One unit of work (ADR-002, Ref): the limit and the name are read first, and guarded again where the row is written.
        return $this->units->run('saved_filters', function (UnitOfWork $uow) use ($name, $query): Ref {
            $name = $this->name($name);
            if ($query === '') {
                throw new UserError('Choose a search, filter or sort first – there is nothing to save.', 422);
            }
            $full = fn (): UserError => new UserError('You can keep up to ' . $this->max . ' saved filters. Delete one first.', 422);
            if ((int)(Writes::rows($this->db, 'SELECT COUNT(*) AS n FROM saved_filters')[0]['n'] ?? 0) >= $this->max) {
                throw $full();
            }
            $uow->assert('(SELECT COUNT(*) FROM saved_filters) < ?', [$this->max], 'saved filter limit', $full);
            $uow->assert('NOT EXISTS (SELECT 1 FROM saved_filters WHERE name_key = ?)', [Text::fold($name)], 'saved filter name',
                static fn (): UserError => new UserError('A saved filter called “' . $name . '” already exists.', 409));

            return $uow->insert('saved_filters', ['name' => $name, 'name_key' => Text::fold($name), 'query' => $query, 'sort_key' => Text::natkey($name), 'created_at' => $this->clock->now()], 'save filter');
        })->id();
    }

    public function rename(int $id, string $name): void
    {
        if ($this->get($id) === null) {
            throw new UserError('Saved filter not found.', 404);
        }
        $name = $this->name($name, $id);
        Writes::check($this->db->table('saved_filters')->where('id', $id)->update(['name' => $name, 'name_key' => Text::fold($name), 'sort_key' => Text::natkey($name)]), 'rename filter');
    }

    public function delete(int $id): void
    {
        if ($this->get($id) === null) {
            throw new UserError('Saved filter not found.', 404);
        }
        Writes::check($this->db->table('saved_filters')->where('id', $id)->delete(), 'delete filter');
    }

    private function name(string $name, ?int $except = null): string
    {
        $name = Text::cleanLine($name, 60);
        if ($name === '') {
            throw new UserError('Give the filter a name.', 422);
        }
        $ex = Writes::rows($this->db, 'SELECT id, name FROM saved_filters WHERE name_key = ?', [Text::fold($name)])[0] ?? null;
        if ($ex !== null && (int)$ex['id'] !== $except) {
            throw new UserError('A saved filter called “' . $ex['name'] . '” already exists.', 409);
        }

        return $name;
    }

    /**
     * @param array<string,mixed> $r
     * @return Saved
     */
    private static function row(array $r): array
    {
        return ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'query' => (string)$r['query']];
    }
}
