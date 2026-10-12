<?php
declare(strict_types=1);

/*
 * Apotheke integration tests. Run: php tests/integration.php   (or tests/run.sh for lint + tests)
 * Needs PHP 8.1+ with pdo_sqlite, gd, mbstring (exif optional: orientation test is skipped without it).
 */

require __DIR__ . '/lib.php';

foreach (['pdo_sqlite', 'gd', 'mbstring'] as $ext) {
    if (!extension_loaded($ext)) { fwrite(STDERR, "missing PHP extension: $ext\n"); exit(2); }
}

$tmpDirs = [];
register_shutdown_function(function () use (&$tmpDirs) {
    if (getenv('KEEP_TEST_DATA')) return;
    foreach ($tmpDirs as $d) { rrmdir($d); @unlink($d . '.server.log'); try { testdb_drop($d); } catch (Throwable) {} }
});
$newDir = function (string $p) use (&$tmpDirs): string { return $tmpDirs[] = tmpdir('apotheke-' . $p); };
$savedId = fn(array $r): int => preg_match('/saved=(\d+)/', (string)($r['json']['redirect'] ?? $r['headers']['location'] ?? ''), $m) ? (int)$m[1] : 0;

/* ======================================================================= */
T::section('Fresh install');
$dir = $newDir('main');
$srv = new Server($dir);
$c = $srv->client();
$r = $c->get('/healthz');
T::eq(200, $r['status'], 'GET /healthz → 200');
T::eq('ok', $r['body'], '/healthz body is "ok"');
T::ok((test_driver() === 'pgsql' || is_file("$dir/catalog.sqlite")) && is_dir("$dir/images") && is_dir("$dir/thumbs") && is_dir("$dir/sessions"), 'data dir layout created (catalog.sqlite, images/, thumbs/, sessions/)');
$db = testdb($dir);
T::eq(migration_versions(), $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN), 'all migrations recorded in schema_migrations');
if (test_driver() === 'sqlite') T::eq(3, (int)$db->query('PRAGMA user_version')->fetchColumn(), 'legacy user_version 3 kept for older app versions');
T::eq(0, (int)$db->query('SELECT COUNT(*) FROM locations')->fetchColumn() + (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn(), 'no starter data when SEED_DEFAULTS=0');
T::eq([], glob("$dir/catalog-v*-backup-*.sqlite"), 'no backup snapshot on fresh install');
$r = $c->get('/');
T::eq(200, $r['status'], 'list page renders');
T::ok(str_contains($r['body'], '<title>Apotheke – the storehouse for your stuff</title>'), 'branded <title>');
T::ok(!str_contains($r['body'], 'stats-box'), 'Overview hidden while catalog is empty');
T::ok(str_contains($r['body'], 'Your catalog is empty'), 'friendly empty state');
T::ok(isset($c->cookies['apothekesid']), 'session cookie apothekesid set');
foreach (['content-security-policy', 'x-content-type-options', 'x-frame-options'] as $hn) T::ok(isset($r['headers'][$hn]), "security header $hn");
$tok = $c->csrf();
T::ok(strlen($tok) === 64, 'CSRF token present in page');

T::section('CSRF protection');
foreach (['/save' => ['name' => 'x'], '/locations' => ['name' => 'x'], '/categories' => ['name' => 'x'], '/item/1/qty' => ['delta' => 1], '/item/1/delete' => []] as $p => $f) {
    T::eq(419, $c->api($p, $f)['status'], "POST $p without token → 419");
    T::eq(419, $c->api($p, $f + ['csrf' => str_repeat('0', 64)])['status'], "POST $p with wrong token → 419");
}
T::eq(0, (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'nothing was written');

T::section('Locations & categories');
$ids = [];
foreach (['box 1023', 'box 2', 'box 10', 'garage shelf 2', 'Κουτί 5'] as $n) {
    $r = $c->api('/locations', ['csrf' => $tok, 'name' => "  $n  "]);
    T::ok($r['json']['ok'] === true && $r['json']['created'] === true && $r['json']['name'] === $n, "create location \"$n\" (trimmed)");
    $ids['loc'][$n] = $r['json']['id'];
}
$r = $c->api('/locations', ['csrf' => $tok, 'name' => 'BOX   1023']);
T::ok($r['json']['created'] === false && $r['json']['id'] === $ids['loc']['box 1023'], 'duplicate (case/space-insensitive) returns existing');
$r = $c->api('/locations', ['csrf' => $tok, 'name' => 'κουτι 5']);
T::ok($r['json']['created'] === false, 'duplicate (accent-insensitive Greek) returns existing');
T::eq(422, $c->api('/locations', ['csrf' => $tok, 'name' => '   '])['status'], 'empty location name rejected');
foreach (['Tools', 'Electronics', 'Toys 10', 'Toys 2'] as $n) {
    $r = $c->api('/categories', ['csrf' => $tok, 'name' => $n]);
    T::ok($r['json']['ok'] === true && $r['json']['created'] === true, "create category \"$n\"");
    $ids['cat'][$n] = $r['json']['id'];
}
$form = $c->get('/new')['body'];
T::eq(['— No location —', 'box 2', 'box 10', 'box 1023', 'garage shelf 2', 'Κουτί 5', '＋ New location…'], option_labels($form, 'location_id'), 'location dropdown naturally sorted');
T::eq(['— No category —', 'Electronics', 'Tools', 'Toys 2', 'Toys 10', '＋ New category…'], option_labels($form, 'category_id'), 'category dropdown naturally sorted');
T::eq(null, selected_option($form, 'location_id'), 'no default location yet');

T::section('Defaults');
T::eq(303, $c->post('/locations/' . $ids['loc']['box 1023'] . '/default', ['csrf' => $tok, 'on' => '1'])['status'], 'set default location');
$c->post('/categories/' . $ids['cat']['Electronics'] . '/default', ['csrf' => $tok, 'on' => '1']);
$c->post('/categories/' . $ids['cat']['Tools'] . '/default', ['csrf' => $tok, 'on' => '1']);
T::eq(1, (int)$db->query('SELECT COUNT(*) FROM categories WHERE is_default = 1')->fetchColumn(), 'only one default category');
$form = $c->get('/new')['body'];
T::eq('box 1023 ★', selected_option($form, 'location_id'), 'new item preselects default location');
T::eq('Tools ★', selected_option($form, 'category_id'), 'new item preselects default category');
$form = $c->get('/new?loc=' . $ids['loc']['box 2'] . '&cat=' . $ids['cat']['Toys 2'])['body'];
T::ok(selected_option($form, 'location_id') === 'box 2' && selected_option($form, 'category_id') === 'Toys 2', '?loc=&cat= override defaults');
$c->post('/categories/' . $ids['cat']['Tools'] . '/default', ['csrf' => $tok, 'on' => '0']);
T::eq(null, selected_option($c->get('/new')['body'], 'category_id'), 'unset default → no category preselected');
$c->post('/categories/' . $ids['cat']['Tools'] . '/default', ['csrf' => $tok, 'on' => '1']);

T::section('Create items + uploads');
$big = img_jpeg(4000, 3000);
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'Cordless drill', 'location_id' => $ids['loc']['box 1023'], 'category_id' => $ids['cat']['Tools'], 'quantity' => 1], ['photo' => jpg_file($big)]);
$drill = $savedId($r);
T::ok($r['status'] === 200 && $drill > 0, 'create item with 4000×3000 JPEG', $r['body']);
$img = $db->query("SELECT image FROM items WHERE id = $drill")->fetchColumn();
T::ok(is_string($img) && preg_match('/^[a-f0-9]{32}\.jpg$/', $img) === 1, 'random hex file name');
[$w, $h] = getimagesize("$dir/images/$img") ?: [0, 0];
T::ok(max($w, $h) === 1600, "full image downscaled to 1600px ({$w}×{$h})");
[$tw, $th] = getimagesize("$dir/thumbs/$img") ?: [0, 0];
T::ok(max($tw, $th) === 480, "thumbnail 480px ({$tw}×{$th})");
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'Πρίζα πολύπριζο', 'location_id' => $ids['loc']['Κουτί 5'], 'category_id' => $ids['cat']['Electronics'], 'quantity' => 3, 'notes' => 'Ελληνικά'], ['photo2' => jpg_file(img_png(800, 600), 'p.png', 'image/png')]);
$plug = $savedId($r);
T::ok($plug > 0 && is_file("$dir/images/" . $db->query("SELECT image FROM items WHERE id = $plug")->fetchColumn()), 'PNG via library field (photo2) stored as JPEG');
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'HDMI cables', 'location_id' => '', 'category_id' => '']);
$hdmi = $savedId($r);
T::eq(1, (int)$db->query("SELECT quantity FROM items WHERE id = $hdmi")->fetchColumn(), 'default quantity 1, no photo, no location/category');
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'Evil'], ['photo' => jpg_file('<?php system($_GET["c"]); ?>', 'evil.jpg')]);
T::ok($r['status'] === 422 && str_contains((string)$r['json']['error'], 'Unsupported image'), 'non-image upload rejected');
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'Huge'], ['photo' => jpg_file(random_bytes(21 * 1024 * 1024), 'huge.jpg')]);
T::ok($r['status'] === 422 && str_contains((string)$r['json']['error'], 'too large'), 'file over 20 MB rejected', (string)$r['body']);
T::eq(422, $c->api('/save', ['csrf' => $tok, 'name' => '  '])['status'], 'missing name rejected');
T::eq(422, $c->api('/save', ['csrf' => $tok, 'name' => 'X', 'location_id' => '9999'])['status'], 'unknown location id rejected');
T::eq(422, $c->api('/save', ['csrf' => $tok, 'name' => 'X', 'category_id' => '__new', 'new_category' => ''])['status'], '"＋ New category" without a name rejected');
T::eq(0, count(glob("$dir/images/*")) - 2, 'rejected uploads left no files behind');
if (function_exists('exif_read_data')) {
    $r = $c->api('/save', ['csrf' => $tok, 'name' => 'Rotated'], ['photo' => jpg_file(img_jpeg(400, 200, 90, 6))]);
    $rid = $savedId($r);
    [$w, $h] = getimagesize("$dir/images/" . $db->query("SELECT image FROM items WHERE id = $rid")->fetchColumn());
    T::ok($w === 200 && $h === 400, "EXIF orientation 6 applied (stored {$w}×{$h})");
    $c->post("/item/$rid/delete", ['csrf' => $tok]);
} else {
    fwrite(STDOUT, "  - skipped EXIF orientation test (exif extension not loaded)\n");
}
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'Stove', 'location_id' => '__new', 'new_location' => 'Basement rack', 'category_id' => '__new', 'new_category' => 'Kitchen']);
$stove = $savedId($r);
$row = $db->query("SELECT l.name AS l, c.name AS c FROM items i JOIN locations l ON l.id = i.location_id JOIN categories c ON c.id = i.category_id WHERE i.id = $stove")->fetch();
T::ok($row && $row['l'] === 'Basement rack' && $row['c'] === 'Kitchen', 'typed-but-not-added location + category created on save');
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'Nails', 'location_id' => $ids['loc']['box 10'], 'category_id' => $ids['cat']['Tools'], 'after' => 'another']);
T::ok(preg_match('#^/new\?loc=' . $ids['loc']['box 10'] . '&cat=' . $ids['cat']['Tools'] . '&saved=\d+$#', (string)$r['json']['redirect']) === 1, '"Save & add another" keeps location + category', (string)$r['json']['redirect']);
$nails = $savedId($r);
$r = $c->post('/save', ['csrf' => $tok, 'name' => 'No-JS item', 'location_id' => $ids['loc']['box 2']]);
T::ok($r['status'] === 303 && str_starts_with($r['headers']['location'] ?? '', '/?saved='), 'plain form POST (no JS) redirects 303');
$nojs = $savedId($r);
$r = $c->post('/save', ['csrf' => $tok, 'name' => '<script>alert(1)</script>', 'notes' => '"><img src=x onerror=alert(1)>']);
$xss = $savedId($r);
$c->api('/categories', ['csrf' => $tok, 'name' => '<svg onload=alert(1)>']);
$bad = 0;
foreach (['/', '/new', "/item/$xss", '/categories', '/stats'] as $p) $bad += preg_match('/<script>alert|<img src=x|<svg onload/', $c->get($p)['body']);
T::eq(0, $bad, 'user input is HTML-escaped everywhere');
$c->post("/item/$xss/delete", ['csrf' => $tok]);
$xcat = (int)$db->query("SELECT id FROM categories WHERE name LIKE '<svg%'")->fetchColumn();
$c->post("/categories/$xcat/delete", ['csrf' => $tok, 'action' => 'unassign']);

