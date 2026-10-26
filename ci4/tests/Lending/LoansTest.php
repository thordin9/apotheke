<?php

declare(strict_types=1);

namespace Tests\Lending;

use Apotheke\Activity\ActivityLogger;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Lending\Loans;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Lending writes the golden test cannot interleave: a lend or return while
 * another client holds a write on the item (L-230). The byte-level parity
 * with legacy is tests/Unit/Ci4LabelsLendingParityTest.php.
 */
final class LoansTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    /**
     * L-230: while another client's write on the item is still open, a lend
     * and a return wait for it and then go through (SQLite answered
     * "database is locked"). A return that waited for another return sees
     * the item back and logs nothing a second time.
     */
    #[DataProvider('drivers')]
    public function testLendAndReturnWaitForAnotherClientsWrite(string $driver): void
    {
        [$loans, $item] = $this->seed($driver);
        $wait = $this->testDb()->holdInAnotherClient([["UPDATE items SET notes = 'other client' WHERE id = ?", [$item]]], 300);
        $loans->lend($item, 'Nikos', '2026-06-01', null);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame(['Nikos', 'other client'], array_values($this->row($item, 'lent_to, notes')));

        $wait = $this->testDb()->holdInAnotherClient([['UPDATE items SET notes = ? WHERE id = ?', ['still other', $item]]], 300);
        $loans->giveBack($item);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertSame([null, 'still other'], array_values($this->row($item, 'lent_to, notes')));
        $this->assertSame(['lent', 'returned'], $this->actions());

        // Returned by another client meanwhile: this return finds it back and logs nothing.
        $loans->lend($item, 'Eleni', '2026-06-01', null);
        $wait = $this->testDb()->holdInAnotherClient([['UPDATE items SET lent_to = NULL, lent_at = NULL, lent_due = NULL WHERE id = ?', [$item]]], 300);
        $loans->giveBack($item);
        $this->assertStringEndsWith("committed\n", $wait());
        $this->assertNull($this->row($item, 'lent_to')['lent_to']);
        $this->assertSame(['lent', 'returned', 'lent'], $this->actions());
    }

    /**
     * N-234: control characters leave the borrower's name, in the item and
     * in its history alike; whitespace ones become one space, as before.
     */
    #[DataProvider('drivers')]
    public function testTheBorrowerNameLosesControlCharacters(string $driver): void
    {
        [$loans, $item] = $this->seed($driver);
        foreach (["Ni\0kos" => 'Nikos', "\x07Ana\x1b[1m\x7f" => 'Ana[1m', "Eleni\u{85}\u{9f}x" => 'Eleni x', " Bo\t\r\n  <b>&\" " => 'Bo <b>&"', "Ma\0\0\x01\u{80}ria" => 'Maria'] as $in => $stored) {
            $loans->lend($item, $in, '2026-06-01', null);
            $this->assertSame($stored, $this->row($item, 'lent_to')['lent_to'], json_encode($in));
            $last = $this->db()->query("SELECT details FROM activity WHERE action = 'lent' ORDER BY id DESC")->getRowArray()['details'] ?? '';
            $this->assertSame($stored, json_decode((string)$last, true)['to'] ?? null, json_encode($in) . ' in the history');
        }
        foreach (["\0", "\x01\x02 \x7f", "\xff"] as $in) {
            try {
                $loans->lend($item, $in, null, null);
                $this->fail(json_encode(bin2hex($in)) . ' was taken as a name');
            } catch (UserError $e) {
                $this->assertSame('Enter who you lent it to.', $e->getMessage());
            }
        }
        $this->assertSame('Maria', $this->row($item, 'lent_to')['lent_to']);
    }

    /** @return array{0:Loans,1:int} */
    private function seed(string $driver): array
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->db();
        $clock = new Clock();
        $history = new ActivityLogger($db, $clock, static fn (): ?string => null);
        $items = $this->testDb()->items();
        $db->query("INSERT INTO items (name, quantity, notes, search, created_at, updated_at) VALUES ('Drill', 1, '', 'drill', '2026-01-02 08:00:00', '2026-01-02 08:00:00')");

        return [new Loans($db, $clock, new Calendar($clock, 'UTC', 7), $history, $items), (int)$db->insertID()];
    }

    /** @return array<string,mixed> */
    private function row(int $item, string $cols): array
    {
        return $this->db()->query("SELECT $cols FROM items WHERE id = ?", [$item])->getRowArray() ?? [];
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_column($this->db()->query('SELECT action FROM activity ORDER BY id')->getResultArray(), 'action');
    }

    private function testDb(): TestDatabase
    {
        $this->assertNotNull($this->database);

        return $this->database;
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        return $this->testDb()->db;
    }
}
