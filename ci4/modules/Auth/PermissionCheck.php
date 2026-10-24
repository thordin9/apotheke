<?php

declare(strict_types=1);

namespace Apotheke\Auth;

/**
 * Legacy user_can() and the refusal public/index.php gives when it fails.
 * allows() is user_can() for a resolved user (CurrentUser::resolve()).
 * refusal() is null when the request may go on; otherwise SIGN_IN (nobody,
 * or the anonymous guest: sign in first) or the 403 message for a signed-in
 * user, the anonymous admin included, whose role lacks the permission.
 * UNMAPPED (a route without a recorded permission) is allowed to nobody.
 */
final class PermissionCheck
{
    public const SIGN_IN = 'sign-in';

    public function __construct(private readonly RoleRepository $roles)
    {
    }

    /** @param array{role:string,guest:bool,anon:bool}|null $user */
    public function allows(?array $user, string $need): bool
    {
        if ($need === Permissions::PUBLIC) {
            return true;
        }
        if ($user === null || $need === Permissions::UNMAPPED) {
            return false;
        }
        if ($need === Permissions::SELF) {
            return !$user['guest'];
        }

        return $this->roles->has($user['role'], $need);
    }

    /** @param array{role:string,guest:bool,anon:bool}|null $user */
    public function refusal(?array $user, string $need): ?string
    {
        if ($this->allows($user, $need)) {
            return null;
        }
        if ($user === null || $user['anon']) {
            return self::SIGN_IN;
        }

        return 'Your role (' . $this->roles->name($user['role']) . ') does not allow this'
            . (isset(Permissions::ALL[$need]) ? ' – it needs the “' . Permissions::ALL[$need][0] . '” permission' : '')
            . '. Ask an admin.';
    }
}
