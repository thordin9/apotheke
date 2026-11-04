<?php
declare(strict_types=1);

/*
 * P4-2b: a staged restore (Backup\RestoreStage) of a backup zip on a real install, for the upgrade/rollback job.
 * MODE is transaction (ConnectionExecutor) or batch (LocalBatchExecutor, the D1 stand-in).
 *   php tests/upgrade/staged-restore.php MODE restore ZIP RUN   restore ZIP through the staged path, marked RUN
 *   php tests/upgrade/staged-restore.php MODE before ZIP RUN    the same, but the process ends (exit 3) just before
 *                                                               the swap batch, with no cleanup
 *   php tests/upgrade/staged-restore.php MODE after ZIP RUN     ... just after the swap batch committed
 *   php tests/upgrade/staged-restore.php MODE applied RUN       RUN's swap committed (else exit 1)
 *   php tests/upgrade/staged-restore.php MODE sweep             the leftover sweep once the lock expired: no copy and
 *                                                               no lock row are left (else exit 1)
 *   php tests/upgrade/staged-restore.php MODE parity            the restored ids: every id sequence is past the highest
 *                                                               id (items: and past activity.item_id), and a location's
 *                                                               parent_id names a later row (else exit 1)
 */

use Apotheke\Backup\RestoreStage;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\Dialect;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\SqlExecutor;
use CodeIgniter\Database\BaseConnection;
use Config\Services;

if (PHP_SAPI !== 'cli') exit(2);
[, $mode, $step] = $argv + [null, '', ''];
if (!in_array($mode, ['transaction', 'batch'], true) || !in_array($step, ['restore', 'before', 'after', 'applied', 'sweep', 'parity'], true)) {
    fwrite(STDERR, "usage: staged-restore.php transaction|batch restore|before|after ZIP RUN | applied RUN | sweep | parity\n");
    exit(2);
}
$root = dirname(__DIR__, 2);
define('FCPATH', $root . '/public/');
define('ENVIRONMENT', 'production');
chdir(FCPATH);
require $root . '/app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
CodeIgniter\Boot::bootConsole($paths);
/** @var BaseConnection<object|resource, object|resource> $db */
$db = Services::apothekeDb();
$exec = $mode === 'batch' ? new LocalBatchExecutor($db) : new ConnectionExecutor($db);
$label = "staged restore ($mode, $step)";

if ($step === 'applied') {
    $ok = (new RestoreStage($exec))->applied((string)($argv[3] ?? ''));
    echo ($ok ? 'ok' : 'FAIL') . " $label: run {$argv[3]} is " . ($ok ? '' : 'not ') . "marked applied\n";
    exit($ok ? 0 : 1);
}
if ($step === 'sweep') {
    $dropped = (new RestoreStage($exec, static fn (): int => time() + RestoreStage::LEASE + 1))->sweep();
    $sql = $db->DBDriver === 'Postgre'
        ? 'SELECT table_name AS n FROM information_schema.tables WHERE table_schema = current_schema()'
        : "SELECT name AS n FROM sqlite_master WHERE type = 'table'";
    $left = array_filter(array_column($db->query($sql)->getResultArray(), 'n'), static fn (string $n): bool => str_starts_with(strtolower($n), RestoreStage::PREFIX));
    $locked = $db->query('SELECT run FROM restore_marker WHERE run = ?', [RestoreStage::LOCK])->getResultArray() !== [];
    $ok = $dropped && $left === [] && !$locked;
    echo ($ok ? 'ok' : 'FAIL') . " $label: " . ($dropped ? 'swept' : 'lock still held') . ', ' . count($left) . ' copies left' . ($locked ? ', lock row left' : '') . "\n";
    exit($ok ? 0 : 1);
}
if ($step === 'parity') {
    $pg = $db->DBDriver === 'Postgre';
    $bad = [];
    $one = static fn (string $sql, array $p = []): int => (int)($db->query($sql, $p)->getRowArray()['n'] ?? 0);
    $backups = Services::backups(false);
    foreach (Apotheke\Backup\Backups::tables() as $t) {
        if (!in_array('id', $backups->columns($t), true)) continue;
        $max = $one("SELECT COALESCE(MAX(id), 0) AS n FROM $t");
        if ($t === 'items') $max = max($max, $one('SELECT COALESCE(MAX(item_id), 0) AS n FROM activity'));
        if ($pg) {
            $seq = (string)($db->query("SELECT pg_get_serial_sequence('$t', 'id') AS s")->getRowArray()['s'] ?? '');
            $row = $seq === '' ? null : $db->query("SELECT last_value, is_called FROM $seq")->getRowArray();
            $next = $row === null ? 0 : (int)$row['last_value'] + (in_array($row['is_called'], [true, 't', 1, '1'], true) ? 1 : 0);
        } else {
            $next = $one('SELECT CAST(seq AS INTEGER) + 1 AS n FROM sqlite_sequence WHERE name = ?', [$t]);
        }
        if ($next <= $max) $bad[] = "$t's next id $next is not past $max";
    }
    if ($one('SELECT COUNT(*) AS n FROM locations WHERE parent_id > id') === 0) $bad[] = 'no location has a parent that comes after it';
    foreach ($bad as $b) echo "FAIL $label: $b\n";
    if ($bad === []) echo "ok $label: every id sequence is past the restored ids, a later parent_id kept\n";
    exit($bad === [] ? 0 : 1);
}

// The process ends at the swap batch (before or after it runs), as a crash would: no finally, no cleanup.
if ($step !== 'restore') {
    $exec = new class ($exec, $step) implements SqlExecutor {
        public function __construct(private readonly SqlExecutor $inner, private readonly string $step)
        {
        }

        public function run(array $statements): array
        {
            $swap = in_array('INSERT INTO restore_marker (run, sha256, applied_at) VALUES (?, ?, ?)', array_column($statements, 'sql'), true);
            if ($swap && $this->step === 'before') {
                exit(3);
            }
            $out = $this->inner->run($statements);
            if ($swap) {
                exit(3);
            }

            return $out;
        }

        public function query(string $sql, array $params = []): array
        {
            return $this->inner->query($sql, $params);
        }

        public function dialect(): Dialect
        {
            return $this->inner->dialect();
        }

        public function connection(): ?BaseConnection
        {
            return $this->inner->connection();
        }
    };
}
$config = Services::apothekeConfig();
$settings = Services::settings();
$restorer = new Apotheke\Backup\Restorer($db, Services::backups(false, $settings), $settings, Services::undoTokens(), static fn (): array => array_keys((new Apotheke\Migrations\PdoMigrationRunner($root . '/migrations'))->available()),
    new Apotheke\Core\Storage\LocalStorage($config->dataDir()), new Apotheke\Core\Storage\LocalStorage($config->originalsDir(), 0640), (int)$settings->get('image_thumb_max'), Services::requestCleanup(), $exec);
$out = $restorer->restore((string)($argv[3] ?? ''), true, (string)($argv[4] ?? ''));
printf("ok %s: %d tables, %d photos restored\n", $label, count($out['tables']), $out['photos']);
