<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\SessionUidMatch;
use Apotheke\Core\Database\Writes;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * The shared session match: a row matches only when uid|i:<id>; opens the payload or follows a ';' or '}' boundary,
 * on every test driver, with the id as a placeholder or as a column of a joined table.
 */
final class SessionUidMatchTest extends CIUnitTestCase
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

    #[DataProvider('drivers')]
    public function testPlaceholderMatchesOnlyTheExactUidField(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->seed();

        $rows = Writes::rows(
            $this->database->db,
            'SELECT id FROM sessions WHERE ' . SessionUidMatch::condition('data', '?') . ' ORDER BY id',
            SessionUidMatch::bindings(1),
        );

        $this->assertSame(['a-open', 'b-semicolon', 'c-brace'], array_map(static fn (array $r): string => (string)$r['id'], $rows));
    }

    #[DataProvider('drivers')]
    public function testColumnExpressionMatchesThroughAJoinedTable(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->seed();

        $rows = Writes::rows(
            $this->database->db,
            'SELECT sessions.id FROM sessions WHERE EXISTS (SELECT 1 FROM (SELECT 11 AS id) picked WHERE '
            . SessionUidMatch::condition('sessions.data', 'picked.id') . ') ORDER BY sessions.id',
        );

        $this->assertSame(['d-neighbour'], array_map(static fn (array $r): string => (string)$r['id'], $rows));
    }

    public function testBindingsRepeatTheIdOncePerUse(): void
    {
        $this->assertSame([7, 7, 7], SessionUidMatch::bindings(7));
        $this->assertSame(SessionUidMatch::USES, substr_count(SessionUidMatch::condition('data', '?'), '?'));
    }

    private function seed(): void
    {
        $this->assertNotNull($this->database);
        $rows = [
            'a-open' => 'uid|i:1;last_activity|i:1760000000;',
            'b-semicolon' => 'auth_epoch|s:2:"ab";uid|i:1;',
            'c-brace' => 'flash|a:0:{}uid|i:1;',
            'd-neighbour' => 'uid|i:11;',
            'e-other-key' => 'otheruid|i:1;',
            'f-other-key-later' => 'x|i:2;otheruid|i:1;',
        ];
        foreach ($rows as $id => $data) {
            $this->database->db->query(
                'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
                [$id, $data, 2000000000, '', 0],
            );
        }
    }
}
