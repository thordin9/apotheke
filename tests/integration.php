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

T::section('Branding: icons and manifest');
foreach (['/favicon.ico' => ['image/vnd.microsoft.icon', 'image/x-icon'], '/assets/favicon.svg' => ['image/svg+xml'], '/assets/icon.svg' => ['image/svg+xml'],
          '/assets/apple-touch-icon.png' => ['image/png'], '/assets/icon-192.png' => ['image/png'], '/assets/icon-512.png' => ['image/png'],
          '/assets/icon-maskable-512.png' => ['image/png'], '/manifest.webmanifest' => ['application/manifest+json']] as $u => $types) {
    $br = $c->get($u);
    $ct = strtolower(trim(explode(';', $br['headers']['content-type'] ?? '')[0]));
    T::ok($br['status'] === 200 && in_array($ct, $types, true) && strlen($br['body']) > 100, "$u served as " . $types[0]);
}
$man = json_decode($c->get('/manifest.webmanifest')['body'], true);
T::eq(['#C8502D', '#FBF1E3'], [$man['theme_color'] ?? '', $man['background_color'] ?? ''], 'manifest uses the brand colours');
$sizes = [];
foreach ($man['icons'] as $ic) {
    $sizes[$ic['sizes'] . ' ' . ($ic['purpose'] ?? 'any')] = true;
    T::eq(200, $c->get($ic['src'])['status'], 'manifest icon ' . $ic['src'] . ' exists');
}
T::ok(isset($sizes['192x192 any'], $sizes['512x512 any'], $sizes['512x512 maskable']), 'manifest has 192, 512 and a maskable icon');
[$pw, $ph] = getimagesizefromstring($c->get('/assets/apple-touch-icon.png')['body']);
T::eq([180, 180], [$pw, $ph], 'apple-touch-icon is 180×180');
$home = $c->get('/')['body'];
T::ok(str_contains($home, '<link rel="icon" href="/favicon.ico"') && str_contains($home, 'type="image/svg+xml"') && str_contains($home, 'rel="apple-touch-icon"'), 'pages link favicon (ico + svg) and touch icon');
T::ok(preg_match('#<h1 class="brand"><img class="brand-logo" src="/assets/icon\.svg\?v=\w+"[^>]*alt=""#', $home) === 1, 'header shows the logo');

T::section('HEAD');
$headHome = $c->req('HEAD', '/');
T::eq($r['status'], $headHome['status'], 'HEAD / status matches GET /');
T::eq(200, $headHome['status'], 'HEAD / is 200');
T::eq('no-store', $r['headers']['cache-control'] ?? '', 'GET / sends Cache-Control: no-store');
T::eq(404, $c->req('HEAD', '/no/such/page')['status'], 'HEAD unknown path is 404');
T::eq(200, $c->req('HEAD', '/healthz')['status'], 'HEAD /healthz is 200');
foreach (['/new', '/stats', '/labels', '/backup', '/activity', '/lent', '/filters', '/barcode', '/locations', '/categories', '/export.csv'] as $hp) {
    T::eq(200, $c->req('HEAD', $hp)['status'], "HEAD $hp is 200");
}
T::eq(404, $c->req('HEAD', '/save')['status'], 'HEAD /save (POST-only) is 404');
T::eq(404, $c->req('HEAD', '/bulk')['status'], 'HEAD /bulk (POST-only) is 404');

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
$c->post('/categories/' . $ids['cat']['Electronics'] . '/default', ['csrf' => $tok, 'on' => '0']);
T::eq('Tools ★', selected_option($c->get('/new')['body'], 'category_id'), 'clearing a non-default category leaves the real default');

