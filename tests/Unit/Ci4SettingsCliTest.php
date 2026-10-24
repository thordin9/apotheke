<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Exceptions/UserError.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Libraries/Text.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Settings/SettingsSchema.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Security/SecretKey.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Security/SecretBox.php';

use Apotheke\Core\Security\SecretBox;
use Apotheke\Settings\SettingsSchema;

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
            // The command line may import sign-in settings (SEC-M1 limits only web users).
            [['import'], '{"format":"apotheke-settings","settings":{"session_days":7,"auth_register_disabled":false,"trust_proxy":true}}'],
            [['import'], 'not json'], [['import'], '{"format":"other","settings":{}}'],
            [['frob']], [['--help']], [['export']],
        ];
        $this->runBoth($left, $right, $steps);
        $this->assertSame($this->settingsRows($left), $this->settingsRows($right));
    }

    /**
     * Every other form and case, byte for byte: `get` of every readable key,
     * `set` across every value type (valid, refused, normalised, dash values,
     * protected keys, missing and extra arguments), `reset` of every section,
     * `import` from stdin and files (empty, malformed, partial, over the web
     * form's 256 KiB), unknown and mis-cased commands, and a final export.
     */
    public function testEveryOtherFormAndCaseMatchesLegacy(): void
    {
        [$left, $right] = $this->twins('cli-all');
        $schema = new SettingsSchema();
        $steps = [];
        foreach ($schema->keys() as $key) {
            if (!$schema->isSecret($key)) {
                $steps[] = [['get', $key]];
            }
        }
        $steps[] = [['get', 'app_name', 'extra']];
        $sets = [
            ['app_tagline', 'Ein Lager'], ['app_tagline', str_repeat('x', 300)], ['default_notes', "line 1\nline 2"],
            ['public_url', 'ftp://example.com'], ['public_url', 'example.com'], ['public_url', 'https://example.com/box/'], ['public_url', ''],
            ['accent', 'blue'], ['accent', '#abc'], ['accent', '0F766E'], ['icon_background', '#FFFFFF'], ['icon_background', 'white'],
            ['theme_default', 'dark'], ['theme_default', 'DARK'], ['theme_default', 'nope'], ['list_view', 'list'], ['recent_scope', 'shared'],
            ['search_typos', 'true'], ['search_typos', 'false'], ['search_typos', '1'], ['search_typos', '0'], ['search_typos', 'yes'],
            ['search_typos', 'no'], ['search_typos', 'on'], ['search_typos', 'maybe'], ['search_typos', ''],
            ['page_size', ' 30 '], ['page_size', '1e2'], ['page_size', '3.5'], ['page_size', '501'], ['page_size', '500'], ['page_size', 'abc'],
            ['default_quantity', '-5'], ['default_quantity', '-0'], ['default_quantity', '1000001'],
            ['default_min_qty', '0'], ['default_min_qty', 'x'], ['default_min_qty', ''], ['lend_due_days', '14'], ['default_expiry_months', '-1'],
            ['default_tags', implode(',', range(1, 21))], ['default_tags', 'Ä, ä, Kabel'], ['default_tags', ''],
            ['timezone', 'Europe/Athens'], ['timezone', 'europe/athens'], ['currency', 'eur'], ['currency', 'EURO'], ['currency', ''],
            ['money_format', 'nope'], ['date_format', 'd.m.Y'], ['date_format', 'Y/m/d H'], ['time_format', 'nope'],
            ['default_sort', 'name'], ['default_sort', 'nope'], ['attach_types', 'nope'], ['attach_types', 'any'],
            ['jpeg_quality', '39'], ['image_full_max', '20000'], ['max_upload_mb', '0'],
            ['autofill_url', 'http://192.0.2.1:8080'], ['autofill_url', 'not a url'], ['autofill_model', 'llava:7b'], ['autofill_model', 'bad model!'],
            ['autofill_image_size', '63'], ['autofill_image_size', '64'], ['autofill_timeout', '2'], ['autofill_enabled', 'on'],
            ['auth_register_role', 'admin'], ['auth_register_role', 'editor'], ['session_days', '0'], ['session_days', '365'],
            ['trust_proxy', 'on'], ['import_create_missing', 'off'], ['label_columns', '0'], ['label_show_url', 'false'],
            ['password_hash', 'x'], ['logo', 'png'],
        ];
        foreach ($sets as [$key, $value]) {
            $steps[] = [['set', $key, $value]];
        }
        $steps[] = [['set', 'page_size', '5', 'extra']];
        $steps[] = [['set', 'page_size', '']];
        foreach (array_keys(SettingsSchema::SECTIONS) as $section) {
            $steps[] = [['reset', $section]];
        }
        $steps[] = [['reset', 'APPEARANCE']];
        $big = $left . '-big.json';
        file_put_contents($big, json_encode(['format' => 'apotheke-settings', 'settings' => ['app_tagline' => 'big'], 'pad' => str_repeat('x', 300 * 1024)]));
        $partial = $left . '-partial.json';
        file_put_contents($partial, '{"format":"apotheke-settings","settings":{"page_size":"7","list_view":["x"],"accent":"#C8502D","undo_seconds":"nope"}}');
        array_push(
            $steps,
            [['import'], ''], [['import'], '[]'], [['import'], '{"format":"apotheke-settings"}'],
            [['import'], '{"format":"apotheke-settings","settings":[]}'], [['import'], '{"format":"apotheke-settings","settings":{}}'],
            [['import', $partial]], [['import', $big]], [['import', 'php://stdin'], '{"format":"apotheke-settings","settings":{"currency":"USD"}}'],
            [['LIST']], [['Get', 'app_name']], [['']], [['help']], [['-h']],
            [['list']], [['export']],
        );
        $this->assertGreaterThan(150, count($steps));
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

    /**
     * The four intentional deviations (signed off by the PM), each pinned on
     * both sides so the difference is exactly the documented one.
     */
    public function testIntentionalDeviationsFromLegacy(): void
    {
        [$left, $right] = $this->twins('cli-dev');
        foreach ([$left, $right] as $dir) {
            $this->cli($dir, ['list'], $dir === $right);   // create both databases
            $this->storeToken($dir, 'placeholder-token');
        }
        // 1. Secrets are write-only. Legacy prints them (with the S-L5 notice, as no APP_KEY is set).
        $l = $this->cli($left, ['get', 'autofill_token'], false);
        $this->assertSame(["\"placeholder-token\"\n", "apotheke: set APP_KEY to encrypt stored secrets\n", 0], [$l['stdout'], $l['stderr'], $l['exit']]);
        $r = $this->cli($right, ['get', 'autofill_token'], true);
        $this->assertSame(['', "“autofill_token” is write-only.\n", 1], [$r['stdout'], $r['stderr'], $r['exit']]);
        $l = $this->cli($left, ['get', 'password_hash'], false);
        $this->assertSame(0, $l['exit']);
        $r = $this->cli($right, ['get', 'password_hash'], true);
        $this->assertSame(['', "“password_hash” is write-only.\n", 1], [$r['stdout'], $r['stderr'], $r['exit']]);

        // 2. get of an unknown key: legacy dies with an uncaught exception (exit 255).
        foreach ([['get', 'nope'], ['get']] as $args) {
            $l = $this->cli($left, $args, false);
            $this->assertSame(255, $l['exit'], implode(' ', $args));
            $key = $args[1] ?? '';
            $r = $this->cli($right, $args, true);
            $this->assertSame(['', "Unknown setting “{$key}”.\n", 1], [$r['stdout'], $r['stderr'], $r['exit']]);
        }

        // 3. import of a missing file: the same message and exit code, minus PHP's warning line.
        $l = $this->cli($left, ['import', '/nonexistent/settings.json'], false);
        $r = $this->cli($right, ['import', '/nonexistent/settings.json'], true);
        $this->assertStringContainsString('file_get_contents(/nonexistent/settings.json)', $l['stdout'] . $l['stderr']);
        $this->assertSame([$this->withoutPhpWarnings($l['stdout']), $this->withoutPhpWarnings($l['stderr']), $l['exit']], [$r['stdout'], $r['stderr'], $r['exit']]);
        $this->assertSame(['', "That is not an Apotheke settings file.\n", 1], [$r['stdout'], $r['stderr'], $r['exit']]);

        // 4. list is read-only: with sign-in on, no accounts and APP_PASSWORD set, legacy
        //    creates the account "admin" (auth_enabled()); CI4 does not until the Auth PR.
        [$left, $right] = $this->twins('cli-dev-admin');
        $extra = ['APP_PASSWORD' => 'placeholder-password'];
        foreach ([$left, $right] as $dir) {
            $this->cli($dir, ['list'], $dir === $right);
            testdb($dir)->exec('DELETE FROM users');
            testdb($dir)->exec("UPDATE settings SET value = 'true' WHERE name = 'auth_enabled'");
        }
        $l = $this->cli($left, ['list'], false, null, $extra);
        $r = $this->cli($right, ['list'], true, null, $extra);
        $this->assertStringEndsWith("sign-in                  on\nusers                    1\n", $l['stdout']);
        $this->assertStringEndsWith("sign-in                  on\nusers                    0\n", $r['stdout']);
        $this->assertSame(str_replace("users                    1\n", "users                    0\n", $l['stdout']), $r['stdout'], 'only the users count differs');
        $this->assertSame(1, (int)testdb($left)->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(0, (int)testdb($right)->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    /**
     * S-L5 (#78): with APP_KEY set, unusable or missing, both apps read the same
     * enc:v1: rows and print the same log lines; a legacy plaintext token is
     * sealed on read by both. Placeholder keys only.
     */
    public function testStoredSecretKeyHandlingMatchesLegacy(): void
    {
        $key = base64_encode(str_repeat('k', 32));
        $other = base64_encode(str_repeat('o', 32));
        $sealed = SecretBox::sealWith('placeholder-token', (string)base64_decode($key, true));
        $cases = [
            'usable key, sealed row' => [['APP_KEY' => $key], $sealed, ''],
            'wrong key, sealed row' => [['APP_KEY' => $other], $sealed, "apotheke: setting autofill_token could not be decrypted and is treated as unset.\n"],
            'no key, sealed row' => [[], $sealed, "apotheke: set APP_KEY to encrypt stored secrets\napotheke: setting autofill_token could not be decrypted and is treated as unset.\n"],
            'malformed key, plain row' => [['APP_KEY' => 'malformed-placeholder'], 'placeholder-token', "apotheke: APP_KEY is not a 32-byte key; stored secrets will not be saved.\n"],
            'sodium missing, plain row' => [['APP_KEY' => $key, 'APOTHEKE_SODIUM' => '0'], 'placeholder-token', "apotheke: sodium extension is missing; stored secrets will not be saved.\n"],
            'key file in the data dir' => [['APP_KEY_FILE' => '{dir}/app.key'], 'placeholder-token', "apotheke: APP_KEY_FILE is inside the data directory or a backup path; stored secrets will not be saved.\n"],
        ];
        foreach ($cases as $label => [$extra, $stored, $listStderr]) {
            [$left, $right] = $this->twins('cli-key');
            foreach ([$left, $right] as $dir) {
                $this->cli($dir, ['set', 'autofill_url', 'http://192.0.2.1:8080'], $dir === $right);
                $this->storeToken($dir, $stored);
                file_put_contents($dir . '/app.key', $key . "\n");
            }
            foreach ([['list'], ['set', 'autofill_url', 'HTTP://192.0.2.1:8080/'], ['set', 'autofill_url', 'http://192.0.2.2:8080']] as $args) {
                $l = $this->cli($left, $args, false, null, str_replace('{dir}', $left, $extra));
                $r = $this->cli($right, $args, true, null, str_replace('{dir}', $right, $extra));
                $this->assertSame([$l['stdout'], $l['stderr'], $l['exit']], [$r['stdout'], $r['stderr'], $r['exit']], "$label: " . implode(' ', $args));
                $this->assertSame($this->settingsRows($left), $this->settingsRows($right), "$label: " . implode(' ', $args));
                $this->assertStringNotContainsString('placeholder-token', $l['stdout'] . $l['stderr'] . $r['stdout'] . $r['stderr'], $label);
                if ($args === ['list']) {
                    $this->assertSame($listStderr, $r['stderr'], "$label: list log lines");
                }
            }
            $this->assertSame('""', $this->settingsRows($right)['autofill_token'][0], "$label: the moved address cleared the token");
        }

        // A legacy plaintext row is sealed on read by both; the nonces differ, nothing else does.
        [$left, $right] = $this->twins('cli-key-upgrade');
        foreach ([$left, $right] as $dir) {
            $this->cli($dir, ['list'], $dir === $right);
            $this->storeToken($dir, 'placeholder-token');
        }
        $l = $this->cli($left, ['list'], false, null, ['APP_KEY' => $key]);
        $r = $this->cli($right, ['list'], true, null, ['APP_KEY' => $key]);
        $this->assertSame([$l['stdout'], $l['stderr'], $l['exit']], [$r['stdout'], $r['stderr'], $r['exit']]);
        $rows = [$this->settingsRows($left), $this->settingsRows($right)];
        $box = SecretBox::fromEnvironment(['APP_KEY' => $key], [], static function (string $message): void {
        });
        foreach ($rows as $i => $set) {
            $value = json_decode($set['autofill_token'][0], true);
            $this->assertStringStartsWith(SecretBox::ENVELOPE, (string)$value, (string)$i);
            $this->assertSame('placeholder-token', $box->open('autofill_token', (string)$value), (string)$i);
        }
        unset($rows[0]['autofill_token'][0], $rows[1]['autofill_token'][0]);
        $this->assertSame($rows[0], $rows[1]);
    }

    /**
     * #76 N-1 (main #77): with sign-in and guest browsing on, the current user on
     * the command line is the anonymous guest, yet the command line still imports
     * sign-in settings. CI4 passes the CommandLine actor, never the request's user.
     */
    public function testTheCommandLineImportsSignInSettingsWithGuestBrowsingOn(): void
    {
        [, $right] = $this->twins('cli-guest');
        $this->cli($right, ['list'], true);
        $db = testdb($right);
        $db->prepare('INSERT INTO users (username, username_key, password_hash, role, created_at) VALUES (?,?,?,?,?)')
            ->execute(['root', 'root', password_hash('placeholder-pass', PASSWORD_DEFAULT), 'admin', '2026-01-01 00:00:00']);
        foreach (['auth_enabled' => 'true', 'auth_guest_disabled' => 'false'] as $name => $value) {
            $db->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
            $db->prepare("INSERT INTO settings (name, value, updated_at) VALUES (?, ?, '2026-01-01')")->execute([$name, $value]);
        }
        $import = '{"format":"apotheke-settings","settings":{"session_days":7,"trust_proxy":true,"auth_register_disabled":false}}';
        $r = $this->cli($right, ['import'], true, $import);
        $this->assertSame(['Imported 3 settings.' . "\n", '', 0], [$r['stdout'], $r['stderr'], $r['exit']], 'bin/settings.php');
        $rows = $this->settingsRows($right);
        $this->assertSame(['7', 'true', 'false'], [$rows['session_days'][0] ?? null, $rows['trust_proxy'][0] ?? null, $rows['auth_register_disabled'][0] ?? null]);
        $r = $this->proc([PHP_BINARY, ROOT . '/ci4/spark', 'apotheke:settings', '--no-header', 'import'], $this->env($right, true), '{"format":"apotheke-settings","settings":{"session_days":9}}');
        $this->assertSame(['Imported 1 settings.' . "\n", 0], [$r['stdout'], $r['exit']], 'spark');
        $this->assertSame('9', $this->settingsRows($right)['session_days'][0] ?? null);
    }

    /** Spark-only behaviour of apotheke:settings (bin/settings.php keeps these forms on legacy). */
    public function testSparkEntryPoint(): void
    {
        [, $right] = $this->twins('cli-spark');
        foreach (['users', 'user', 'roles', 'auth', 'password'] as $form) {
            $r = $this->spark($right, [$form]);
            $this->assertSame(2, $r['exit']);
            $this->assertSame("The {$form} command is not in apotheke:settings yet. Use php bin/settings.php {$form}.\n", $r['stderr']);
        }
        // A value that starts with "-" reaches the command unchanged (CI4's parser would drop it).
        $r = $this->spark($right, ['set', 'default_quantity', '-5']);
        $this->assertSame("Quantity: between 0 and 1000000.\n", $r['stderr']);
    }

    private function withoutPhpWarnings(string $text): string
    {
        return (string)preg_replace('/^(PHP )?Warning: .*\R(\R)?/m', '', $text);
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
     * @param array<string,string> $extraEnv
     * @return array{stdout:string,stderr:string,exit:int}
     */
    private function cli(string $dir, array $args, bool $ci4, ?string $stdin = null, array $extraEnv = []): array
    {
        return $this->proc([PHP_BINARY, ROOT . '/bin/settings.php', ...$args], array_merge($this->env($dir, $ci4), $extraEnv), $stdin);
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
