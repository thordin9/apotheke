<?php

declare(strict_types=1);

namespace Apotheke\Core\Database\D1;

use LogicException;

/**
 * A step the D1 driver refuses because it would split one atomic step into two batches (a unit inside a transaction,
 * a write beside the open unit, a second unit). Always thrown, with DBDebug on or off: a caller that ignores a failed
 * write must not commit the rest of its unit without it.
 */
final class UnitRuleViolation extends LogicException
{
}