T::section('Create items + uploads');
$big = img_jpeg(4000, 3000);
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'Cordless drill', 'location_id' => $ids['loc']['box 1023'], 'category_id' => $ids['cat']['Tools'], 'quantity' => 1], ['photo' => jpg_file($big)]);
$drill = $savedId($r);
T::ok($r['status'] === 200 && $drill > 0, 'create item with 4000×3000 JPEG', $r['body']);
$one = $c->get('/')['body'];
T::ok(str_contains($one, 'class="stats">1 item · 1 pc<'), 'header stats use singular for one item / one pc');
T::ok(str_contains($one, 'st-mini">1 item · 1 pc<'), 'Overview mini summary uses singular');
T::eq(200, $c->req('HEAD', "/item/$drill")['status'], 'HEAD /item/{id} is 200');
T::eq('no-store', $c->get("/item/$drill")['headers']['cache-control'] ?? '', 'item page sends no-store');
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
$r = $c->api('/save', ['csrf' => $tok, 'name' => 'BadQty', 'quantity' => 'abc']);
T::ok($r['status'] === 422 && str_contains((string)($r['json']['error'] ?? ''), 'Quantity'), 'invalid quantity rejected', (string)$r['body']);
T::eq(0, (int)$db->query("SELECT COUNT(*) FROM items WHERE name = 'BadQty'")->fetchColumn(), 'invalid quantity wrote nothing');
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
$soloCat = (int)$c->api('/categories', ['csrf' => $tok, 'name' => 'SoloCat'])['json']['id'];
$soloItem = $savedId($c->api('/save', ['csrf' => $tok, 'name' => 'Solo item', 'category_id' => $soloCat]));
$soloPage = $c->get("/categories/$soloCat/delete")['body'];
T::ok(str_contains($soloPage, '1 item is') && !str_contains($soloPage, '1 items are'), 'confirm page singular grammar for one item');
$c->post("/categories/$soloCat/delete", ['csrf' => $tok, 'action' => 'unassign']);
$c->post("/item/$soloItem/delete", ['csrf' => $tok]);
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
T::eq(['id', 'name', 'location', 'category', 'quantity', 'notes', 'image', 'created_at', 'updated_at', 'tags', 'value', 'currency', 'purchase_date', 'serial', 'min_qty', 'lent_to', 'lent_at', 'lent_due', 'expires_on', 'barcode'], $lines[0], 'CSV header');
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
$nBox2 = (int)$db->query("SELECT COUNT(*) FROM items WHERE location_id = $box2")->fetchColumn();
$nounBox2 = $nBox2 . ' item' . ($nBox2 === 1 ? '' : 's');
T::ok(str_contains($r['body'], $nounBox2), 'location label subtitle uses singular/plural item count');
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
$us = new Server($dir, ['PUBLIC_URL' => 'https://env.example.lan']);
T::ok(!str_contains($us->client()->get("/labels?loc=$box2")['body'], 'env.example.lan'), 'PUBLIC_URL env on an existing database: the stored setting wins');
$us->stop();
$c->post('/settings/general', ['csrf' => $tok, 'app_name' => 'Apotheke', 'app_tagline' => 'the storehouse for your stuff', 'public_url' => 'https://stuff.example.lan/']);
$r = $c->get("/labels?loc=$box2");
T::ok(str_contains($r['body'], 'https://stuff.example.lan/?loc=' . $box2), 'public address setting is used for label links (trailing slash trimmed)');
preg_match('#<svg class="qr".*?</svg>#s', $r['body'], $qm);
if (($dec = qr_decode_svg($qm[0] ?? '')) !== null) T::eq('https://stuff.example.lan/?loc=' . $box2, $dec, 'QR encodes the public address link');
$c->post('/settings/general/reset', ['csrf' => $tok]);
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
T::eq(['AV, Cables, Ηλεκτρικά', '1234.50', 'EUR', '2024-03-01', 'SN-42/X', '', '', '', '', '', ''], array_slice($hrow, 9), 'CSV includes tags, value, currency, date, serial');
$cs = new Server($newDir('cur'), ['CURRENCY' => 'CHF']);
T::ok(str_contains($cs->client()->get('/new')['body'], 'Purchase price <em>CHF</em>'), 'CURRENCY seeds the currency setting of a new database');
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
while ((int)$db->query("SELECT COUNT(*) FROM item_photos WHERE item_id = $drill")->fetchColumn() < 12) {
    $r = $c->api("/item/$drill/photos", ['csrf' => $tok], ['photo' => jpg_file(img_jpeg(40, 40))]);
    if (($r['status'] ?? 0) !== 200) break;
}
T::eq(12, (int)$db->query("SELECT COUNT(*) FROM item_photos WHERE item_id = $drill")->fetchColumn(), 'filled gallery to MAX_PHOTOS');
$r = $c->api("/item/$drill/photos", ['csrf' => $tok], ['photo' => jpg_file(img_jpeg(40, 40))]);
T::ok($r['status'] === 422 && str_contains((string)($r['json']['error'] ?? ''), 'at most 12'), '13th photo rejected over HTTP', (string)$r['body']);
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
T::ok(str_contains($c->get('/?select=1&q=Bulk&bulk_done=1&bulk_act=move')['body'], 'Moved 1 item')
    && !str_contains($c->get('/?select=1&q=Bulk&bulk_done=1&bulk_act=move')['body'], 'Moved 1 items'), 'toast singular for one item');
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
T::ok(str_contains($lent, '2 items out') && str_contains($lent, '1 overdue'), '/lent summary plural + overdue');
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
T::ok(str_contains($c->get('/lent')['body'], '1 item out') && !str_contains($c->get('/lent')['body'], '1 items out'), '/lent summary singular when one item remains out');
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
$today = (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d');
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
$v1 = new Server($newDir('val1'), ['CURRENCY' => 'EUR', 'SEED_DEFAULTS' => '0']);
$v1c = $v1->client();
$v1t = $v1c->csrf();
$v1c->api('/save', ['csrf' => $v1t, 'name' => 'Only', 'value' => '9.99']);
T::ok(str_contains($v1c->get('/stats')['body'], '1 item with a price') && !str_contains($v1c->get('/stats')['body'], '1 items with a price'), 'value card singular for one priced item');
$v1->stop();
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
T::eq(404, $c->api('/item/999999/delete', ['csrf' => $tok])['status'], 'delete missing item → 404 (no false toast)');
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

T::section('Barcodes');
$bs = new Server($newDir('bc'), ['SEED_DEFAULTS' => '0']);
$bc = $bs->client();
$bt = $bc->csrf();
$loc = fn(array $r) => $r['headers']['location'] ?? '';
T::eq('/new?barcode=4006381333931', $loc($bc->get('/barcode/4006381333931')), 'unknown code → new item form with the code');
T::ok(str_contains($bc->get('/new?barcode=4006381333931')['body'], 'id="barcode" value="4006381333931"'), 'new item form prefilled');
T::eq('/barcode/4006381333931', $loc($bc->get('/barcode?code=' . rawurlencode(' 4006381 333931 '))), 'manual lookup form → /barcode/{code} (spaces removed)');
T::ok(str_contains($bc->get('/barcode')['body'], 'id="code"') && str_contains($bc->get('/barcode')['body'], 'data-scan="#code"'), 'lookup page with manual field and (JS-enabled) Scan button');
T::eq('/barcode', $loc($bc->get('/barcode/%20')), 'blank code → lookup page');
$br = $bc->post('/save', ['csrf' => $bt, 'name' => 'Pencils', 'barcode' => '4006381333931']);
$bId = (int)$bc->json('/?q=pencils')['json']['total'] ? (int)preg_replace('#\D#', '', $loc($bc->get('/barcode/4006381333931'))) : 0;
T::ok($bId > 0 && $loc($bc->get('/barcode/4006381333931')) === "/item/$bId", 'known code → its item');
T::ok(str_contains($bc->get("/item/$bId")['body'], '<dt>Barcode</dt><dd>4006381333931'), 'item page shows the barcode');
T::eq(['Pencils'], titles($bc->json('/?q=4006381333931')), 'search by barcode');
T::eq(['Pencils'], titles($bc->json('/?q=333931')), 'search by part of the barcode');
$bc->api('/save', ['csrf' => $bt, 'name' => 'Pencils (box 2)', 'barcode' => '4006381333931']);
T::eq('/?q=4006381333931', $loc($bc->get('/barcode/4006381333931')), 'shared code → list of matches');
$bc->api('/save', ['csrf' => $bt, 'name' => 'Odd', 'barcode' => 'a/b?c#d']);
T::ok(str_starts_with($loc($bc->get('/barcode/' . rawurlencode('a/b?c#d'))), '/item/'), 'codes with URL characters round-trip');
$bc->api('/save', ['csrf' => $bt, 'id' => $bId, 'name' => 'Pencils', 'barcode' => '']);
T::ok(str_contains($bc->get("/item/$bId")['body'], 'Barcode'), 'history records the barcode change');
$bcsv = array_map('str_getcsv', array_filter(explode("\n", ltrim($bc->get('/export.csv')['body'], "\xEF\xBB\xBF"))));
$bh = array_flip($bcsv[0]);
$brow = array_values(array_filter($bcsv, fn($l) => ($l[1] ?? '') === 'Pencils (box 2)'))[0];
T::eq('4006381333931', $brow[$bh['barcode']], 'CSV barcode column');
$bz = sys_get_temp_dir() . '/apotheke-bc-' . bin2hex(random_bytes(3)) . '.zip';
file_put_contents($bz, $bc->get('/backup.zip')['body']);
$z = new ZipArchive(); $z->open($bz);
$bbi = json_decode((string)$z->getFromName('backup.json'), true)['tables']['items'];
$z->close(); @unlink($bz);
T::ok(in_array('4006381333931', array_column($bbi['rows'], array_search('barcode', $bbi['columns'], true)), true), 'backup includes the barcode');
$bs->stop();

T::section('Password mode (APP_PASSWORD)');
$pdir = $newDir('pw');
$ps = new Server($pdir, ['APP_PASSWORD' => 's3cret']);
$pc = $ps->client();
$r = $pc->get('/');
T::ok($r['status'] === 303 && ($r['headers']['location'] ?? '') === '/login', 'anonymous → /login');
T::eq(401, $pc->get('/media/thumb/' . str_repeat('a', 32) . '.jpg')['status'], 'media → 401');
T::eq(401, $pc->json('/?q=x')['status'], 'JSON → 401');
T::eq(303, $pc->get('/stats')['status'], 'stats fragment requires login');
T::eq(303, $pc->get('/export.csv')['status'], 'CSV export requires login');
T::eq(303, $pc->get('/backup.zip')['status'], 'backup zip requires login');
$ptok = $pc->csrf();
T::eq(419, $pc->post('/login', ['password' => 's3cret'])['status'], 'login without CSRF → 419');
$r = $pc->post('/login', ['csrf' => $ptok, 'password' => '']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'Wrong password'), 'empty password refused');
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

