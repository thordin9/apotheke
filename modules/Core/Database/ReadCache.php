<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

use CodeIgniter\Database\BaseConnection;
use Closure;

/**
 * Reads remembered for one request on the D1 driver (Services::readCache(), never persistent in worker mode), where
 * each read is a round trip to the bridge. An entry names the tables it was read from and is used again only while
 * the connection's WriteClock has counted no write to them since, so a write earlier in the request (a new account,
 * saved settings, a role, a password or a session epoch) is read back. Nothing is remembered or answered inside a
 * transaction, so a rollback cannot leave an entry behind. On SQLite and PostgreSQL, and with off(), every
 * remember() reads again.
 *
 * @phpstan-type Read array{key:string,tables:list<string>,sql:string,binds?:list<int|string|null>,shape:Closure(list<array<string,mixed>>):mixed}
 */
final class ReadCache
{
    /** @var array<string,array{stamp:array<string,int>,value:mixed}> */
    private array $entries = [];

    public function __construct(private readonly bool $on = true)
    {
    }

    public static function off(): self
    {
        return new self(false);
    }

    /**
     * The value $read returned for $key on $db, read again when a write to one of $tables came since.
     *
     * @template T
     * @param BaseConnection<object|resource, object|resource> $db
     * @param list<string> $tables every table the read depends on (including the ones a foreign key cascade changes)
     * @param Closure():T $read
     * @param (Closure(T):bool)|null $keep whether to remember this value (false: a failed read, read again next time)
     * @return T
     */
    public function remember(BaseConnection $db, string $key, array $tables, Closure $read, ?Closure $keep = null): mixed
    {
        $clock = $this->clock($db);
        if ($clock === null) {
            return $read();
        }
        $key = spl_object_id($db) . "\0" . $key;
        $stamp = $clock->stamp($tables);
        if (isset($this->entries[$key]) && $this->entries[$key]['stamp'] === $stamp) {
            return $this->entries[$key]['value'];
        }
        $value = $read();
        if ($keep === null || $keep($value)) {
            $this->entries[$key] = ['stamp' => $stamp, 'value' => $value];
        }

        return $value;
    }

    /**
     * remember() of one read described as prefetch() takes it: its rows, as shape() makes them.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     * @param Read $read
     */
    public function through(BaseConnection $db, array $read): mixed
    {
        return $this->remember($db, $read['key'], $read['tables'], static fn (): mixed => ($read['shape'])(Writes::rows($db, $read['sql'], $read['binds'] ?? [])));
    }

    /**
     * Read the entries not remembered yet in one batch (one round trip to the bridge), and remember each as its
     * shape() makes it: what through() would store. When nothing is remembered on $db (see above), fewer than two
     * entries are missing, or the batch is not sent or fails, nothing is read: each through() reads (and fails) on
     * its own, as without this call.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     * @param list<Read> $reads
     */
    public function prefetch(BaseConnection $db, array $reads): void
    {
        if ($this->clock($db) === null) {
            return;
        }
        $missing = array_values(array_filter($reads, fn (array $r): bool => !$this->has($db, $r['key'], $r['tables'])));
        if (count($missing) < 2) {
            return;
        }
        $rows = Writes::readMany($db, array_map(static fn (array $r): array => [$r['sql'], $r['binds'] ?? []], $missing));
        if ($rows === null) {
            return;
        }
        foreach ($missing as $i => $r) {
            $this->put($db, $r['key'], $r['tables'], ($r['shape'])($rows[$i]));
        }
    }

    /**
     * Store a value read elsewhere (a batch that read several keys at once), as remember() would.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     * @param list<string> $tables
     */
    public function put(BaseConnection $db, string $key, array $tables, mixed $value): void
    {
        $clock = $this->clock($db);
        if ($clock !== null) {
            $this->entries[spl_object_id($db) . "\0" . $key] = ['stamp' => $clock->stamp($tables), 'value' => $value];
        }
    }

    /**
     * Whether remember() would answer $key from memory now.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     * @param list<string> $tables
     */
    public function has(BaseConnection $db, string $key, array $tables): bool
    {
        $clock = $this->clock($db);
        $e = $this->entries[spl_object_id($db) . "\0" . $key] ?? null;

        return $clock !== null && $e !== null && $e['stamp'] === $clock->stamp($tables);
    }

    /** Forget every entry. */
    public function clear(): void
    {
        $this->entries = [];
    }

    /**
     * The clock entries on $db are checked against; null when nothing is remembered there now.
     *
     * @param BaseConnection<object|resource, object|resource> $db
     */
    private function clock(BaseConnection $db): ?WriteClock
    {
        return $this->on && $db instanceof D1\Connection && (int)$db->transDepth === 0 ? $db->writeClock() : null;
    }
}
