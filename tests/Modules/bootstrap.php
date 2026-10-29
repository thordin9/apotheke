<?php

declare(strict_types=1);

/*
 * Bootstrap for the CI4 module tests: an isolated data dir and no config.php,
 * then CodeIgniter's own test bootstrap from the repository root. The database for
 * repository and service tests comes from Tests\Support\TestDatabase:
 * SQLite always, PostgreSQL as well when DB_DRIVER=pgsql (CI) or TEST_PG_HOST is set.
 * No at-rest key variables from the runner's environment.
 */

$tmp = sys_get_temp_dir() . '/apotheke-ci4-phpunit-' . getmypid();
@mkdir($tmp, 0775, true);
foreach (['DATA_DIR' => $tmp, 'APOTHEKE_CONFIG' => '/nonexistent', 'SEED_DEFAULTS' => '0', 'DB_DRIVER' => 'sqlite', 'DB_SCHEMA' => '', 'APP_PASSWORD' => ''] as $k => $v) {
    if ($k === 'DB_DRIVER' && getenv('DB_DRIVER') === 'pgsql') {
        putenv('APOTHEKE_CI4_TEST_PGSQL=1');
    }
    putenv("$k=$v");
    $_SERVER[$k] = $v;
}
// The at-rest key comes from the environment (S-L5). The runner's own APP_KEY, APP_KEY_FILE
// or APOTHEKE_SODIUM never reaches a test; a test that needs one sets it (#93 N-17).
foreach (['APP_KEY', 'APP_KEY_FILE', 'APOTHEKE_SODIUM'] as $k) {
    putenv($k);
    unset($_SERVER[$k], $_ENV[$k]);
}
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
    }
    @rmdir($tmp);
});

$home = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
define('HOMEPATH', $home);
define('CONFIGPATH', $home . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);
define('PUBLICPATH', $home . 'public' . DIRECTORY_SEPARATOR);
require $home . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
require_once __DIR__ . '/_support/TestDatabase.php';
