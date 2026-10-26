<?php
declare(strict_types=1);

/*
 * The other client of DatabaseTestCase::holdInAnotherClient(): connects with the environment's database settings,
 * runs argv[1] (JSON list of [sql, args]) in one transaction (BEGIN IMMEDIATE on SQLite), prints "held",
 * waits argv[2] ms, commits and prints "committed".
 */

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = db_connect(cfg()['db']);
$pdo->exec(db_driver() === 'sqlite' ? 'BEGIN IMMEDIATE' : 'BEGIN');
foreach (json_decode($argv[1], true, 8, JSON_THROW_ON_ERROR) as [$sql, $args]) $pdo->prepare($sql)->execute($args);
echo "held\n";
usleep(1000 * (int)$argv[2]);
$pdo->exec('COMMIT');
echo "committed\n";
