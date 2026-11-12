<?php

declare(strict_types=1);

/**
 * FrankenPHP worker entry (worker mode, opt-in with APOTHEKE_PHP_WORKER=1; edge/wrangler.jsonc sets the hosted value;
 * see docker/frankenphp/php-worker.caddy). The framework boots once per PHP thread and then serves requests in a
 * loop; between requests CodeIgniter's worker reset drops every service not in Config\WorkerMode::$persistentServices,
 * the factories, the request's event listeners, closes the session and rolls back an open transaction.
 *
 * The same per-request rules as public/index.php: a camera-original path is a 404 before the framework runs, and
 * the request path is routed as sent (SCRIPT_NAME empty). Caddy only routes requests here from the unix-socket PHP
 * server; the public server answers any *.php path other than /index.php with 404, so this file is never a URL.
 *
 * An answered error must not end the worker: Apotheke\Core\Exceptions\PlainHandler returns instead of exit() when
 * APOTHEKE_PHP_WORKER is defined (an exit would restart the worker with FrankenPHP's failure backoff).
 *
 * Each worker logs one "apotheke: PHP worker started" line when it boots, so a restart shows in the container log, and
 * it recycles itself after APOTHEKE_WORKER_MAX_REQUESTS requests (Apotheke\Core\Http\WorkerRecycle, L-1979).
 */

use Apotheke\Core\Http\OriginalsGuard;
use Apotheke\Core\Http\WorkerRecycle;
use CodeIgniter\Boot;
use CodeIgniter\Config\Factories;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\Config as DatabaseConfig;
use CodeIgniter\Events\Events;
use Config\Paths;
use Config\WorkerMode;

// Only under FrankenPHP (composer.json and public/index.php check the PHP version).
if (!function_exists('frankenphp_handle_request')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Apotheke is not available right now.';

    exit(1);
}

define('APOTHEKE_PHP_WORKER', true);
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

require_once FCPATH . '../app/Config/Paths.php';
require_once dirname(__DIR__) . '/modules/Core/Http/OriginalsGuard.php';
$paths = new Paths();
require_once $paths->systemDirectory . '/Boot.php';

$app = Boot::bootWorker($paths);
ignore_user_abort(true);
$guard = new OriginalsGuard();
/** @var WorkerMode $workerConfig */
$workerConfig = config(WorkerMode::class);
$maxRequests = WorkerRecycle::limit(getenv('APOTHEKE_WORKER_MAX_REQUESTS'));
// One line per worker boot on the container's stderr (error_log() from a worker script does not reach the log).
file_put_contents('php://stderr', 'apotheke: PHP worker started (recycles after ' . ($maxRequests === 0 ? 'no limit' : $maxRequests . ' requests') . ")\n");

$handler = static function () use ($app, $guard, $workerConfig): void {
    $path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
    if ($guard->matches($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not found';

        return;
    }
    // As public/index.php: route the path as sent (CI4 would drop a leading /index.php segment).
    $_SERVER['SCRIPT_NAME'] = '';

    DatabaseConfig::reconnectForWorkerMode();
    Services::reconnectCacheForWorkerMode();
    $app->resetForWorkerMode();
    service('superglobals')
        ->setServerArray($_SERVER)
        ->setGetArray($_GET)
        ->setPostArray($_POST)
        ->setCookieArray($_COOKIE)
        ->setFilesArray($_FILES)
        ->setRequestArray($_REQUEST);

    $level = ob_get_level();
    try {
        $app->run();
    } catch (Throwable $e) {
        Services::exceptions()->exceptionHandler($e);
    }
    // Nothing a request buffered may reach the next one: send what is left of this request's buffers, then drop them.
    while (ob_get_level() > $level) {
        ob_end_flush();
    }

    if ($workerConfig->forceGarbageCollection) {
        gc_collect_cycles();
    }
};

// After $maxRequests the loop ends and the script returns: FrankenPHP starts this worker again.
for ($served = 0; WorkerRecycle::keepServing($served, $maxRequests) && frankenphp_handle_request($handler); $served++) {
    if (Services::has('session')) {
        Services::session()->close();
    }
    DatabaseConfig::cleanupForWorkerMode();
    Factories::reset();
    Services::resetForWorkerMode($workerConfig);
    Events::cleanupForWorkerMode($workerConfig->resetEventListeners);
}
