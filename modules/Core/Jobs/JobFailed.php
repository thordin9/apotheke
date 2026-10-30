<?php

declare(strict_types=1);

namespace Apotheke\Core\Jobs;

use RuntimeException;

/** A job failure that a retry cannot fix. Its message is stored and shown to the client, so it must be user text. */
final class JobFailed extends RuntimeException
{
}
