<?php

declare(strict_types=1);

namespace Tests\Backup;

use Apotheke\Backup\RestoreFence;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Storage\LeaseDenied;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\TestDatabase;

/** A catalog transaction rolls back when another restore has already committed the fence token. */
final class RestoreFenceTest extends CIUnitTestCase
{
    public function testATransactionDoesNotCommitAfterTheFenceIsTaken(): void
    {
        $database = TestDatabase::create('sqlite');
        try {
            $db = $database->db;
            $db->query('CREATE TABLE fence_probe (id INTEGER PRIMARY KEY)');
            RestoreFence::claim($db, 'holder-a');
            RestoreFence::claim($db, 'holder-b');
            try {
                Writes::transaction($db, static function () use ($db): void {
                    $db->query('INSERT INTO fence_probe (id) VALUES (7)');
                    RestoreFence::rejectUnless($db, 'holder-a');
                });
                $this->fail('The stale transaction must not commit.');
            } catch (LeaseDenied) {
            }
            $this->assertSame([], $db->query('SELECT id FROM fence_probe')->getResultArray());
            Writes::transaction($db, static function () use ($db): void {
                $db->query('INSERT INTO fence_probe (id) VALUES (7)');
                RestoreFence::rejectUnless($db, 'holder-b');
            });
            $this->assertSame([['id' => 7]], $db->query('SELECT id FROM fence_probe')->getResultArray());
        } finally {
            $database->drop();
        }
    }
}
