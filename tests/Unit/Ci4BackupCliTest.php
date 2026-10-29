<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * CLI goldens (PLAN step 14, second part): `php bin/backup.php` and `php
 * bin/restore.php`, and the spark commands they start (apotheke:backup and
 * apotheke:restore), on twin databases of the
 * configured driver. Every step compares stdout, stderr and the exit code,
 * then the catalog tables and the photo files; every zip written is
 * compared entry by entry. A file name is relative to where the command
 * was started.
 */
final class Ci4BackupCliTest extends TestCase
{
    private const CLOCK = '2026-06-15 12:00:00';

    private const PHOTO = 'abcdef0123456789abcdef0123456789.jpg';

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        if (!is_file(ROOT . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
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

    public function testBackupAndRestoreForms(): void
    {
        $left = tmpdir('bcli-spark');
        $right = tmpdir('bcli-bin');
        array_push($this->dirs, $left, $right);
        foreach ([$left, $right] as $dir) {
            $m = $this->proc([PHP_BINARY, ROOT . '/bin/migrate.php'], $this->env($dir, false), null, ROOT);
            $this->assertSame(0, $m['exit'], $m['stdout'] . $m['stderr']);
            $this->seed($dir);
        }
        $steps = [
            ['backup', ['--help']],
            ['backup', ['-h']],
            ['backup', ['home.zip']],
            ['backup', ['{dir}/abs.zip']],
            ['restore', []],
            ['restore', ['--help']],
            ['restore', ['home.zip', '--help']],
            ['restore', ['missing.zip']],
            ['restore', ['not-a-zip.zip']],
            ['restore', ['home.zip', '--dry-run']],
            ['sql', "UPDATE items SET name = 'Changed' WHERE id = 1"],
            ['restore', ['home.zip'], "no\n"],
            ['restore', ['home.zip'], ''],
            ['restore', ['home.zip'], "yes\n"],
            ['sql', "INSERT INTO tags (name, name_key, sort_key, created_at) VALUES ('new', 'new', 'new', '2026-06-01')"],
            ['restore', ['--yes', '{dir}/abs.zip']],
            ['restore', ['broken.zip', '--yes']],
            ['unlink', 'images/' . self::PHOTO],
            ['backup', ['missing-photo.zip']],
            ['restore', ['missing-photo.zip', '--dry-run']],
        ];
        foreach ([$left, $right] as $dir) {
            file_put_contents($dir . '/work/not-a-zip.zip', 'not a zip');
        }
        foreach ($steps as $i => $step) {
            [$form, $args] = $step;
            if ($form === 'sql' || $form === 'unlink') {
                foreach ([$left, $right] as $dir) {
                    $form === 'sql' ? testdb($dir)->exec($args) : unlink($dir . '/' . $args);
                }
                continue;
            }
            if ($args === ['broken.zip', '--yes']) {
                $this->broken($left);
                $this->broken($right);
            }
            $out = $detail = [];
            foreach (['spark' => $left, 'bin' => $right] as $app => $dir) {
                $argv = array_map(static fn (string $a): string => str_replace('{dir}', $dir, $a), $args);
                // The wrapper hands spark the arguments as typed and the directory it was started in.
                $env = $this->env($dir, true);
                $cmd = [PHP_BINARY, ROOT . '/bin/' . $form . '.php', ...$argv];
                if ($app === 'spark') {
                    $env += ['APOTHEKE_CLI_CWD' => $dir . '/work', 'APOTHEKE_CLI_ARGS' => (string)json_encode($argv)];
                    $cmd = [PHP_BINARY, ROOT . '/spark', 'apotheke:' . $form, '--no-header'];
                }
                $r = $this->proc($cmd, $env, $step[2] ?? null, $dir . '/work');
                // The database's own error text is never shown (2.x added it; HANDOFF Tracked).
                $err = str_replace($dir, '{dir}', $r['stderr']);
                $detail[$app] = preg_match('/^The backup could not be restored \(.+\)\. Nothing was changed\.$/m', $err) === 1;
                $err = (string)preg_replace('/^The backup could not be restored \(.+\)\. Nothing was changed\.$/m', 'The backup could not be restored. Nothing was changed.', $err);
                $out[$app] = [str_replace($dir, '{dir}', $r['stdout']), $err, $r['exit'], $this->state($dir), $this->zips($dir)];
            }
            $label = "step $i: $form.php " . implode(' ', $args);
            $this->assertSame([false, false], [$detail['spark'], $detail['bin']], "$label (database text)");
            foreach (['stdout', 'stderr', 'exit', 'tables', 'zips'] as $k => $what) {
                $this->assertSame($out['spark'][$k], $out['bin'][$k], "$label ($what)");
            }
            if ($i === 2) {
                $this->assertMatchesRegularExpression('#^Backup written: home\.zip \(0\.0 MB\)\n  3 items, 1 locations, 0 categories, 0 tags, 1 history entries, 1 photos\n$#', $out['bin'][0]);
            }
        }
        $this->assertSame([], array_diff_key($this->zips($left), ['home.zip' => 1, 'abs.zip' => 1, 'missing-photo.zip' => 1, 'not-a-zip.zip' => 1, 'broken.zip' => 1]));
    }

    private function seed(string $dir): void
    {
        $pdo = testdb($dir);
        $pdo->exec("INSERT INTO locations (name, name_key, sort_key, created_at) VALUES ('Shed', 'shed', 'shed', '2026-01-01 00:00:00')");
        $item = $pdo->prepare('INSERT INTO items (name, location_id, quantity, notes, image, search, sort_key, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([['Ladder', 1, 1, 'tall', self::PHOTO], ['Ελιά', null, 2, '', null], ['Rope', 1, 3, "a\nb", null]] as $r) {
            $item->execute([...$r, mb_strtolower($r[0]), mb_strtolower($r[0]), '2026-01-02 08:00:00', '2026-01-02 08:00:00']);
        }
        $pdo->exec("INSERT INTO item_photos (item_id, file, position, created_at) VALUES (1, '" . self::PHOTO . "', 0, '2026-01-02 08:00:00')");
        $pdo->exec("INSERT INTO activity (item_id, item_name, action, details, actor, created_at) VALUES (1, 'Ladder', 'created', '{}', null, '2026-01-02 08:00:00')");
        foreach (['images', 'thumbs', 'work'] as $sub) {
            @mkdir($dir . '/' . $sub, 0775, true);
        }
        file_put_contents($dir . '/images/' . self::PHOTO, img_jpeg(64, 48));
        file_put_contents($dir . '/thumbs/' . self::PHOTO, img_jpeg(32, 24));
    }

    /** A backup whose rows break a foreign key: the restore changes nothing. */
    private function broken(string $dir): void
    {
        $z = new ZipArchive();
        $z->open($dir . '/work/home.zip', ZipArchive::RDONLY);
        $dump = json_decode((string)$z->getFromName('backup.json'), true);
        $z->close();
        $at = array_search('location_id', $dump['tables']['items']['columns'], true);
        $dump['tables']['items']['rows'][0][$at] = 99;
        $b = new ZipArchive();
        $b->open($dir . '/work/broken.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $b->addFromString('backup.json', (string)json_encode($dump));
        $b->close();
    }

    /** @return array<string,mixed> the catalog tables and the photo files */
    private function state(string $dir): array
    {
        $pdo = testdb($dir);
        $o = [];
        foreach (['locations', 'categories', 'tags', 'items', 'item_photos', 'activity'] as $t) {
            $o[$t] = $pdo->query("SELECT * FROM $t ORDER BY 1")->fetchAll();
        }
        foreach (['images', 'thumbs'] as $sub) {
            foreach (glob($dir . '/' . $sub . '/*') ?: [] as $f) {
                $o['files'][$sub . '/' . basename($f)] = hash_file('sha256', $f);
            }
        }

        return $o;
    }

    /** @return array<string,mixed> every zip in work/ by its entries (name, method, sizes, bytes) */
    private function zips(string $dir): array
    {
        $out = [];
        foreach (glob($dir . '/work/*.zip') ?: [] as $f) {
            $z = new ZipArchive();
            if ($z->open($f, ZipArchive::RDONLY) !== true) {
                $out[basename($f)] = 'not a zip';
                continue;
            }
            $entries = [];
            for ($i = 0; $i < $z->numFiles; $i++) {
                $s = $z->statIndex($i);
                $entries[] = [$s['name'], $s['comp_method'], $s['size'], $s['comp_size'], hash('sha256', (string)$z->getFromIndex($i))];
            }
            $z->close();
            $out[basename($f)] = $entries;
        }

        return $out;
    }

    /**
     * @param list<string> $cmd
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function proc(array $cmd, array $env, ?string $stdin, string $cwd): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], (string)$stdin);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($proc)];
    }

    /** @return array<string,string> */
    private function env(string $dir, bool $ci4): array
    {
        $env = array_merge(getenv(), test_server_env($dir), [
            'APOTHEKE_CLOCK' => self::CLOCK,
            'CI_ENVIRONMENT' => 'production',
            'PHP_INI_SCAN_DIR' => '',
        ]);
        unset($env['APOTHEKE_APP'], $env['APP_PASSWORD']);

        return array_map('strval', $env);
    }
}
