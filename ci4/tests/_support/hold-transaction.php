<?php

declare(strict_types=1);

/*
 * The other client of TestDatabase::holdInAnotherClient(): connects with the
 * test database's environment, runs the statements of argv[1] (JSON list of
 * [sql, binds]) in one transaction, prints "held", waits argv[2] ms, commits.
 */

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Migrations\PdoMigrationRunner;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = ApothekeConfig::load(ApothekeConfig::repoRoot(), null, getenv());
$pdo = (new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->connect($config->database());
$pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'BEGIN IMMEDIATE' : 'BEGIN');
/** @var list<array{0:string,1:list<int|string|null>}> $statements */
$statements = json_decode($argv[1], true, 8, JSON_THROW_ON_ERROR);
foreach ($statements as [$sql, $binds]) {
    $pdo->prepare($sql)->execute($binds);
}
echo "held\n";
usleep(1000 * (int)$argv[2]);
$pdo->exec('COMMIT');
echo "committed\n";
