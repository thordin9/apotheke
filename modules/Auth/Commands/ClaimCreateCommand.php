<?php

declare(strict_types=1);

namespace Apotheke\Auth\Commands;

use Apotheke\Auth\FirstAdminClaim;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * accounts:claim-create: a new first-admin claim on a hosted installation (operator console C1). Prints the token
 * once on stdout (the link is https://<host>/claim#<token>) and its expiry on stderr. Only with APOTHEKE_HOSTED.
 * Config\Services::commands() builds it with its collaborator (constructor injection).
 */
final class ClaimCreateCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'accounts:claim-create';

    protected $description = 'Hosted only: a one-time first-admin claim (prints its token once).';

    protected $usage = 'accounts:claim-create';

    /** @param (Closure():FirstAdminClaim)|null $claim */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $claim = null)
    {
        parent::__construct($logger, $commands);
    }

    /**
     * @param array<array-key, string|null> $params
     * @return int
     */
    public function run(array $params)
    {
        return $this->runWith(STDOUT, STDERR);
    }

    /**
     * @param resource $out
     * @param resource $err
     */
    public function runWith(mixed $out, mixed $err): int
    {
        if ($this->claim === null) {
            fwrite($err, "accounts:claim-create is not wired (Config\\Services::commands).\n");

            return 1;
        }
        try {
            $claim = ($this->claim)();
            if (!$claim->enabled()) {
                fwrite($err, "accounts:claim-create is only for a hosted installation (APOTHEKE_HOSTED).\n");

                return 1;
            }
            $made = $claim->create();
        } catch (Throwable) {
            fwrite($err, "The claim could not be created.\n");

            return 1;
        }
        fwrite($out, $made['token'] . "\n");
        fwrite($err, 'Valid once, until ' . $made['expires_at'] . ". Any earlier claim no longer works.\n");

        return 0;
    }
}
