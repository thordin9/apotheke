<?php

declare(strict_types=1);

/*
 * The other client used by HostedAdminResetTest: connects with the test
 * database's environment, prints "waiting", polls pg_locks until another
 * backend holds the two-key advisory lock in argv[1], then takes that lock
 * and prints "snapshot <admins> <claim>" for the claim name in argv[2].
 * argv[3] is the wait timeout in milliseconds (default 5000).
 */

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Migrations\PdoMigrationRunner;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$config = ApothekeConfig::load(ApothekeConfig::repoRoot(), null, getenv());
$pdo = (new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations'))->connect($config->database());
/** @var array{0:int|string,1:int|string} $keys */
$keys = json_decode($argv[1], true, 8, JSON_THROW_ON_ERROR);
$setting = (string)$argv[2];
$timeoutMs = (int)($argv[3] ?? 5000);

echo "waiting\n";
flush();

$deadline = hrtime(true) + $timeoutMs * 1_000_000;
$wait = $pdo->prepare(
    'SELECT 1 FROM pg_locks WHERE locktype = \'advisory\' AND granted AND pid <> pg_backend_pid()'
    . ' AND classid = ((?::bigint + 4294967296) % 4294967296) AND objid = ((?::bigint + 4294967296) % 4294967296) AND objsubid = 2'
);
while (true) {
    $wait->execute([(int)$keys[0], (int)$keys[1]]);
    if ($wait->fetchColumn() !== false) {
        break;
    }
    if (hrtime(true) >= $deadline) {
        fwrite(STDERR, "timeout waiting for advisory lock\n");
        exit(1);
    }
    usleep(5_000);
}

$pdo->exec('BEGIN');
$pdo->prepare('SELECT pg_advisory_xact_lock(?, ?)')->execute([(int)$keys[0], (int)$keys[1]]);
$admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$claim = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE name = ?');
$claim->execute([$setting]);
echo 'snapshot ' . $admins . ' ' . (int)$claim->fetchColumn() . "\n";
flush();
$pdo->exec('COMMIT');
echo "committed\n";
