<?php

declare(strict_types=1);

namespace Apotheke\Catalog;

use Apotheke\Core\Database\ReadCache;
use Apotheke\Core\Database\Writes;
use Apotheke\Taxonomy\Kind;
use Apotheke\Taxonomy\LocationTree;
use Apotheke\Taxonomy\TaxonomyRepository;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The overview's aggregates (legacy stats_totals(), stats_added_series(),
 * stats_by(), stats_location_rollup(), stats_value(), locations_nested()).
 *
 * @phpstan-type Bar array{id:int|string|null,name:string,n:int,other?:bool}
 */
final class Stats
{
    /** @var array<string, list<array<string,mixed>>> rows read ahead by prefetch(), each handed out once */
    private array $ahead = [];

    private readonly ReadCache $cache;

    /** @param BaseConnection<object|resource, object|resource> $db */
    public function __construct(private readonly BaseConnection $db, private readonly TaxonomyRepository $taxonomy, private readonly Calendar $calendar, ?ReadCache $cache = null)
    {
        $this->cache = $cache ?? ReadCache::off();
    }

    /**
     * Read every aggregate of the overview (ListView::statsBody()) in one batch: one round trip on the D1 driver, the
     * same queries one after another elsewhere. Each result is used once by the call that asks for it, so a later
     * call reads again.
     */
    public function prefetch(): void
    {
        $queries = [$this->totalsQuery(), ['SELECT COUNT(*) AS n FROM locations'], ['SELECT COUNT(*) AS n FROM categories'],
            $this->addedQuery('day'), $this->addedQuery('month'), [self::VALUE_TOTAL], [self::VALUE_BY_CATEGORY],
            [$this->rollupSql('count')], [$this->rollupSql('value')], [self::NESTED]];
        foreach ([self::NONE_VALUE_LOCATION, self::NONE_VALUE_CATEGORY] as $sql) {
            $queries[] = [$sql];
        }
        foreach (Kind::KINDS as $kind) {
            if ($kind !== 'location') {
                $queries[] = [$this->bySql($kind)];
            }
            $queries[] = [$this->noneSql($kind)];
        }
        // The rollups' location tree (TaxonomyRepository::all()) joins the batch unless the request remembers it.
        $tree = $this->taxonomy->allRead('location');
        $withTree = Writes::batches($this->db) && !$this->cache->has($this->db, $tree['key'], $tree['tables']);
        if ($withTree) {
            $queries[] = [$tree['sql']];
        }
        $read = Writes::rowsMany($this->db, $queries);
        if ($withTree) {
            $this->cache->put($this->db, $tree['key'], $tree['tables'], ($tree['shape'])(array_pop($read)));
            array_pop($queries);
        }
        $this->ahead = [];
        foreach ($read as $i => $rows) {
            $this->ahead[self::key($queries[$i][0], $queries[$i][1] ?? [])] = $rows;
        }
    }

    private const VALUE_TOTAL = 'SELECT COALESCE(SUM(value_cents), 0) AS total, COUNT(value_cents) AS priced FROM items';

    private const VALUE_BY_CATEGORY = 'SELECT c.id, c.name, SUM(i.value_cents) AS n FROM items i JOIN categories c ON c.id = i.category_id
                WHERE i.value_cents IS NOT NULL GROUP BY c.id, c.name, c.sort_key ORDER BY n DESC, c.sort_key, c.id';

    private const NONE_VALUE_LOCATION = 'SELECT COALESCE(SUM(value_cents), 0) AS n FROM items WHERE location_id IS NULL';

    private const NONE_VALUE_CATEGORY = 'SELECT COALESCE(SUM(value_cents), 0) AS n FROM items WHERE category_id IS NULL';

    private const NESTED = 'SELECT 1 AS x FROM locations WHERE parent_id IS NOT NULL LIMIT 1';

