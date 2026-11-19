<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Database\UnitOfWork;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\HostedLimits;
use Apotheke\Core\Libraries\Clock;
use CodeIgniter\Database\BaseConnection;

/**
 * The first-admin claim of a hosted installation (operator console C1): a one-time link that lets the owner pick a
 * user name and password and become `admin`, instead of a shared APP_PASSWORD. Only the token's SHA-256 and its
 * expiry are stored, as one row of the installation's settings table outside the Control Panel's keys (never
 * exported or backed up). A new claim replaces the old one. It is used once: the row's delete is part of the unit
 * that creates the account. It only works while the installation has no active admin, checked in that same unit;
 * otherwise it is refused and removed. Off without APOTHEKE_HOSTED.
 */
final class FirstAdminClaim
{
    /** The settings row (no Control Panel key has this name). */
    public const SETTING = 'hosted_first_admin_claim';

    /** create() refuses with this (409) while an active admin exists. */
    public const ADMIN_EXISTS = 'An active admin already exists, so no claim was made.';

    private const NO_LONGER_VALID = 'This claim link is no longer valid.';

    private const NO_ACTIVE_ADMIN = "NOT EXISTS (SELECT 1 FROM users WHERE role = 'admin' AND status = 'active')";

    private readonly UnitsOfWork $units;

    /**
     * @param BaseConnection<object|resource, object|resource> $db
     * @param int $hours how long a new claim stays valid
     * @param UnitsOfWork|null $units the request's (Services::unitsOfWork()); its own on $db when null
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly UserRepository $users,
        private readonly Accounts $accounts,
        private readonly Clock $clock,
        private readonly HostedLimits $hosted,
        private readonly int $hours,
        ?UnitsOfWork $units = null,
    ) {
        $this->units = UnitsOfWork::forSite($db, $units);
    }

    public function enabled(): bool
    {
        return $this->hosted->isHosted();
    }

    /**
     * A new claim: the token to put in the link (shown once, never stored) and when it expires (UTC). Refused (409,
     * ADMIN_EXISTS) while an active admin exists, checked in a unit with the active-admins key, as claim() does.
     *
     * @return array{token:string,expires_at:string}
     */
    public function create(): array
    {
        $this->refuseOff();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = $this->clock->unix() + $this->hours * 3600;
        $value = (string)json_encode(['sha256' => hash('sha256', $token), 'expires' => $expires]);
        $now = $this->clock->now();
        // The unit and key of claim(): while an active admin exists no claim is made, and nothing is changed.
        $this->units->run('users', static function (UnitOfWork $uow) use ($value, $now): void {
            $uow->assert(self::NO_ACTIVE_ADMIN, [], 'no admin yet', static fn (): UserError => new UserError(self::ADMIN_EXISTS, 409));
            $uow->write('DELETE FROM settings WHERE name = ?', [self::SETTING], 'replace claim');
            $uow->write('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)', [self::SETTING, $value, $now], 'store claim');
        }, UserRepository::ACTIVE_ADMINS);

        return ['token' => $token, 'expires_at' => gmdate('Y-m-d H:i:s', $expires) . ' UTC'];
    }

    /**
     * Use the claim: a new active `admin` named $name with $password. The claim is deleted in the same unit as the
     * insert, so a second use (also at the same time) is refused and creates nothing. The unit also requires that
     * no active admin exists; it takes the active-admins key, as every unit that adds an active admin does. When an
     * admin exists the claim is refused (410) and its row removed, whatever the token: the same answer and the
     * same steps for any token then (dropAfterAdmin()). Returns the new account.
     *
     * @return array{id:int,username:string,role:string,status:string,password_hash:string,session_epoch:string}
     */
    public function claim(#[\SensitiveParameter] string $token, string $name, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirm): array
    {
        $this->refuseOff();
        $stored = $this->stored();
        $claim = $stored === null ? null : json_decode($stored, true);
        $matches = is_array($claim) && is_string($claim['sha256'] ?? null) && is_int($claim['expires'] ?? null)
            && hash_equals($claim['sha256'], hash('sha256', $token));
        if ($this->adminExists()) {
            $this->dropAfterAdmin();
        }
        if (!$matches) {
            throw new UserError('This claim link is not valid. Ask for a new one.', 403);
        }
        if ($claim['expires'] <= $this->clock->unix()) {
            throw new UserError('This claim link has expired. Ask for a new one.', 410);
        }
        $name = $this->accounts->nameValid($name);
        $hash = password_hash($this->accounts->passwordValid($password, $confirm), PASSWORD_DEFAULT);
        $now = $this->clock->now();
        $adminExists = false;
        try {
            $ref = $this->units->run('users', function (UnitOfWork $uow) use ($stored, $name, $hash, $now, &$adminExists): \Apotheke\Core\Database\Ref {
                $uow->write('DELETE FROM settings WHERE name = ? AND value = ?', [self::SETTING, $stored], 'use claim', 1,
                    static fn (): UserError => new UserError('This claim link has already been used.', 410));
                $uow->assert(self::NO_ACTIVE_ADMIN, [], 'no admin yet',
                    static function () use (&$adminExists): UserError {
                        $adminExists = true;

                        return new UserError(self::NO_LONGER_VALID, 410);
                    });

                return $this->users->createIn($uow, $name, $hash, 'admin', 'active', $now);
            }, [...$this->hosted->lockKeys('users'), UserRepository::ACTIVE_ADMINS]);
        } catch (UserError $e) {
            if ($adminExists) {
                $this->units->run('settings', static function (UnitOfWork $uow) use ($stored): void {
                    $uow->write('DELETE FROM settings WHERE name = ? AND value = ?', [self::SETTING, $stored], 'drop claim');
                }, self::SETTING);
            }
            throw $e;
        }

        return $this->users->find($ref->id()) ?? throw new \RuntimeException('The new account could not be read back.');
    }

    /** Whether an active admin exists (read before the claim's unit, which checks it again). */
    private function adminExists(): bool
    {
        return Writes::rows($this->db, "SELECT 1 AS one FROM users WHERE role = 'admin' AND status = 'active' LIMIT 1") !== [];
    }

    /**
     * An active admin exists: remove the claim row, whatever it holds, and refuse with 410. A right and a wrong
     * token take this same path.
     */
    private function dropAfterAdmin(): never
    {
        $this->units->run('settings', static function (UnitOfWork $uow): void {
            $uow->write('DELETE FROM settings WHERE name = ?', [self::SETTING], 'drop claim');
        }, self::SETTING);

        throw new UserError(self::NO_LONGER_VALID, 410);
    }

    /** The stored claim row, or null when there is none. */
    private function stored(): ?string
    {
        $v = Writes::rows($this->db, 'SELECT value FROM settings WHERE name = ?', [self::SETTING])[0]['value'] ?? null;

        return is_string($v) ? $v : null;
    }

    private function refuseOff(): void
    {
        if (!$this->hosted->isHosted()) {
            throw new UserError('Page not found.', 404);
        }
    }
}
