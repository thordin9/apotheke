<?php

declare(strict_types=1);

// One racer for D1BootstrapTest: says "ready", waits for "go" on stdin, then bootstraps the schema.
// argv: bridge URL.
require __DIR__ . '/../bootstrap.php';

use Apotheke\Core\Database\D1HttpExecutor;
use Apotheke\Migrations\D1Bootstrap;
use Apotheke\Migrations\D1MigrationRunner;

$bootstrap = new D1Bootstrap(new D1HttpExecutor($argv[1]), new D1MigrationRunner(dirname(__DIR__, 3) . '/migrations'));
echo "ready\n";
fgets(STDIN);
try {
    echo json_encode(['applied' => count($bootstrap->migrate())]), "\n";
} catch (RuntimeException $e) {
    echo json_encode(['error' => $e->getMessage()]), "\n";
}
