<?php

declare(strict_types=1);

namespace Tests\Settings;

use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Settings\SettingsRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * M-25 for the settings write (SettingsRepository::replace(), behind every
 * SettingsService save): with DBDebug off a failed statement or a failed
 * commit must throw and roll back, and the next write must work.
 */
final class SettingsWriteFailureTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:string}> */
    public static function failures(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver . ': the delete' => [$driver, 'DELETE'];
            yield $driver . ': the insert' => [$driver, 'INSERT'];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    #[DataProvider('failures')]
    public function testAFailedStatementRollsBackAndThrows(string $driver, string $op): void
    {
        $repo = $this->wire($driver);
        $repo->replace(['app_name' => '"Before"', 'accent' => '"#111111"'], '2026-01-01 00:00:00');
        $before = $repo->rows();
        $this->failOn("BEFORE $op ON settings FOR EACH ROW");
        // Two rows: the first statement may pass, the second fails; neither may stay.
        $this->assertFails(fn () => $repo->replace(['accent' => '"#222222"', 'app_name' => '"After"'], '2026-01-02 00:00:00'));
        $this->assertSame($before, $repo->rows());
        $this->dropFailure();
        $repo->replace(['app_name' => '"After"'], '2026-01-02 00:00:00');
        $this->assertSame('"After"', $repo->rows()['app_name']);
    }

    /** A deferred constraint fails only at COMMIT: the case CI4's transCommit() result alone reports. */
    public function testAFailedCommitThrows(): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->markTestSkipped('PostgreSQL only (deferred constraint trigger)');
        }
        $repo = $this->wire('pgsql');
        $before = $repo->rows();
        $this->failOn('AFTER INSERT ON settings DEFERRABLE INITIALLY DEFERRED FOR EACH ROW', true);
        $this->assertFails(fn () => $repo->replace(['app_name' => '"Late"'], '2026-01-02 00:00:00'));
        $this->assertSame($before, $repo->rows());
        $this->dropFailure();
        $repo->replace(['app_name' => '"Late"'], '2026-01-02 00:00:00');
        $this->assertSame('"Late"', $repo->rows()['app_name']);
    }

    private function wire(string $driver): SettingsRepository
    {
        $this->database = TestDatabase::create($driver);
        $this->assertFalse((bool)$this->db()->DBDebug, 'the shipped connection has DBDebug off');

        return new SettingsRepository($this->db());
    }

    private function assertFails(callable $write): void
    {
        try {
            $write();
            $this->fail('the failed write must throw');
        } catch (WriteFailed $e) {
            $this->assertSame('Could not save the settings.', $e->getMessage());
            $this->assertSame(0, (int)$this->db()->transDepth, 'the transaction is closed');
        }
    }

    /** $when: the trigger timing and event; $constraint: a PostgreSQL constraint trigger (checked at COMMIT). */
    private function failOn(string $when, bool $constraint = false): void
    {
        if ($this->db()->DBDriver === 'Postgre') {
            $this->db()->query("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            $this->db()->query('CREATE ' . ($constraint ? 'CONSTRAINT ' : '') . "TRIGGER apotheke_test_fail $when EXECUTE FUNCTION apotheke_test_fail()");

            return;
        }
        $this->db()->query('CREATE TRIGGER apotheke_test_fail ' . str_replace(' FOR EACH ROW', '', $when) . " BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    }

    private function dropFailure(): void
    {
        $this->db()->query($this->db()->DBDriver === 'Postgre' ? 'DROP TRIGGER apotheke_test_fail ON settings' : 'DROP TRIGGER apotheke_test_fail');
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }
}
