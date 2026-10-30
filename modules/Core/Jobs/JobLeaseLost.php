<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

use RuntimeException;

/** Another worker took the job over (this worker's lease ran out): stop and leave the row alone. */
final class JobLeaseLost extends RuntimeException
{
}
