<?php

declare(strict_types=1);

namespace Apotheke\Backup\Commands;

use Apotheke\Core\Exceptions\DatabaseUnavailable;
use Apotheke\Settings\Commands\SettingsCommand;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * apotheke:restore: legacy bin/restore.php, with the same output and exit codes.
 * bin/restore.php runs it. Config\Services::commands()
 * builds it with the factory of its runner (constructor injection).
 */
final class RestoreCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:restore';

    protected $description = 'Restore a backup zip. Replaces all catalog data.';

    protected $usage = 'apotheke:restore backup.zip [--dry-run] [--yes]';

    /**
     * @param (Closure(resource, resource, resource):BackupCli)|null $cli
     * @param (Closure():string)|null $dataDir DATA_DIR: started as root, the command runs as its owner (DataDirOwner)
     */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $cli = null, private readonly ?Closure $dataDir = null)
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
            fwrite(STDERR, "apotheke:restore is not wired (Config\\Services::commands).\n");

            return 1;
        }
        if ($this->dataDir !== null && !DataDirOwner::become(($this->dataDir)(), STDERR)) {   // before the services are built
            return 1;
        }

        try {
            $cli = ($this->cli)(STDIN, STDOUT, STDERR);   // builds the services: connects to the database
        } catch (DatabaseUnavailable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");   // the allowlisted line and exit 1, as legacy db()

            return 1;
        }

        return $cli->restore(BackupCli::args(getenv('APOTHEKE_CLI_ARGS'), SettingsCommand::rawArgs($_SERVER['argv'] ?? [], $this->name)));
    }
}
