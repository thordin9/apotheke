<?php

declare(strict_types=1);

namespace Apotheke\Catalog\Commands;

use Apotheke\Catalog\DemoItems;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * apotheke:seed [--demo]: legacy bin/seed.php, with the same output. Starter
 * locations and categories (one default each, existing names skipped), and
 * with --demo a handful of demo items with generated photos. Idempotent.
 * bin/seed.php runs it when APOTHEKE_APP=ci4. Config\Services::commands()
 * builds it with its factories (constructor injection).
 */
final class SeedCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:seed';

    protected $description = 'Add the starter locations and categories, and with --demo a few demo items.';

    protected $usage = 'apotheke:seed [--demo]';

    /**
     * @param (Closure():int)|null $starter the starter rows on the migrated database; returns the rows created
     * @param (Closure():DemoItems)|null $demo
     */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $starter = null, private readonly ?Closure $demo = null)
    {
        parent::__construct($logger, $commands);
    }

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(array $params)
    {
        if ($this->starter === null || $this->demo === null) {
            fwrite(STDERR, "apotheke:seed is not wired (Config\\Services::commands).\n");

            return 1;
        }
        try {
            fwrite(STDOUT, 'Starter locations/categories created: ' . ($this->starter)() . "\n");
            if (in_array('--demo', $_SERVER['argv'] ?? [], true)) {
                $demo = ($this->demo)();
                ($this->starter)();   // legacy seed_demo() seeds the starter rows again first
                fwrite(STDOUT, 'Demo items created: ' . $demo->create() . "\n");
            }
        } catch (RuntimeException $e) {
            // The runner's connect failure and a short APP_PASSWORD are already safe lines.
            $message = $e->getMessage();
            fwrite(STDERR, (str_starts_with($message, 'Could not connect to the database') || str_starts_with($message, 'APP_PASSWORD ') ? $message : 'Seeding failed.') . "\n");

            return 1;
        }

        return 0;
    }
}
