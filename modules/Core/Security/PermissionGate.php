<?php

declare(strict_types=1);

namespace Apotheke\Core\Security;

use Apotheke\Core\Exceptions\UserError;

/**
 * A permission check inside an action (legacy require_perm()), for what the
 * route's own permission does not settle, such as moving an item while
 * editing it. Auth implements it (built in Config\Services).
 */
interface PermissionGate
{
    /** @throws UserError 403 with legacy's message when the current role lacks $permission */
    public function require(string $permission): void;
}
