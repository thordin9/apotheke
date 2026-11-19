<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\QueuedUnitOfWork;
use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\HostedLimits;
use Closure;
use CodeIgniter\Database\BaseConnection;

/**
 * On a hosted installation, remove existing admin accounts, their session records, and any first-admin claim so the
 * first-admin claim can be used. Off without APOTHEKE_HOSTED. Non-admin accounts and their sessions are left as they
 * are. The change takes the active-admins unit lock, as every unit that adds or removes an active admin does.
 */
final class HostedAdminReset
{
    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     * @param (Closure(string):void)|null $observe test hook: 'before' immediately before the unit, 'in-unit' at the
     *     start of the unit (after its lock, same connection), 'in-unit-end' after the unit's writes (still in the
     *     unit), and 'after' immediately after
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly HostedLimits $hosted,
        ?UnitsOfWork $units = null,
        private readonly ?Closure $observe = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    public function enabled(): bool
    {
        return $this->hosted->isHosted();
    }

    /** Remove every admin account, their session records and any first-admin claim, or do nothing when there is none. */
    public function run(): void
    {
        if (!$this->hosted->isHosted()) {
            throw new UserError('Page not found.', 404);
        }

        if ($this->observe !== null) {
            ($this->observe)('before');
        }
        $this->units->run('users', function (UnitOfWork $uow): void {
            if ($this->observe !== null) {
                ($this->observe)('in-unit');
            }
            // Session rows store the account as uid|i:<id>; in the payload. Match that exact field so a neighbouring
            // id (1 vs 11) is not treated as the same account. Sessions are deleted first, while the admin rows remain.
            $uow->write(
                "DELETE FROM sessions WHERE EXISTS ("
                . "SELECT 1 FROM users WHERE role = 'admin'"
                . " AND sessions.data LIKE '%uid|i:' || CAST(id AS TEXT) || ';%')",
                [],
                'reset hosted admin sessions',
            );
            $uow->write('DELETE FROM settings WHERE name = ?', [FirstAdminClaim::SETTING], 'reset hosted claim');
            $uow->write("DELETE FROM users WHERE role = 'admin'", [], 'reset hosted admins');
            if ($this->observe !== null && $uow instanceof QueuedUnitOfWork) {
                $observe = $this->observe;
                $uow->afterWrites(static function () use ($observe): void {
                    $observe('in-unit-end');
                });
            }
        }, UserRepository::ACTIVE_ADMINS);
        if ($this->observe !== null) {
            ($this->observe)('after');
        }
    }
}
