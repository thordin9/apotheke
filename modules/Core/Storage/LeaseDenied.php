<?php

declare(strict_types=1);

namespace Apotheke\Core\Storage;

use RuntimeException;

/** The lease is held, expired under us, or the conditional write lost. */
final class LeaseDenied extends RuntimeException
{
}