T::section('Search, filters, sort');
$s = fn(string $qs): array => titles($c->json('/?' . $qs));
T::eq(['Cordless drill'], $s('q=DRILL'), 'search by name (case-insensitive)');
T::eq(['Πρίζα πολύπριζο'], $s('q=' . rawurlencode('πριζα')), 'search Greek without accents');
T::eq(['Πρίζα πολύπριζο'], $s('q=' . rawurlencode('κουτι')), 'search matches location name');
T::eq(['Stove'], $s('q=kitchen'), 'search matches category name');
T::eq(['Cordless drill'], $s('q=' . rawurlencode('tools 1023')), 'multi-word: category + location');
T::eq([], $s('q=%25'), 'LIKE wildcard % is escaped');
T::eq(['Cordless drill'], $s('loc=' . $ids['loc']['box 1023']), 'filter by location');
T::eq(['Nails', 'Cordless drill'], $s('cat=' . $ids['cat']['Tools']), 'filter by category');
T::eq(['HDMI cables'], $s('loc=none&cat=none'), 'filter "No location" + "No category"');
T::eq(['Nails'], $s('loc=' . $ids['loc']['box 10'] . '&cat=' . $ids['cat']['Tools']), 'combined location + category filter');
T::eq(['Stove', 'No-JS item', 'Nails', 'Cordless drill', 'Πρίζα πολύπριζο', 'HDMI cables'], $s('sort=location'), 'sort by location (natural, case-insensitive, unassigned last)');
T::eq(['Πρίζα πολύπριζο', 'Stove', 'Cordless drill', 'Nails', 'HDMI cables', 'No-JS item'], $s('sort=category'), 'sort by category (Electronics, Kitchen, Tools, uncategorized last)');
$j = $c->json('/?q=drill')['json'];
T::ok($j['total'] === 1 && $j['count'] === '1 item found' && $j['more'] === false, 'JSON search payload (total/count/more)');

T::section('Quantity');
foreach ([[1, 2], [1, 3], [-1, 2], [-10, 0], [5, 5]] as [$d, $want]) {
    T::eq($want, $c->api("/item/$drill/qty", ['csrf' => $tok, 'delta' => $d])['json']['quantity'] ?? null, "delta $d → $want");
}
T::eq(404, $c->api('/item/99999/qty', ['csrf' => $tok, 'delta' => 1])['status'], 'unknown item → 404');

T::section('Edit');
$old = $db->query("SELECT image FROM items WHERE id = $drill")->fetchColumn();
$r = $c->api('/save', ['csrf' => $tok, 'id' => $drill, 'name' => 'Cordless drill 18V', 'location_id' => $ids['loc']['box 2'], 'category_id' => $ids['cat']['Tools'], 'quantity' => 2, 'notes' => 'Makita'], ['photo' => jpg_file(img_jpeg(1000, 800))]);
T::eq("/item/$drill", $r['json']['redirect'] ?? null, 'edit redirects to detail');
$row = $db->query("SELECT name, location_id, quantity, notes, image FROM items WHERE id = $drill")->fetch();
T::ok($row['name'] === 'Cordless drill 18V' && (int)$row['location_id'] === $ids['loc']['box 2'] && (int)$row['quantity'] === 2 && $row['notes'] === 'Makita', 'fields updated');
T::ok($row['image'] !== $old && !is_file("$dir/images/$old") && !is_file("$dir/thumbs/$old") && is_file("$dir/images/{$row['image']}"), 'photo replaced, old files deleted');
$c->api('/save', ['csrf' => $tok, 'id' => $plug, 'name' => 'Πρίζα πολύπριζο', 'location_id' => $ids['loc']['Κουτί 5'], 'category_id' => $ids['cat']['Electronics'], 'quantity' => 3, 'remove_image' => '1']);
T::eq(null, $db->query("SELECT image FROM items WHERE id = $plug")->fetchColumn(), 'photo removed');
$detail = $c->get("/item/$drill")['body'];
T::ok(str_contains($detail, 'Cordless drill 18V') && str_contains($detail, 'chip-category') && str_contains($detail, 'Makita'), 'detail page shows name, category chip, notes');
$edit = $c->get("/item/$drill/edit")['body'];
T::ok(selected_option($edit, 'location_id') === 'box 2' && selected_option($edit, 'category_id') === 'Tools ★', 'edit form preselects item location/category');

T::section('Media');
$img = $db->query("SELECT image FROM items WHERE id = $drill")->fetchColumn();
$r = $c->get("/media/thumb/$img");
T::ok($r['status'] === 200 && ($r['headers']['content-type'] ?? '') === 'image/jpeg', 'thumbnail served as image/jpeg');
T::eq(304, $c->get("/media/thumb/$img", ['If-None-Match: ' . ($r['headers']['etag'] ?? '')])['status'], 'ETag revalidation → 304');
foreach (['/media/full/..%2F..%2Fcatalog.sqlite', '/media/thumb/abc.php', '/media/full/' . str_repeat('a', 32) . '.jpg'] as $p) T::eq(404, $c->get($p)['status'], "GET $p → 404");

T::section('Locations/categories: rename & delete');
T::eq(303, $c->post('/locations/' . $ids['loc']['box 10'] . '/rename', ['csrf' => $tok, 'name' => 'Box 11'])['status'], 'rename location');
T::eq(['Nails'], $s('q=' . rawurlencode('box 11')), 'search follows rename');
T::ok(in_array($c->api('/categories/' . $ids['cat']['Toys 2'] . '/rename', ['csrf' => $tok, 'name' => 'tools'])['status'], [409, 422], true), 'rename to existing name refused');
T::eq('Toys 2', $db->query('SELECT name FROM categories WHERE id = ' . $ids['cat']['Toys 2'])->fetchColumn(), '…name unchanged');
T::eq(303, $c->post('/categories/' . $ids['cat']['Toys 2'] . '/rename', ['csrf' => $tok, 'name' => 'toys 2'])['status'], 'case-only rename allowed');
$tools = $ids['cat']['Tools'];
$r = $c->post("/categories/$tools/delete", ['csrf' => $tok]);
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === "/categories/$tools/delete", 'delete with items and no choice → confirm page');
T::eq(1, (int)$db->query("SELECT COUNT(*) FROM categories WHERE id = $tools")->fetchColumn(), '…and nothing was deleted');
$page = $c->get("/categories/$tools/delete")['body'];
T::ok(str_contains($page, 'value="move"') && str_contains($page, 'value="unassign"') && str_contains($page, '2 items are'), 'confirm page offers move / unassign');
T::eq(422, $c->api("/categories/$tools/delete", ['csrf' => $tok, 'action' => 'move', 'target' => $tools])['status'], 'move to itself refused');
$kitchen = (int)$db->query("SELECT id FROM categories WHERE name = 'Kitchen'")->fetchColumn();
$c->post("/categories/$tools/delete", ['csrf' => $tok, 'action' => 'move', 'target' => $kitchen]);
T::eq(3, (int)$db->query("SELECT COUNT(*) FROM items WHERE category_id = $kitchen")->fetchColumn(), 'items moved to Kitchen');
T::eq(0, (int)$db->query('SELECT COUNT(*) FROM categories WHERE is_default = 1')->fetchColumn(), 'deleting the default leaves no default');
$c->post('/locations/' . $ids['loc']['Κουτί 5'] . '/delete', ['csrf' => $tok, 'action' => 'unassign']);
T::eq(null, $db->query("SELECT location_id FROM items WHERE id = $plug")->fetchColumn(), 'delete location + unassign keeps the item');
T::eq(6, (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'no items lost');
if (test_driver() === 'sqlite') T::eq([], $db->query('PRAGMA foreign_key_check')->fetchAll(), 'foreign keys consistent');

T::section('Overview statistics');
$r = $c->get('/stats');
preg_match_all('#<div class="tile"><b>([\d,]+)</b><span>([^<]+)#', $r['body'], $m);
$tiles = array_combine($m[2], array_map('intval', $m[1]));
$tot = $db->query('SELECT COUNT(*) n, SUM(quantity) q FROM items')->fetch();
T::ok($tiles['Items'] === (int)$tot['n'] && $tiles['Total qty'] === (int)$tot['q'], 'totals match DB (' . json_encode($tiles) . ')');
T::ok($tiles['Locations'] === (int)$db->query('SELECT COUNT(*) FROM locations')->fetchColumn() && $tiles['Categories'] === (int)$db->query('SELECT COUNT(*) FROM categories')->fetchColumn(), 'location/category counts');
T::ok($tiles['This week'] === (int)$tot['n'] && $tiles['This month'] === (int)$tot['n'], 'all items added this week/month');
T::eq(42, preg_match_all('/<rect class="bar/', $r['body']), '30 daily + 12 monthly bars');
T::ok(str_contains($r['body'], '<h3>By location</h3>') && str_contains($r['body'], '<h3>By category</h3>') && str_contains($r['body'], 'data-cat="' . $kitchen . '"'), 'by-location and by-category charts with filter links');
$c->cookies['stats_open'] = '1';
T::ok(str_contains($c->get('/')['body'], 'class="tiles"'), 'Overview open → rendered inline');
$c->cookies['stats_open'] = '0';
$list = $c->get('/')['body'];
T::ok(str_contains($list, 'stats-box') && !str_contains($list, 'class="tiles"'), 'Overview collapsed → charts not computed');
unset($c->cookies['stats_open']);

T::section('CSV export & misc');
$r = $c->get('/export.csv');
T::ok(str_contains($r['headers']['content-disposition'] ?? '', 'filename="apotheke-'), 'CSV filename apotheke-<date>.csv');
$lines = array_map('str_getcsv', array_filter(explode("\n", ltrim($r['body'], "\xEF\xBB\xBF"))));
T::eq(['id', 'name', 'location', 'category', 'quantity', 'notes', 'image', 'created_at', 'updated_at', 'tags', 'value', 'currency', 'purchase_date', 'serial', 'min_qty', 'lent_to', 'lent_at', 'lent_due', 'expires_on'], $lines[0], 'CSV header');
T::eq(7, count($lines), 'CSV has header + one row per item');
T::eq(404, $c->get('/nope')['status'], 'unknown route → 404');
T::eq(404, $c->get('/item/' . $nojs . '/delete')['status'], 'GET on delete route does nothing');

T::section('Theme preference (Auto / Light / Dark)');
$r = $c->get('/');
T::ok(str_contains($r['body'], '<html lang="en" data-theme="auto">'), 'default theme is auto');
T::ok(substr_count($r['body'], 'name="theme-color"') === 2 && str_contains($r['body'], 'media="(prefers-color-scheme: dark)"'), 'auto: light + dark theme-color metas');
T::ok(preg_match('#<script src="/assets/theme\.js\?v=[0-9a-f]+"></script>#', $r['body']) === 1, 'external theme.js loaded in <head> (no inline script, CSP-safe)');
T::ok(!preg_match('#<script(?![^>]*\bsrc=)[^>]*>#i', $r['body']), 'no inline <script> blocks');
T::ok(str_contains($r['body'], 'id="menu-btn"') && substr_count($r['body'], 'name="theme" value=') === 3, 'menu with 3 theme options');
T::ok(str_contains($r['body'], 'value="auto" role="radio" aria-checked="true"'), 'auto marked as checked');
T::eq(419, $c->post('/theme', ['theme' => 'dark'])['status'], 'POST /theme without CSRF → 419');
$r = $c->post('/theme', ['csrf' => $tok, 'theme' => 'dark', 'back' => '/categories']);
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === '/categories', 'no-JS theme form redirects back');
T::eq('dark', $c->cookies['theme'] ?? null, 'theme cookie set to dark');
$r = $c->get('/');
T::ok(str_contains($r['body'], 'data-theme="dark"') && str_contains($r['body'], '<meta name="theme-color" content="#111113">') && substr_count($r['body'], 'name="theme-color"') === 1, 'dark: server renders data-theme + single dark theme-color (no flash)');
T::ok(str_contains($r['body'], '<meta name="color-scheme" content="dark">'), 'dark: color-scheme meta');
T::ok(str_contains($r['body'], 'value="dark" role="radio" aria-checked="true"'), 'dark marked as checked');
$r = $c->api('/theme', ['csrf' => $tok, 'theme' => 'light']);
T::ok($r['json']['ok'] === true && $r['json']['theme'] === 'light', 'JSON theme switch');
T::ok(str_contains($c->get('/item/' . $nojs)['body'], 'data-theme="light"'), 'theme applies on every page');
$r = $c->api('/theme', ['csrf' => $tok, 'theme' => 'neon']);
T::eq('auto', $r['json']['theme'], 'unknown theme falls back to auto');
foreach (['//evil.example/x', 'https://evil.example', '/\\evil.example'] as $bad) {
    $r = $c->post('/theme', ['csrf' => $tok, 'theme' => 'auto', 'back' => $bad]);
    T::eq('/', $r['headers']['location'] ?? '', "open redirect blocked: $bad");
}
$c->cookies['theme'] = '"><script>';
T::ok(str_contains($c->get('/')['body'], 'data-theme="auto"'), 'garbage theme cookie ignored');
$c->cookies['theme'] = 'auto';

