<?php

declare(strict_types=1);

namespace Apotheke\Core\Commands;

use Apotheke\Core\Exceptions\DatabaseUnavailable;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;

/** jobs:work: the background jobs worker (JobsCli). Config\Services::commands() builds it with its runner's factory. */
final class JobsCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'jobs:work';

    protected $description = 'Run queued background jobs (imports, restores).';

    protected $usage = 'jobs:work [--once] [--max-jobs=N] [--max-seconds=N] [--sleep=MS]';

    /** @param (Closure(resource, resource):JobsCli)|null $cli */
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
            fwrite(STDERR, "jobs:work is not wired (Config\\Services::commands).\n");

            return 1;
        }
        try {
            $cli = ($this->cli)(STDOUT, STDERR);
        } catch (DatabaseUnavailable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        return $cli->work(self::args($_SERVER['argv'] ?? [], $this->name));
    }

    /**
     * The words after the command name, as typed (CodeIgniter's own parse would read --max-jobs=2 as a flag).
     *
     * @param array<array-key, mixed> $argv
     * @return list<string>
     */
    public static function args(array $argv, string $name): array
    {
        $argv = array_values(array_map(static fn ($a): string => is_scalar($a) ? (string)$a : '', $argv));
        $at = array_search($name, $argv, true);

        return $at === false ? [] : array_slice($argv, (int)$at + 1);
    }
}
