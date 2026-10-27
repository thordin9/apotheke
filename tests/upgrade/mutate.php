<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: the job's mutation checks. Runs one SQL statement on the install's database (from the
 * environment, as snapshot.php), so the job can show that an emptied or dropped table is caught.
 *   php tests/upgrade/mutate.php "DELETE FROM item_tags"
 */

if (PHP_SAPI !== 'cli' || $argc !== 2) { fwrite(STDERR, "usage: mutate.php SQL\n"); exit(2); }
require __DIR__ . '/lib.php';
upg_pdo()->exec($argv[1]);
