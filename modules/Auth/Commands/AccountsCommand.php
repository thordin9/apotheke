<?php

declare(strict_types=1);

namespace Apotheke\Auth\Commands;

use Apotheke\Settings\Commands\SettingsCommand;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * apotheke:accounts users|user|roles|auth|password: the account and sign-in
 * forms of legacy bin/settings.php, with the same output and exit codes.
 * `auth status` runs AuthStatus, which never boots the CI4 database.
 * bin/settings.php runs it. Config\Services::commands()
 * builds it with the factory of its collaborators (constructor injection).
 */
final class AccountsCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:accounts';

    protected $description = 'Accounts, roles and sign-in on or off (recovery, scripting).';

    protected $usage = 'apotheke:accounts [users | user add|password|role|approve|rename|delete|signout NAME … | roles [reset] | auth on|off|status | password [PASSWORD|--off]]';

    /**
     * @param (Closure(resource, resource, resource):AccountsCli)|null $cli
     * @param (Closure(resource, resource):AuthStatus)|null $status `auth status`, built without the CI4
     *        database (booting it migrates)
     */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $cli = null, private readonly ?Closure $status = null)
    {
        parent::__construct($logger, $commands);
    }

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(array $params)
    {
        if ($this->cli === null) {
            fwrite(STDERR, "apotheke:accounts is not wired (Config\\Services::commands).\n");

            return 1;
        }
        $args = SettingsCommand::rawArgs($_SERVER['argv'] ?? [], $this->name);
        if (($args[0] ?? '') === 'auth' && ($args[1] ?? '') === 'status' && $this->status !== null) {
            return ($this->status)(STDOUT, STDERR)->run();
        }
        try {
            $cli = ($this->cli)(STDIN, STDOUT, STDERR);
        } catch (RuntimeException $e) {
            // The runner's connect failure is already the sanitized line; so is a short APP_PASSWORD
            // refused by the first migration (legacy's message, never the value).
            $message = $e->getMessage();
            fwrite(STDERR, (str_starts_with($message, 'Could not connect to the database') || str_starts_with($message, 'APP_PASSWORD ') ? $message : 'Could not open the database.') . "\n");

            return 1;
        }

        return $cli->run($args);
    }
}