T::section('List pagination (PAGE_SIZE)');
$pgDir = $newDir('page');
$pgS = new Server($pgDir, ['PAGE_SIZE' => '2', 'SEED_DEFAULTS' => '0']);
$pgC = $pgS->client();
$pgTok = $pgC->csrf();
foreach (['Alpha', 'Bravo', 'Charlie'] as $n) {
    $pgC->api('/save', ['csrf' => $pgTok, 'name' => $n]);
}
$p1 = $pgC->json('/?sort=name');
T::eq(200, $p1['status'], 'page 1 JSON ok');
T::eq(3, (int)($p1['json']['total'] ?? 0), 'total is 3');
T::eq(true, (bool)($p1['json']['more'] ?? false), 'more=true when PAGE_SIZE=2 and 3 items');
T::eq(2, substr_count((string)($p1['json']['html'] ?? ''), 'class="card"'), 'page 1 returns 2 cards');
T::ok(str_contains((string)($p1['json']['count'] ?? ''), '3 items'), 'count text uses total not page size');
$p2 = $pgC->json('/?sort=name&page=2');
T::eq(1, substr_count((string)($p2['json']['html'] ?? ''), 'class="card"'), 'page 2 returns the remaining card');
T::eq(false, (bool)($p2['json']['more'] ?? true), 'more=false on last page');
$pgS->stop();

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