    /** @return array{0:string,1:list<string>} */
    private function totalsQuery(): array
    {
        $today = $this->calendar->today();

        return ['SELECT COUNT(*) AS items, COALESCE(SUM(quantity), 0) AS qty,
            COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS week,
            COALESCE(SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END), 0) AS month,
            COALESCE(SUM(CASE WHEN min_qty IS NOT NULL AND quantity <= min_qty THEN 1 ELSE 0 END), 0) AS low,
            COALESCE(SUM(CASE WHEN expires_on IS NOT NULL AND expires_on >= ? AND expires_on <= ? THEN 1 ELSE 0 END), 0) AS expiring,
            COALESCE(SUM(CASE WHEN expires_on IS NOT NULL AND expires_on < ? THEN 1 ELSE 0 END), 0) AS expired
            FROM items', [$this->calendar->boundaryUtc('monday this week'), $this->calendar->boundaryUtc('first day of this month'), $today, $this->calendar->soonUntil(), $today]];
    }

    /** @return array{items:int,qty:int,week:int,month:int,low:int,expiring:int,expired:int,locations:int,categories:int} */
    public function totals(): array
    {
        $r = $this->rows(...$this->totalsQuery())[0];
        $out = [];
        foreach (['items', 'qty', 'week', 'month', 'low', 'expiring', 'expired'] as $k) {
            $out[$k] = (int)$r[$k];
        }
        $out['locations'] = $this->count('SELECT COUNT(*) AS n FROM locations');
        $out['categories'] = $this->count('SELECT COUNT(*) AS n FROM categories');

        return $out;
    }

    /**
     * Items added per local day (last 30 days) or per month (last 12), zero-filled.
     *
     * @return list<array{key:string,label:string,n:int}>
     */
    public function addedSeries(string $unit): array
    {
        $day = $unit === 'day';
        [$start, $step, $fmtKey, $count] = $this->addedRange($day);
        $byKey = [];
        foreach ($this->rows(...$this->addedQuery($unit)) as $r) {
            $byKey[(string)$r['k']] = (int)$r['n'];
        }
        $out = [];
        for ($i = 0, $d = $start; $i < $count; $i++, $d = $d->modify($step)) {
            $k = $d->format($fmtKey);
            $out[] = ['key' => $k, 'label' => $day ? $d->format('j M') : $d->format('M y'), 'n' => $byKey[$k] ?? 0];
        }

        return $out;
    }

