<?php

declare(strict_types=1);

namespace Apotheke\Settings\Commands;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Settings\SettingsService;
use Closure;
use RuntimeException;

/**
 * The settings forms of legacy bin/settings.php: list (the default), get,
 * set, reset, export and import. Output and exit codes match: 0 on success,
 * 1 with the message on stderr for a UserError, 2 for an unknown command.
 *
 * The users, user, roles, auth and password forms move to the Auth module
 * (Auth PR); bin/settings.php keeps running them on legacy until then.
 */
final class SettingsCli
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** Forms that stay in bin/settings.php for now: the Auth module's, and key generate|rotate (S-L5). */
    public const AUTH_FORMS = ['users', 'user', 'roles', 'auth', 'password', 'key'];

    private ?SettingsService $settings = null;

    /**
     * @param Closure():SettingsService $resolve builds the service on first use, so a database
     *        that cannot be opened is reported like a UserError
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly Closure $resolve,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
    ) {
    }

    /** @param list<string> $args argv after the command name */
    public function run(array $args): int
    {
        $cmd = $args[0] ?? 'list';
        try {
            switch ($cmd) {
                case 'list':
                    $this->list();
                    break;
                case 'get':
                    $this->get($args[1] ?? '');
                    break;
                case 'set':
                    $this->set($args[1] ?? '', $args[2] ?? '');
                    break;
                case 'reset':
                    $this->settings()->resetSection($args[1] ?? '');
                    $this->out("reset\n");
                    $this->tokenNotice();
                    break;
                case 'export':
                    $this->out(json_encode($this->settings()->export(), JSON_PRETTY_PRINT | self::JSON) . "\n");
                    break;
                case 'import':
                    $this->import($args[1] ?? 'php://stdin');
                    break;
                default:
                    $this->err(in_array($cmd, self::AUTH_FORMS, true)
                        ? "The {$cmd} command is not in apotheke:settings yet. Use php bin/settings.php {$cmd}.\n"
                        : "Unknown command. See the comment at the top of bin/settings.php.\n");

                    return 2;
            }
        } catch (UserError $e) {
            $this->err($e->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    private function list(): void
    {
        $settings = $this->settings();
        foreach ($settings->export()['settings'] as $key => $value) {
            $this->out(sprintf("%-24s %s\n", $key, json_encode($value, self::JSON)));
        }
        $this->out(sprintf("%-24s %s\n", 'sign-in', $settings->signInEnabled() ? 'on' : 'off'));
        $this->out(sprintf("%-24s %d\n", 'users', $settings->accountCount()));
    }

    private function get(string $key): void
    {
        $settings = $this->settings();
        if (!$settings->schema()->has($key)) {
            throw new UserError("Unknown setting “{$key}”.");
        }
        if ($settings->schema()->isSecret($key)) {
            throw new UserError("“{$key}” is write-only.");
        }
        $this->out(json_encode($settings->get($key), self::JSON) . "\n");
    }

    private function set(string $key, string $value): void
    {
        $settings = $this->settings();
        if (!$settings->schema()->has($key) || $settings->schema()->isProtected($key)) {
            throw new UserError("Unknown setting “{$key}” (use `user` / `auth` for accounts and sign-in).");
        }
        $err = $settings->save([$key => $value]);
        if ($err !== []) {
            throw new UserError($err[$key]);
        }
        $this->out("$key = " . json_encode($settings->get($key), self::JSON) . "\n");
        $this->tokenNotice();
    }

    private function import(string $file): void
    {
        $json = @file_get_contents($file);
        // No size limit here, like legacy bin/settings.php (the web form has one).
        $r = $this->settings()->import(is_string($json) ? $json : '', null);
        $this->out("Imported {$r['applied']} settings.\n");
        foreach ($r['errors'] as $key => $message) {
            $this->out("  skipped $key: $message\n");
        }
    }

    private function tokenNotice(): void
    {
        if ($this->settings()->tokenCleared()) {
            $this->out(SettingsService::TOKEN_CLEARED_NOTICE . "\n");
        }
    }

    private function settings(): SettingsService
    {
        if ($this->settings === null) {
            try {
                $this->settings = ($this->resolve)();
            } catch (UserError $e) {
                throw $e;
            } catch (RuntimeException $e) {
                // The runner's connect failure is already the sanitized line.
                throw new UserError(str_starts_with($e->getMessage(), 'Could not connect to the database') ? $e->getMessage() : 'Could not open the database.');
            }
        }

        return $this->settings;
    }

    private function out(string $text): void
    {
        fwrite($this->stdout, $text);
    }

    private function err(string $text): void
    {
        fwrite($this->stderr, $text);
    }
}
