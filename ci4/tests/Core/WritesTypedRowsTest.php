<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\Writes;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Writes::typedRows() returns what legacy's PDO returns, so rows stored as
 * JSON (undo snapshots) are the same on both apps: on PostgreSQL integers,
 * floats and booleans are typed, numeric stays a string.
 */
final class WritesTypedRowsTest extends CIUnitTestCase
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
    public function testRowsAreTypedAsLegacyPdoReturnsThem(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $db = $this->database->db;
        $sql = $driver === 'pgsql'
            ? "SELECT 1::int4 AS a, 2::int8 AS b, 1.5::float8 AS c, 1.5::numeric AS d, true AS e, 'x' AS f, NULL::int4 AS g"
            : "SELECT 1 AS a, 2 AS b, 1.5 AS c, '1.5' AS d, 1 AS e, 'x' AS f, NULL AS g";
        $row = Writes::typedRows($db, $sql)[0];
        $pdo = $this->database->pdo()->query($sql)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame($pdo, $row);
        $this->assertSame($driver === 'pgsql' ? true : 1, $row['e']);
    }
}
