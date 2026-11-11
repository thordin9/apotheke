<?php

declare(strict_types=1);

namespace Apotheke\Core\Database;

/**
 * A batch executor that must know when a unit of work is open on it (the D1 driver): so a unit cannot open inside
 * the connection's own transaction, and the connection refuses writes that bypass the open unit.
 */
interface UnitScope
{
    /** A unit opens on this executor; throws when it cannot be atomic here. */
    public function enterUnit(): void;

    public function leaveUnit(): void;
}
