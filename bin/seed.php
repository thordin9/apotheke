#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – seed data (idempotent; safe to run repeatedly).
 *   php bin/seed.php           starter locations & categories (one default each), skipping existing names
 *   php bin/seed.php --demo    …plus a handful of demo items with generated photos
 */

if (PHP_SAPI !== 'cli') exit(1);
// Runs as `spark apotheke:seed`.
$spark = dirname(__DIR__) . '/spark';
// started as root: run as DATA_DIR's owner before anything else, or stop (L-622, N-625; DataDirOwner)
require_once dirname(__DIR__) . '/modules/Backup/Commands/DataDirOwner.php';
if (!\Apotheke\Backup\Commands\DataDirOwner::forCommandLine(STDERR, 'php bin/seed.php')) exit(1);
$args = [PHP_BINARY, $spark, 'apotheke:seed', '--no-header'];
if (in_array('--demo', $argv, true)) $args[] = '--demo';
passthru(implode(' ', array_map('escapeshellarg', $args)), $code);
exit($code);
