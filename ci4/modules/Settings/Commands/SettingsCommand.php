<?php

declare(strict_types=1);

namespace Apotheke\Settings\Commands;

use Apotheke\Settings\SettingsService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * apotheke:settings list|get|set|reset|export|import. Output and exit codes
 * match legacy bin/settings.php. Config\Services::commands() builds it with
 * the settings factory (constructor injection).
 */
final class SettingsCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:settings';

    protected $description = 'List, get, set, reset, export or import Control Panel settings.';

    protected $usage = 'apotheke:settings [list | get KEY | set KEY VALUE | reset SECTION | export | import [FILE]]';

    /** @param (Closure():SettingsService)|null $settings */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $settings = null)
    {
        parent::__construct($logger, $commands);
    }

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(array $params)
    {
        if ($this->settings === null) {
            fwrite(STDERR, "apotheke:settings is not wired (Config\\Services::commands).\n");

            return 1;
        }

        return (new SettingsCli($this->settings, STDOUT, STDERR))->run(self::rawArgs($_SERVER['argv'] ?? [], $this->name));
    }

    /**
     * The arguments after the command name, exactly as typed. CI4's own
     * parser treats anything starting with "-" as an option, which would
     * drop a value such as "-1"; the legacy CLI takes argv as is. The
     * --no-header flag that bin/settings.php passes to spark is removed.
     *
     * @param array<array-key, mixed> $argv
     * @return list<string>
     */
    public static function rawArgs(array $argv, string $name): array
    {
        $argv = array_values(array_map(static fn ($a): string => is_scalar($a) ? (string)$a : '', $argv));
        $at = array_search($name, $argv, true);
        if ($at === false) {
            return [];
        }
        $args = array_slice($argv, (int)$at + 1);
        if (($args[0] ?? null) === '--no-header') {
            array_shift($args);
        }

        return $args;
    }
}
