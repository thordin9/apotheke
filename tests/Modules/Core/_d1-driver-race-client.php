<?php

declare(strict_types=1);

// One racer for D1DriverBridgeTest: reads, says "ready", waits for "go" on stdin, then commits.
// argv: bridge URL, mode (cas|add).
require __DIR__ . '/../bootstrap.php';

use Apotheke\Core\Database\D1\Connection;

$db = new Connection(['DBDriver' => 'Apotheke\\Core\\Database\\D1', 'hostname' => $argv[1], 'database' => '', 'DBDebug' => false]);
$db->transBegin();
if ($argv[2] === 'cas') {
    $v = (int)$db->query('SELECT version FROM item WHERE id = 1')->getRow()->version;
    $db->query('UPDATE item SET qty = ' . getmypid() . ', version = version + 1 WHERE id = 1 AND version = ' . $v);
    $db->query('INSERT INTO uow_guard (ok) SELECT NULL WHERE changes() <> 1');
} else {
    $db->query('UPDATE item SET qty = qty + 1 WHERE id = 1');
}
echo "ready\n";
fgets(STDIN);
$ok = $db->transCommit();
if (!$ok) {
    $db->transRollback();
}
echo json_encode(['ok' => $ok, 'code' => $db->error()['code']]), "\n";
