<?php
declare(strict_types=1);

/*
 * PLAN step 16 part 2: after `bin/seed.php --demo` (JPEG photos), fills every table a backup carries: an item
 * with a PNG and a HEIC photo (with their originals), two tags, a PDF attachment under files/, a saved filter and
 * an open stocktake with one line checked. Run with the legacy app (PHP 8.3, UPGRADE_LEGACY_ROOT). Idempotent.
 */

if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/lib.php';
$legacy = upg_legacy_root();
require $legacy . '/src/bootstrap.php';
require $legacy . '/src/items.php';
require_once $legacy . '/src/attachments.php';
require_once $legacy . '/src/stocktake.php';
$root = dirname(__DIR__, 2);

$tmpCopy = static function (string $bytes): string {
    $tmp = (string)tempnam(sys_get_temp_dir(), 'upg');
    file_put_contents($tmp, $bytes);
    return $tmp;
};
$store = static function (string $fixture) use ($tmpCopy): string {
    $tmp = $tmpCopy((string)file_get_contents($fixture));
    try {
        return image_store(['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'size' => filesize($tmp)]);
    } finally {
        @unlink($tmp);
    }
};
$exists = db()->prepare('SELECT COUNT(*) FROM items WHERE name = ?');
$exists->execute(['Upgrade check']);
if ((int)$exists->fetchColumn() > 0) {
    echo "Upgrade seed: already there\n";
    exit(0);
}
$garage = tax_create('location', 'Garage')['id'];
$id = item_save(null, ['name' => 'Upgrade check', 'location_id' => $garage, 'category_id' => tax_create('category', 'Tools')['id'],
    'quantity' => 1, 'notes' => 'PNG cover, HEIC second', 'tags' => ['winter', 'red']],
    $store($root . '/tests/fixtures/transparent.png'), false);
photo_add($id, $store($root . '/tests/fixtures/tiny-gps.heic'));

$pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
$tmp = $tmpCopy($pdf);
try {
    attachments_add($id, [['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmp, 'name' => 'manual.pdf', 'size' => strlen($pdf)]]);
} finally {
    @unlink($tmp);
}
saved_filter_create('Winter things', 'q=winter');
$st = stocktake_start((int)$garage);
stocktake_check($st, $id, true);
echo "Upgrade seed: item $id with a PNG and a HEIC photo, tags, an attachment, a saved filter, stocktake $st\n";
