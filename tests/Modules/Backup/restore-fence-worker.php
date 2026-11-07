<?php

declare(strict_types=1);

/**
 * The other client for RestoreFenceTest. argv: mode, client env JSON, and the
 * mode's own arguments. Prints one status word and exits 0, or the error on stderr.
 */

use Apotheke\Backup\RestoreFence;
use Apotheke\Backup\RestoreMutation;
use Apotheke\Core\Storage\R2Bridge;
use Apotheke\Core\Storage\R2Storage;
use Tests\Support\TestDatabase;

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
define('HOMEPATH', $root . DIRECTORY_SEPARATOR);
define('CONFIGPATH', $root . '/app/Config/');
define('PUBLICPATH', $root . '/public/');
require $root . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
require $root . '/tests/Modules/_support/TestDatabase.php';

/** @var array<string,string> $env */
$env = json_decode($argv[2] ?? '', true, 8, JSON_THROW_ON_ERROR);
$database = TestDatabase::fromEnv($env, false);
$mode = $argv[1] ?? '';
try {
    if ($mode === 'replace') {
        $storage = new R2Storage(new R2Bridge($argv[3]));
        RestoreMutation::replace($database->db, $argv[6], $storage, $argv[4], $argv[5]);
        fwrite(STDOUT, "replaced\n");
        fflush(STDOUT);
        exit(0);
    }
    if ($mode === 'takeover') {
        fwrite(STDOUT, "started\n");
        fflush(STDOUT);
        RestoreFence::claim($database->db, $argv[3]);
        fwrite(STDOUT, "claimed\n");
        fflush(STDOUT);
        $storage = new R2Storage(new R2Bridge($argv[4]));
        RestoreMutation::replace($database->db, $argv[3], $storage, $argv[5], $argv[6]);
        fwrite(STDOUT, "wrote\n");
        fflush(STDOUT);
        exit(0);
    }
    fwrite(STDERR, "unknown mode\n");
    exit(2);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}
