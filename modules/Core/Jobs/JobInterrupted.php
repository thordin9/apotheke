<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

use RuntimeException;

/** Thrown by a handler that saw stopRequested(): the job goes back to the queue without using an attempt. */
final class JobInterrupted extends RuntimeException
{
}
