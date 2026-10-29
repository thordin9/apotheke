<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: shared by snapshot.php and mutate.php. Opens the install's database from the environment
 * (DATA_DIR, SQLITE_PATH, DB_*), as both apps read it, without booting either app.
 */

function upg_data_dir(): string
{
    $dir = rtrim((string)getenv('DATA_DIR'), '/');
    if ($dir === '' || !is_dir($dir)) { fwrite(STDERR, "upgrade: DATA_DIR is not a directory\n"); exit(2); }
    return $dir;
}

function upg_pdo(): PDO
{
    if (getenv('DB_DRIVER') === 'pgsql') {
        $pdo = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'apotheke'),
            (string)getenv('DB_USER'), (string)getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = (string)getenv('DB_SCHEMA');
        if ($schema !== '') $pdo->exec('SET search_path TO "' . str_replace('"', '""', $schema) . '"');
        return $pdo;
    }
    return new PDO('sqlite:' . (getenv('SQLITE_PATH') ?: upg_data_dir() . '/catalog.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/**
 * The tables a backup zip carries, from both apps (legacy backup_tables(), CI4 Backups::tables()); they must agree.
 * @return list<string>
 */
function upg_carried_tables(): array
{
    require_once upg_legacy_root() . '/src/backup.php';
    require_once dirname(__DIR__, 2) . '/modules/Backup/Backups.php';
    $legacy = backup_tables();
    $ci4 = \Apotheke\Backup\Backups::tables();
    if ($legacy !== $ci4) { fwrite(STDERR, "upgrade: legacy backup_tables() and CI4 Backups::tables() differ\n"); exit(2); }
    return $legacy;
}

/** The legacy 2.x checkout scripts/upgrade-rollback.sh made (UPGRADE_LEGACY_ROOT). */
function upg_legacy_root(): string
{
    $root = rtrim((string)getenv('UPGRADE_LEGACY_ROOT'), '/');
    if ($root === '' || !is_file($root . '/src/bootstrap.php')) { fwrite(STDERR, "upgrade: UPGRADE_LEGACY_ROOT is not a legacy checkout\n"); exit(2); }
    return $root;
}
