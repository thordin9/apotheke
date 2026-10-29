<?php

declare(strict_types=1);

namespace Apotheke\Core\Security;

/** spark and bin/*.php: whoever runs the command line already controls the install. */
final class CommandLine implements ActorGate
{
    public function isAdmin(): bool
    {
        return true;
    }
}
