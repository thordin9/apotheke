<?php

declare(strict_types=1);

namespace Apotheke\Lending;

use Apotheke\Catalog\Calendar;
use Apotheke\Catalog\Items\ItemRepository;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Libraries\ItemHistory;
use Apotheke\Core\Libraries\Text;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Lending (legacy item_lend(), item_return(), items_lent(),
 * parse_lend_date()): who has an item, since when and until when. Each
 * change and its history entry are one unit of work (ADR-002).
 */
final class Loans
{
    private const COLS = 'i.id, i.name, i.location_id, l.name AS location, i.category_id, c.name AS category, i.quantity, i.min_qty, i.expires_on, i.notes, i.image, i.value_cents, i.lent_to, i.lent_at, i.lent_due, i.purchase_date, i.serial, i.barcode, i.favourite, i.created_at, i.updated_at';

    private const FROM = 'FROM items i LEFT JOIN locations l ON l.id = i.location_id LEFT JOIN categories c ON c.id = i.category_id';

    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly Clock $clock,
        private readonly Calendar $calendar,
        private readonly ItemHistory $history,
        private readonly ItemRepository $items,
        ?UnitsOfWork $units = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    /** A date from the form: YYYY-MM-DD, or null when empty. */
    public static function date(string $s, string $what): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
        if ($d === false || $d->format('Y-m-d') !== $s || $s < '1900-01-01' || $s > '2999-12-31') {
            throw new UserError("$what must be a valid date (YYYY-MM-DD).");
        }

        return $s;
    }

    /** Mark an item as lent to someone (also changes who has it or the due date). */
    public function lend(int $id, string $to, ?string $at, ?string $due): void
    {
        // Control characters go (a NUL cut the name on SQLite); whitespace ones are spaces, as before.
        $to = Text::cleanLine((string)preg_replace('/[^\P{Cc}\s]/u', '', $to), 100);
        if ($to === '') {
            throw new UserError('Enter who you lent it to.');
        }
        $today = $this->calendar->today();
        $at ??= $today;
        if ($at > $today) {
            throw new UserError('The lent date cannot be in the future.');
        }
        if ($due !== null && $due < $at) {
            throw new UserError('The due date cannot be before the lent date.');
        }
        // The unit lock alone serialises this item (L-1753); no separate row lock.
        $this->units->run('items', function (UnitOfWork $uow) use ($id, $to, $at, $due): void {
            $it = $this->items->get($id) ?? throw new UserError('Item not found.', 404);
            $uow->write('UPDATE items SET lent_to = ?, lent_at = ?, lent_due = ?, updated_at = ? WHERE id = ?', [$to, $at, $due, $this->clock->now(), $id], 'lend', 1);
            $this->history->queue($uow, $id, (string)$it['name'], 'lent', array_filter(['to' => $to, 'at' => $at, 'due' => $due, 'before' => $it['lent_to']], static fn (mixed $v): bool => $v !== null));
        }, (string)$id);
    }

    /** Mark a lent item as returned. */
    public function giveBack(int $id): void
    {
        $this->units->run('items', function (UnitOfWork $uow) use ($id): void {
            $it = $this->items->get($id) ?? throw new UserError('Item not found.', 404);
            if ($it['lent_to'] === null) {
                return;
            }
            $uow->write('UPDATE items SET lent_to = NULL, lent_at = NULL, lent_due = NULL, updated_at = ? WHERE id = ?', [$this->clock->now(), $id], 'return', 1);
            $days = (int)(new DateTimeImmutable((string)$it['lent_at']))->diff(new DateTimeImmutable($this->calendar->today()))->format('%a');
            $this->history->queue($uow, $id, (string)$it['name'], 'returned', ['from' => $it['lent_to'], 'days' => $days]);
        }, (string)$id);
    }

    /**
     * Items that are out: overdue first, then by due date (none last), then by lent date.
     *
     * @return list<array<string,mixed>>
     */
    public function out(): array
    {
        return Writes::rows($this->db, 'SELECT ' . self::COLS . ' ' . self::FROM . ' WHERE i.lent_to IS NOT NULL
        ORDER BY CASE WHEN i.lent_due IS NOT NULL AND i.lent_due < ? THEN 0 ELSE 1 END, CASE WHEN i.lent_due IS NULL THEN 1 ELSE 0 END, i.lent_due, i.lent_at, i.id', [$this->calendar->today()]);
    }
}
