<?php

declare(strict_types=1);

namespace Apotheke\Auth\Commands;

use Apotheke\Auth\HostedAdminReset;
use Apotheke\Core\Exceptions\UserError;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\Commands;
use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * accounts:hosted-reset-admins: remove existing admin accounts on a hosted installation so the first-admin claim
 * can be used. Only with APOTHEKE_HOSTED. Non-admin accounts are left as they are.
 * Config\Services::commands() builds it with its collaborator (constructor injection).
 */
final class HostedAdminResetCommand extends BaseCommand
{
    protected $group = 'Apotheke';

    protected $name = 'accounts:hosted-reset-admins';

    protected $description = 'Hosted only: remove existing admin accounts so the first-admin claim can be used.';

    protected $usage = 'accounts:hosted-reset-admins';

    /** @param (Closure():HostedAdminReset)|null $reset */
    public function __construct(LoggerInterface $logger, Commands $commands, private readonly ?Closure $reset = null)
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
        if ($this->reset === null) {
            fwrite($err, "accounts:hosted-reset-admins is not wired (Config\\Services::commands).\n");

            return 1;
        }
        try {
            $reset = ($this->reset)();
            if (!$reset->enabled()) {
                fwrite($err, "accounts:hosted-reset-admins is only for a hosted installation (APOTHEKE_HOSTED).\n");

                return 1;
            }
            $reset->run();
        } catch (UserError) {
            fwrite($err, "The admin accounts could not be reset.\n");

            return 1;
        } catch (Throwable) {
            fwrite($err, "The admin accounts could not be reset.\n");

            return 1;
        }
        fwrite($out, "Admin accounts were reset. The first-admin claim can be created.\n");

        return 0;
    }
}
