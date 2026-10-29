<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/** Camera originals are never a URL, in any spelling, including a nested path. */
final class OriginalsGuard
{
    public function matches(string $path): bool
    {
        return preg_match('#(?:^|/)originals(?:/|$)#i', $path) === 1;
    }
}
