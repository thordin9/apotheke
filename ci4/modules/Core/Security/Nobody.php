<?php

declare(strict_types=1);

namespace Apotheke\Core\Security;

/** No actor known: never admin. The default, so a missing actor fails closed. */
final class Nobody implements ActorGate
{
    public function isAdmin(): bool
    {
        return false;
    }
}