T::section('Mobile filter sheet & active chips');
$r = $c->get('/');
T::ok(str_contains($r['body'], 'id="fsheet"') && str_contains($r['body'], 'id="filter-open"'), 'filter sheet rendered');
T::ok(str_contains($r['body'], 'id="fcount" hidden'), 'no active-filter badge without filters');
T::eq('', trim((string)preg_replace('/.*<div class="achips" id="achips">(.*?)<\/div>.*/s', '$1', $r['body'])), 'no chips without filters');
$box2 = $ids['loc']['box 2']; $tools = $ids['cat']['Tools'];
$r = $c->get("/?loc=$box2&cat=$tools&sort=name&q=x");
T::ok(preg_match('#id="fcount">3</b>#', $r['body']) === 1, 'badge counts 3 active filters (loc, cat, sort)');
T::ok(str_contains($r['body'], 'data-clear="loc"') && str_contains($r['body'], '>box 2<'), 'location chip');
T::ok(str_contains($r['body'], 'data-clear="cat"') && str_contains($r['body'], '>' . htmlspecialchars((string)$db->query("SELECT name FROM categories WHERE id = $tools")->fetchColumn()) . '<'), 'category chip');
T::ok(str_contains($r['body'], 'Sort: Name'), 'sort chip');
T::ok(str_contains($r['body'], 'href="/?q=x&amp;cat=' . $tools . '&amp;sort=name" data-clear="loc"'), 'chip link removes only its own filter (keeps q)');
T::ok(str_contains($r['body'], 'data-clear="all">Clear all'), 'clear-all chip');
T::ok(str_contains($r['body'], '<label class="field"><span>Location</span><select name="loc" id="loc">'), 'filter selects are labelled');
$j = $c->json("/?loc=$box2")['json'];
T::ok(isset($j['chips'], $j['nfilters']) && $j['nfilters'] === 1 && str_contains($j['chips'], 'data-clear="loc"'), 'JSON search returns chips + filter count');
$r = $c->get('/?loc=none');
T::ok(str_contains($r['body'], 'No location'), '"No location" chip label');
$r = $c->get('/?q=zzzznomatch');
T::ok(str_contains($r['body'], 'id="empty-clear" href="/">') && str_contains($r['body'], 'id="empty-add" href="/new" hidden'), 'filtered empty state offers "Clear", not "Add first"');
$r = $c->get('/item/' . $nojs);
T::ok(str_contains($r['body'], 'class="actionbar"') && str_contains($r['body'], 'href="/item/' . $nojs . '/edit"><svg'), 'item page sticky action bar with Edit');
$r = $c->get('/new');
T::ok(str_contains($r['body'], 'actionbar actionbar-form') && str_contains($r['body'], 'id="save-another"'), 'form sticky action bar with both save buttons');

T::section('QR labels');
$r = $c->get('/labels');
T::eq(200, $r['status'], 'labels sheet for all locations');
T::eq(count($db->query('SELECT id FROM locations')->fetchAll()), substr_count($r['body'], '<figure class="label"'), 'one label per location');
T::ok(str_contains($r['body'], 'data-print') && str_contains($r['body'], 'id="labels"'), 'print button + label grid');
T::ok(preg_match('#<svg class="qr"[^>]*viewBox="0 0 \d+ \d+"#', $r['body']) === 1, 'inline SVG QR codes (no external requests)');
$r = $c->get("/labels?loc=$box2");
T::eq(1, substr_count($r['body'], '<figure class="label"'), 'single location label');
T::ok(str_contains($r['body'], $c->base . '/?loc=' . $box2), 'label URL opens the location-filtered list (default base = request host)');
preg_match('#<svg class="qr".*?</svg>#s', $r['body'], $qm);
$dec = qr_decode_svg($qm[0] ?? '');
if ($dec !== null) T::eq($c->base . '/?loc=' . $box2, $dec, 'QR decodes to the location URL (zbarimg)');
$n2 = (int)$db->query("SELECT COUNT(*) FROM items WHERE location_id = $box2")->fetchColumn();
T::eq($n2, substr_count($c->get("/labels?loc=$box2&items=1")['body'], '<figure class="label"'), 'one label per item in the location');
$r = $c->get('/item/' . $nojs . '/label');
T::ok($r['status'] === 200 && str_contains($r['body'], $c->base . '/item/' . $nojs), 'item label links to the item page');
T::ok(str_contains($c->get('/item/' . $nojs)['body'], 'href="/item/' . $nojs . '/label"'), 'item page links to its label');
T::ok(str_contains($c->get('/locations')['body'], 'href="/labels?loc='), 'manage page links to location labels');
T::eq(404, $c->get('/labels?loc=999999')['status'], 'unknown location → 404');
T::eq(404, $c->get('/item/999999/label')['status'], 'unknown item → 404');
$us = new Server($dir, ['PUBLIC_URL' => 'https://stuff.example.lan/']);
$r = $us->client()->get("/labels?loc=$box2");
T::ok(str_contains($r['body'], 'https://stuff.example.lan/?loc=' . $box2), 'PUBLIC_URL is used for label links (trailing slash trimmed)');
preg_match('#<svg class="qr".*?</svg>#s', $r['body'], $qm);
if (($dec = qr_decode_svg($qm[0] ?? '')) !== null) T::eq('https://stuff.example.lan/?loc=' . $box2, $dec, 'QR encodes the PUBLIC_URL link');
$us->stop();
$r = $c->get('/', ['Host: evil"><script>']);
T::ok(!str_contains($c->get('/labels', ['Host: evil"><x>'])['body'], 'evil"><x>'), 'malformed Host header is not echoed into labels');

T::section('Tags & custom fields');
$r = $c->api('/save', ['csrf' => $tok, 'id' => $hdmi, 'name' => 'HDMI cables', 'tags' => 'Cables, #AV;  cables , Ηλεκτρικά', 'value' => '1.234,50', 'purchase_date' => '2024-03-01', 'serial' => 'SN-42/X']);
T::eq(200, $r['status'], 'save tags + custom fields', $r['body']);
$row = $db->query("SELECT value_cents, purchase_date, serial FROM items WHERE id = $hdmi")->fetch();
T::ok((int)$row['value_cents'] === 123450 && $row['purchase_date'] === '2024-03-01' && $row['serial'] === 'SN-42/X', 'value (1.234,50 → 123450 cents), date, serial stored');
T::eq(['AV', 'Cables', 'Ηλεκτρικά'], $db->query("SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = $hdmi ORDER BY t.sort_key")->fetchAll(PDO::FETCH_COLUMN), 'tags parsed, # stripped, duplicates folded');
$c->api('/save', ['csrf' => $tok, 'id' => $nails, 'name' => 'Nails', 'location_id' => $ids['loc']['box 10'], 'tags' => 'CABLES, hardware']);
$tagId = (int)$db->query("SELECT id FROM tags WHERE name_key = 'cables'")->fetchColumn();
T::eq(1, (int)$db->query("SELECT COUNT(*) FROM tags WHERE name_key = 'cables'")->fetchColumn(), 'same tag reused case-insensitively');
$page = $c->get("/item/$hdmi")['body'];
T::ok(str_contains($page, 'href="/?tag=' . $tagId . '"') && str_contains($page, '#Ηλεκτρικά'), 'item page shows tag chips linking to the tag filter');
T::ok(str_contains($page, '1,234.50 EUR') && str_contains($page, '2024-03-01') && str_contains($page, 'SN-42/X'), 'item page shows value with currency, date, serial');
T::ok(!str_contains($c->get("/item/$stove")['body'], 'id="facts"'), 'no empty custom-field block');
T::eq([$hdmi, $nails], ids_from_html($c->json('/?tag=' . $tagId . '&sort=name')['json']['html'] ?? ''), 'tag filter');
$j = $c->json('/?tag=' . $tagId)['json'];
T::ok(str_contains($j['chips'], 'data-clear="tag"') && str_contains($j['chips'], 'Cables') && $j['nfilters'] === 1, 'active tag chip + filter count');
T::ok(str_contains($c->get('/')['body'], 'id="tag"'), 'tag filter in the filter sheet');
T::eq([$hdmi], ids_from_html($c->json('/?q=sn-42')['json']['html']), 'search finds serial numbers');
T::eq([$hdmi], ids_from_html($c->json('/?q=' . rawurlencode('ηλεκτρικα'))['json']['html']), 'search finds tags (accent-insensitive)');
$edit = $c->get("/item/$hdmi/edit")['body'];
T::ok(str_contains($edit, 'value="AV, Cables, Ηλεκτρικά"') && str_contains($edit, 'value="1234.50"') && str_contains($edit, 'datalist id="tag-list"'), 'edit form prefilled + tag suggestions');
foreach ([['value' => '12.345'], ['value' => '-5'], ['value' => 'abc'], ['purchase_date' => '2024-02-30'], ['purchase_date' => '2999-01-01'], ['tags' => implode(',', range(1, 21))]] as $bad) {
    T::eq(422, $c->api('/save', ['csrf' => $tok, 'id' => $hdmi, 'name' => 'HDMI cables'] + $bad)['status'], 'rejects ' . json_encode($bad, JSON_UNESCAPED_UNICODE));
}
$c->api('/save', ['csrf' => $tok, 'id' => $nails, 'name' => 'Nails', 'location_id' => $ids['loc']['box 10'], 'tags' => 'cables']);
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM tags WHERE name_key = 'hardware'")->fetchColumn(), 'unused tags are removed');
$csv = array_map('str_getcsv', array_filter(explode("\n", ltrim($c->get('/export.csv')['body'], "\xEF\xBB\xBF"))));
$hrow = array_values(array_filter($csv, fn($l) => ($l[0] ?? '') === (string)$hdmi))[0] ?? [];
T::eq(['AV, Cables, Ηλεκτρικά', '1234.50', 'EUR', '2024-03-01', 'SN-42/X', '', '', '', '', ''], array_slice($hrow, 9), 'CSV includes tags, value, currency, date, serial');
$cs = new Server($dir, ['CURRENCY' => 'CHF']);
T::ok(str_contains($cs->client()->get("/item/$hdmi")['body'], '1,234.50 CHF'), 'CURRENCY config is shown');
$cs->stop();

T::section('Multiple photos');
$cover = $db->query("SELECT image FROM items WHERE id = $drill")->fetchColumn();
T::eq([$cover], $db->query("SELECT file FROM item_photos WHERE item_id = $drill ORDER BY position")->fetchAll(PDO::FETCH_COLUMN), 'saved photo is the first gallery photo');
$r = $c->api("/item/$drill/photos", ['csrf' => $tok], ['photos[0]' => jpg_file(img_jpeg(900, 600)), 'photos[1]' => jpg_file(img_png(300, 300), 'b.png', 'image/png')]);
T::ok($r['status'] === 200 && ($r['json']['added'] ?? 0) === 2 && ($r['json']['count'] ?? 0) === 3, 'upload two photos at once (JSON)', $r['body']);
$pics = $db->query("SELECT id, file FROM item_photos WHERE item_id = $drill ORDER BY position")->fetchAll();
T::ok(count($pics) === 3 && is_file("$dir/images/{$pics[2]['file']}") && is_file("$dir/thumbs/{$pics[2]['file']}"), 'photos stored with thumbnails');
$r = $c->post("/item/$drill/photos", ['csrf' => $tok], ['photo' => jpg_file(img_jpeg(300, 200))]);
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === "/item/$drill?p=3#photos", 'no-JS upload redirects to the new photo', $r['headers']['location'] ?? '');
$page = $c->get("/item/$drill")['body'];
T::ok(str_contains($page, 'id="gal-next"') && str_contains($page, 'href="/item/' . $drill . '?p=1"') && str_contains($page, '1 / 4'), 'gallery arrows + counter');
T::eq(4, substr_count($page, 'data-full="/media/full/'), 'thumbnail strip lists every photo');
$page = $c->get("/item/$drill?p=2")['body'];
T::ok(str_contains($page, '3 / 4') && str_contains($page, 'src="/media/full/' . $pics[2]['file'] . '"'), '?p= selects the photo without JS');
$r = $c->api("/item/$drill/photos/{$pics[2]['id']}/cover", ['csrf' => $tok]);
T::ok(($r['json']['index'] ?? null) === 0 && $db->query("SELECT image FROM items WHERE id = $drill")->fetchColumn() === $pics[2]['file'], 'set cover updates items.image (list thumbnail)');
T::ok(str_contains($c->get('/')['body'], '/media/thumb/' . $pics[2]['file']), 'list shows the new cover');
$r = $c->post("/item/$drill/photos/{$pics[2]['id']}/right", ['csrf' => $tok]);
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === "/item/$drill?p=1#photos", 'move right (no JS)');
T::eq($cover, $db->query("SELECT image FROM items WHERE id = $drill")->fetchColumn(), 'moving the cover away promotes the next photo');
$c->api("/item/$drill/photos/{$pics[2]['id']}/left", ['csrf' => $tok]);
T::eq($pics[2]['file'], $db->query("SELECT file FROM item_photos WHERE item_id = $drill AND position = 0")->fetchColumn(), 'move left');
$r = $c->api("/item/$drill/photos/{$pics[1]['id']}/delete", ['csrf' => $tok]);
T::ok(($r['json']['count'] ?? 0) === 3 && !is_file("$dir/images/{$pics[1]['file']}") && !is_file("$dir/thumbs/{$pics[1]['file']}"), 'delete photo removes its files');
T::eq([0, 1, 2], array_map('intval', $db->query("SELECT position FROM item_photos WHERE item_id = $drill ORDER BY position")->fetchAll(PDO::FETCH_COLUMN)), 'positions renumbered');
T::eq(419, $c->api("/item/$drill/photos/{$pics[0]['id']}/cover", [])['status'], 'photo actions need CSRF');
T::eq(404, $c->api("/item/$hdmi/photos/{$pics[0]['id']}/cover", ['csrf' => $tok])['status'], "another item's photo → 404");
T::eq(404, $c->api("/item/999999/photos", ['csrf' => $tok], ['photo' => jpg_file(img_jpeg(10, 10))])['status'], 'unknown item → 404');
T::eq(422, $c->api("/item/$drill/photos", ['csrf' => $tok])['status'], 'no file → 422');
T::eq(422, $c->api("/item/$drill/photos", ['csrf' => $tok], ['photo' => jpg_file('nope', 'x.jpg')])['status'], 'non-image → 422');
T::ok(!str_contains($c->get("/item/$hdmi")['body'], 'id="gallery"') && str_contains($c->get("/item/$hdmi")['body'], 'id="photoadd"'), 'item without photos: add-photo form, no gallery');
$allPics = $db->query("SELECT file FROM item_photos WHERE item_id = $drill")->fetchAll(PDO::FETCH_COLUMN);

