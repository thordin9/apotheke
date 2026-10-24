<?php

declare(strict_types=1);

namespace Apotheke\Core\Cli;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Events\Events;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * CI4's command runner, but a command listed in $factories is built by its
 * factory, so it can take its collaborators through the constructor
 * (constructor injection, no service() in commands). Config\Services::commands()
 * supplies the factories. Discovery (`spark list`) still builds every command
 * with ($logger, $commands), so a wired command keeps that signature working.
 */
final class WiredCommands extends Commands
{
    /**
     * @param array<class-string<BaseCommand>, Closure(LoggerInterface, Commands): BaseCommand> $factories
     */
    public function __construct(?LoggerInterface $logger = null, private readonly array $factories = [])
    {
        parent::__construct($logger);
    }

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(string $command, array $params)
    {
        $className = $this->commands[$command]['class'] ?? null;
        $factory = $className !== null ? ($this->factories[$className] ?? null) : null;
        if ($factory === null) {
            return parent::run($command, $params);
        }
        $instance = $factory($this->logger, $this);
        Events::trigger('pre_command');
        $exit = $instance->run($params);
        Events::trigger('post_command');

        return is_int($exit) ? $exit : 0;   // EXIT_SUCCESS
    }
}
