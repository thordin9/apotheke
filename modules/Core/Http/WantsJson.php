<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

final class WantsJson
{
    /** @param array<string,mixed> $server */
    public function check(array $server): bool
    {
        return ($server['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
            || str_contains((string)($server['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}
