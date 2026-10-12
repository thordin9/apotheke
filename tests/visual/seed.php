<?php
declare(strict_types=1);

/*
 * Visual E2E fixtures: wipe a data dir (and PostgreSQL schema) and load a deterministic catalog.
 *   php tests/visual/seed.php DATA_DIR demo|empty
 * DB settings come from the environment (DB_DRIVER, DB_SCHEMA, …) exactly like the app server.
 * Run with APOTHEKE_CLOCK set so every timestamp is fixed.
 */

if (PHP_SAPI !== 'cli' || $argc < 3) { fwrite(STDERR, "usage: seed.php DATA_DIR demo|empty\n"); exit(2); }
[$dir, $scenario] = [$argv[1], $argv[2]];

// wipe the data dir completely (sqlite file, photos, sessions, tmp)
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir((string)$f) : unlink((string)$f);
}
putenv("DATA_DIR=$dir");
putenv('SEED_DEFAULTS=0');
putenv('APOTHEKE_CONFIG=/nonexistent');
$root = dirname(__DIR__, 2);
require "$root/src/bootstrap.php";
require "$root/src/items.php";

if (db_driver() === 'pgsql') {
    $c = cfg()['db'];
    $schema = (string)$c['schema'];
    if ($schema === '' || !preg_match('/^[a-z0-9_]+$/D', $schema)) { fwrite(STDERR, "DB_SCHEMA required for pgsql\n"); exit(2); }
    $pdo = db_connect(['schema' => ''] + $c);
    $pdo->exec("DROP SCHEMA IF EXISTS \"$schema\" CASCADE");
    unset($pdo);
}
db();   // migrations

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
    return image_store(['name' => 'p.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]);
}

$L = [];
foreach (['Garage shelf', 'Κουτί 5', 'Attic', 'box 2', 'box 10'] as $n) $L[$n] = tax_create('location', $n)['id'];
$C = [];
foreach (['Tools', 'Electronics', 'Camping', 'Kitchen'] as $n) $C[$n] = tax_create('category', $n)['id'];
tax_set_default('location', $L['Garage shelf'], true);
tax_set_default('category', $C['Tools'], true);

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
foreach ($items as $i => [$name, $loc, $cat, $qty, $notes, $tags, $value, $date, $serial, $photos, $age]) {
    $id = item_save(null, ['name' => $name, 'location_id' => $loc ? $L[$loc] : null, 'category_id' => $cat ? $C[$cat] : null, 'quantity' => $qty,
        'notes' => $notes, 'tags' => $tags, 'value_cents' => parse_money($value), 'purchase_date' => $date ?: null, 'serial' => $serial],
        $photos ? fixture_photo($photos[0], $i % 2) : null, false);
    foreach (array_slice($photos, 1) as $k => $rgb) photo_add($id, fixture_photo($rgb, $k % 2));
    $ts = gmdate('Y-m-d H:i:s', app_time() - $age * 86400 - $i * 600);
    db()->prepare('UPDATE items SET created_at = ?, updated_at = ? WHERE id = ?')->execute([$ts, $ts, $id]);
}
item_adjust_qty(2, 1);   // some history beyond "created"
echo "demo\n";