T::section('Backup & restore');
T::ok(str_contains($c->get('/')['body'], 'href="/backup"'), 'menu links to Backup & restore');
$r = $c->get('/backup');
T::ok($r['status'] === 200 && str_contains($r['body'], 'href="/backup.zip"') && str_contains($r['body'], 'id="restore-form"'), 'backup page: download + restore form');
$r = $c->get('/backup.zip');
T::ok($r['status'] === 200 && ($r['headers']['content-type'] ?? '') === 'application/zip' && str_contains($r['headers']['content-disposition'] ?? '', 'apotheke-backup-'), 'download backup zip');
$bk = tmpdir('apotheke-bkzip') . '/b.zip';
$tmpDirs[] = dirname($bk);
file_put_contents($bk, $r['body']);
$z = new ZipArchive();
T::ok($z->open($bk) === true && $z->locateName('backup.json') !== false, 'zip contains backup.json');
$dump = json_decode((string)$z->getFromName('backup.json'), true);
$pFiles = $db->query('SELECT file FROM item_photos')->fetchAll(PDO::FETCH_COLUMN);
T::ok(($dump['format'] ?? '') === 'apotheke-backup' && count($dump['tables']['items']['rows']) === (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'dump has every item');
T::ok($pFiles && array_filter($pFiles, fn($f) => $z->locateName("images/$f") === false || $z->locateName("thumbs/$f") === false) === [], 'every photo + thumbnail is in the zip');
$z->close();
T::eq([], glob("$dir/tmp/dl-*") ?: [], 'temporary download file removed');
$itemsBefore = $db->query('SELECT id, name, quantity FROM items ORDER BY id')->fetchAll();
$c->post("/item/$stove/delete", ['csrf' => $tok]);
$c->api("/item/$hdmi/qty", ['csrf' => $tok, 'delta' => 5]);
$zipUp = fn(string $data, string $name = 'b.zip') => ['backup' => ['name' => $name, 'type' => 'application/zip', 'data' => $data]];
$r = $c->post('/backup/restore', ['csrf' => $tok], $zipUp(file_get_contents($bk)));
T::ok($r['status'] === 200 && str_contains($r['body'], 'id="restore-preview"') && str_contains($r['body'], 'name="token"'), 'upload shows a dry-run summary with confirm form', substr($r['body'], 0, 300));
T::ok(str_contains($r['body'], '<td data-t="items"><b>' . count($itemsBefore) . '</b>'), 'summary shows the item count in the backup');
T::eq(count($itemsBefore) - 1, (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'dry run changed nothing');
preg_match('/name="token" value="([a-f0-9]{32})"/', $r['body'], $tm);
$token = $tm[1] ?? '';
T::eq(422, $c->post('/backup/restore/confirm', ['csrf' => $tok, 'token' => $token])['status'], 'restore needs the confirm box');
T::eq(419, $c->post('/backup/restore/confirm', ['token' => $token, 'confirm' => '1'])['status'], 'restore needs CSRF');
$r = $c->post('/backup/restore/confirm', ['csrf' => $tok, 'token' => $token, 'confirm' => '1']);
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === '/backup?restored=1', 'confirmed restore redirects', $r['body']);
T::eq($itemsBefore, $db->query('SELECT id, name, quantity FROM items ORDER BY id')->fetchAll(), 'catalog is back to the backup state');
T::ok(str_contains($c->get('/backup?restored=1')['body'], 'Backup restored'), 'success toast');
T::eq(410, $c->post('/backup/restore/confirm', ['csrf' => $tok, 'token' => $token, 'confirm' => '1'])['status'], 'a restore token works only once');
T::eq(410, $c->post('/backup/restore/confirm', ['csrf' => $tok, 'token' => '../../catalog', 'confirm' => '1'])['status'], 'malformed token rejected');
$r = $c->post('/backup/restore', ['csrf' => $tok], $zipUp('PK not really', 'x.zip'));
T::ok($r['status'] === 422 && str_contains($r['body'], 'not a zip'), 'non-zip upload rejected');
T::eq([], glob("$dir/tmp/restore-*") ?: [], 'rejected upload removed');
T::eq(422, $c->post('/backup/restore', ['csrf' => $tok])['status'], 'no file → 422');
$n = (int)$db->query('SELECT COUNT(*) FROM items')->fetchColumn();
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'After restore']);
T::ok($savedId($r) > max(array_column($itemsBefore, 'id')), 'new ids continue after the restored ones');
$c->post('/item/' . $savedId($r) . '/delete', ['csrf' => $tok]);
// CLI
$env = array_merge(getenv(), ['DATA_DIR' => $dir, 'APOTHEKE_CONFIG' => '/nonexistent', 'SEED_DEFAULTS' => '0'], test_db_env($dir));
$run = function (array $args, string $stdin = '') use ($env): array {
    $p = proc_open(array_merge([PHP_BINARY], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env);
    fwrite($pipes[0], $stdin); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    return [proc_close($p), $out];
};
$cli = dirname($bk) . '/cli.zip';
[$code, $out] = $run(['bin/backup.php', $cli]);
T::ok($code === 0 && is_file($cli) && str_contains($out, "$n items"), 'bin/backup.php writes a zip', $out);
[$code, $out] = $run(['bin/restore.php', $cli, '--dry-run']);
T::ok($code === 0 && str_contains($out, 'Dry run: nothing changed'), 'bin/restore.php --dry-run', $out);
[$code, $out] = $run(['bin/restore.php', $cli], "no\n");
T::ok($code === 1 && str_contains($out, 'Aborted'), 'bin/restore.php asks before replacing', $out);
$c->post("/item/$nails/delete", ['csrf' => $tok]);
[$code, $out] = $run(['bin/restore.php', $cli, '--yes']);
T::ok($code === 0 && str_contains($out, 'Restored:') && item_exists_in($db, $nails), 'bin/restore.php --yes restores', $out);
[$code, $out] = $run(['bin/restore.php', __FILE__, '--dry-run']);
T::ok($code === 1 && str_contains($out, 'Invalid backup'), 'bin/restore.php rejects a non-backup', $out);

T::section('Activity history');
$hid = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'History item', 'location_id' => $ids['loc']['box 2'], 'quantity' => 2]));
$c->api("/item/$hid/qty", ['csrf' => $tok, 'delta' => 1]);
$c->api('/save', ['csrf' => $tok, 'id' => $hid, 'name' => 'History item v2', 'location_id' => $ids['loc']['box 10'], 'quantity' => 3, 'notes' => 'n', 'serial' => 'S1']);
$c->api("/item/$hid/photos", ['csrf' => $tok], ['photo' => jpg_file(img_jpeg(200, 100))]);
$acts = $db->query("SELECT action, details FROM activity WHERE item_id = $hid ORDER BY id")->fetchAll();
T::eq(['created', 'quantity', 'edited', 'moved', 'photo'], array_column($acts, 'action'), 'create, quantity, edit, move, photo logged in order');
T::eq(['name' => ['History item', 'History item v2'], 'notes' => ['', 'n'], 'serial' => ['', 'S1']], json_decode($acts[2]['details'], true)['changes'] ?? null, 'edit stores old → new per field');
$b10 = $db->query('SELECT name FROM locations WHERE id = ' . $ids['loc']['box 10'])->fetchColumn();
T::eq(['from' => 'box 2', 'to' => $b10], json_decode($acts[3]['details'], true), 'move stores both locations');
$page = $c->get("/item/$hid")['body'];
T::ok(str_contains($page, 'id="history"') && str_contains($page, 'Moved from <b>box 2</b> to <b>' . htmlspecialchars($b10) . '</b>') && str_contains($page, 'Quantity <b>2</b> → <b>3</b>'), 'item page shows its history');
T::ok(str_contains($page, '“History item” → “History item v2”'), 'item page shows field changes');
T::ok(!str_contains($page, 'id="hist-more"'), 'no "full history" link for short histories');
for ($i = 0; $i < 10; $i++) $c->api("/item/$hid/qty", ['csrf' => $tok, 'delta' => 1]);
$page = $c->get("/item/$hid")['body'];
T::eq(10, substr_count(explode('id="history"', $page)[1] ?? '', '<li class="act '), 'item page shows the latest 10 entries');
T::ok(str_contains($page, 'href="/activity?item=' . $hid . '"'), '"Full history" link');
$r = $c->get("/activity?item=$hid");
T::ok($r['status'] === 200 && substr_count($r['body'], '<li class="act ') === 15 && str_contains($r['body'], 'History item v2'), 'full per-item history');
$r = $c->get('/activity');
T::ok($r['status'] === 200 && str_contains($r['body'], 'href="/item/' . $hid . '"') && str_contains($r['body'], 'class="act-item"'), 'global recent activity links to items');
T::ok(str_contains($c->get('/')['body'], 'href="/activity"'), 'menu links to recent activity');
$total = (int)$db->query('SELECT COUNT(*) FROM activity')->fetchColumn();
T::eq(min(50, $total), substr_count($r['body'], '<li class="act '), 'first page shows up to 50 entries');
if ($total > 50) T::ok(str_contains($r['body'], 'href="/activity?page=2"') && substr_count($c->get('/activity?page=2')['body'], '<li class="act ') === min(50, $total - 50), 'older entries on page 2');
T::eq(404, $c->get('/activity?item=999999')['status'], 'unknown item history → 404');
$c->post("/item/$hid/delete", ['csrf' => $tok]);
$r = $c->get('/activity');
T::ok(str_contains($r['body'], 'act-item act-gone">History item v2') && str_contains($r['body'], 'act-deleted'), 'deleted item stays in the global history (not linked)');
T::eq(200, $c->get("/activity?item=$hid")['status'], 'history of a deleted item is still viewable');
$xid = $savedId($c->api('/save', ['csrf' => $tok, 'name' => '<img src=x onerror=alert(1)>']));
T::ok(!str_contains($c->get('/activity')['body'], '<img src=x'), 'item names are escaped in the history');
$c->post("/item/$xid/delete", ['csrf' => $tok]);
$bk2 = dirname($bk) . '/hist.zip';
file_put_contents($bk2, $c->get('/backup.zip')['body']);
$z = new ZipArchive(); $z->open($bk2);
T::eq($total + 3, count(json_decode((string)$z->getFromName('backup.json'), true)['tables']['activity']['rows'] ?? []), 'backup includes the activity log');
$z->close();

