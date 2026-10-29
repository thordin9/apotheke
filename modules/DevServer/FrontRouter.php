<?php

declare(strict_types=1);

namespace Apotheke\DevServer;

use Apotheke\Core\Http\OriginalsGuard;

/** originals, a static file, or the application. */
final class FrontRouter
{
    public function __construct(private OriginalsGuard $guard = new OriginalsGuard())
    {
    }

    public function decide(string $docroot, string $path): string
    {
        if ($this->guard->matches($path)) {
            return 'originals';
        }
        if (StaticFile::isStatic($docroot, $path)) {
            return 'static';
        }

        return 'app';
    }
}
