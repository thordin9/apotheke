<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\HostedLimits;
use CodeIgniter\Database\BaseConnection;

/**
 * On a hosted installation, remove existing admin accounts so the first-admin claim can be used. Off without
 * APOTHEKE_HOSTED. Non-admin accounts are left as they are. The change takes the active-admins unit lock, as every
 * unit that adds or removes an active admin does.
 */
final class HostedAdminReset
{
    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly HostedLimits $hosted,
        ?UnitsOfWork $units = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    public function enabled(): bool
    {
        return $this->hosted->isHosted();
    }

    /** How many admin accounts were removed. 0 when there were none. */
    public function run(): int
    {
        if (!$this->hosted->isHosted()) {
            throw new UserError('Page not found.', 404);
        }

        return $this->units->run('users', function (UnitOfWork $uow): int {
            $n = count(Writes::rows($this->db, "SELECT id FROM users WHERE role = 'admin'"));
            if ($n > 0) {
                $uow->write("DELETE FROM users WHERE role = 'admin'", [], 'reset hosted admins', $n);
            }

            return $n;
        }, UserRepository::ACTIVE_ADMINS);
    }
}
