<?php

declare(strict_types=1);

namespace Apotheke\Backup\Commands;

use Apotheke\Settings\Commands\SettingsCommand;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * apotheke:backup: legacy bin/backup.php, with the same output and exit codes.
 * bin/backup.php runs it when APOTHEKE_APP=ci4. Config\Services::commands()
 * builds it with the factory of its runner (constructor injection).
 */
final class BackupCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:backup';

    protected $description = 'Write a backup zip (portable JSON dump and photos).';

    protected $usage = 'apotheke:backup [file.zip]';

    /** @param (Closure(resource, resource, resource):BackupCli)|null $cli */
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
            fwrite(STDERR, "apotheke:backup is not wired (Config\\Services::commands).\n");

            return 1;
        }

        return ($this->cli)(STDIN, STDOUT, STDERR)->backup(BackupCli::args(getenv('APOTHEKE_CLI_ARGS'), SettingsCommand::rawArgs($_SERVER['argv'] ?? [], $this->name)));
    }
}
