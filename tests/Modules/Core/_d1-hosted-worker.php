<?php

declare(strict_types=1);

// One racer for D1HostedWritesTest: opens the d1 test database from its env (argv[1], JSON), says "ready",
// waits for "go", then runs one step (argv[2]) and prints the outcome as JSON.
require __DIR__ . '/../bootstrap.php';

use Tests\Core\D1HostedWritesTest;
use Tests\Support\TestDatabase;

require_once __DIR__ . '/D1HostedWritesTest.php';

$t = TestDatabase::fromEnv(json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR));
$apply = D1HostedWritesTest::undoApply($t);
echo "ready\n";
fgets(STDIN);
try {
    $r = $argv[2] === 'undo' ? $apply->apply($argv[3]) : throw new RuntimeException('unknown step');
    echo json_encode(['ok' => true, 'ids' => $r['ids']]), "\n";
} catch (Apotheke\Core\Exceptions\UserError $e) {
    echo json_encode(['ok' => false, 'code' => $e->getCode(), 'message' => $e->getMessage()]), "\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 500, 'message' => get_class($e) . ': ' . $e->getMessage()]), "\n";
}