T::section('Low-stock alerts');
$lid = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'AA batteries', 'quantity' => 3, 'min_qty' => '2']));
T::eq(2, (int)$db->query("SELECT min_qty FROM items WHERE id = $lid")->fetchColumn(), 'min quantity stored');
T::eq(422, $c->api('/save', ['csrf' => $tok, 'name' => 'Bad min', 'min_qty' => '-1'])['status'], 'negative min quantity rejected');
T::eq(422, $c->api('/save', ['csrf' => $tok, 'name' => 'Bad min', 'min_qty' => 'abc'])['status'], 'non-numeric min quantity rejected');
T::ok(!in_array('AA batteries', titles($c->json('/?stock=low')), true), 'not low while quantity > min');
$r = $c->api("/item/$lid/qty", ['csrf' => $tok, 'delta' => -1]);
T::ok($r['json']['quantity'] === 2 && $r['json']['low'] === true, 'qty endpoint reports running low at quantity = min');
T::ok(in_array('AA batteries', titles($c->json('/?stock=low')), true), '?stock=low filter lists it');
$lowList = $c->json('/?stock=low')['json'];
T::ok(str_contains($lowList['chips'], 'data-clear="stock"') && $lowList['nfilters'] === 1, 'running-low chip + filter count');
T::ok(str_contains($lowList['html'], 'card is-low') && str_contains($lowList['html'], 'Running low'), 'card carries the Running low badge');
T::ok(str_contains($c->get("/item/$lid")['body'], 'class="lowbox is-low"'), 'detail page shows the badge');
T::ok(str_contains($c->get('/stats')['body'], 'tile-low has-low') && preg_match('#<b>(\d+)</b><span>Running low#', $c->get('/stats')['body'], $mm) === 1 && (int)$mm[1] >= 1, 'overview tile counts low items');
T::ok(str_contains($c->get('/')['body'], 'name="stock"'), 'filter sheet has a Stock select');
$c->api('/save', ['csrf' => $tok, 'id' => $lid, 'name' => 'AA batteries', 'quantity' => 2, 'min_qty' => '']);
T::eq(null, $db->query("SELECT min_qty FROM items WHERE id = $lid")->fetchColumn() ?: null, 'clearing the field removes the alert');
$last = json_decode((string)$db->query("SELECT details FROM activity WHERE item_id = $lid AND action = 'edited' ORDER BY id DESC")->fetchColumn(), true);
T::eq(['min_qty' => ['2', '']], $last['changes'] ?? null, 'history logs the min quantity change');
T::ok(str_contains($c->get("/item/$lid")['body'], 'Running low at'), 'history shows the field name');
$c->api('/save', ['csrf' => $tok, 'id' => $lid, 'name' => 'AA batteries', 'quantity' => 2, 'min_qty' => '5']);
$csvL = array_map('str_getcsv', array_filter(explode("\n", ltrim($c->get('/export.csv')['body'], "\xEF\xBB\xBF"))));
$arow = array_values(array_filter($csvL, fn($l) => ($l[0] ?? '') === (string)$lid))[0] ?? [];
T::eq('5', $arow[14] ?? null, 'CSV includes min_qty');
$bkL = sys_get_temp_dir() . '/apotheke-low-' . bin2hex(random_bytes(3)) . '.zip';
file_put_contents($bkL, $c->get('/backup.zip')['body']);
$z = new ZipArchive(); $z->open($bkL);
$it = json_decode((string)$z->getFromName('backup.json'), true)['tables']['items'];
$z->close(); @unlink($bkL);
$mi = array_search('min_qty', $it['columns'], true);
T::ok($mi !== false && in_array(5, array_column($it['rows'], $mi), true), 'backup includes min_qty');
$c->post("/item/$lid/delete", ['csrf' => $tok]);

T::section('Bulk actions');
$bA = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Bulk A', 'tags' => 'Keep']));
$bB = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Bulk B']));
$bC = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Bulk C', 'tags' => 'Keep, Gone']));
$shelf = (int)$db->query("SELECT id FROM locations ORDER BY id LIMIT 1")->fetchColumn();
$kitchen = (int)$db->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$sel = $c->get('/?select=1&q=Bulk')['body'];
T::ok(substr_count($sel, 'class="selbox"') === 3 && str_contains($sel, 'id="bulkform"') && !str_contains($sel, 'id="fab"'), 'selection mode renders checkboxes + bulk bar instead of the FAB');
T::ok(substr_count($c->get('/?select=1&q=Bulk&all=1')['body'], ' checked aria-label="Select') === 3, 'all=1 pre-selects every card (no-JS "All on page")');
T::ok(str_contains($c->json('/?select=1&q=Bulk')['json']['html'], 'class="selbox"'), 'instant search keeps the checkboxes');
T::ok(!str_contains($c->get('/?q=Bulk')['body'], 'class="selbox"'), 'no checkboxes outside selection mode');
T::eq(419, $c->post('/bulk', ['ids' => [$bA], 'action' => 'move', 'location_id' => $shelf])['status'], 'bulk requires CSRF');
$r = $c->post('/bulk', ['csrf' => $tok, 'ids' => [$bA, $bB, $bC], 'action' => 'move', 'location_id' => $shelf, 'back' => '/?select=1&q=Bulk']);
T::ok($r['status'] === 303 || $r['status'] === 302, 'move redirects');
T::ok(str_contains($r['headers']['location'] ?? '', '/?select=1&q=Bulk&bulk_done=3&bulk_act=move'), 'redirect returns to the selection with a result');
T::eq(3, (int)$db->query("SELECT COUNT(*) FROM items WHERE location_id = $shelf AND id IN ($bA,$bB,$bC)")->fetchColumn(), 'all three moved');
T::eq(3, (int)$db->query("SELECT COUNT(*) FROM activity WHERE action = 'moved' AND item_id IN ($bA,$bB,$bC)")->fetchColumn(), 'one history entry per item');
T::ok(str_contains($c->get('/?select=1&q=Bulk&bulk_done=3&bulk_act=move')['body'], 'Moved 3 items'), 'toast');
$c->post('/bulk', ['csrf' => $tok, 'ids' => [$bA, $bB], 'action' => 'move', 'location_id' => '']);
T::eq(2, (int)$db->query("SELECT COUNT(*) FROM items WHERE location_id IS NULL AND id IN ($bA,$bB)")->fetchColumn(), 'move to "No location"');
$c->post('/bulk', ['csrf' => $tok, 'ids' => [$bA, $bC], 'action' => 'category', 'category_id' => $kitchen]);
T::eq(2, (int)$db->query("SELECT COUNT(*) FROM items WHERE category_id = $kitchen AND id IN ($bA,$bC)")->fetchColumn(), 'set category');
$c->post('/bulk', ['csrf' => $tok, 'ids' => [$bA, $bB, $bC], 'action' => 'tag_add', 'tags' => 'Winter, keep']);
$tagsOf = fn(int $id) => array_column($db->query("SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = $id ORDER BY t.name")->fetchAll(), 'name');
T::eq(['Keep', 'Winter'], $tagsOf($bA), 'add tags merges case-insensitively');
T::eq(['Keep', 'Winter'], $tagsOf($bB), 'add tags to an untagged item (existing tag reused)');
$gone = (int)$db->query("SELECT id FROM tags WHERE name = 'Gone'")->fetchColumn();
$c->post('/bulk', ['csrf' => $tok, 'ids' => [$bC], 'action' => 'tag_remove', 'tag_id' => $gone]);
T::eq(['Keep', 'Winter'], $tagsOf($bC), 'remove tag');
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM tags WHERE name = 'Gone'")->fetchColumn(), 'unused tag cleaned up');
T::eq(422, $c->post('/bulk', ['csrf' => $tok, 'ids' => [], 'action' => 'move'])['status'], 'empty selection rejected');
T::eq(422, $c->post('/bulk', ['csrf' => $tok, 'ids' => [$bA], 'action' => 'explode'])['status'], 'unknown action rejected');
T::eq(422, $c->post('/bulk', ['csrf' => $tok, 'ids' => range(1, 501), 'action' => 'move'])['status'], 'more than 500 rejected');
$conf = $c->post('/bulk', ['csrf' => $tok, 'ids' => [$bA, $bB], 'action' => 'delete', 'back' => '/?select=1']);
T::ok($conf['status'] === 200 && str_contains($conf['body'], 'Delete 2 items?') && str_contains($conf['body'], 'name="confirm" value="1"'), 'delete asks for confirmation first');
T::eq(2, (int)$db->query("SELECT COUNT(*) FROM items WHERE id IN ($bA,$bB)")->fetchColumn(), 'nothing deleted before confirming');
$c->post('/bulk', ['csrf' => $tok, 'ids' => [$bA, $bB], 'action' => 'delete', 'confirm' => '1']);
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM items WHERE id IN ($bA,$bB)")->fetchColumn(), 'confirmed delete removes them');
T::eq(2, (int)$db->query("SELECT COUNT(*) FROM activity WHERE action = 'deleted' AND item_id IN ($bA,$bB)")->fetchColumn(), 'deletes are logged per item');
$r = $c->post('/bulk', ['csrf' => $tok, 'ids' => [$bC], 'action' => 'move', 'location_id' => '', 'back' => 'https://evil.example/']);
T::ok(str_starts_with($r['headers']['location'] ?? '', '/?select=1'), 'back is restricted to the list');
$c->post("/item/$bC/delete", ['csrf' => $tok]);

T::section('Lending');
$lA = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Ladder']));
$lB = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Projector']));
T::ok(str_contains($c->get("/item/$lA")['body'], 'Lend this item'), 'item page offers lending');
T::eq(419, $c->post("/item/$lA/lend", ['lent_to' => 'Maria'])['status'], 'lend requires CSRF');
T::eq(422, $c->post("/item/$lA/lend", ['csrf' => $tok, 'lent_to' => ' '])['status'], 'name required');
T::eq(422, $c->post("/item/$lA/lend", ['csrf' => $tok, 'lent_to' => 'Maria', 'lent_at' => '2999-01-01'])['status'], 'future lent date rejected');
T::eq(422, $c->post("/item/$lA/lend", ['csrf' => $tok, 'lent_to' => 'Maria', 'lent_at' => '2020-05-02', 'lent_due' => '2020-05-01'])['status'], 'due before lent rejected');
T::eq(422, $c->post("/item/$lA/lend", ['csrf' => $tok, 'lent_to' => 'Maria', 'lent_due' => '31/12/2020'])['status'], 'bad date rejected');
$r = $c->post("/item/$lA/lend", ['csrf' => $tok, 'lent_to' => 'Maria', 'lent_at' => '2020-05-01', 'lent_due' => '2020-05-15']);
T::ok(str_ends_with($r['headers']['location'] ?? '', "/item/$lA#lending"), 'lend redirects to the item');
$c->post("/item/$lB/lend", ['csrf' => $tok, 'lent_to' => 'Nikos <b>', 'lent_due' => '2999-01-01']);
$row = $db->query("SELECT lent_to, lent_at, lent_due FROM items WHERE id = $lA")->fetch(PDO::FETCH_ASSOC);
T::eq(['lent_to' => 'Maria', 'lent_at' => '2020-05-01', 'lent_due' => '2020-05-15'], $row, 'lending stored');
T::ok((string)$db->query("SELECT lent_at FROM items WHERE id = $lB")->fetchColumn() !== '', 'lent date defaults to today');
$pA = $c->get("/item/$lA")['body'];
T::ok(str_contains($pA, 'lendbox is-out is-overdue') && str_contains($pA, 'Mark as returned'), 'item page: overdue + Return button');
T::ok(str_contains($c->get("/item/$lB")['body'], 'Nikos &lt;b&gt;') && !str_contains($c->get("/item/$lB")['body'], 'Nikos <b>'), 'borrower name escaped');
T::eq(['Ladder', 'Projector'], array_values(array_intersect(titles($c->json('/?lend=out&sort=name')), ['Ladder', 'Projector'])), 'Lent out filter');
T::eq(['Ladder'], array_values(array_intersect(titles($c->json('/?lend=overdue')), ['Ladder', 'Projector'])), 'Overdue filter');
$ov = $c->json('/?lend=overdue')['json'];
T::ok(str_contains($ov['html'], 'lendbadge is-overdue') && str_contains($ov['chips'], 'data-clear="lend"'), 'overdue badge + chip');
T::ok(str_contains($c->json('/?lend=out')['json']['html'], 'Lent · Nikos'), 'lent badge on card');
$lent = $c->get('/lent')['body'];
T::ok(strpos($lent, 'Ladder') !== false && strpos($lent, 'Ladder') < strpos($lent, 'Projector'), '/lent lists overdue first');
T::ok(str_contains($lent, '1 overdue'), '/lent summary');
T::ok(str_contains($c->get('/')['body'], 'href="/lent"'), 'menu links to the Lent page');
$csvLend = array_map('str_getcsv', array_filter(explode("\n", ltrim($c->get('/export.csv')['body'], "\xEF\xBB\xBF"))));
$lrow = array_values(array_filter($csvLend, fn($l) => ($l[0] ?? '') === (string)$lA))[0] ?? [];
T::eq(['Maria', '2020-05-01', '2020-05-15'], array_slice($lrow, 15, 3), 'CSV includes lending');
$bkLend = sys_get_temp_dir() . '/apotheke-lend-' . bin2hex(random_bytes(3)) . '.zip';
file_put_contents($bkLend, $c->get('/backup.zip')['body']);
$z = new ZipArchive(); $z->open($bkLend);
$bi = json_decode((string)$z->getFromName('backup.json'), true)['tables']['items'];
$z->close();
T::ok(in_array('Maria', array_column($bi['rows'], array_search('lent_to', $bi['columns'], true)), true), 'backup includes lending');
$c->post("/item/$lA/return", ['csrf' => $tok, 'back' => '/lent']);
T::eq(null, $db->query("SELECT lent_to FROM items WHERE id = $lA")->fetchColumn() ?: null, 'returned');
$acts = array_column($db->query("SELECT action FROM activity WHERE item_id = $lA ORDER BY id")->fetchAll(), 'action');
T::eq(['created', 'lent', 'returned'], $acts, 'history: lent + returned');
T::ok(str_contains($c->get("/item/$lA")['body'], 'Returned by <b>Maria</b>'), 'history rendered');
// restore brings the lending back
$rs = $c->post('/backup/restore', ['csrf' => $tok], ['backup' => ['name' => 'l.zip', 'type' => 'application/zip', 'data' => file_get_contents($bkLend)]]);
preg_match('/name="token" value="([a-f0-9]{32})"/', $rs['body'], $tm);
$c->post('/backup/restore/confirm', ['csrf' => $tok, 'token' => $tm[1] ?? '', 'confirm' => '1']);
T::eq('Maria', (string)$db->query("SELECT lent_to FROM items WHERE id = $lA")->fetchColumn(), 'restore brings lending back');
@unlink($bkLend);
$c->post("/item/$lA/delete", ['csrf' => $tok]);
$c->post("/item/$lB/delete", ['csrf' => $tok]);

