<?php
declare(strict_types=1);

/* Apotheke – first-use seeding (starter locations/categories) and optional demo items. All idempotent. */

const SEED_LOCATIONS  = ['Box 1', 'Living room', 'Kitchen', 'Garage', 'Basement', 'Attic'];
const SEED_CATEGORIES = ['General', 'Tools', 'Electronics', 'Kitchen', 'Documents', 'Hobby'];
const SEED_DEFAULT_LOCATION = 'Box 1';
const SEED_DEFAULT_CATEGORY = 'General';

function setting_get(PDO $pdo, string $name): ?string
{
    $st = $pdo->prepare('SELECT value FROM app_settings WHERE name = ?');
    $st->execute([$name]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

function setting_set(PDO $pdo, string $name, string $value): void
{
    $pdo->prepare('DELETE FROM app_settings WHERE name = ?')->execute([$name]);
    $pdo->prepare('INSERT INTO app_settings (name, value) VALUES (?, ?)')->execute([$name, $value]);
}

/**
 * Starter locations/categories with one default each. Runs once per database (flag in app_settings);
 * never duplicates names (case/accent-insensitive) and never overrides an existing default.
 * Returns the number of rows created.
 */
function seed_defaults(PDO $pdo, bool $force = false): int
{
    if (!$force && setting_get($pdo, 'seeded_defaults') !== null) return 0;
    $n = 0;
    foreach (['locations' => [SEED_LOCATIONS, SEED_DEFAULT_LOCATION], 'categories' => [SEED_CATEGORIES, SEED_DEFAULT_CATEGORY]] as $table => [$names, $default]) {
        $find = $pdo->prepare("SELECT id FROM $table WHERE name_key = ?");
        foreach ($names as $name) {
            $find->execute([fold($name)]);
            if ($find->fetchColumn() !== false) continue;
            db_insert($pdo, "INSERT INTO $table (name, name_key, sort_key, created_at) VALUES (?,?,?,?)", [$name, fold($name), natkey($name), now_utc()]);
            $n++;
        }
        if (!(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE is_default = 1")->fetchColumn()) {
            $pdo->prepare("UPDATE $table SET is_default = 1 WHERE name_key = ?")->execute([fold($default)]);
        }
    }
    setting_set($pdo, 'seeded_defaults', now_utc());
    return $n;
}

/** A few demo items with generated photos. Skips items whose name already exists. Returns items created. */
function seed_demo(): int
{
    $pdo = db();
    seed_defaults($pdo, true);
    $demo = [
        ['Cordless drill', 'Garage', 'Tools', 1, [200, 80, 40], "18V, two batteries."],
        ['HDMI cables', 'Box 1', 'Electronics', 4, [40, 90, 200], ''],
        ['Cast iron pan', 'Kitchen', 'Kitchen', 2, [90, 60, 50], ''],
        ['Passport', 'Living room', 'Documents', 1, [120, 40, 60], 'Expires 2031.'],
        ['Camping stove', 'Attic', 'Hobby', 1, [30, 140, 90], ''],
        ['Raspberry Pi 4', 'Box 1', 'Electronics', 3, [180, 40, 90], ''],
        ['Screwdriver set', 'Garage', 'Tools', 1, [220, 160, 30], ''],
        ['Extension cord', 'Basement', 'General', 2, null, ''],
    ];
    $n = 0;
    $exists = $pdo->prepare('SELECT COUNT(*) FROM items WHERE name = ?');
    foreach ($demo as [$name, $loc, $cat, $qty, $rgb, $notes]) {
        $exists->execute([$name]);
        if ((int)$exists->fetchColumn() > 0) continue;
        $image = $rgb ? seed_image(...$rgb) : null;
        item_save(null, ['name' => $name, 'location_id' => tax_create('location', $loc)['id'], 'category_id' => tax_create('category', $cat)['id'],
                         'quantity' => $qty, 'notes' => $notes], $image, false);
        $n++;
    }
    return $n;
}

function seed_image(int $r, int $g, int $b): string
{
    $im = imagecreatetruecolor(900, 900);
    for ($y = 0; $y < 900; $y++) imageline($im, 0, $y, 899, $y, imagecolorallocate($im, min(255, $r + intdiv($y, 9)), min(255, $g + intdiv($y, 12)), min(255, $b + intdiv($y, 15))));
    imagefilledellipse($im, 450, 470, 460, 460, imagecolorallocatealpha($im, 255, 255, 255, 70));
    imagefilledrectangle($im, 300, 330, 600, 610, imagecolorallocate($im, intdiv($r, 2), intdiv($g, 2), intdiv($b, 2)));
    $tmp = (string)tempnam(sys_get_temp_dir(), 'apo');
    imagejpeg($im, $tmp, 85);
    try {
        return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
    } finally {
        @unlink($tmp);
    }
}