    /** @return array{0:string,1:list<string>} */
    private function addedQuery(string $unit): array
    {
        $tz = $this->calendar->zone();
        $day = $unit === 'day';
        if ($this->db->DBDriver === 'Postgre') {
            $expr = "to_char((created_at::timestamp AT TIME ZONE 'UTC') AT TIME ZONE ?, '" . ($day ? 'YYYY-MM-DD' : 'YYYY-MM') . "')";
            $mod = $tz->getName();
        } else {
            // SQLite: shift by the current UTC offset (DST edge days may shift by one hour)
            $expr = $day ? 'date(created_at, ?)' : "strftime('%Y-%m', created_at, ?)";
            $mod = sprintf('%+d seconds', (new DateTimeImmutable('now', $tz))->getOffset());
        }
        $start = $this->addedRange($day)[0];

        return ["SELECT $expr AS k, COUNT(*) AS n FROM items WHERE created_at >= ? GROUP BY 1", [$mod, $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')]];
    }

    /** @return array{0:DateTimeImmutable,1:string,2:string,3:int} the first bucket, the step, the key format and the bucket count */
    private function addedRange(bool $day): array
    {
        $tz = $this->calendar->zone();
        if ($day) {
            $start = $this->calendar->now($tz)->setTime(0, 0)->modify('-29 days');
            [$step, $fmtKey, $count] = ['+1 day', 'Y-m-d', 30];
        } else {
            $start = $this->calendar->now($tz)->modify('first day of this month')->setTime(0, 0)->modify('-11 months');
            [$step, $fmtKey, $count] = ['+1 month', 'Y-m', 12];
        }

        return [$start, $step, $fmtKey, $count];
    }

    /** @return list<Bar> top $n entries of a kind by item count, plus "Other" and "No …" */
    public function by(string $kind, int $n = 5): array
    {
        $rows = $kind === 'location' ? $this->rollup() : array_map(
            static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'n' => (int)$r['n']],
            $this->rows($this->bySql($kind)),
        );

        return $this->bucket($rows, $kind, $n, $this->noneSql($kind));
    }

    private function bySql(string $kind): string
    {
        $t = Kind::get($kind);

        return "SELECT x.id, x.name, COUNT(i.id) AS n FROM items i JOIN {$t['table']} x ON x.id = i.{$t['col']}
                GROUP BY x.id, x.name, x.sort_key ORDER BY n DESC, x.sort_key, x.id";
    }

    private function noneSql(string $kind): string
    {
        return 'SELECT COUNT(*) AS n FROM items WHERE ' . Kind::get($kind)['col'] . ' IS NULL';
    }

    /** $measure picks one of two fixed aggregates (never user input). */
    private function rollupSql(string $measure): string
    {
        $agg = $measure === 'value' ? 'COALESCE(SUM(value_cents), 0)' : 'COUNT(*)';

        return "SELECT location_id, $agg AS n FROM items WHERE location_id IS NOT NULL" . ($measure === 'value' ? ' AND value_cents IS NOT NULL' : '') . ' GROUP BY location_id';
    }

    /**
     * Counts (or summed prices) per top-level location, each including its
     * sub-locations, sorted by size then tree order.
     *
     * @return list<array{id:int,name:string,n:int}>
     */
    public function rollup(string $measure = 'count'): array
    {
        $byId = $this->taxonomy->locationsById();
        $root = [];
        foreach ($byId as $id => $l) {
            $r = $id;
            for ($i = 0; ($p = $byId[$r]['parent_id'] ?? null) !== null && isset($byId[$p]) && $i <= LocationTree::MAX_DEPTH + 1; $i++) {
                $r = $p;
            }
            $root[$id] = $r;
        }
        $sum = [];
        foreach ($this->rows($this->rollupSql($measure)) as $row) {
            $r = $root[(int)$row['location_id']] ?? null;
            if ($r !== null) {
                $sum[$r] = ($sum[$r] ?? 0) + (int)$row['n'];
            }
        }
        $pos = array_flip(array_keys($byId));
        $out = [];
        foreach ($sum as $id => $cnt) {
            $out[] = ['id' => $id, 'name' => $byId[$id]['name'], 'n' => $cnt];
        }
        usort($out, static fn (array $a, array $b): int => [$b['n'], $pos[$a['id']]] <=> [$a['n'], $pos[$b['id']]]);

        return $out;
    }

    /** @return array{total:int, priced:int, by_location: list<Bar>, by_category: list<Bar>} purchase-price totals in cents */
    public function value(int $n = 5): array
    {
        $r = $this->rows(self::VALUE_TOTAL)[0];
        $byLoc = array_values(array_filter($this->rollup('value'), static fn (array $x): bool => $x['n'] > 0));
        $byCat = array_map(static fn (array $x): array => ['id' => (int)$x['id'], 'name' => (string)$x['name'], 'n' => (int)$x['n']],
            $this->rows(self::VALUE_BY_CATEGORY));

        return ['total' => (int)$r['total'], 'priced' => (int)$r['priced'],
            'by_location' => $this->bucket($byLoc, 'location', $n, self::NONE_VALUE_LOCATION),
            'by_category' => $this->bucket($byCat, 'category', $n, self::NONE_VALUE_CATEGORY)];
    }

    /** True when at least one location sits inside another. */
    public function locationsNested(): bool
    {
        return $this->rows(self::NESTED) !== [];
    }

    /**
     * @param list<array{id:int,name:string,n:int}> $rows
     * @return list<Bar>
     */
    private function bucket(array $rows, string $kind, int $n, string $noneSql): array
    {
        $top = array_slice($rows, 0, $n);
        $rest = array_slice($rows, $n);
        if ($rest !== []) {
            $top[] = ['id' => null, 'name' => 'Other (' . count($rest) . ')', 'n' => array_sum(array_column($rest, 'n')), 'other' => true];
        }
        $un = $this->count($noneSql);
        if ($un > 0) {
            $top[] = ['id' => 'none', 'name' => Kind::get($kind)['none'], 'n' => $un];
        }

        return $top;
    }

    private function count(string $sql): int
    {
        return (int)($this->rows($sql)[0]['n'] ?? 0);
    }

    /**
     * @param list<int|string|null> $binds
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, array $binds = []): array
    {
        $key = self::key($sql, $binds);
        if (array_key_exists($key, $this->ahead)) {
            $rows = $this->ahead[$key];
            unset($this->ahead[$key]);

            return $rows;
        }

        return Writes::rows($this->db, $sql, $binds);
    }

    /** @param list<int|string|null> $binds */
    private static function key(string $sql, array $binds): string
    {
        return $sql . "\0" . json_encode($binds);
    }
}
