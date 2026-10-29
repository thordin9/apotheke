<?php

declare(strict_types=1);

/**
 * Router for `php -S 127.0.0.1:9091 -t public public/router.php`.
 * Returning false lets the built-in server send a real file under public/.
 */

use Apotheke\Core\Http\OriginalsGuard;
use Apotheke\DevServer\StaticFile;

$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
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
