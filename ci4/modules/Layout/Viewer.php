<?php

declare(strict_types=1);

namespace Apotheke\Layout;

use Closure;
use Throwable;

/**
 * The request's user as the layout needs it: who is signed in and what their
 * role may do. Built by Config\Services from the Auth module, so Layout does
 * not depend on Auth. Every call reads through the closures again.
 */
final class Viewer
{
    /**
     * @param Closure(): (array{username:string,role:string,guest:bool,anon:bool}|null) $user
     * @param Closure(array{username:string,role:string,guest:bool,anon:bool}|null, string): bool $can legacy user_can($perm, $u)
     * @param Closure(string): string $roleName legacy role_name()
     * @param list<string> $permissions the permission keys, in legacy PERMISSIONS order
     */
    public function __construct(
        private readonly Closure $user,
        private readonly Closure $can,
        private readonly Closure $roleName,
        private readonly array $permissions,
    ) {
    }

    /** @return array{username:string,role:string,guest:bool,anon:bool}|null */
    public function user(): ?array
    {
        return ($this->user)();
    }

    public function can(string $permission): bool
    {
        return ($this->can)($this->user(), $permission);
    }

    public function roleName(string $role): string
    {
        return ($this->roleName)($role);
    }

    /**
     * Legacy body_role_class(): "role-<key>", "no-<permission>" for every
     * permission the role lacks, and "is-guest" for the anonymous guest. CSS
     * hides the controls they may not use; the server checks every request
     * anyway. '' for nobody, an admin, or when the user cannot be read.
     */
    public function bodyClass(): string
    {
        try {
            $user = $this->user();
        } catch (Throwable) {
            return '';
        }
        if ($user === null || $user['role'] === 'admin') {
            return '';
        }
        $classes = ['role-' . preg_replace('/[^a-z0-9-]/', '', $user['role'])];
        foreach ($this->permissions as $permission) {
            if (!($this->can)($user, $permission)) {
                $classes[] = 'no-' . $permission;
            }
        }
        if ($user['anon']) {
            $classes[] = 'is-guest';
        }

        return implode(' ', $classes);
    }
}
