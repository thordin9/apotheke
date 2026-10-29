<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

final class LendingTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrateFresh();
    }

    private function item(string $name): int
    {
        return item_save(null, ['name' => $name, 'location_id' => null, 'category_id' => null, 'quantity' => 1, 'notes' => ''], null, false);
    }

    private function day(int $offset): string
    {
        return (new DateTimeImmutable(local_today()))->modify("$offset days")->format('Y-m-d');
    }

    public function testLendOverdueReturn(): void
    {
        $a = $this->item('Ladder');
        $b = $this->item('Drill');
        $c = $this->item('Tent');
        item_lend($a, ' Maria ', $this->day(-10), $this->day(-1));
        item_lend($b, 'Nikos', null, $this->day(5));
        item_lend($c, 'Eleni', $this->day(-3), null);
        $this->assertSame(['Maria', $this->day(-10), $this->day(-1)], [item_get($a)['lent_to'], item_get($a)['lent_at'], item_get($a)['lent_due']]);
        $this->assertSame(local_today(), item_get($b)['lent_at']);
        $this->assertTrue(is_overdue(item_get($a)));
        $this->assertFalse(is_overdue(item_get($b)));
        $this->assertFalse(is_overdue(item_get($c)), 'no due date is never overdue');
        $this->assertSame(['Ladder', 'Drill', 'Tent'], array_column(items_lent(), 'name'));
        $this->assertSame(['out' => 3, 'overdue' => 1], lent_counts());
        $f = fn(string $l) => array_column(items_search('', ['loc' => '', 'cat' => '', 'lend' => $l], 'name', 1)['items'], 'name');
        $this->assertSame(['Drill', 'Ladder', 'Tent'], $f('out'));
        $this->assertSame(['Ladder'], $f('overdue'));

        item_return($a);
        item_return($a);   // no-op
        $this->assertFalse(is_lent(item_get($a)));
        $log = array_reverse(activity_list($a));
        $this->assertSame(['created', 'lent', 'returned'], array_column($log, 'action'));
        $this->assertSame(['from' => 'Maria', 'days' => 10], $log[2]['details']);
        $this->assertSame(['to' => 'Maria', 'at' => $this->day(-10), 'due' => $this->day(-1)], $log[1]['details']);

        // editing an item keeps it lent
        item_save(item_get($b), item_input(item_get($b)) + [], null, false);
        $this->assertSame('Nikos', item_get($b)['lent_to']);
    }

    public function testValidation(): void
    {
        $a = $this->item('Ladder');
        foreach ([['', null, null], ['X', $this->day(1), null], ['X', $this->day(-1), $this->day(-2)]] as [$to, $at, $due]) {
            try { item_lend($a, $to, $at, $due); $this->fail('accepted'); } catch (UserError) { $this->addToAssertionCount(1); }
        }
        $this->assertNull(parse_lend_date('', 'x'));
        foreach (['2020-02-30', '12/01/2020', 'tomorrow'] as $bad) {
            try { parse_lend_date($bad, 'x'); $this->fail("accepted $bad"); } catch (UserError) { $this->addToAssertionCount(1); }
        }
        $this->assertFalse(is_lent(item_get($a)));
    }

    /**
     * A lend or return while another client's write on the item is still open waits for it and then reads the
     * committed item (L-230): a return after another return logs nothing, and a lend logs the other client's borrower
     * as "before". Without the lock first, PostgreSQL logs from a stale read and SQLite answers "database is locked".
     */
    public function testLendAndReturnWaitForAnotherClientsWrite(): void
    {
        $id = $this->item('Drill');
        item_lend($id, 'Maria', null, null);
        $actions = fn(): array => array_column(array_reverse(activity_list($id)), 'action');
        $before = $actions();

        $wait = $this->holdInAnotherClient([['UPDATE items SET lent_to = NULL, lent_at = NULL, lent_due = NULL WHERE id = ?', [$id]]], 300);
        item_return($id);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertNull(item_get($id)['lent_to']);
        $this->assertSame($before, $actions(), 'returned by the other client: this return logs nothing');

        $wait = $this->holdInAnotherClient([['UPDATE items SET lent_to = ?, lent_at = ? WHERE id = ?', ['Nikos', local_today(), $id]]], 300);
        item_lend($id, 'Eleni', null, null);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame('Eleni', item_get($id)['lent_to']);
        $this->assertSame(['to' => 'Eleni', 'at' => local_today(), 'before' => 'Nikos'], activity_list($id, 1, 1)[0]['details']);
    }
}
