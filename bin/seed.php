#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – seed data (idempotent; safe to run repeatedly).
 *   php bin/seed.php           starter locations & categories (one default each), skipping existing names
 *   php bin/seed.php --demo    …plus a handful of demo items with generated photos
 */

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';
require_once dirname(__DIR__) . '/src/seed.php';

$pdo = db();   // runs pending migrations first
$n = seed_defaults($pdo, true);
echo "Starter locations/categories created: $n\n";
if (in_array('--demo', $argv, true)) {
    echo 'Demo items created: ' . seed_demo() . "\n";
}
