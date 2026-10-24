<?php

declare(strict_types=1);

namespace Apotheke\Auth;

use Apotheke\Core\Security\ActorGate;
use Apotheke\Settings\SettingsService;
use Closure;

/**
 * The request's user (legacy current_user()): the session's account when it is
 * active and its epoch still matches; an anonymous admin when sign-in is off;
 * the guest while guest browsing is allowed; otherwise nobody. Nothing is kept
 * on the instance: every call reads the session and the settings again.
 */
final class CurrentUser implements ActorGate
{
    /**
     * @param Closure():SettingsService $settings this request's settings
     * @param Closure():array<array-key,mixed> $session this request's $_SESSION
     */
    public function __construct(
        private readonly Closure $settings,
        private readonly UserRepository $users,
        private readonly Closure $session,
        private readonly SessionEpoch $epoch = new SessionEpoch(),
    ) {
    }

    /** @return array{id:?int,username:string,role:string,status:string,guest:bool,anon:bool}|null */
    public function resolve(): ?array
    {
        $settings = ($this->settings)();
        if (!$settings->signInEnabled()) {
            return ['id' => null, 'username' => '', 'role' => 'admin', 'status' => 'active', 'guest' => true, 'anon' => false];
        }
        $session = ($this->session)();
        $uid = is_scalar($session['uid'] ?? null) ? (int)$session['uid'] : 0;
        $user = $uid > 0 ? $this->users->find($uid) : null;
        $held = $session['auth_epoch'] ?? '';
        if ($user !== null && $user['status'] === 'active' && is_string($held) && hash_equals($this->epoch->of($user), $held)) {
            return ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role'], 'status' => 'active', 'guest' => false, 'anon' => false];
        }
        if (!$settings->get('auth_guest_disabled')) {
            return ['id' => null, 'username' => '', 'role' => 'guest', 'status' => 'active', 'guest' => true, 'anon' => true];
        }

        return null;
    }

    /** Legacy actor_is_admin() for a web request: the anonymous admin (sign-in off) or an admin account. */
    public function isAdmin(): bool
    {
        return ($this->resolve()['role'] ?? '') === 'admin';
    }
}
