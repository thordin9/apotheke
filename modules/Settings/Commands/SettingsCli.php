<?php

declare(strict_types=1);

namespace Apotheke\Settings\Commands;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Settings\SettingsService;
use Closure;
use RuntimeException;

/**
 * The settings forms of legacy bin/settings.php: list (the default), get,
 * set, reset, export, import and key rotate. Output and exit codes match: 0 on success,
 * 1 with the message on stderr for a UserError, 2 for an unknown command.
 *
 * The users, user, roles, auth and password forms are the Auth module's
 * apotheke:accounts; bin/settings.php runs it for them.
 */
final class SettingsCli
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** The Auth module's forms (apotheke:accounts). */
    public const AUTH_FORMS = ['users', 'user', 'roles', 'auth', 'password'];

    private ?SettingsService $settings = null;

    /**
     * @param Closure():SettingsService $resolve builds the service on first use, so a database
     *        that cannot be opened is reported like a UserError
     * @param resource $stdout
     * @param resource $stderr
     * @param (Closure(SettingsService):void)|null $firstAdmin legacy auth_enabled()'s write for `list`: with sign-in
     *        on and no account, APP_PASSWORD becomes the account "admin", or a short one stops
     *        (Auth's Accounts::ensureFirstAdmin(), wired in Config\Services)
     */
    public function __construct(
        private readonly Closure $resolve,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly ?Closure $firstAdmin = null,
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
                case 'key':
                    $this->key($args);
                    break;
                default:
                    $this->err(match (true) {
                        in_array($cmd, self::AUTH_FORMS, true) => "The {$cmd} command is in apotheke:accounts. Use php bin/settings.php {$cmd}.\n",
                        default => "Unknown command. See the comment at the top of bin/settings.php.\n",
                    });

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
        if ($this->firstAdmin !== null) {
            try {
                ($this->firstAdmin)($settings);
            } catch (RuntimeException $e) {
                // A short APP_PASSWORD exits 1 with its message, as legacy (never the value).
                if (!str_starts_with($e->getMessage(), 'APP_PASSWORD ')) {
                    throw $e;
                }
                throw new UserError($e->getMessage());
            }
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

    /**
     * key rotate (key generate is answered by bin/settings.php before anything
     * else). The new key comes from APP_KEY_NEW or stdin, never argv.
     *
     * @param list<string> $args
     */
    private function key(array $args): void
    {
        if (($args[1] ?? '') !== 'rotate') {
            throw new UserError('Use: key generate | key rotate');
        }
        if (isset($args[2])) {
            throw new UserError('The new key is not read from the command line. Set APP_KEY_NEW or pass it on stdin.');
        }
        $new = getenv('APP_KEY_NEW');
        if (!is_string($new) || $new === '') {
            $new = @file_get_contents('php://stdin');
            if (!is_string($new)) {
                $new = '';
            }
        }
        $c = $this->settings()->rotateSecrets($new);
        $this->out('rotated ' . $c['rotated'] . ', encrypted ' . $c['encrypted'] . "\n");
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
                // The runner's connect failure is already the sanitized line; a short APP_PASSWORD
                // refused by the first migration says so, as legacy (it never contains the value).
                $message = $e->getMessage();
                throw new UserError(str_starts_with($message, 'Could not connect to the database') || str_starts_with($message, 'APP_PASSWORD ') ? $message : 'Could not open the database.');
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