T::section('Control Panel (settings)');
$sd = $newDir('settings');
$ss = new Server($sd, ['SEED_DEFAULTS' => '1', 'CURRENCY' => 'CHF', 'PAGE_SIZE' => '40']);
$sc = $ss->client();
$st = $sc->csrf();
$sdb = testdb($sd);
$secKeys = [
    'general' => ['app_name', 'app_tagline', 'public_url'],
    'appearance' => ['accent', 'theme_default', 'icon_background'],
    'locale' => ['currency', 'money_format', 'currency_position', 'date_format', 'time_format', 'timezone'],
    'list' => ['page_size', 'default_sort', 'list_view', 'overview_open'],
    'items' => ['default_quantity', 'default_min_qty', 'default_tags', 'default_notes', 'default_purchase_today', 'default_expiry_months'],
    'limits' => ['expiry_soon_days', 'lend_due_days', 'saved_filters_max', 'max_photos'],
    'uploads' => ['max_upload_mb', 'image_full_max', 'image_thumb_max', 'jpeg_quality'],
    'security' => [], 'data' => [], 'system' => [],
];
$visible = array_merge(...array_values($secKeys));
$settingsTotal = count($visible) + 3; // + logo, auth_enabled, password_hash
T::eq($settingsTotal, (int)$sdb->query('SELECT COUNT(*) FROM settings')->fetchColumn(), 'migration seeds one row per setting');
T::eq('"CHF"', $sdb->query("SELECT value FROM settings WHERE name = 'currency'")->fetchColumn(), 'config/env values are the initial defaults');
T::eq('40', $sdb->query("SELECT value FROM settings WHERE name = 'page_size'")->fetchColumn(), 'PAGE_SIZE seeded');
$home = $sc->get('/');
T::ok(str_contains($home['body'], 'id="menu-settings"') && str_contains($home['body'], 'href="/settings"'), 'menu links to the Control Panel');
$sh = $sc->get('/settings');
T::ok($sh['status'] === 200 && ($sh['headers']['cache-control'] ?? '') === 'no-store', '/settings 200, no-store');
foreach ($secKeys as $sec => $keys) {
    T::ok(str_contains($sh['body'], 'id="sl-' . $sec . '"'), "home links to $sec");
    $pg = $sc->get("/settings/$sec");
    T::eq(200, $pg['status'], "/settings/$sec renders");
    foreach ($keys as $k) T::ok(str_contains($pg['body'], 'id="s-' . $k . '"'), "$sec shows $k");
}
T::eq(404, $sc->get('/settings/bogus')['status'], 'unknown section 404');
// export lists every visible setting, never the password
$ex = $sc->get('/settings/export.json');
T::ok(str_contains($ex['headers']['content-type'] ?? '', 'application/json') && str_contains($ex['headers']['content-disposition'] ?? '', 'attachment'), 'export is a JSON download');
T::eq($visible, array_keys($ex['json']['settings'] ?? []), 'export has every visible setting');
T::ok(!str_contains($ex['body'], 'password_hash'), 'export never includes the password');
// a helper that posts a whole section like the browser does
$cur = fn() => $sc->get('/settings/export.json')['json']['settings'];
$save = function (string $sec, array $over) use ($sc, &$st, $cur, $secKeys) {
    $vals = $cur();
    $f = ['csrf' => $st];
    foreach ($secKeys[$sec] as $k) {
        $v = array_key_exists($k, $over) ? $over[$k] : ($vals[$k] ?? '');
        $f[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)($v ?? '');
    }
    foreach ($over as $k => $v) if (!isset($f[$k])) $f[$k] = (string)$v;
    return $sc->post("/settings/$sec", $f);
};
// CSRF on every form
foreach (['/settings/general', '/settings/general/reset', '/settings/import', '/settings/logo', '/settings/logo/reset', '/settings/password'] as $u) {
    T::eq(419, $sc->post($u, ['app_name' => 'Hacked'])['status'], "$u needs the CSRF token");
}
T::eq('Apotheke', $cur()['app_name'], 'nothing changed without CSRF');
// validation: 422, nothing stored, error shown
$bad = $save('list', ['page_size' => '0', 'default_sort' => 'name']);
T::ok($bad['status'] === 422 && str_contains($bad['body'], 'between 1 and 500') && str_contains($bad['body'], 'aria-invalid'), 'invalid value → 422 with the message');
T::eq(40, $cur()['page_size'], 'invalid save stores nothing');
T::eq('recent', $cur()['default_sort'], 'all-or-nothing');

