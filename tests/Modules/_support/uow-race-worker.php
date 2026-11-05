<?php

declare(strict_types=1);

/*
 * One parallel client for UnitOfWorkRemainingSitesRaceTest: connects with the
 * test database's clientEnv (JSON in argv[1]) and runs argv[2] (JSON job:
 * {op, batch, id?, delta?, to?, at?, due?, kind?, name?, parent?}).
 * Prints one JSON line and exits 0; stderr carries unexpected errors.
 */

use Apotheke\Activity\ActivityLogger;
use Apotheke\BulkMerge\QuantityAdjust;
use Apotheke\Catalog\Calendar;
use Apotheke\Core\Database\ConnectionExecutor;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Lending\Loans;
use Apotheke\Taxonomy\TaxonomyRepository;
use Tests\Support\TestDatabase;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$tmp = sys_get_temp_dir() . '/apotheke-ci4-race-worker-' . getmypid();
@mkdir($tmp, 0775, true);
foreach (['APOTHEKE_CONFIG' => '/nonexistent', 'SEED_DEFAULTS' => '0', 'APP_PASSWORD' => ''] as $k => $v) {
    putenv("$k=$v");
    $_SERVER[$k] = $v;
}
foreach (['APP_KEY', 'APP_KEY_FILE', 'APOTHEKE_SODIUM'] as $k) {
    putenv($k);
    unset($_SERVER[$k], $_ENV[$k]);
}
define('HOMEPATH', dirname(__DIR__, 3) . DIRECTORY_SEPARATOR);
define('CONFIGPATH', HOMEPATH . 'app' . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR);
define('PUBLICPATH', HOMEPATH . 'public' . DIRECTORY_SEPARATOR);
require HOMEPATH . 'vendor/codeigniter4/framework/system/Test/bootstrap.php';
require_once __DIR__ . '/TestDatabase.php';

/** @var array<string,string> $env */
$env = json_decode($argv[1], true, 8, JSON_THROW_ON_ERROR);
/** @var array<string,mixed> $job */
$job = json_decode($argv[2], true, 8, JSON_THROW_ON_ERROR);

$database = TestDatabase::fromEnv($env, false);
$db = $database->db;
$units = UnitsOfWork::on(!empty($job['batch']) ? new LocalBatchExecutor($db) : new ConnectionExecutor($db));
$clock = new Clock();
$history = new ActivityLogger($db, $clock, static fn (): ?string => null);

try {
    $out = match ((string)$job['op']) {
        'adjust' => [
            'ok' => true,
            'quantity' => (new QuantityAdjust($db, $clock, $history, $units))->adjust((int)$job['id'], (int)$job['delta']),
        ],
        'lend' => (static function () use ($database, $db, $clock, $history, $units, $job): array {
            $items = $database->items($db, $units, $history);
            (new Loans($db, $clock, new Calendar($clock, 'UTC', 7), $history, $items, $units))
                ->lend((int)$job['id'], (string)$job['to'], $job['at'] ?? null, $job['due'] ?? null);

            return ['ok' => true];
        })(),
        'giveBack' => (static function () use ($database, $db, $clock, $history, $units, $job): array {
            $items = $database->items($db, $units, $history);
            (new Loans($db, $clock, new Calendar($clock, 'UTC', 7), $history, $items, $units))->giveBack((int)$job['id']);

            return ['ok' => true];
        })(),
        'createUnder' => (static function () use ($db, $clock, $history, $units, $job): array {
            $r = (new TaxonomyRepository($db, $clock, $history, $units))
                ->createUnder((string)$job['kind'], (string)$job['name'], isset($job['parent']) ? (int)$job['parent'] : null);

            return ['ok' => true, 'id' => $r['id'], 'created' => $r['created']];
        })(),
        'create' => (static function () use ($db, $clock, $history, $units, $job): array {
            $r = (new TaxonomyRepository($db, $clock, $history, $units))
                ->create((string)$job['kind'], (string)$job['name']);

            return ['ok' => true, 'id' => $r['id'], 'created' => $r['created']];
        })(),
        default => throw new InvalidArgumentException('unknown op ' . $job['op']),
    };
    echo json_encode($out, JSON_THROW_ON_ERROR) . "\n";
} catch (UserError $e) {
    echo json_encode(['ok' => false, 'code' => $e->getCode(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");
    echo json_encode(['ok' => false, 'code' => 500, 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
    exit(1);
} finally {
    $database->db->close();
}
