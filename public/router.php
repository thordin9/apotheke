<?php

declare(strict_types=1);

/**
 * Router for `php -S 127.0.0.1:9091 -t public public/router.php`.
 * Returning false lets the built-in server send a real file under public/.
 */

use Apotheke\Core\Http\OriginalsGuard;
use Apotheke\Core\Http\RequestPath;
use Apotheke\DevServer\StaticFile;

require_once dirname(__DIR__) . '/modules/Core/Http/RequestPath.php';
$path = RequestPath::of(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/');
require_once dirname(__DIR__) . '/modules/Core/Http/OriginalsGuard.php';
if ((new OriginalsGuard())->matches($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';

    return true;
}

require_once dirname(__DIR__) . '/modules/DevServer/StaticFile.php';
if (StaticFile::isStatic(__DIR__, $path)) {
    return false;
}

require __DIR__ . '/index.php';
