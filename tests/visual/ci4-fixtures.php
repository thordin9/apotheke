<?php
declare(strict_types=1);

/*
 * Visual E2E fixtures, through the app itself.
 *   php tests/visual/ci4-fixtures.php seed DATA_DIR demo|empty   wipe the data dir (and PostgreSQL schema), load a fixed catalog
 *   php tests/visual/ci4-fixtures.php sql QUERY                  rows as JSON
 *   php tests/visual/ci4-fixtures.php exec SQL                   run one statement
 *   php tests/visual/ci4-fixtures.php migrate                    apply pending migrations
 *   php tests/visual/ci4-fixtures.php default-category NAME      create a category and make it the default
 *   php tests/visual/ci4-fixtures.php settings JSON              store settings (name => value)
 * DB settings come from the environment (DB_DRIVER, DB_SCHEMA, …) exactly like the app server.
 * Run with APOTHEKE_CLOCK set so every timestamp is fixed.
 */

use Apotheke\Catalog\Items\ItemInput;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Migrations\PdoMigrationRunner;
use CodeIgniter\Boot;
use Config\Paths;
use Config\Services;

if (PHP_SAPI !== 'cli' || $argc < 2) { fwrite(STDERR, "usage: ci4-fixtures.php seed|sql|exec|migrate|default-category|settings …\n"); exit(2); }
$op = $argv[1];
if ($op === 'seed') {
    if ($argc < 4) { fwrite(STDERR, "usage: ci4-fixtures.php seed DATA_DIR demo|empty\n"); exit(2); }
    [$dir, $scenario] = [$argv[2], $argv[3]];
    // wipe the data dir completely (sqlite file, photos, sessions, tmp)
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir((string)$f) : unlink((string)$f);
    }
    putenv("DATA_DIR=$dir");
    putenv('SEED_DEFAULTS=0');
    putenv('APOTHEKE_CONFIG=/nonexistent');
}

$ci4 = dirname(__DIR__, 2);
define('FCPATH', $ci4 . '/public/');
chdir(FCPATH);
require $ci4 . '/app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
define('ENVIRONMENT', (string)(getenv('CI_ENVIRONMENT') ?: 'production'));
Boot::bootConsole($paths);

switch ($op) {
    case 'sql':
        $result = Services::apothekeDb()->query($argv[2]);
        if ($result === false) { fwrite(STDERR, "query failed\n"); exit(1); }
        if ($result === true) { echo '[]'; exit(0); }   // a write answers [] as PDO's fetchAll() did
        // PostgreSQL rows come back as text: give integer, float and boolean columns PDO's native types, as legacy did
        $types = [];
        foreach ($result->getFieldData() as $field) $types[$field->name] = (string)($field->type_name ?? '');
        $rows = array_map(static function (array $row) use ($types): array {
            foreach ($row as $k => $v) {
                if (!is_string($v)) continue;
                $t = $types[$k] ?? '';
                if (in_array($t, ['int2', 'int4', 'int8'], true)) $row[$k] = (int)$v;
                elseif (in_array($t, ['float4', 'float8'], true)) $row[$k] = (float)$v;
                elseif ($t === 'bool') $row[$k] = $v === 't';
            }
            return $row;
        }, $result->getResultArray());
        echo json_encode($rows);
        exit(0);
    case 'exec':
        Services::apothekeDb()->query($argv[2]);
        exit(0);
    case 'migrate':
        Services::apothekeDb();
        exit(0);
    case 'default-category':
        $taxonomy = Services::taxonomy();
        $taxonomy->setDefault('category', (int)$taxonomy->create('category', $argv[2])['id'], true);
        exit(0);
    case 'settings':
        Services::settings()->store((array)json_decode($argv[2], true, 4, JSON_THROW_ON_ERROR));
        exit(0);
    case 'seed':
        break;
    default:
        fwrite(STDERR, "unknown fixture: $op\n");
        exit(2);
}

$db = Services::apothekeConfig()->database();
if (($db['driver'] ?? '') === 'pgsql') {
    $schema = (string)($db['schema'] ?? '');
    if ($schema === '' || !preg_match('/^[a-z0-9_]+$/D', $schema)) { fwrite(STDERR, "DB_SCHEMA required for pgsql\n"); exit(2); }
    $runner = new PdoMigrationRunner(PdoMigrationRunner::repoRoot() . '/migrations');
    $pdo = $runner->connect($db);
    $pdo->exec("DROP SCHEMA IF EXISTS \"$schema\" CASCADE");
    $runner->close($pdo);
}
Services::apothekeDb();   // migrations

