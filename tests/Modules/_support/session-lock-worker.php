<?php

declare(strict_types=1);

/**
 * The other client for DatabaseSessionHandlerTest races. argv: mode, client
 * env JSON, session id, payload, hold milliseconds. Prints status words only;
 * never the session id or stored data.
 */

use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Http\DatabaseSessionHandler;
use Tests\Support\TestDatabase;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
define('HOMEPATH', $root . DIRECTORY_SEPARATOR);
define('CONFIGPATH', $root . '/app/Config/');
define('PUBLICPATH', $root . '/public/');
require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
require $root . '/tests/Modules/_support/TestDatabase.php';

$mode = $argv[1] ?? '';
/** @var array<string,string> $env */
$env = json_decode($argv[2] ?? '', true, 8, JSON_THROW_ON_ERROR);
$id = $argv[3] ?? '';
$payload = $argv[4] ?? '';
$holdMs = (int)($argv[5] ?? 200);
$database = TestDatabase::fromEnv($env, false);
$handler = new DatabaseSessionHandler(new LocalBatchExecutor($database->db), 3600);
try {
    if ($mode === 'hold') {
        $read = $handler->read($id);
        if ($read === false) {
            fwrite(STDERR, "hold-read-failed\n");
            exit(1);
        }
        fwrite(STDOUT, "held\n");
        fflush(STDOUT);
        usleep($holdMs * 1000);
        if (!$handler->write($id, $payload)) {
            fwrite(STDERR, "hold-write-failed\n");
            exit(1);
        }
        $handler->close();
        fwrite(STDOUT, "wrote\n");
        exit(0);
    }
    if ($mode === 'follow') {
        fwrite(STDOUT, "started\n");
        fflush(STDOUT);
        $read = $handler->read($id);
        if ($read === false) {
            fwrite(STDERR, "follow-read-failed\n");
            exit(1);
        }
        $tag = $read === 'first' ? 'saw-first' : ($read === 'second' ? 'saw-second' : 'saw-other');
        fwrite(STDOUT, $tag . "\n");
        fflush(STDOUT);
        if (!$handler->write($id, $payload)) {
            fwrite(STDERR, "follow-write-failed\n");
            exit(1);
        }
        $handler->close();
        fwrite(STDOUT, "wrote\n");
        exit(0);
    }
    fwrite(STDERR, "unknown mode\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, 'failed: ' . $e->getMessage() . "\n");
    exit(1);
}