T::section('Duplicate item');
$dSrc = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Dup source', 'quantity' => 4, 'min_qty' => '1', 'notes' => 'n', 'tags' => 'Alpha, Beta',
    'value' => '12.30', 'purchase_date' => '2024-01-02', 'serial' => 'S-1', 'location_id' => $shelf ?? '', 'category_id' => $kitchen ?? ''],
    []));
$c->api("/item/$dSrc/photos", ['csrf' => $tok], ['photos[0]' => jpg_file(img_jpeg(60, 40)), 'photos[1]' => jpg_file(img_jpeg(50, 50))]);
$c->post("/item/$dSrc/lend", ['csrf' => $tok, 'lent_to' => 'Maria']);
$srcFiles = $db->query("SELECT file FROM item_photos WHERE item_id = $dSrc ORDER BY position")->fetchAll(PDO::FETCH_COLUMN);
T::eq(2, count($srcFiles), 'source has two photos');
T::ok(str_contains($c->get("/item/$dSrc")['body'], 'action="/item/' . $dSrc . '/duplicate"'), 'item page has Duplicate');
T::eq(419, $c->post("/item/$dSrc/duplicate", [])['status'], 'duplicate requires CSRF');
$r = $c->post("/item/$dSrc/duplicate", ['csrf' => $tok]);
T::ok(preg_match('#/item/(\d+)/edit\?copied=1$#', $r['headers']['location'] ?? '', $dm) === 1, 'opens the edit form of the copy');
$dNew = (int)($dm[1] ?? 0);
$cols = 'name, location_id, category_id, quantity, min_qty, notes, value_cents, purchase_date, serial, lent_to, image';
$src = $db->query("SELECT $cols FROM items WHERE id = $dSrc")->fetch(PDO::FETCH_ASSOC);
$cp = $db->query("SELECT $cols FROM items WHERE id = $dNew")->fetch(PDO::FETCH_ASSOC);
T::eq(['Dup source (copy)', null, null], [$cp['name'], $cp['lent_to'], $cp['image']], 'name gets " (copy)", lending and photos not copied by default');
unset($src['name'], $src['lent_to'], $src['image'], $cp['name'], $cp['lent_to'], $cp['image']);
T::eq($src, $cp, 'location, category, quantity, low level, notes, custom fields copied');
T::eq(['Alpha', 'Beta'], array_column($db->query("SELECT t.name FROM item_tags it JOIN tags t ON t.id = it.tag_id WHERE it.item_id = $dNew ORDER BY t.name")->fetchAll(), 'name'), 'tags copied');
$det = json_decode((string)$db->query("SELECT details FROM activity WHERE item_id = $dNew AND action = 'created'")->fetchColumn(), true);
T::eq('Dup source', $det['copied_from'] ?? null, 'history: created as a copy');
T::ok(str_contains($c->get("/item/$dNew/edit?copied=1")['body'], 'Copy created'), 'edit form toast');
$r = $c->post("/item/$dSrc/duplicate", ['csrf' => $tok, 'photos' => '1']);
preg_match('#/item/(\d+)/edit#', $r['headers']['location'] ?? '', $dm);
$dPh = (int)($dm[1] ?? 0);
$newFiles = $db->query("SELECT file FROM item_photos WHERE item_id = $dPh ORDER BY position")->fetchAll(PDO::FETCH_COLUMN);
T::ok(count($newFiles) === 2 && !array_intersect($newFiles, $srcFiles), 'with photos: two new photo files');
T::ok(array_filter($newFiles, fn($f) => !is_file("$dir/images/$f") || !is_file("$dir/thumbs/$f")) === [], 'files and thumbnails exist');
T::eq(md5_file("$dir/images/{$srcFiles[0]}"), md5_file("$dir/images/{$newFiles[0]}"), 'same image content, same order');
T::eq($newFiles[0], (string)$db->query("SELECT image FROM items WHERE id = $dPh")->fetchColumn(), 'cover set');
$c->post("/item/$dPh/delete", ['csrf' => $tok]);
T::ok(is_file("$dir/images/{$srcFiles[0]}"), 'deleting the copy keeps the original photos');
T::eq(404, $c->post('/item/999999/duplicate', ['csrf' => $tok])['status'], 'unknown item: 404');
foreach ([$dSrc, $dNew] as $x) $c->post("/item/$x/delete", ['csrf' => $tok]);

