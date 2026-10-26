<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * L-370: GET /export.csv streams its rows. 8,000 items with 3 KB notes make
 * a 24 MB file, which a server limited to 24 MB can only send row by row,
 * as legacy does. Legacy runs on every leg; CI4 runs too where it can, and
 * its file must be byte for byte legacy's (tags cross CI4's batches of 500).
 */
final class ExportMemoryTest extends TestCase
{
    private const ITEMS = 8000;

    public function testTheExportStreamsUnderALowMemoryLimit(): void
    {
        $apps = ['legacy'];
        if (is_file(dirname(__DIR__, 2) . '/ci4/vendor/autoload.php') && PHP_VERSION_ID >= 80400) {
            $apps[] = 'ci4';
        }
        $hashes = [];
        foreach ($apps as $app) {
            $dir = tmpdir('export-memory-' . $app);
            $server = new Server($dir, ['APOTHEKE_CLOCK' => '2026-06-15 21:30:00'], $app, null, ['memory_limit' => '24M']);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $this->seed(testdb($dir));
                $r = $server->client()->get('/export.csv');
                $this->assertSame(200, $r['status'], $app . ': ' . substr((string)$r['body'], 0, 300));
                $body = (string)$r['body'];
                $this->assertSame('text/csv; charset=utf-8', $r['headers']['content-type'] ?? null, $app);
                $this->assertSame(self::ITEMS + 1, substr_count($body, "\n"), $app);
                // The last item, whole (no assertStringEndsWith: a failure would print 24 MB).
                $last = substr($body, (int)strrpos($body, "\n", -2) + 1);
                $this->assertSame('8000,"Item 08000",,,1,' . str_repeat('n', 3000) . ',,2026-01-01,2026-01-01,"A, B",,,,,,,,,,' . "\n", $last, $app);
                $hashes[$app] = hash('sha256', $body);
                if ($app === 'ci4') {
                    // CI4 runs the items query before it sends anything: a failing read is a 500 page.
                    testdb($dir)->exec('ALTER TABLE items RENAME TO items_gone');
                    $failed = $server->client()->get('/export.csv');
                    $this->assertSame([500, 'text/html; charset=UTF-8'], [$failed['status'], $failed['headers']['content-type'] ?? null]);
                }
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
        if (isset($hashes['ci4'])) {
            $this->assertSame($hashes['legacy'], $hashes['ci4']);
        }
    }

    private function seed(PDO $pdo): void
    {
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('B', 'b', 'b', '2026-01-01'), ('A', 'a', 'a', '2026-01-01')");
        $item = $pdo->prepare('INSERT INTO items (id, name, quantity, notes, search, sort_key, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?, ?, ?)');
        $notes = str_repeat('n', 3000);
        for ($i = 1; $i <= self::ITEMS; $i++) {
            $name = sprintf('Item %05d', $i);
            $item->execute([$i, $name, $notes, strtolower($name), strtolower($name), '2026-01-01', '2026-01-01']);
        }
        // Tagged items on both sides of CI4's batch boundaries.
        foreach ([1, 500, 501, 1000, 1001, self::ITEMS] as $id) {
            $pdo->exec("INSERT INTO item_tags (item_id, tag_id) SELECT $id, id FROM tags");
        }
        $pdo->commit();
    }
}
