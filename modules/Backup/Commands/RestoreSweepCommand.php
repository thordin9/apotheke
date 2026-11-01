<?php

declare(strict_types=1);

namespace Apotheke\Backup\Commands;

use Apotheke\Backup\RestoreUploads;
use Apotheke\Core\Exceptions\UserError;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;

/**
 * apotheke:restore-sweep: remove the chunked restore uploads (DATA_DIR/uploads/restore) idle for an hour or more.
 * The API also sweeps them on every request; this is for a cron job. Needs no database and creates no folder.
 */
final class RestoreSweepCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'apotheke:restore-sweep';

    protected $description = 'Remove chunked restore uploads idle for an hour or more.';

    protected $usage = 'apotheke:restore-sweep';

    /** @param (Closure():RestoreUploads)|null $uploads */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $uploads = null)
    {
        parent::__construct($logger, $commands);
    }

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(array $params)
    {
        if ($this->uploads === null) {
            fwrite(STDERR, "apotheke:restore-sweep is not wired (Config\\Services::commands).\n");

            return 1;
        }
        try {
            $uploads = ($this->uploads)();
            $before = $uploads->count();
            $uploads->sweep(null, false);
            $left = $uploads->count();
        } catch (UserError $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }
        fwrite(STDOUT, 'Removed ' . ($before - $left) . ' idle restore upload(s); ' . $left . " left.\n");

        return 0;
    }
}
