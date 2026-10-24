<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

use Closure;

/**
 * The request-level Host check (legacy request_host_ok()). A request without a
 * Host header (HTTP/1.0 tools) passes. The configured names are read through
 * the closure only for a name that is not built in, and nothing is kept: each
 * request builds its own instance (Services::hostCheck(false)).
 */
final class HostCheck
{
    /** @param Closure():list<string> $configured PUBLIC_URL's host and ALLOWED_HOSTS for this request */
    public function __construct(private readonly HostAllowList $list, private readonly Closure $configured)
    {
    }

    public function passes(string $hostHeader): bool
    {
        if ($hostHeader === '') {
            return true;
        }

        return $this->list->allowed($hostHeader, $this->configured);
    }
}
