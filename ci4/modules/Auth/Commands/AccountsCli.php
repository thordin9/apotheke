<?php

declare(strict_types=1);

namespace Apotheke\Auth\Commands;

use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\SignInSwitch;
use Apotheke\Auth\SignOutEverywhere;
use Apotheke\Auth\UserAdmin;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Settings\SettingsService;
use RuntimeException;

/**
 * The account and sign-in forms of legacy bin/settings.php: users, user
 * add|password|role|approve|rename|delete|signout, roles [reset], auth on|off
 * and password [PASSWORD|--off]. Output, prompts and exit codes match: 0 on
 * success, 1 with the message on stderr for a refusal, 2 for an unknown
 * command. `auth status` stays in bin/settings.php: it must run without
 * migrating, which booting the CI4 database does.
 *
 * A password is read from argv (as legacy) or from stdin after a prompt; on a
 * terminal the typed characters are not shown. A password never reaches the
 * output or a log line. A failed database write exits 1 with a short message
 * and no stack trace (legacy: PHP's uncaught-exception exit 255).
 */
final class AccountsCli
{
    /**
     * @param resource $stdin
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly UserAdmin $admin,
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly SignInSwitch $switch,
        private readonly SignOutEverywhere $signOut,
        private readonly SettingsService $settings,
        private readonly CommandLineActor $actor,
        private readonly mixed $stdin,
        private readonly mixed $stdout,
        private readonly mixed $stderr,
    ) {
    }

    /** @param list<string> $args argv after the command name */
    public function run(array $args): int
    {
        try {
            switch ($args[0] ?? '') {
                case 'users':
                    $this->listUsers();
                    break;
                case 'user':
                    $this->user($args[1] ?? '', $args[2] ?? '', $args);
                    break;
                case 'roles':
                    if (($args[1] ?? '') === 'reset') {
                        $this->roles->resetBuiltin();
                        $this->out("Built-in roles restored.\n");
                        break;
                    }
                    foreach ($this->roles->all() as $key => $role) {
                        $this->out(sprintf("%-14s %-16s %s\n", $key, $role['name'], implode(',', $role['perms'])));
                    }
                    break;
                case 'auth':
                    $this->auth($args[1] ?? '');
                    break;
                case 'password':
                    $this->password($args);
                    break;
                default:
                    $this->err("Unknown command. See the comment at the top of bin/settings.php.\n");

                    return 2;
            }
        } catch (UserError $e) {
            $this->err($e->getMessage() . "\n");

            return 1;
        } catch (WriteFailed $e) {
            $this->err($e->getMessage() . "\n");

            return 1;
        } catch (RuntimeException $e) {
            // A blank or too short APP_PASSWORD (Accounts::ensureFirstAdmin; the message never contains it).
            if (!str_starts_with($e->getMessage(), 'APP_PASSWORD ')) {
                throw $e;
            }
            $this->err($e->getMessage() . "\n");

            return 1;
        }

        return 0;
    }

    private function listUsers(): void
    {
        if ($this->users->count() === 0) {
            $this->out("No accounts.\n");
        }
        foreach ($this->users->all() as $u) {
            $this->out(sprintf("%-24s %-7s %s\n", $u['username'], $u['role'] . ($u['status'] === 'pending' ? ' (pending)' : ''),
                $u['last_login_at'] !== null && $u['last_login_at'] !== '' ? 'last sign-in ' . $u['last_login_at'] . ' UTC' : 'never signed in'));
        }
        $this->actor->isAdmin();   // legacy auth_enabled(): the first admin from APP_PASSWORD
        $this->out(sprintf("sign-in is %s\n", $this->settings->signInEnabled() ? 'on' : 'off'));
    }

    /** @param list<string> $args */
    private function user(string $sub, string $name, array $args): void
    {
        $find = function () use ($name): int {
            $u = $this->users->findByName($name) ?? throw new UserError("No user “{$name}”. Run `php bin/settings.php users`.");

            return $u['id'];
        };
        switch ($sub) {
            case 'add':
                $this->admin->create($name, $args[4] ?? $this->askPassword(), $args[3] ?? '');
                $this->out("Added {$name}.\n");
                break;
            case 'password':
                $id = $find();
                $this->admin->setPassword($id, $args[3] ?? $this->askPassword());
                $this->out("Password set; {$name} has to sign in again on every device.\n");
                break;
            case 'role':
                $this->admin->setRole($find(), $args[3] ?? '');
                $this->out("{$name} is now " . ($args[3] ?? '') . ".\n");
                break;
            case 'approve':
                $this->admin->approve($find());
                $this->out("{$name} can sign in now.\n");
                break;
            case 'rename':
                $this->admin->rename($find(), $args[3] ?? '');
                $this->out("Renamed.\n");
                break;
            case 'delete':
                $this->admin->delete($find());
                $this->out("Deleted {$name}.\n");
                break;
            case 'signout':
                $this->signOut->run($find());
                $this->out("Signed {$name} out on every device.\n");
                break;
            default:
                throw new UserError('Use: user add|password|role|approve|rename|delete|signout NAME …');
        }
    }

    private function auth(string $sub): void
    {
        if ($sub === 'on') {
            $this->switch->on();
            $this->out("Sign-in turned on.\n");
        } elseif ($sub === 'off') {
            $this->switch->off();
            $this->out("Sign-in turned off.\n");
        } else {
            throw new UserError('Use: auth on|off|status');
        }
    }

    /**
     * Legacy recovery shortcut: the account "admin" gets this password, the
     * admin role and approval (or is created), and sign-in goes on. The steps
     * run one after another, as on legacy.
     *
     * @param list<string> $args
     */
    private function password(array $args): void
    {
        if (($args[1] ?? '') === '--off') {
            $this->switch->off();
            $this->out("Sign-in turned off.\n");

            return;
        }
        $password = $args[1] ?? $this->askPassword();
        $u = $this->users->findByName('admin');
        if ($u !== null) {
            $this->admin->setPassword($u['id'], $password);
            $this->admin->setRole($u['id'], 'admin');
            $this->admin->approve($u['id']);
        } else {
            $this->admin->create('admin', $password, 'admin');
        }
        $this->switch->on();
        $this->out("The account “admin” has a new password set and sign-in is on; other devices have to sign in again.\n");
    }

    /** Legacy cli_ask_password(), without showing the typing on a terminal. */
    private function askPassword(): string
    {
        $this->out('New password: ');
        $password = $this->readSecret();
        $this->out('Repeat: ');
        if ($this->readSecret() !== $password) {
            throw new UserError('The passwords do not match.');
        }

        return $password;
    }

    private function readSecret(): string
    {
        $tty = stream_isatty($this->stdin) && function_exists('shell_exec');
        if ($tty) {
            @shell_exec('stty -echo 2>/dev/null');
        }
        try {
            $line = fgets($this->stdin);
        } finally {
            if ($tty) {
                @shell_exec('stty echo 2>/dev/null');
                $this->out("\n");
            }
        }

        return rtrim(is_string($line) ? $line : '', "\r\n");
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