if ($scenario === 'empty') { echo "empty\n"; exit(0); }

/** Deterministic test photo (gradient + shape), stored like an upload. */
function fixture_photo(array $rgb, int $shape): string
{
    $im = imagecreatetruecolor(800, 600);
    for ($y = 0; $y < 600; $y++) {
        imageline($im, 0, $y, 799, $y, imagecolorallocate($im, min(255, $rgb[0] + intdiv($y, 6)), min(255, $rgb[1] + intdiv($y, 8)), min(255, $rgb[2] + intdiv($y, 10))));
    }
    $w = imagecolorallocatealpha($im, 255, 255, 255, 60);
    $shape === 0 ? imagefilledellipse($im, 400, 300, 360, 360, $w) : imagefilledrectangle($im, 250, 170, 550, 430, $w);
    $tmp = tempnam(sys_get_temp_dir(), 'apfx');
    imagejpeg($im, $tmp, 85);
    try {
        return Services::imageStore()->store($tmp, (int)filesize($tmp));
    } finally {
        @unlink($tmp);
    }
}

$taxonomy = Services::taxonomy();
$L = [];
foreach (['Garage shelf', 'Κουτί 5', 'Attic', 'box 2', 'box 10'] as $n) $L[$n] = (int)$taxonomy->create('location', $n)['id'];
$C = [];
foreach (['Tools', 'Electronics', 'Camping', 'Kitchen'] as $n) $C[$n] = (int)$taxonomy->create('category', $n)['id'];
$taxonomy->setDefault('location', $L['Garage shelf'], true);
$taxonomy->setDefault('category', $C['Tools'], true);

$items = [
    // name, location, category, qty, notes, tags, value, date, serial, photo colours, age in days
    ['Cordless drill', 'Garage shelf', 'Tools', 2, "Makita 18V, 2 batteries.", ['Garage', 'Power tools'], '189.90', '2024-03-14', 'MKT-DHP482', [[200, 80, 40], [60, 60, 70], [210, 140, 60]], 0],
    ['HDMI cables', 'box 2', 'Electronics', 4, '', ['AV'], '', '', '', [[40, 90, 200]], 1],
    ['Camping stove', 'Attic', 'Camping', 1, '', [], '45', '', '', [[30, 140, 90]], 3],
    ['Πρίζα πολύπριζο', 'Κουτί 5', 'Electronics', 3, 'Ελληνικά σημειώσεις', [], '', '', '', [], 7],
    ['Cast iron pan', null, 'Kitchen', 1, '', [], '', '', '', [[90, 60, 50]], 12],
    ['Tent (2 person)', 'Attic', 'Camping', 1, '', ['Garage'], '', '', '', [], 20],
    ['Screwdriver set', 'Garage shelf', 'Tools', 1, '', ['Garage'], '34.50', '', '', [[220, 160, 30]], 35],
    ['Headlamp', 'box 10', null, 5, '', [], '', '', '', [[20, 20, 60]], 80],
];
$repo = Services::itemRepository();
$photos = Services::photoRepository();
$maxPhotos = (int)Services::settings()->get('max_photos');
$app = Services::apothekeDb();
$now = (new Clock())->unix();
foreach ($items as $i => [$name, $loc, $cat, $qty, $notes, $tags, $value, $date, $serial, $colours, $age]) {
    $id = $repo->save(null, ['name' => $name, 'location_id' => $loc ? $L[$loc] : null, 'category_id' => $cat ? $C[$cat] : null, 'quantity' => $qty,
        'notes' => $notes, 'tags' => $tags, 'value_cents' => ItemInput::money($value), 'purchase_date' => $date ?: null, 'serial' => $serial],
        $colours ? fixture_photo($colours[0], $i % 2) : null, false);
    foreach (array_slice($colours, 1) as $k => $rgb) $photos->add($id, fixture_photo($rgb, $k % 2), $maxPhotos);
    $ts = gmdate('Y-m-d H:i:s', $now - $age * 86400 - $i * 600);
    $app->query('UPDATE items SET created_at = ?, updated_at = ? WHERE id = ?', [$ts, $ts, $id]);
}
Services::quantityAdjust()->adjust(2, 1);   // some history beyond "created"
echo "demo\n";
