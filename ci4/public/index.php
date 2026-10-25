<?php

declare(strict_types=1);

/**
 * CI4 front controller. Camera originals are refused before the framework boots,
 * so a mis-set ORIGINALS_DIR still 404s that URL.
 */

use Apotheke\Core\Http\OriginalsGuard;
use Apotheke\DevServer\StaticFile;
use CodeIgniter\Boot;
use Config\Paths;

$minPhpVersion = '8.4';
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );
    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
require_once dirname(__DIR__) . '/modules/Core/Http/OriginalsGuard.php';
if ((new OriginalsGuard())->matches($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';

    exit;
}

require_once dirname(__DIR__) . '/modules/DevServer/StaticFile.php';
$script = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
if (PHP_SAPI === 'cli-server' && $script === __FILE__ && StaticFile::isStatic(__DIR__, $path)) {
    return false;
}

// Route the request path as sent: CI4 would drop a leading /index.php segment
// (SCRIPT_NAME), so /index.php/stats would answer as /stats where legacy 404s.
$_SERVER['SCRIPT_NAME'] = '';

define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

require FCPATH . '../app/Config/Paths.php';

$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