// general
$r = $save('general', ['app_name' => 'Shed Stock', 'app_tagline' => 'tools & bits', 'public_url' => 'https://inv.example.org/']);
T::ok(str_contains($r['headers']['location'] ?? '', '/settings/general?done=saved'), 'save redirects with a toast');
$hb = $sc->get('/')['body'];
T::ok(str_contains($hb, '<title>Shed Stock') || str_contains($hb, 'Shed Stock</title>'), 'app name in the title');
T::ok(str_contains($sc->get('/settings/general?done=saved')['body'], 'Settings saved'), 'saved toast');
T::ok(str_contains(json_encode($sc->get('/manifest.webmanifest')['json']), 'Shed Stock'), 'app name in the manifest');
T::ok(str_contains($sc->get('/labels')['body'], 'https://inv.example.org'), 'public URL used on QR labels');
// appearance
$r = $save('appearance', ['accent' => 'c8502d', 'theme_default' => 'dark', 'icon_background' => '#ffffff']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved'), 'appearance saved');
$ap = $sc->get('/settings/appearance?done=saved')['body'];
T::ok(str_contains($ap, 'C8502D') && str_contains($ap, '4.16:1'), 'contrast warning for terracotta on light');
$hb = $sc->get('/')['body'];
T::ok(preg_match('#<link rel="stylesheet" href="/theme\.css\?v=[a-f0-9]+" id="accent-css">#', $hb) === 1, 'custom accent adds the generated stylesheet');
T::ok(str_contains($hb, 'data-theme="dark"'), 'theme default applies when the device has no choice');
$css = $sc->get('/theme.css');
T::ok(str_contains($css['headers']['content-type'] ?? '', 'text/css') && str_contains($css['body'], '--accent:#') && str_contains($css['body'], 'prefers-color-scheme:dark'), '/theme.css serves light + dark variables');
T::ok(!str_contains($hb, 'style="') || !preg_match('#<style#', $hb), 'no inline style blocks (CSP)');
$csp = $sc->get('/')['headers']['content-security-policy'] ?? '';
T::ok($csp === '' || !str_contains($csp, 'unsafe-inline') || str_contains($csp, "style-src 'self'"), 'CSP without unsafe-inline styles');
$mf = $sc->get('/manifest.webmanifest');
T::ok(str_contains($mf['headers']['content-type'] ?? '', 'manifest+json') && ($mf['json']['background_color'] ?? '') !== '', 'manifest served dynamically');
T::eq(422, $save('appearance', ['accent' => 'blue'])['status'], 'invalid colour rejected');
// logo: SVG (sanitized)
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(1)</script><rect width="10" height="10" fill="#c8502d"/></svg>';
$r = $sc->post('/settings/logo', ['csrf' => $st], ['logo' => ['name' => 'l.svg', 'type' => 'image/svg+xml', 'data' => $svg]]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=logo'), 'SVG logo uploaded');
$hb = $sc->get('/')['body'];
T::ok(preg_match('#src="/brand/logo\.svg\?v=[a-f0-9]+"#', $hb) === 1, 'header uses the custom SVG logo');
$ls = $sc->get('/brand/logo.svg');
T::ok(str_contains($ls['headers']['content-type'] ?? '', 'image/svg+xml') && str_contains($ls['headers']['content-security-policy'] ?? '', 'sandbox'), 'logo served as SVG with a sandbox CSP');
T::ok(!str_contains($ls['body'], 'script') && !str_contains($ls['body'], 'onload') && str_contains($ls['body'], '<rect'), 'SVG sanitized on upload');
T::ok(str_contains($hb, 'href="/brand/logo.svg?v='), 'SVG logo is the favicon');
T::eq(422, $sc->post('/settings/logo', ['csrf' => $st], ['logo' => ['name' => 'x.svg', 'type' => 'image/svg+xml', 'data' => '<!DOCTYPE x [<!ENTITY a "b">]><svg/>']])['status'], 'SVG with entities rejected');
T::eq(422, $sc->post('/settings/logo', ['csrf' => $st], ['logo' => ['name' => 'x.gif', 'type' => 'image/gif', 'data' => 'GIF89a....']])['status'], 'other formats rejected');
T::eq(422, $sc->post('/settings/logo', ['csrf' => $st], ['logo' => ['name' => 'big.png', 'type' => 'image/png', 'data' => str_repeat('x', 2 * 1024 * 1024 + 10)]])['status'], 'logo size limit');
T::eq(422, $sc->post('/settings/logo', ['csrf' => $st], ['logo' => jpg_file(img_jpeg(32, 32))])['status'], 'too-small raster logo rejected');
// logo: PNG → icons regenerated
$icoBefore = $sc->get('/favicon.ico')['body'];
$r = $sc->post('/settings/logo', ['csrf' => $st], ['logo' => jpg_file(img_png(600, 300), 'logo.png', 'image/png')]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=logo'), 'PNG logo uploaded');
foreach (['logo.png' => 512, 'icon-32.png' => 32, 'icon-180.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512, 'icon-maskable-512.png' => 512] as $fn => $px) {
    $g = $sc->get("/brand/$fn");
    $sz = @getimagesizefromstring($g['body']) ?: [0, 0];
    T::ok($g['status'] === 200 && ($g['headers']['content-type'] ?? '') === 'image/png' && $sz[0] === $px && $sz[1] === $px, "$fn generated {$px}px");
}
$mask = imagecreatefromstring($sc->get('/brand/icon-maskable-512.png')['body']);
T::eq('ffffff', sprintf('%06x', imagecolorat($mask, 2, 2) & 0xffffff), 'maskable icon uses the icon background');
$ico = $sc->get('/favicon.ico');
T::ok($ico['body'] !== $icoBefore && str_starts_with($ico['body'], "\0\0\1\0"), 'favicon.ico regenerated');
$mf = $sc->get('/manifest.webmanifest')['json'];
T::ok(str_contains(json_encode($mf['icons'] ?? [], JSON_UNESCAPED_SLASHES), '/brand/icon-192.png'), 'manifest icons point to the generated icons');
$hb = $sc->get('/')['body'];
T::ok(str_contains($hb, 'src="/brand/logo.png?v=') && str_contains($hb, 'apple-touch-icon" href="/brand/icon-180.png?v='), 'header + apple-touch use the PNG logo');
T::eq(404, $sc->get('/brand/../catalog.sqlite')['status'] ?: 404, 'brand route only serves its files');
T::eq(404, $sc->get('/brand/other.png')['status'], 'unknown brand file 404');
$r = $sc->post('/settings/logo/reset', ['csrf' => $st]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=logo-reset') && !is_file("$sd/brand/logo.png"), 'reset to the default logo');
T::eq(404, $sc->get('/brand/logo.png')['status'], 'custom logo gone after reset');
T::ok(str_contains($sc->get('/')['body'], '/assets/'), 'default logo back');
// locale
$val = $sc->api('/save', ['csrf' => $st, 'name' => 'Money item', 'value' => '1234.5', 'purchase_date' => '2026-01-15', 'expires_on' => '2030-03-04']);
$mi = $savedId($val);
$r = $save('locale', ['currency' => 'USD', 'money_format' => 'de', 'currency_position' => 'before', 'date_format' => 'd.m.Y', 'time_format' => 'g:i A', 'timezone' => 'America/New_York']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved'), 'locale saved');
$ip = $sc->get("/item/$mi")['body'];
T::ok(str_contains($ip, 'USD 1.234,50') || str_contains($ip, 'USD&nbsp;1.234,50') || str_contains($ip, 'USD 1.234,50'), 'currency, number format and position take effect');
T::ok(str_contains($ip, '15.01.2026') && str_contains($ip, '04.03.2030'), 'date format takes effect');
T::ok(preg_match('/\d{1,2}:\d{2} (AM|PM)/', $ip) === 1, 'time format takes effect');
$ny = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('g:i A');
$ny2 = (new DateTimeImmutable('-1 minute', new DateTimeZone('America/New_York')))->format('g:i A');
T::ok(str_contains($ip, $ny) || str_contains($ip, $ny2), 'timezone takes effect');
T::eq(422, $save('locale', ['timezone' => 'Mars/Olympus'])['status'], 'unknown time zone rejected');
// list
foreach (['Zeta', 'Alpha', 'Mid'] as $n) $sc->api('/save', ['csrf' => $st, 'name' => "Sort $n"]);
$save('list', ['page_size' => '2', 'default_sort' => 'name', 'list_view' => 'compact', 'overview_open' => false]);
$lj = $sc->json('/');
T::ok(($lj['json']['more'] ?? false) === true && substr_count($lj['json']['html'] ?? '', '<article class="card') === 2, 'page size takes effect');
T::eq(['Money item', 'Sort Alpha'], array_slice(titles($lj), 0, 2), 'default sort takes effect');
$hb = $sc->get('/')['body'];
T::ok(str_contains($hb, 'is-compact'), 'compact view takes effect');
T::ok(str_contains($hb, 'id="stats" data-loaded="0"'), 'overview starts closed');
T::ok(str_contains($hb, 'data-default="name"'), 'sort menu knows the default');
// new item defaults
$locs = $sdb->query("SELECT id, name FROM locations ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$cats = $sdb->query("SELECT id, name FROM categories ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$r = $save('items', ['default_quantity' => '4', 'default_min_qty' => '2', 'default_tags' => 'garage, new', 'default_notes' => 'Bought at', 'default_purchase_today' => true, 'default_expiry_months' => '24',
                     'default_location' => (string)array_key_last($locs), 'default_category' => (string)array_key_last($cats)]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved'), 'item defaults saved');
$nw = $sc->get('/new')['body'];
T::ok(str_contains($nw, 'name="quantity" id="quantity" value="4"') || preg_match('/name="quantity"[^>]*value="4"/', $nw) === 1, 'default quantity');
T::ok(preg_match('/name="min_qty"[^>]*value="2"/', $nw) === 1, 'default min qty');
T::ok(str_contains($nw, 'garage, new'), 'default tags');
T::ok(str_contains($nw, 'Bought at'), 'default notes');
$todayNy = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
T::ok(preg_match('/name="purchase_date"[^>]*value="' . $todayNy . '"/', $nw) === 1, 'purchase date = today (in the configured zone)');
T::ok(preg_match('/name="expires_on"[^>]*value="' . (new DateTimeImmutable($todayNy))->modify('+24 months')->format('Y-m-d') . '"/', $nw) === 1, 'expiry window default');
T::eq($locs[array_key_last($locs)] . ' ★', selected_option($nw, 'location_id'), 'default location');
T::eq($cats[array_key_last($cats)] . ' ★', selected_option($nw, 'category_id'), 'default category');
$save('items', ['default_location' => '', 'default_category' => '']);
T::eq(0, (int)$sdb->query('SELECT COUNT(*) FROM locations WHERE is_default = 1')->fetchColumn(), 'default location can be cleared');
// thresholds & limits
$save('limits', ['expiry_soon_days' => '5', 'lend_due_days' => '14', 'saved_filters_max' => '1', 'max_photos' => '1']);
$sc->api('/save', ['csrf' => $st, 'name' => 'Exp ten', 'expires_on' => (new DateTimeImmutable($todayNy))->modify('+10 days')->format('Y-m-d')]);
T::ok(!in_array('Exp ten', titles($sc->json('/?exp=soon')), true), 'expiry window takes effect');
$save('limits', ['expiry_soon_days' => '30']);
T::ok(in_array('Exp ten', titles($sc->json('/?exp=soon')), true), 'wider expiry window');
T::ok(str_contains($sc->get("/item/$mi")['body'], 'value="' . (new DateTimeImmutable($todayNy))->modify('+14 days')->format('Y-m-d') . '"'), 'loan period pre-fills the due date');
T::eq(200, $sc->api('/filters', ['csrf' => $st, 'name' => 'One', 'q' => 'a'])['status'], 'first saved filter');
T::eq(422, $sc->api('/filters', ['csrf' => $st, 'name' => 'Two', 'q' => 'b'])['status'], 'saved-filter limit takes effect');
$r1 = $sc->api("/item/$mi/photos", ['csrf' => $st], ['photos[0]' => jpg_file(img_jpeg(300, 200)), 'photos[1]' => jpg_file(img_jpeg(300, 200))]);
T::ok($r1['status'] === 422 && str_contains((string)($r1['json']['error'] ?? ''), 'at most 1'), 'photo limit takes effect', $r1['body']);
// uploads
$save('uploads', ['image_full_max' => '800', 'image_thumb_max' => '200', 'jpeg_quality' => '95', 'max_upload_mb' => '1']);
$up = $sc->api('/save', ['csrf' => $st, 'name' => 'Big photo'], ['photo' => jpg_file(img_jpeg(1200, 900, 70))]);
$bi = (string)$sdb->query('SELECT image FROM items WHERE id = ' . $savedId($up))->fetchColumn();
[$fw] = @getimagesize("$sd/images/$bi") ?: [0];
[$tw] = @getimagesize("$sd/thumbs/$bi") ?: [0];
T::ok($fw === 800 && $tw === 200, "photo + thumbnail sizes take effect ($fw / $tw)");
$hiQ = filesize("$sd/images/$bi");
$save('uploads', ['jpeg_quality' => '40']);
$up2 = $sc->api('/save', ['csrf' => $st, 'name' => 'Low q'], ['photo' => jpg_file(img_jpeg(1200, 900, 70))]);
$bi2 = (string)$sdb->query('SELECT image FROM items WHERE id = ' . $savedId($up2))->fetchColumn();
T::ok(filesize("$sd/images/$bi2") < $hiQ, 'JPEG quality takes effect');
$huge = $sc->api('/save', ['csrf' => $st, 'name' => 'Too big'], ['photo' => jpg_file(random_bytes(1200 * 1024))]);
T::ok($huge['status'] >= 400 && str_contains((string)($huge['json']['error'] ?? ''), '1 MB'), 'upload limit takes effect');
// reset a section
$r = $sc->post('/settings/uploads/reset', ['csrf' => $st]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=reset') && $cur()['image_full_max'] === 1600 && $cur()['max_upload_mb'] === 20, 'reset section restores the defaults');
T::eq('Shed Stock', $cur()['app_name'], 'reset leaves other sections alone');
// import / export
$dump = $sc->get('/settings/export.json')['body'];
$save('general', ['app_name' => 'Changed']);
$im = $sc->post('/settings/import', ['csrf' => $st], ['file' => ['name' => 's.json', 'type' => 'application/json', 'data' => $dump]]);
T::ok($im['status'] === 200 && str_contains($im['body'], 'Imported ' . count($visible) . ' settings'), 'import applies every setting');
T::eq('Shed Stock', $cur()['app_name'], 'import restores values');
$bj = json_decode($dump, true);
$bj['settings']['page_size'] = 0;
$bj['settings']['password_hash'] = 'x';
$im = $sc->post('/settings/import', ['csrf' => $st, 'json' => json_encode($bj)]);
T::ok(str_contains($im['body'], 'Skipped page_size') && str_contains($im['body'], 'password_hash'), 'import reports invalid and protected keys');
T::eq(422, $sc->post('/settings/import', ['csrf' => $st, 'json' => '{"nope":1}'])['status'], 'foreign JSON rejected');
// system: bootstrap read-only
$sy = $sc->get('/settings/system')['body'];
T::ok(str_contains($sy, 'id="systable"') && str_contains($sy, 'Data directory') && !str_contains($sy, '<input type="text"'), 'bootstrap values shown read-only');
// password on/off
T::eq(422, $sc->post('/settings/password', ['csrf' => $st, 'mode' => 'change', 'new' => 'short', 'confirm' => 'short'])['status'], 'short password rejected');
$r = $sc->post('/settings/password', ['csrf' => $st, 'mode' => 'change', 'new' => 'garage-secret', 'confirm' => 'garage-secret']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=pw'), 'password set');
T::eq(200, $sc->get('/')['status'], 'this device stays signed in');
$st = $sc->csrf();
$other = $ss->client();
T::ok(str_contains($other->get('/')['headers']['location'] ?? '', '/login'), 'others must sign in');
T::ok(str_contains($other->get('/settings')['headers']['location'] ?? '', '/login'), 'Control Panel protected by the password');
T::eq(200, $other->get('/theme.css')['status'], 'theme.css stays public (sign-in page)');
$ot = $other->csrf();
$other->post('/login', ['csrf' => $ot, 'password' => 'garage-secret']);
T::eq(200, $other->get('/settings')['status'], 'sign in with the new password');
T::eq(403, $sc->post('/settings/password', ['csrf' => $st, 'mode' => 'change', 'current' => 'wrong', 'new' => 'another-one', 'confirm' => 'another-one'])['status'], 'wrong current password → 403');
$sc->post('/settings/password', ['csrf' => $st, 'mode' => 'change', 'current' => 'garage-secret', 'new' => 'another-one', 'confirm' => 'another-one']);
$st = $sc->csrf();
T::ok(str_contains($other->get('/')['headers']['location'] ?? '', '/login'), 'changing the password signs other sessions out');
$save('security', ['auth_enabled' => '0', 'password_hash' => '']);
T::eq(true, auth_on_db($sdb), 'posting the security section cannot clear the password');
$r = $sc->post('/settings/password', ['csrf' => $st, 'mode' => 'disable', 'current' => 'another-one']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=pw-off') && !auth_on_db($sdb), 'password turned off');
T::eq(200, $ss->client()->get('/')['status'], 'open again without a password');
// settings travel with backups
$bk = $sc->get('/backup.zip')['body'];
$zf = tempnam(sys_get_temp_dir(), 'bk');
file_put_contents($zf, $bk);
$z = new ZipArchive();
$z->open($zf);
$dumpJ = json_decode((string)$z->getFromName('backup.json'), true);
$z->close();
@unlink($zf);
T::ok(($dumpJ['settings']['app_name'] ?? '') === 'Shed Stock' && !isset($dumpJ['settings']['password_hash']), 'backups contain the settings (never the password)');
// env no longer overrides a configured database; the CLI
$ss->stop();
$ss = new Server($sd, ['PAGE_SIZE' => '7', 'APP_PASSWORD' => 'envpw']);
T::eq(2, $ss->client()->get('/settings/export.json')['json']['settings']['page_size'] ?? null, 'DB wins over env once seeded');
$ss->stop();
$cli = fn(string $a) => shell_exec('cd ' . escapeshellarg(ROOT) . ' && ' . implode(' ', array_map(fn($k, $v) => $k . '=' . escapeshellarg((string)$v), array_keys($e = ['DATA_DIR' => $sd, 'APOTHEKE_CONFIG' => '/nonexistent'] + test_db_env($sd)), $e)) . ' php bin/settings.php ' . $a . ' 2>&1');
T::ok(str_contains((string)$cli('set page_size 33'), 'page_size = 33'), 'CLI set');
T::ok(str_contains((string)$cli('set page_size 0'), 'between 1 and 500'), 'CLI validates');
T::eq('33', trim((string)$cli('get page_size')), 'CLI get');
T::ok(str_contains((string)$cli('password locksmith1'), 'Password set') && auth_on_db($sdb), 'CLI recovery: set a password');
T::ok(str_contains((string)$cli('password --off'), 'turned off') && !auth_on_db($sdb), 'CLI recovery: password off');

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
