<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseConnection;
use Throwable;

/**
 * Per-installation disabled accounts on a hosted install (hosted_disabled_users). Off without APOTHEKE_HOSTED:
 * isDisabled() is then always false, and disable/enable are not used. A missing table is not treated as disabled.
 */
final class HostedDisabledUsers
{
    public const MESSAGE = 'This account cannot sign in.';

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly UnitsOfWork $units,
        private readonly Clock $clock,
        private readonly bool $hosted,
    ) {
    }

    public function isDisabled(int $userId): bool
    {
        if (!$this->hosted || $userId <= 0) {
            return false;
        }
        try {
            return Writes::rows($this->db, 'SELECT 1 AS one FROM hosted_disabled_users WHERE user_id = ?', [$userId]) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    public function disable(int $userId): void
    {
        $this->units->run('users', function (UnitOfWork $uow) use ($userId): void {
            $user = Writes::rows($this->db, 'SELECT id, role, status FROM users WHERE id = ?', [$userId])[0] ?? null;
            if ($user === null) {
                throw new UserError('That user was not found.', 404);
            }
            if (Writes::rows($this->db, 'SELECT 1 AS one FROM hosted_disabled_users WHERE user_id = ?', [$userId]) === []) {
                if (($user['role'] ?? '') === 'admin' && ($user['status'] ?? '') === 'active') {
                    $uow->assert(
                        "EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?"
                        . ' AND id NOT IN (SELECT user_id FROM hosted_disabled_users))',
                        [$userId],
                        'last active admin',
                        static fn (): UserError => new UserError('That change would leave the install without an admin.', 409),
                    );
                }
                $uow->write(
                    'INSERT INTO hosted_disabled_users (user_id, disabled_at) VALUES (?, ?)',
                    [$userId, $this->clock->now()],
                    'disable hosted user',
                    1,
                );
            }
            $uow->write(
                'DELETE FROM sessions WHERE ' . SessionUidMatch::condition('data', '?'),
                SessionUidMatch::bindings($userId),
                'disable hosted user sessions',
            );
        }, UserRepository::ACTIVE_ADMINS);
    }

    public function enable(int $userId): void
    {
        $this->units->run('users', function (UnitOfWork $uow) use ($userId): void {
            if (Writes::rows($this->db, 'SELECT 1 AS one FROM users WHERE id = ?', [$userId]) === []) {
                throw new UserError('That user was not found.', 404);
            }
            $uow->write('DELETE FROM hosted_disabled_users WHERE user_id = ?', [$userId], 'enable hosted user');
        }, UserRepository::ACTIVE_ADMINS);
    }
}
