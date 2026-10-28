<?php
declare(strict_types=1);

// PHPUnit bootstrap: isolated data dir, no config.php, no starter data; driver from DB_DRIVER (sqlite default).
$tmp = sys_get_temp_dir() . '/apotheke-phpunit-' . getmypid();
@mkdir($tmp, 0775, true);
foreach (['DATA_DIR' => $tmp, 'APOTHEKE_CONFIG' => '/nonexistent', 'SEED_DEFAULTS' => '0', 'TZ' => 'Europe/Athens'] as $k => $v) putenv("$k=$v");
foreach (['APP_KEY', 'APP_KEY_FILE', 'APP_KEY_NEW'] as $k) putenv($k);
if (getenv('APOTHEKE_TEST_DB') !== 'pgsql' && getenv('APOTHEKE_TEST_DB') !== 'sqlite') {
    putenv('APOTHEKE_TEST_DB=' . (getenv('DB_DRIVER') === 'pgsql' ? 'pgsql' : 'sqlite'));
}
if (getenv('DB_DRIVER') === 'pgsql') {
    foreach (['DB_HOST' => 'TEST_PG_HOST', 'DB_PORT' => 'TEST_PG_PORT', 'DB_NAME' => 'TEST_PG_DB', 'DB_USER' => 'TEST_PG_USER', 'DB_PASSWORD' => 'TEST_PG_PASSWORD'] as $k => $t) {
        if (getenv($k) === false && getenv($t) !== false) putenv("$k=" . getenv($t));
    }
    if (getenv('DB_PASSWORD') === false) putenv('DB_PASSWORD=apotheke');
}
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require dirname(__DIR__) . '/src/seed.php';
require dirname(__DIR__) . '/src/backup.php';
require dirname(__DIR__) . '/src/cli-owner.php';
register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir((string)$f) : @unlink((string)$f);
    @rmdir($tmp);
});
