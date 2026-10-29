<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * CLI goldens: `php bin/seed.php [--demo]` on legacy and with
 * APOTHEKE_APP=ci4 (spark apotheke:seed), on twin databases of the configured
 * driver: the same output and exit code, then the same rows (photo names
 * aside, which are random) and the same served photo bytes. Run again, it
 * adds nothing.
 */
final class Ci4SeedCliTest extends TestCase
{
    private const CLOCK = '2020-06-15 12:00:00';

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        if (!is_file(ROOT . '/ci4/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            try {
                testdb_drop($dir);
            } catch (Throwable) {
            }
            rrmdir($dir);
        }
    }

    public function testSeedAndDemoMatchLegacy(): void
    {
        foreach (['0', '1'] as $seedDefaults) {
            [$left, $right] = $this->twins('seed');
            foreach ([[], ['--demo'], ['--frob'], ['--demo']] as $i => $args) {
                $l = $this->seed($left, $args, false, $seedDefaults);
                $r = $this->seed($right, $args, true, $seedDefaults);
                $label = "SEED_DEFAULTS=$seedDefaults, step $i: seed.php " . implode(' ', $args);
                $this->assertSame([$l['stdout'], $l['stderr'], $l['exit']], [$r['stdout'], $r['stderr'], $r['exit']], $label);
                $this->assertSame(0, $r['exit'], $label . ': ' . $r['stderr']);
                $this->assertSame($this->state($left), $this->state($right), $label);
            }
            $this->assertStringContainsString('Demo items created: 0', $r['stdout'], 'idempotent');
            $this->assertSame(8, (int)testdb($right)->query('SELECT COUNT(*) FROM items')->fetchColumn());
            $this->assertSame(7, (int)testdb($right)->query('SELECT COUNT(*) FROM item_photos')->fetchColumn());
        }
    }

    public function testSparkEntryPoint(): void
    {
        [, $right] = $this->twins('seed-spark');
        $r = $this->proc([PHP_BINARY, ROOT . '/ci4/spark', 'apotheke:seed', '--no-header'], $this->env($right, true, '0'));
        $this->assertSame([0, "Starter locations/categories created: 16\n", ''], [$r['exit'], $r['stdout'], $r['stderr']]);
    }

    /**
     * The rows seeding writes, photo file names left out, and the served
     * photos' bytes in a stable order.
     *
     * @return array<string,mixed>
     */
    private function state(string $dir): array
    {
        $db = testdb($dir);
        $out = [];
        foreach ([
            'locations' => 'SELECT id, name, name_key, sort_key, is_default, parent_id, created_at FROM locations ORDER BY id',
            'categories' => 'SELECT id, name, name_key, sort_key, is_default, created_at FROM categories ORDER BY id',
            'items' => 'SELECT id, name, location_id, category_id, quantity, notes, search, sort_key, created_at, updated_at, (image IS NOT NULL) AS photo FROM items ORDER BY id',
            'item_photos' => 'SELECT item_id, position FROM item_photos ORDER BY item_id, position',
            'activity' => 'SELECT item_id, item_name, action, details, created_at FROM activity ORDER BY id',
            'app_settings' => 'SELECT name, value FROM app_settings ORDER BY name',
        ] as $table => $sql) {
            $out[$table] = array_map(static fn (array $row): array => array_map('strval', $row), $db->query($sql)->fetchAll(PDO::FETCH_NUM));
        }
        foreach (['images', 'thumbs'] as $folder) {
            $hashes = array_map(static fn (string $f): string => md5_file($f) ?: '', glob($dir . '/' . $folder . '/*.jpg') ?: []);
            sort($hashes);
            $out[$folder] = $hashes;
        }

        return $out;
    }

    /**
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function seed(string $dir, array $args, bool $ci4, string $seedDefaults): array
    {
        return $this->proc([PHP_BINARY, ROOT . '/bin/seed.php', ...$args], $this->env($dir, $ci4, $seedDefaults));
    }

    /**
     * @param list<string> $cmd
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function proc(array $cmd, array $env): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    /** @return array<string,string> */
    private function env(string $dir, bool $ci4, string $seedDefaults): array
    {
        $env = array_merge(getenv(), test_server_env($dir), [
            'APOTHEKE_CLOCK' => self::CLOCK,
            'CI_ENVIRONMENT' => 'production',
            'PHP_INI_SCAN_DIR' => '',
            'SEED_DEFAULTS' => $seedDefaults,
        ]);
        unset($env['APOTHEKE_APP']);
        if ($ci4) {
            $env['APOTHEKE_APP'] = 'ci4';
        }

        return array_map('strval', $env);
    }

    /** @return array{0:string,1:string} */
    private function twins(string $prefix): array
    {
        $left = tmpdir($prefix . '-legacy');
        $right = tmpdir($prefix . '-ci4');
        array_push($this->dirs, $left, $right);

        return [$left, $right];
    }
}