T::section('Nested locations');
$nl = fn(string $n, $parent = '') => (int)($c->api('/locations', ['csrf' => $tok, 'name' => $n, 'parent' => (string)$parent])['json']['id'] ?? 0);
$nG = $nl('Nest garage');
$nS = $nl('Nest shelf', $nG);
$nB = $nl('Nest box', $nS);
T::ok($nG && $nS && $nB, 'locations created inside each other');
T::eq([null, $nG, $nS], array_map(fn($id) => ($v = $db->query("SELECT parent_id FROM locations WHERE id = $id")->fetchColumn()) === null ? null : (int)$v, [$nG, $nS, $nB]), 'parent_id stored');
$nItem = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Nested drill', 'location_id' => $nB]));
$nTop = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Garage broom', 'location_id' => $nG]));
T::eq(['Garage broom', 'Nested drill'], array_values(array_intersect(titles($c->json("/?loc=$nG&sort=name")), ['Garage broom', 'Nested drill'])), 'filtering a location includes its sub-locations');
T::eq(['Nested drill'], array_values(array_intersect(titles($c->json("/?loc=$nS")), ['Garage broom', 'Nested drill'])), 'filtering a sub-location excludes its parent');
T::eq(['Garage broom', 'Nested drill'], array_values(array_intersect(titles($c->json('/?q=' . rawurlencode('nest garage') . '&sort=name')), ['Garage broom', 'Nested drill'])), 'search for a parent location finds items in its sub-locations');
T::eq(['Nested drill'], array_values(array_intersect(titles($c->json('/?q=' . rawurlencode('nest shelf'))), ['Garage broom', 'Nested drill'])), 'search for a middle location skips its parent');
T::eq(['Nested drill'], array_values(array_intersect(titles($c->json('/?q=' . rawurlencode('drill garage'))), ['Garage broom', 'Nested drill'])), 'item word + ancestor word');
$stN = $c->get('/stats')['body'];
T::ok(preg_match('#data-loc="' . $nG . '">Nest garage</a></span>.*?<span class="hb-n">(\d+)</span>#s', $stN, $hm) === 1 && (int)$hm[1] === 2, 'overview rolls sub-location items up into the top-level location', $hm[1] ?? '');
T::ok(!preg_match('#data-loc="' . $nS . '">#', $stN) && str_contains($stN, 'id="st-rollup"'), 'sub-locations are not listed separately; note shown');
T::ok(str_contains($c->json("/?loc=$nB")['json']['chips'] ?? '', 'Nest garage › Nest shelf › Nest box'), 'filter chip shows the full path');
$ip = $c->get("/item/$nItem")['body'];
T::ok(str_contains($ip, 'id="loctrail"') && strpos($ip, 'href="/?loc=' . $nG . '"') < strpos($ip, 'href="/?loc=' . $nS . '"'), 'item page shows the location trail top-down');
$mg = $c->get('/locations')['body'];
T::ok(str_contains($mg, 'id="loc-' . $nB . '" data-depth="2"') && str_contains($mg, 'loc-d2'), 'manage page indents by depth');
T::ok(preg_match('#id="loc-' . $nG . '".*?class="loc-count"[^>]*>2 items#s', $mg) === 1, 'counts include sub-locations');
T::ok(str_contains($c->get('/new')['body'], "\u{00A0}↳ Nest box"), 'pickers show the tree');
T::eq(419, $c->post("/locations/$nS/parent", ['parent' => ''])['status'], 'move requires CSRF');
T::eq(422, $c->post("/locations/$nG/parent", ['csrf' => $tok, 'parent' => (string)$nB])['status'], 'cannot move into its own sub-location');
T::eq(422, $c->post("/locations/$nG/parent", ['csrf' => $tok, 'parent' => (string)$nG])['status'], 'cannot move into itself');
T::eq(422, $c->post("/locations/$nG/parent", ['csrf' => $tok, 'parent' => 'x'])['status'], 'bad parent rejected');
T::eq(404, $c->post('/categories/1/parent', ['csrf' => $tok, 'parent' => ''])['status'], 'categories do not nest');
$r = $c->post("/locations/$nB/parent", ['csrf' => $tok, 'parent' => '']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=moved'), 'move to the top level');
T::eq(null, $db->query("SELECT parent_id FROM locations WHERE id = $nB")->fetchColumn() ?: null, 'now top level');
$c->post("/locations/$nB/parent", ['csrf' => $tok, 'parent' => (string)$nS]);
// deep chains are limited
$chain = [$nB];
for ($i = 0; $i < 4; $i++) $chain[] = $nl("Nest deep $i", end($chain));
T::ok(!in_array(0, $chain, true), 'nesting works down to 6 levels below the top');
$tooDeep = $c->api('/locations', ['csrf' => $tok, 'name' => 'Nest too deep', 'parent' => (string)end($chain)]);
T::ok($tooDeep['status'] === 422 && str_contains($tooDeep['json']['error'] ?? '', 'at most 6 levels'), 'a 7th level is rejected');
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM locations WHERE name = 'Nest too deep'")->fetchColumn(), 'rejected location is not created');
T::eq(422, $c->post("/locations/$nG/parent", ['csrf' => $tok, 'parent' => (string)$nl('Nest other')])['status'], 'moving a deep tree below another location is rejected');
// backup + restore keep the tree
$bkN = sys_get_temp_dir() . '/apotheke-nest-' . bin2hex(random_bytes(3)) . '.zip';
file_put_contents($bkN, $c->get('/backup.zip')['body']);
$rs = $c->post('/backup/restore', ['csrf' => $tok], ['backup' => ['name' => 'n.zip', 'type' => 'application/zip', 'data' => file_get_contents($bkN)]]);
preg_match('/name="token" value="([a-f0-9]{32})"/', $rs['body'], $tm);
$c->post('/backup/restore/confirm', ['csrf' => $tok, 'token' => $tm[1] ?? '', 'confirm' => '1']);
@unlink($bkN);
T::eq($nS, (int)$db->query("SELECT parent_id FROM locations WHERE id = $nB")->fetchColumn(), 'restore keeps the tree');
// deleting a middle location moves its children up one level
$del = $c->get("/locations/$nS/delete")['body'];
T::ok(str_contains($del, 'id="del-children"') && str_contains($del, 'into “Nest garage”'), 'delete page explains what happens to sub-locations');
$c->post("/locations/$nS/delete", ['csrf' => $tok, 'action' => 'unassign']);
T::eq($nG, (int)$db->query("SELECT parent_id FROM locations WHERE id = $nB")->fetchColumn(), 'children move up after delete');
T::eq($nB, (int)$db->query("SELECT location_id FROM items WHERE id = $nItem")->fetchColumn(), 'items in sub-locations stay put');
foreach ([$nItem, $nTop] as $x) $c->post("/item/$x/delete", ['csrf' => $tok]);
foreach (array_reverse(array_merge([$nG, (int)$db->query("SELECT id FROM locations WHERE name = 'Nest other'")->fetchColumn()], $chain)) as $x) $c->post("/locations/$x/delete", ['csrf' => $tok, 'action' => 'unassign']);
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM locations WHERE name LIKE 'Nest %'")->fetchColumn(), 'cleaned up');

T::section('Expiry / warranty');
$today = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format('Y-m-d');
$dayOff = fn(int $n) => (new DateTimeImmutable($today))->modify("$n days")->format('Y-m-d');
T::eq(422, $c->api('/save', ['csrf' => $tok, 'name' => 'Bad expiry', 'expires_on' => '2024-02-30'])['status'], 'invalid expiry date rejected');
$eMilk = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Exp milk', 'expires_on' => $dayOff(-2)]));
$eBattery = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Exp battery', 'expires_on' => $dayOff(10)]));
$eTv = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Exp TV warranty', 'expires_on' => $dayOff(400)]));
T::eq($dayOff(400), (string)$db->query("SELECT expires_on FROM items WHERE id = $eTv")->fetchColumn(), 'future date stored');
$expT = fn(string $v) => array_values(array_intersect(titles($c->json("/?exp=$v&sort=name")), ['Exp battery', 'Exp milk', 'Exp TV warranty']));
T::eq(['Exp milk'], $expT('expired'), 'Expired filter');
T::eq(['Exp battery'], $expT('soon'), 'Expiring soon filter (default 30 days)');
T::eq(3, count(array_intersect(titles($c->json('/?exp=bogus')), ['Exp battery', 'Exp milk', 'Exp TV warranty'])), 'unknown value ignored');
$ej = $c->json('/?exp=soon')['json'];
T::ok(str_contains($ej['html'], 'expbadge is-soon') && str_contains($ej['html'], 'Expires in 10 days') && str_contains($ej['chips'], 'data-clear="exp"'), 'badge + chip');
T::ok(str_contains($c->json('/?exp=expired')['json']['html'], 'expbadge is-expired'), 'expired badge');
$ep = $c->get("/item/$eMilk")['body'];
T::ok(str_contains($ep, '<dt>Expires</dt>') && str_contains($ep, 'is-expired'), 'item page fact + badge');
T::ok(str_contains($c->get("/item/$eMilk/edit")['body'], 'name="expires_on" id="expires_on" value="' . $dayOff(-2) . '"'), 'edit form prefilled');
$stE = $c->get('/stats')['body'];
T::ok(preg_match('#id="tile-expiring"><b>(\d+)</b>#', $stE, $em) === 1 && (int)$em[1] >= 1, 'Expiring soon tile');
T::ok(preg_match('#id="tile-expired"><b>(\d+)</b>#', $stE, $em2) === 1 && (int)$em2[1] >= 1, 'Expired tile');
$c->api('/save', ['csrf' => $tok, 'id' => $eTv, 'name' => 'Exp TV warranty', 'expires_on' => $dayOff(20)]);
T::eq(['Exp battery', 'Exp TV warranty'], $expT('soon'), 'edit moves it into the window');
$chg = json_decode((string)$db->query("SELECT details FROM activity WHERE item_id = $eTv AND action = 'edited' ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
T::eq([$dayOff(400), $dayOff(20)], $chg['changes']['expires_on'] ?? null, 'history records the change');
T::ok(str_contains($c->get("/item/$eTv")['body'], 'Expires'), 'history label');
$csvE = array_map('str_getcsv', array_filter(explode("\n", ltrim($c->get('/export.csv')['body'], "\xEF\xBB\xBF"))));
$hdrE = array_search('expires_on', $csvE[0], true);
$rowE = array_values(array_filter($csvE, fn($l) => ($l[0] ?? '') === (string)$eMilk))[0] ?? [];
T::eq($dayOff(-2), $rowE[$hdrE] ?? null, 'CSV column');
$r = $c->post("/item/$eBattery/duplicate", ['csrf' => $tok]);
preg_match('#/item/(\d+)/edit#', $r['headers']['location'] ?? '', $dm);
T::eq($dayOff(10), (string)$db->query('SELECT expires_on FROM items WHERE id = ' . (int)($dm[1] ?? 0))->fetchColumn(), 'duplicate keeps the date');
$c->post('/bulk', ['csrf' => $tok, 'ids' => [$eBattery], 'action' => 'tag_add', 'tags' => 'x', 'back' => '/']);
T::eq($dayOff(10), (string)$db->query("SELECT expires_on FROM items WHERE id = $eBattery")->fetchColumn(), 'bulk actions keep the date');
$bkE = sys_get_temp_dir() . '/apotheke-exp-' . bin2hex(random_bytes(3)) . '.zip';
file_put_contents($bkE, $c->get('/backup.zip')['body']);
$z = new ZipArchive(); $z->open($bkE);
$bi = json_decode((string)$z->getFromName('backup.json'), true)['tables']['items'];
$z->close(); @unlink($bkE);
T::ok(in_array($dayOff(-2), array_column($bi['rows'], array_search('expires_on', $bi['columns'], true)), true), 'backup includes the date');
foreach ([$eMilk, $eBattery, $eTv, (int)($dm[1] ?? 0)] as $x) $c->post("/item/$x/delete", ['csrf' => $tok]);
// configurable window: EXPIRY_SOON_DAYS=5, frozen clock
$xs = new Server($newDir('exp'), ['EXPIRY_SOON_DAYS' => '5', 'APOTHEKE_CLOCK' => '2026-06-15 09:00:00', 'TZ' => 'UTC']);
$xc = $xs->client();
$xt = $xc->csrf();
foreach (['In 3 days' => '2026-06-18', 'In 5 days' => '2026-06-20', 'In 6 days' => '2026-06-21', 'Yesterday' => '2026-06-14', 'Today' => '2026-06-15'] as $n => $d) {
    $xc->api('/save', ['csrf' => $xt, 'name' => $n, 'expires_on' => $d]);
}
T::eq(['In 3 days', 'In 5 days', 'Today'], titles($xc->json('/?exp=soon&sort=name')), 'EXPIRY_SOON_DAYS=5: today … today+5');
T::eq(['Yesterday'], titles($xc->json('/?exp=expired')), 'before today is expired');
T::ok(str_contains($xc->json('/?exp=soon&sort=name')['json']['html'], 'Expires today'), '"Expires today" badge');
$xs->stop();


T::section('Purchase price and value totals');
$vs = new Server($newDir('val'), ['CURRENCY' => 'CHF', 'SEED_DEFAULTS' => '0']);
$vc = $vs->client();
$vt = $vc->csrf();
$vLoc = fn(string $n, $p = '') => (int)$vc->api('/locations', ['csrf' => $vt, 'name' => $n, 'parent' => (string)$p])['json']['id'];
$vG = $vLoc('Garage');
$vS = $vLoc('Shelf', $vG);
$vA = $vLoc('Attic');
$vTools = (int)$vc->api('/categories', ['csrf' => $vt, 'name' => 'Tools'])['json']['id'];
T::ok(str_contains($vc->get('/new')['body'], 'Purchase price <em>CHF</em>'), 'form shows the configured currency');
T::eq(422, $vc->api('/save', ['csrf' => $vt, 'name' => 'Bad', 'value' => 'abc'])['status'], 'invalid price rejected');
foreach ([['Drill', '150,00', $vS, $vTools, '2024-01-10'], ['Saw', '50.00', $vG, $vTools, ''], ['Lamp', '25.50', $vA, '', ''], ['Box', '', $vA, $vTools, ''], ['Loose', '10', '', '', '']] as [$n, $v, $l, $cat, $d]) {
    $vc->api('/save', ['csrf' => $vt, 'name' => $n, 'value' => $v, 'location_id' => (string)$l, 'category_id' => (string)$cat, 'purchase_date' => $d]);
}
$vst = $vc->get('/stats')['body'];
T::ok(str_contains($vst, 'id="value-total">235.50 CHF</b>') && str_contains($vst, '4 items with a price'), 'overall total in the configured currency');
T::ok(preg_match('#id="st-value".*?data-loc="' . $vG . '">Garage</a></span>.*?<span class="hb-n">200\.00 CHF</span>#s', $vst) === 1, 'per location, sub-locations rolled up');
T::ok(!preg_match('#id="st-value".*?data-loc="' . $vS . '">#s', $vst), 'sub-location not listed on its own');
T::ok(preg_match('#id="st-value".*?data-cat="' . $vTools . '">Tools</a></span>.*?<span class="hb-n">200\.00 CHF</span>#s', $vst) === 1, 'per category');
T::ok(preg_match('#id="st-value".*?<h4>By category</h4>.*?>No category</a></span>.*?<span class="hb-n">35\.50 CHF</span>#s', $vst) === 1, '"No category" bucket (Lamp + Loose)');
T::eq(['Drill', 'Saw', 'Lamp', 'Loose', 'Box'], titles($vc->json('/?sort=value')), 'sort by value: highest first, no price last');
T::ok(str_contains($vc->get('/')['body'], '<option value="value"'), 'sort option offered');
$vcsv = array_map('str_getcsv', array_filter(explode("\n", ltrim($vc->get('/export.csv')['body'], "\xEF\xBB\xBF"))));
$vh = array_flip($vcsv[0]);
$vrow = array_values(array_filter($vcsv, fn($l) => ($l[1] ?? '') === 'Drill'))[0];
T::eq(['150.00', 'CHF', '2024-01-10'], [$vrow[$vh['value']], $vrow[$vh['currency']], $vrow[$vh['purchase_date']]], 'CSV: price, currency, purchase date');
$vz = sys_get_temp_dir() . '/apotheke-val-' . bin2hex(random_bytes(3)) . '.zip';
file_put_contents($vz, $vc->get('/backup.zip')['body']);
$z = new ZipArchive(); $z->open($vz);
$bi = json_decode((string)$z->getFromName('backup.json'), true)['tables']['items'];
$z->close(); @unlink($vz);
T::ok(in_array(15000, array_map('intval', array_column($bi['rows'], array_search('value_cents', $bi['columns'], true))), true), 'backup includes the price');
$vDrill = (int)$vrow[0];
T::ok(str_contains($vc->get("/item/$vDrill")['body'], '<dt>Price</dt><dd>150.00 CHF'), 'item page shows the price');
$vc->api('/save', ['csrf' => $vt, 'id' => $vDrill, 'name' => 'Drill', 'value' => '120', 'location_id' => (string)$vS, 'category_id' => (string)$vTools, 'purchase_date' => '2024-01-10']);
T::ok(str_contains($vc->get("/item/$vDrill")['body'], 'Purchase price'), 'history labels the price change');
T::ok(str_contains($vc->get('/stats')['body'], 'id="value-total">205.50 CHF</b>'), 'total follows edits');
$vs->stop();
$v0 = new Server($newDir('val0'), ['SEED_DEFAULTS' => '0']);
T::ok(!str_contains($v0->client()->get('/stats')['body'], 'id="st-value"'), 'no value card without prices');
$v0->stop();

T::section('Delete item');
$img = $db->query("SELECT image FROM items WHERE id = $drill")->fetchColumn();
$r = $c->post("/item/$drill/delete", ['csrf' => $tok]);
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === '/?deleted=1', 'delete redirects to list');
T::ok(!is_file("$dir/images/$img") && !is_file("$dir/thumbs/$img"), 'image + thumbnail removed');
T::ok(array_filter($allPics, fn($f) => is_file("$dir/images/$f") || is_file("$dir/thumbs/$f")) === [], 'every gallery photo removed');
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM item_photos WHERE item_id = $drill")->fetchColumn(), 'photo rows removed');
T::eq(404, $c->get("/item/$drill")['status'], 'item gone');
$srv->stop();
T::ok(!$srv->logHas('[apotheke]'), 'no server-side exceptions logged');

/* ======================================================================= */
T::section('Saved filters');
$fs = new Server($newDir('sf'), ['SEED_DEFAULTS' => '0']);
$fc = $fs->client();
$ft = $fc->csrf();
$fG = (int)$fc->api('/locations', ['csrf' => $ft, 'name' => 'Garage'])['json']['id'];
$fc->api('/save', ['csrf' => $ft, 'name' => 'Drill', 'location_id' => (string)$fG]);
$fc->api('/save', ['csrf' => $ft, 'name' => 'Lamp']);
T::eq(419, $fc->post('/filters', ['name' => 'X', 'loc' => (string)$fG])['status'], 'create without CSRF → 419');
$fr = $fc->api('/filters', ['csrf' => $ft, 'name' => 'Garage stuff', 'loc' => (string)$fG, 'sort' => 'name', 'page' => '2', 'evil' => '<x>']);
T::ok($fr['status'] === 200 && $fr['json']['ok'] && str_contains($fr['json']['html'], 'Garage stuff'), 'create via fetch returns the chip html');
$fId = (int)$fr['json']['id'];
T::eq(409, $fc->api('/filters', ['csrf' => $ft, 'name' => 'garage STUFF', 'q' => 'x'])['status'], 'duplicate name → 409');
T::eq(422, $fc->api('/filters', ['csrf' => $ft, 'name' => 'Nothing', 'sort' => 'recent'])['status'], 'empty combination → 422');
$fl = $fc->get('/?loc=' . $fG . '&sort=name')['body'];
T::ok(preg_match('#<a class="achip sfchip is-on" href="/\?loc=' . $fG . '&amp;sort=name"[^>]*aria-current="true"#', $fl) === 1, 'chip listed on the list page, active for its combination');
T::ok(str_contains($fc->get('/')['body'], 'class="achip sfchip" href="/?loc='), 'chip not active on the plain list');
T::eq('loc=' . $fG . '&sort=name', $fc->json('/?sort=name&loc=' . $fG)['json']['query'], 'list JSON reports the normalised query');
T::eq(['Drill'], titles($fc->json('/?loc=' . $fG . '&sort=name')), 'applying the saved query filters the list');
$fp = $fc->post('/filters', ['csrf' => $ft, 'name' => 'Lamps', 'q' => 'lamp']);
T::ok($fp['status'] === 303 || $fp['status'] === 302, 'no-JS create redirects');
T::ok(str_contains($fp['headers']['location'] ?? '', '/?q=lamp&filter_saved='), 'redirects back to the filtered list');
T::ok(str_contains($fc->get($fp['headers']['location'])['body'], 'Saved filter “Lamps”'), 'toast after no-JS save');
$fm = $fc->get('/filters')['body'];
T::ok(str_contains($fm, 'Garage stuff') && str_contains($fm, 'Garage · sorted by Name') && str_contains($fm, '“lamp”'), 'manage page lists filters with a description');
T::eq(419, $fc->post("/filters/$fId/rename", ['name' => 'Y'])['status'], 'rename without CSRF → 419');
T::eq(419, $fc->post("/filters/$fId/delete", [])['status'], 'delete without CSRF → 419');
$fc->post("/filters/$fId/rename", ['csrf' => $ft, 'name' => 'Workshop']);
T::ok(str_contains($fc->get('/filters')['body'], 'Workshop') && !str_contains($fc->get('/')['body'], 'Garage stuff'), 'rename');
T::eq(409, $fc->post("/filters/$fId/rename", ['csrf' => $ft, 'name' => 'lamps'])['status'], 'rename onto an existing name → 409');
T::eq(404, $fc->post('/filters/999/delete', ['csrf' => $ft])['status'], 'unknown filter → 404');
$fz = sys_get_temp_dir() . '/apotheke-sf-' . bin2hex(random_bytes(3)) . '.zip';
file_put_contents($fz, $fc->get('/backup.zip')['body']);
$z = new ZipArchive(); $z->open($fz);
$fbt = json_decode((string)$z->getFromName('backup.json'), true)['tables']['saved_filters'] ?? null;
$z->close(); @unlink($fz);
T::ok($fbt !== null && count($fbt['rows']) === 2, 'backup includes saved filters');
$fc->post("/filters/$fId/delete", ['csrf' => $ft]);
T::ok(!str_contains($fc->get('/filters')['body'], 'Workshop'), 'delete');
$fs->stop();

T::section('Password mode (APP_PASSWORD)');
$pdir = $newDir('pw');
$ps = new Server($pdir, ['APP_PASSWORD' => 's3cret']);
$pc = $ps->client();
$r = $pc->get('/');
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === '/login', 'anonymous → /login');
T::eq(401, $pc->get('/media/thumb/' . str_repeat('a', 32) . '.jpg')['status'], 'media → 401');
T::eq(401, $pc->json('/?q=x')['status'], 'JSON → 401');
T::eq(303, $pc->get('/stats')['status'], 'stats fragment requires login');
$ptok = $pc->csrf();
$r = $pc->post('/login', ['csrf' => $ptok, 'password' => 'wrong']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Wrong password'), 'wrong password refused');
$sidBefore = $pc->cookies['apothekesid'] ?? '';
$r = $pc->post('/login', ['csrf' => $ptok, 'password' => 's3cret']);
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === '/', 'correct password → /');
T::ok(($pc->cookies['apothekesid'] ?? '') !== $sidBefore, 'session id regenerated on login');
T::eq(200, $pc->get('/')['status'], 'signed in');
$ptok = $pc->csrf();
T::eq(303, $pc->post('/logout', ['csrf' => $ptok])['status'], 'logout');
T::eq(303, $pc->get('/')['status'], 'signed out again');
$ps->stop();

/* ======================================================================= */
T::section('Seeding on first use (SEED_DEFAULTS=1)');
$sd = $newDir('seed');
$ss = new Server($sd, ['SEED_DEFAULTS' => '1']);
T::eq(200, $ss->client()->get('/')['status'], 'fresh install with seeding starts');
$sp = testdb($sd);
T::eq(['Attic', 'Basement', 'Box 1', 'Garage', 'Kitchen', 'Living room'], $sp->query('SELECT name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN), 'starter locations');
T::eq(6, (int)$sp->query('SELECT COUNT(*) FROM categories')->fetchColumn(), 'starter categories');
T::eq('Box 1', $sp->query('SELECT name FROM locations WHERE is_default = 1')->fetchColumn(), 'one default location');
T::eq('General', $sp->query('SELECT name FROM categories WHERE is_default = 1')->fetchColumn(), 'one default category');
$sc = $ss->client(); $st = $sc->csrf();
$sc->post('/locations/' . (int)$sp->query("SELECT id FROM locations WHERE name = 'Attic'")->fetchColumn() . '/delete', ['csrf' => $st, 'action' => 'unassign']);
$ss->stop();
$ss = new Server($sd, ['SEED_DEFAULTS' => '1']);
$ss->client()->get('/');
T::eq(5, (int)$sp->query('SELECT COUNT(*) FROM locations')->fetchColumn(), 'seeding runs once: a deleted starter location stays deleted after restart');
$ss->stop();

if (test_driver() !== 'sqlite') { fwrite(STDOUT, "\n  - skipped legacy SQLite v1/v2 upgrade tests (driver " . test_driver() . ")\n"); exit(T::summary()); }

/* ======================================================================= */
T::section('Migration v1 → v3 (free-text locations)');
$m1 = $newDir('v1');
$p = sqlite("$m1/catalog.sqlite");
$p->exec(<<<SQL
    CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, location TEXT NOT NULL DEFAULT '',
        quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0), notes TEXT NOT NULL DEFAULT '', image TEXT,
        search TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
    CREATE INDEX idx_items_location ON items(location COLLATE NOCASE);
    PRAGMA user_version = 1;
SQL);
$ins = $p->prepare("INSERT INTO items (name, location, quantity, notes, search, created_at, updated_at) VALUES (?,?,?,?,?,'2026-09-01 10:00:00','2026-09-01 10:00:00')");
foreach ([['Drill', 'box 1023', 1], ['Lego', 'Box 1023', 2], ['Lamp', '  BOX   1023 ', 1], ['Tent', 'box 10', 1], ['Πρίζα', 'Κουτί 5', 1], ['Καλώδιο', 'κουτι 5', 2], ['Loose', '   ', 1]] as $it) {
    $ins->execute([$it[0], $it[1], $it[2], 'n', strtolower($it[0])]);
}
$p = null;
$ms = new Server($m1);
T::eq(200, $ms->client()->get('/')['status'], 'app starts on v1 database');
$p = sqlite("$m1/catalog.sqlite");
T::eq(3, (int)$p->query('PRAGMA user_version')->fetchColumn(), 'upgraded to v3');
T::eq(migration_versions(), $p->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN), 'adopted into schema_migrations, later migrations applied');
T::ok(str_contains((string)$p->query("SELECT name FROM schema_migrations WHERE version = '0001'")->fetchColumn(), 'legacy v1'), '0001 marked as adopted from legacy v1');
T::eq(0, (int)$p->query("SELECT COUNT(*) FROM locations WHERE sort_key = ''")->fetchColumn(), 'sort keys back-filled');
T::eq(['box 1023', 'box 10', 'Κουτί 5'], $p->query('SELECT name FROM locations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'locations de-duplicated (trim, case, spacing, accents)');
T::eq(['Drill' => 'box 1023', 'Lego' => 'box 1023', 'Lamp' => 'box 1023', 'Tent' => 'box 10', 'Πρίζα' => 'Κουτί 5', 'Καλώδιο' => 'Κουτί 5', 'Loose' => null],
    $p->query('SELECT i.name, l.name AS l FROM items i LEFT JOIN locations l ON l.id = i.location_id ORDER BY i.id')->fetchAll(PDO::FETCH_KEY_PAIR), 'every item linked (blank → unassigned)');
T::eq(0, (int)$p->query('SELECT COUNT(*) FROM items WHERE category_id IS NOT NULL')->fetchColumn(), 'category_id column added (all NULL)');
T::eq(1, count(glob("$m1/catalog-v1-backup-*.sqlite")), 'v1 backup snapshot written');
T::eq('ok', $p->query('PRAGMA integrity_check')->fetchColumn(), 'integrity_check ok');
T::eq(['Lamp', 'Lego', 'Drill'], titles($ms->client()->json('/?q=' . rawurlencode('box 1023'))), 'search works after upgrade');
$ms->stop();

/* ======================================================================= */
$makeV2 = function (string $dir, bool $preAddCategory = false): void {
    $p = sqlite("$dir/catalog.sqlite");
    $p->exec(<<<SQL
        CREATE TABLE locations (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
            is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0,1)), created_at TEXT NOT NULL);
        CREATE UNIQUE INDEX idx_locations_one_default ON locations(is_default) WHERE is_default = 1;
        CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL,
            location_id INTEGER REFERENCES locations(id) ON DELETE SET NULL, quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
            notes TEXT NOT NULL DEFAULT '', image TEXT, search TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE INDEX idx_items_location ON items(location_id);
        INSERT INTO locations (name, name_key, is_default, created_at) VALUES ('box 1023', 'box 1023', 0, '2026-10-01 10:00:00'), ('attic', 'attic', 1, '2026-10-01 10:00:00');
        INSERT INTO items (name, location_id, quantity, search, created_at, updated_at) VALUES
            ('Thing 1', 2, 1, 'thing 1', '2026-10-01 10:00:00', '2026-10-01 10:00:00'),
            ('Thing 2', 1, 4, 'thing 2', '2026-10-01 10:00:00', '2026-10-01 10:00:00'),
            ('Thing 3', NULL, 1, 'thing 3', '2026-10-01 10:00:00', '2026-10-01 10:00:00');
        PRAGMA user_version = 2;
    SQL);
    if ($preAddCategory) $p->exec('ALTER TABLE items ADD COLUMN category_id INTEGER');
};

T::section('Migration v2 → v3 (adds categories)');
$m2 = $newDir('v2');
$makeV2($m2);
$ms = new Server($m2);
$mc = $ms->client();
T::eq(200, $mc->get('/')['status'], 'app starts on v2 database');
$p = sqlite("$m2/catalog.sqlite");
T::eq(3, (int)$p->query('PRAGMA user_version')->fetchColumn(), 'upgraded to v3');
T::eq(1, (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'categories'")->fetchColumn(), 'categories table created');
T::eq(['Thing 1' => 'attic', 'Thing 2' => 'box 1023', 'Thing 3' => null], $p->query('SELECT i.name, l.name FROM items i LEFT JOIN locations l ON l.id = i.location_id ORDER BY i.id')->fetchAll(PDO::FETCH_KEY_PAIR), 'items keep their locations');
T::eq('attic', $p->query('SELECT name FROM locations WHERE is_default = 1')->fetchColumn(), 'default location kept');
T::eq(1, count(glob("$m2/catalog-v2-backup-*.sqlite")), 'v2 backup snapshot written');
T::eq(3, (int)sqlite(glob("$m2/catalog-v2-backup-*.sqlite")[0])->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'backup holds the original rows');
T::eq(0, (int)$p->query('SELECT COUNT(*) FROM locations')->fetchColumn() - 2, 'legacy upgrade does not add starter data');
$mt = $mc->csrf();
$r = $mc->api('/save', ['csrf' => $mt, 'name' => 'New', 'location_id' => '1', 'category_id' => '__new', 'new_category' => 'Books']);
T::ok($r['status'] === 200, 'can create categorized items after upgrade');
$ms->stop();

T::section('Migration rollback on failure');
$mr = $newDir('rollback');
$makeV2($mr, true);   // a conflicting pre-existing column makes the v3 step fail
$ms = new Server($mr);
T::eq(500, $ms->client()->get('/')['status'], 'failing upgrade → 500 error page');
$ms->stop();
$p = sqlite("$mr/catalog.sqlite");
T::eq(2, (int)$p->query('PRAGMA user_version')->fetchColumn(), 'database left at v2');
T::eq(0, (int)$p->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('categories', 'schema_migrations')")->fetchColumn(), 'partial changes rolled back (no categories, no schema_migrations)');
T::eq(3, (int)$p->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'no items lost');

exit(T::summary());
