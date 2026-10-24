<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * CLI goldens (plan PR 6, M-3): argv → stdout, stderr and exit code of
 * `php bin/settings.php …` on legacy and with APOTHEKE_APP=ci4 (spark
 * apotheke:settings), on twin databases of the configured driver. After the
 * run the settings tables must be identical, updated_at included.
 */
final class Ci4SettingsCliTest extends TestCase
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

    public function testSettingsFormsMatchLegacy(): void
    {
        [$left, $right] = $this->twins('cli');
        $import = $left . '-import.json';
        file_put_contents($import, json_encode(['format' => 'apotheke-settings', 'version' => 1, 'settings' => [
            'app_name' => 'Imported', 'page_size' => '33', 'search_typos' => false, 'accent' => 'red',
            'password_hash' => 'x', 'autofill_token' => 'placeholder-token', 'logo' => 'svg', 'nonsense' => 1, 'default_min_qty' => null,
        ]], JSON_UNESCAPED_UNICODE));
        $steps = [
            [[]], [['list']], [['get', 'app_name']], [['get', 'page_size']], [['get', 'default_min_qty']], [['get', 'auth_enabled']],
            [['set', 'page_size', '25']], [['set', 'page_size', '0']], [['set', 'page_size']], [['set', 'label_gap_mm', '-1']],
            [['set', 'nope', 'x']], [['set']], [['set', 'logo', 'svg']], [['set', 'autofill_token', 'x']], [['set', 'auth_enabled', 'true']],
            [['set', 'app_name', "  Πρίζα \t box "]], [['set', 'app_name', '']], [['set', 'accent', 'c8502d']], [['set', 'accent', '#4F46E5']],
            [['set', 'default_tags', 'a, #b; A']], [['set', 'public_url', 'HTTP://Example.com/']], [['set', 'timezone', 'Mars/Base']],
            [['set', 'search_typos', 'off']], [['set', 'default_min_qty', '']], [['set', 'auth_register_role', 'guest']],
            [['reset', 'nope']], [['reset']], [['reset', 'list']], [['reset', 'appearance']], [['reset', 'system']],
            [['export']], [['import', $import]], [['list']],
            [['import'], '{"format":"apotheke-settings","settings":{"currency":"CHF","jpeg_quality":"200"}}'],
            [['import'], 'not json'], [['import'], '{"format":"other","settings":{}}'],
            [['frob']], [['--help']], [['export']],
        ];
        $this->runBoth($left, $right, $steps);
        $this->assertSame($this->settingsRows($left), $this->settingsRows($right));
    }

    public function testAutofillTokenIsClearedWhenTheAddressMoves(): void
    {
        [$left, $right] = $this->twins('cli-token');
        $this->runBoth($left, $right, [[['set', 'autofill_url', 'http://192.0.2.1:8080']]]);
        foreach ([$left, $right] as $dir) {
            $this->storeToken($dir, 'placeholder-token');
        }
        $this->runBoth($left, $right, [
            [['set', 'autofill_url', 'HTTP://192.0.2.1:8080/']],   // same address after normalising: kept
            [['set', 'autofill_url', 'http://192.0.2.2:8080']],    // moved: cleared, notice + log line
            [['get', 'autofill_url']],
        ]);
        $this->assertSame('""', $this->settingsRows($right)['autofill_token'][0]);
        foreach ([$left, $right] as $dir) {
            $this->storeToken($dir, 'placeholder-token');
        }
        $this->runBoth($left, $right, [[['reset', 'autofill']], [['list']]]);
        $this->assertSame('""', $this->settingsRows($right)['autofill_token'][0]);
        foreach ([$left, $right] as $dir) {
            $this->storeToken($dir, 'placeholder-token');
        }
        $this->runBoth($left, $right, [[['import'], '{"format":"apotheke-settings","settings":{"autofill_url":"http://192.0.2.3:8080"}}']]);
        $this->assertSame($this->settingsRows($left), $this->settingsRows($right));
    }

    public function testSignInLinesOfList(): void
    {
        [$left, $right] = $this->twins('cli-signin');
        $this->runBoth($left, $right, [[['list']]]);
        foreach ([$left, $right] as $dir) {
            $db = testdb($dir);
            $db->exec("UPDATE settings SET value = 'true' WHERE name = 'auth_enabled'");
            $db->prepare('INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES (?,?,?,?,?)')
                ->execute(['admin', 'admin', password_hash('placeholder-password', PASSWORD_DEFAULT), 'admin', self::CLOCK]);
        }
        $this->runBoth($left, $right, [[['list']]]);
        $out = $this->cli($right, ['list'], true);
        $this->assertStringContainsString("sign-in                  on\nusers                    1\n", $out['stdout']);
    }

    /** Documented deviations: CI4 answers with a clean UserError (exit 1) where legacy differs. */
    public function testDeviationsFromLegacy(): void
    {
        [, $right] = $this->twins('cli-dev');
        // Legacy: uncaught LogicException, PHP fatal error, exit 255.
        $r = $this->cli($right, ['get', 'nope'], true);
        $this->assertSame(['', "Unknown setting “nope”.\n", 1], [$r['stdout'], $r['stderr'], $r['exit']]);
        // Legacy prints the stored token; the token is write-only in CI4.
        $r = $this->cli($right, ['get', 'autofill_token'], true);
        $this->assertSame(['', "“autofill_token” is write-only.\n", 1], [$r['stdout'], $r['stderr'], $r['exit']]);
        // Legacy also prints PHP's warning for a missing file.
        $r = $this->cli($right, ['import', '/nonexistent/settings.json'], true);
        $this->assertSame(['', "That is not an Apotheke settings file.\n", 1], [$r['stdout'], $r['stderr'], $r['exit']]);
        // The Auth forms stay on legacy through bin/settings.php; spark says where they are.
        $r = $this->spark($right, ['users']);
        $this->assertSame(2, $r['exit']);
        $this->assertSame("The users command is not in apotheke:settings yet. Use php bin/settings.php users.\n", $r['stderr']);
        // A value that starts with "-" reaches the command unchanged (CI4's parser would drop it).
        $r = $this->spark($right, ['set', 'default_quantity', '-5']);
        $this->assertSame("Quantity: between 0 and 1000000.\n", $r['stderr']);
    }

    /**
     * @param list<array{0:list<string>,1?:string}> $steps
     */
    private function runBoth(string $left, string $right, array $steps): void
    {
        foreach ($steps as $i => $step) {
            [$args, $stdin] = $step + [1 => null];
            $l = $this->cli($left, $args, false, $stdin);
            $r = $this->cli($right, $args, true, $stdin);
            $label = "step $i: settings.php " . implode(' ', $args);
            $this->assertSame($l['stdout'], $r['stdout'], "$label (stdout)");
            $this->assertSame($l['stderr'], $r['stderr'], "$label (stderr)");
            $this->assertSame($l['exit'], $r['exit'], "$label (exit)");
            $this->assertSame($this->settingsRows($left), $this->settingsRows($right), "$label (settings table)");
        }
    }

    /**
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cli(string $dir, array $args, bool $ci4, ?string $stdin = null): array
    {
        return $this->proc([PHP_BINARY, ROOT . '/bin/settings.php', ...$args], $this->env($dir, $ci4), $stdin);
    }

    /**
     * @param list<string> $args
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function spark(string $dir, array $args): array
    {
        return $this->proc([PHP_BINARY, ROOT . '/ci4/spark', 'apotheke:settings', '--no-header', ...$args], $this->env($dir, true), null);
    }

    /**
     * @param list<string> $cmd
     * @param array<string,string> $env
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function proc(array $cmd, array $env, ?string $stdin): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], (string)$stdin);
        fclose($pipes[0]);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit];
    }

    /** @return array<string,string> */
    private function env(string $dir, bool $ci4): array
    {
        $env = array_merge(getenv(), test_server_env($dir), [
            'APOTHEKE_CLOCK' => self::CLOCK,
            'CI_ENVIRONMENT' => 'production',
            // Errors are logged, never shown: the same php.ini for both sides.
            'PHP_INI_SCAN_DIR' => '',
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

    private function storeToken(string $dir, string $token): void
    {
        $db = testdb($dir);
        $db->prepare('UPDATE settings SET value = ? WHERE name = ?')->execute([json_encode($token), 'autofill_token']);
    }

    /** @return array<string,array{0:string,1:string}> name => [value, updated_at] */
    private function settingsRows(string $dir): array
    {
        try {
            $rows = testdb($dir)->query('SELECT name, value, updated_at FROM settings ORDER BY name')->fetchAll();
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if ($row['name'] === 'password_hash') {
                continue;   // a random salt when APP_PASSWORD is set; '' here on both sides
            }
            $out[(string)$row['name']] = [(string)$row['value'], (string)$row['updated_at']];
        }

        return $out;
    }
}
