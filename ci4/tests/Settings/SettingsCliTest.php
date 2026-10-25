<?php

declare(strict_types=1);

namespace Tests\Settings;

use Apotheke\Core\Libraries\Clock;
use Apotheke\Settings\Commands\SettingsCli;
use Apotheke\Settings\Commands\SettingsCommand;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use Tests\Support\TestDatabase;

/**
 * apotheke:settings argv → stdout, stderr and exit code. The golden
 * comparison against legacy bin/settings.php is tests/Unit/Ci4SettingsCliTest.php.
 */
final class SettingsCliTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    public function testForms(): void
    {
        $this->assertSame([0, "page_size = 25\n", ''], $this->cli(['set', 'page_size', '25']));
        $this->assertSame([0, "25\n", ''], $this->cli(['get', 'page_size']));
        $this->assertSame([1, '', "Items per page: between 1 and 500.\n"], $this->cli(['set', 'page_size', '-5']));
        $this->assertSame([0, "app_name = \"Box\"\n", ''], $this->cli(['set', 'app_name', 'Box']));
        $this->assertSame([0, "\"Box\"\n", ''], $this->cli(['get', 'app_name']));
        $this->assertSame([1, '', "Unknown setting “autofill_token” (use `user` / `auth` for accounts and sign-in).\n"], $this->cli(['set', 'autofill_token', 'x']));
        $this->assertSame([1, '', "Unknown setting “nope”.\n"], $this->cli(['get', 'nope']));
        $this->assertSame([1, '', "“password_hash” is write-only.\n"], $this->cli(['get', 'password_hash']));
        $this->assertSame([0, "reset\n", ''], $this->cli(['reset', 'list']));
        $this->assertSame([0, "60\n", ''], $this->cli(['get', 'page_size']));
        $this->assertSame([1, '', "Unknown section.\n"], $this->cli(['reset', 'nope']));
        $this->assertSame([2, '', "Unknown command. See the comment at the top of bin/settings.php.\n"], $this->cli(['frobnicate']));
        foreach (SettingsCli::AUTH_FORMS as $form) {
            $this->assertSame([2, '', "The {$form} command is in apotheke:accounts. Use php bin/settings.php {$form}.\n"], $this->cli([$form]));
        }
        $this->assertSame([2, '', "The key command is not in apotheke:settings. Use php bin/settings.php key.\n"], $this->cli(['key']));
    }

    public function testListIsTheDefault(): void
    {
        [$exit, $out, $err] = $this->cli([]);
        $this->assertSame([0, ''], [$exit, $err]);
        $this->assertStringStartsWith(sprintf("%-24s %s\n", 'app_name', '"Apotheke"'), $out);
        $this->assertStringEndsWith(sprintf("%-24s %s\n%-24s %d\n", 'sign-in', 'off', 'users', 0), $out);
        $this->assertStringNotContainsString('autofill_token', $out);
    }

    public function testExportImportAndTheTokenNotice(): void
    {
        [$exit, $json] = $this->cli(['export']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString("\n        \"app_name\": \"Apotheke\",\n", $json);
        $file = $this->database->dataDir() . '/in.json';
        file_put_contents($file, '{"format":"apotheke-settings","settings":{"page_size":12,"accent":"no"}}');
        $this->assertSame([0, "Imported 1 settings.\n  skipped accent: Accent colour: a colour like #C8502D.\n", ''], $this->cli(['import', $file]));
        $this->assertSame([1, '', "That is not an Apotheke settings file.\n"], $this->cli(['import', $file . '.missing']));
        $this->service()->setAutofillToken('placeholder-token');
        $this->assertSame([0, "autofill_url = \"http://192.0.2.5\"\n" . SettingsService::TOKEN_CLEARED_NOTICE . "\n", ''], $this->cli(['set', 'autofill_url', 'http://192.0.2.5']));
    }

    public function testADatabaseThatCannotOpenIsAUserError(): void
    {
        $cli = fn (string $message): array => $this->capture(new SettingsCli(static fn (): SettingsService => throw new RuntimeException($message), ...$this->streams()), ['list']);
        $this->assertSame([1, '', "Could not connect to the database (host 192.0.2.1).\n"], $cli('Could not connect to the database (host 192.0.2.1).'));
        $this->assertSame([1, '', "Could not open the database.\n"], $cli('SQLSTATE[HY000] with internal detail'));
    }

    public function testRawArgsKeepsDashValues(): void
    {
        $this->assertSame(['set', 'page_size', '-5'], SettingsCommand::rawArgs(['spark', 'apotheke:settings', '--no-header', 'set', 'page_size', '-5'], 'apotheke:settings'));
        $this->assertSame(['get', '--no-header'], SettingsCommand::rawArgs(['spark', 'apotheke:settings', 'get', '--no-header'], 'apotheke:settings'));
        $this->assertSame([], SettingsCommand::rawArgs(['spark', 'list'], 'apotheke:settings'));
    }

    /**
     * @param list<string> $args
     * @return array{int,string,string}
     */
    private function cli(array $args): array
    {
        return $this->capture(new SettingsCli(fn (): SettingsService => $this->service(), ...$this->streams()), $args);
    }

    /** @var array{resource,resource}|null */
    private ?array $streams = null;

    /** @return array{resource,resource} */
    private function streams(): array
    {
        $this->streams = [fopen('php://memory', 'w+b'), fopen('php://memory', 'w+b')];

        return $this->streams;
    }

    /**
     * @param list<string> $args
     * @return array{int,string,string}
     */
    private function capture(SettingsCli $cli, array $args): array
    {
        [$out, $err] = $this->streams;
        $exit = $cli->run($args);
        rewind($out);
        rewind($err);

        return [$exit, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    private function service(): SettingsService
    {
        $this->database ??= TestDatabase::create('sqlite');

        return new SettingsService(new SettingsRepository($this->database->db), new SettingsSchema(), new LocalBrandStore($this->database->dataDir() . '/brand'), new Clock(), $this->database->config->values(), static fn (string $m): null => null);
    }
}
