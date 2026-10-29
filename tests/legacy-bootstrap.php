<?php
declare(strict_types=1);

// The legacy app (src/) for the tests that run it in-process: the legacy tests and the tests that compare CI4 with
// it. tests/phpunit-bootstrap.php has already set up the environment. Goes with src/ at the cutover.
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/items.php';
require_once dirname(__DIR__) . '/src/seed.php';
require_once dirname(__DIR__) . '/src/backup.php';
require_once dirname(__DIR__) . '/src/cli-owner.php';
