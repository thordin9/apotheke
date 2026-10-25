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
 * bin/settings.php runs it when APOTHEKE_APP=ci4. Config\Services::commands()
 * builds it with the factory of its collaborators (constructor injection).
 */
final class AccountsCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:accounts';

    protected $description = 'Accounts, roles and sign-in on or off (recovery, scripting).';

    protected $usage = 'apotheke:accounts [users | user add|password|role|approve|rename|delete|signout NAME … | roles [reset] | auth on|off | password [PASSWORD|--off]]';

    /** @param (Closure(resource, resource, resource):AccountsCli)|null $cli */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $cli = null)
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
        try {
            $cli = ($this->cli)(STDIN, STDOUT, STDERR);
        } catch (RuntimeException $e) {
            // The runner's connect failure is already the sanitized line.
            fwrite(STDERR, (str_starts_with($e->getMessage(), 'Could not connect to the database') ? $e->getMessage() : 'Could not open the database.') . "\n");

            return 1;
        }

        return $cli->run(SettingsCommand::rawArgs($_SERVER['argv'] ?? [], $this->name));
    }
}
