<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: adds a PNG and a HEIC photo (with their originals) to the legacy demo data, after
 * `bin/seed.php --demo` has added the JPEG ones. Run with the legacy app (PHP 8.3). Idempotent.
 */

if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root . '/src/bootstrap.php';
require $root . '/src/items.php';

$store = static function (string $fixture): string {
    $tmp = (string)tempnam(sys_get_temp_dir(), 'upg');
    copy($fixture, $tmp);
    try {
        return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
    } finally {
        @unlink($tmp);
    }
};
$exists = db()->prepare('SELECT COUNT(*) FROM items WHERE name = ?');
$exists->execute(['Upgrade check']);
if ((int)$exists->fetchColumn() > 0) {
    echo "Upgrade photos: already there\n";
    exit(0);
}
$id = item_save(null, ['name' => 'Upgrade check', 'location_id' => tax_create('location', 'Garage')['id'],
    'category_id' => tax_create('category', 'Tools')['id'], 'quantity' => 1, 'notes' => 'PNG cover, HEIC second'],
    $store($root . '/tests/fixtures/transparent.png'), false);
photo_add($id, $store($root . '/tests/fixtures/tiny-gps.heic'));
echo "Upgrade photos: item $id with a PNG and a HEIC photo\n";
