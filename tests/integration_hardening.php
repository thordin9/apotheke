<?php
declare(strict_types=1);

/*
 * Request hardening over HTTP (SEC-M3, SEC-L2): Host allow-list, same-origin rule in no-login mode,
 * sessions only on demand, and the restore pre-check. Included by tests/integration.php.
 * @var Closure(string):string $newDir
 */

$sessFiles = fn(string $d): int => count(glob("$d/sessions/sess_*") ?: []);
$setsSid = function (array $r): bool {
    foreach ($r['raw'] as $l) if (stripos($l, 'set-cookie: apothekesid=') === 0) return true;
    return false;
};

T::section('Host allow-list (SEC-M3)');
$hd = $newDir('hosts');
$hs = new Server($hd);
$port = (int)parse_url($hs->base, PHP_URL_PORT);
$hc = $hs->client();
$r = $hc->get('/', ['Host: other.example']);
T::eq(421, $r['status'], 'unknown host name → 421');
T::ok(!str_contains($r['body'], 'other.example'), 'the refused name is not echoed back');
T::eq(421, $hc->get('/item/1/label', ['Host: other.example'])['status'], 'label pages are refused for an unknown host too');
T::eq(421, $hc->get('/healthz', ['Host: other.example'])['status'], 'health checks follow the same rule');
T::eq(200, $hc->get('/', ["Host: 127.0.0.1:$port"])['status'], 'IPv4 address with port accepted');
T::eq(200, $hc->get('/', ['Host: 192.168.1.10'])['status'], 'any IPv4 address accepted (LAN access by IP)');
T::eq(200, $hc->get('/', ["Host: [::1]:$port"])['status'], 'bracketed IPv6 address with port accepted');
T::eq(200, $hc->get('/', ['Host: [fd00::10]'])['status'], 'bracketed IPv6 address without port accepted');
T::eq(200, $hc->get('/', ["Host: localhost:$port"])['status'], 'localhost accepted');
T::eq(200, $hc->get('/', ['Host: ' . gethostname()])['status'], 'the machine/container host name accepted');
T::eq(421, $hc->get('/', ['Host: localhost:99999'])['status'], 'out-of-range port refused');
T::eq(421, $hc->get('/', ['Host: ::1'])['status'], 'unbracketed IPv6 refused');
$hs->stop();

$hs = new Server($hd, ['ALLOWED_HOSTS' => 'apotheke.lan, stock.example.org:8443', 'PUBLIC_URL' => 'https://inv.example.net']);
$hc = $hs->client();
T::eq(200, $hc->get('/', ['Host: apotheke.lan:9091'])['status'], 'ALLOWED_HOSTS name accepted on any port');
T::eq(200, $hc->get('/', ['Host: STOCK.example.org'])['status'], 'ALLOWED_HOSTS entry with a port, case-insensitive');
T::eq(421, $hc->get('/', ['Host: other.lan'])['status'], 'names outside ALLOWED_HOSTS still refused');
T::eq(200, $hc->get('/', ['Host: 10.0.0.5:9091'])['status'], 'addresses keep working with ALLOWED_HOSTS set');
$hs->stop();
// PUBLIC_URL counts once it is the stored setting (a new database takes the env value as its default)
$hd2 = $newDir('hosts-pub');
$hs = new Server($hd2, ['PUBLIC_URL' => 'https://inv.example.net']);
$hc = $hs->client();
T::eq(200, $hc->get('/', ['Host: inv.example.net'])['status'], "PUBLIC_URL's host accepted");
T::eq(421, $hc->get('/', ['Host: inv.example.net.evil.example'])['status'], 'a longer name that only starts with it is refused');
$hs->stop();

T::section('Same-origin rule with sign-in off (SEC-M3)');
$od = $newDir('origin');
$os = new Server($od);
$oc = $os->client();
$otok = $oc->csrf();
$ocount = fn(): int => (int)testdb($od)->query('SELECT COUNT(*) FROM locations')->fetchColumn();
T::eq(200, $oc->api('/locations', ['csrf' => $otok, 'name' => 'x1'])['status'], 'no Origin and no Sec-Fetch-Site (curl, old clients) allowed');
$base0 = $ocount();
T::eq(403, $oc->post('/locations', ['csrf' => $otok, 'name' => 'xs'], [], ['Sec-Fetch-Site: cross-site'])['status'], 'Sec-Fetch-Site: cross-site → 403');
T::eq(403, $oc->post('/locations', ['csrf' => $otok, 'name' => 'xs'], [], ['Sec-Fetch-Site: same-site'])['status'], 'Sec-Fetch-Site: same-site → 403');
T::eq(403, $oc->post('/locations', ['csrf' => $otok, 'name' => 'xo'], [], ['Origin: http://other.example'])['status'], 'foreign Origin → 403');
T::eq(403, $oc->post('/locations', ['csrf' => $otok, 'name' => 'xn'], [], ['Origin: null'])['status'], 'Origin: null → 403');
T::eq(403, $oc->post('/locations', ['csrf' => $otok, 'name' => 'xo'], [], ['Origin: ' . str_replace('http://', 'https://', $oc->base)])['status'], 'same host, other scheme → 403');
T::eq($base0, $ocount(), 'refused requests changed nothing');
T::ok($oc->post('/locations', ['csrf' => $otok, 'name' => 'ok1'], [], ['Sec-Fetch-Site: same-origin', 'X-Requested-With: fetch', 'Accept: application/json'])['status'] === 200, 'Sec-Fetch-Site: same-origin allowed');
T::ok($oc->post('/locations', ['csrf' => $otok, 'name' => 'ok2'], [], ['Origin: ' . $oc->base, 'X-Requested-With: fetch', 'Accept: application/json'])['status'] === 200, 'own Origin allowed');
T::ok($oc->post('/locations', ['csrf' => $otok, 'name' => 'ok3'], [], ['Sec-Fetch-Site: none', 'X-Requested-With: fetch', 'Accept: application/json'])['status'] === 200, 'Sec-Fetch-Site: none (typed or bookmarked) allowed');
T::eq($base0 + 3, $ocount(), 'allowed requests were applied');
T::eq(200, $oc->get('/', ['Sec-Fetch-Site: cross-site'])['status'], 'safe methods are never refused (links from other sites work)');
$os->stop();

T::section('Sessions on demand (SEC-L2)');
// sign-in off
$sd2 = $newDir('sess-open');
$s2 = new Server($sd2);
$before = $sessFiles($sd2);
foreach (['/no/such/page' => 404, '/favicon.ico' => 200, '/theme.css' => 200, '/labels.css' => 200, '/manifest.webmanifest' => 200, '/healthz' => 200, '/health' => 200, '/backup/restore/check' => 204] as $u => $want) {
    $r = (new Client($s2->base))->get($u);
    T::ok($r['status'] === $want && !$setsSid($r), "cookieless GET $u → $want without a session cookie");
}
$junk = new Client($s2->base);
$junk->cookies['apothekesid'] = str_repeat('a', 26);
T::eq(404, $junk->get('/no/such/page')['status'], 'a made-up session cookie on a 404');
T::eq(404, (new Client($s2->base))->req('HEAD', '/no/such/page')['status'], 'HEAD of an unknown page');
T::eq($before, $sessFiles($sd2), 'no session file for 404s, branding, health, the restore check or a made-up cookie');
$r = (new Client($s2->base))->get('/');
T::ok($r['status'] === 200 && $setsSid($r) && preg_match('/name="csrf" content="[a-f0-9]{64}"/', $r['body']) === 1, 'a page with forms starts the session and carries a token');
T::eq($before + 1, $sessFiles($sd2), 'exactly one session file for that page');
$s2->stop();

// sign-in on
$sd3 = $newDir('sess-pw');
$s3 = new Server($sd3, ['APP_PASSWORD' => 's3cret-pw']);
$anon = new Client($s3->base);
$before = $sessFiles($sd3);
$r = $anon->get('/');
T::ok($r['status'] === 303 && !$setsSid($r), 'anonymous / → /login without a session');
T::eq(303, $anon->get('/no/such/page')['status'], 'anonymous 404 path → /login');
T::eq(419, $anon->post('/login', ['username' => 'admin', 'password' => 's3cret-pw'])['status'], 'cookieless sign-in POST → 419');
T::eq(401, $anon->get('/backup/restore/check')['status'], 'restore check → 401 when not signed in');
T::eq(303, $anon->post('/backup/restore', ['csrf' => 'x'])['status'], 'restore POST without a session → /login');
T::eq($before, $sessFiles($sd3), 'no session file for anonymous redirects, refused POSTs or the restore check');
$r = $anon->get('/login');
T::ok($r['status'] === 200 && $setsSid($r), 'the sign-in page starts a session (it has a form)');
T::eq($before + 1, $sessFiles($sd3), 'one session file for the sign-in page');
$ltok = preg_match('/name="csrf" value="([a-f0-9]+)"/', $r['body'], $lm) ? $lm[1] : '';
T::eq(303, $anon->post('/login', ['csrf' => $ltok, 'username' => 'admin', 'password' => 's3cret-pw'])['status'], 'sign-in works with the on-demand session');
T::eq(204, $anon->get('/backup/restore/check')['status'], 'restore check → 204 for an admin');
T::eq(200, $anon->get('/backup')['status'], 'signed-in pages work');
// a signed-in user without the Backup permission (#70 N-1)
$r = $anon->post('/settings/users', ['csrf' => $anon->csrf(), 'username' => 'eddie', 'role' => 'editor', 'new' => 'eddie-password', 'confirm' => 'eddie-password']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=user-added'), 'editor account added');
$ed = new Client($s3->base);
$ed->post('/login', ['csrf' => $ed->csrf(), 'username' => 'eddie', 'password' => 'eddie-password']);
T::eq(403, $ed->get('/backup')['status'], 'the editor has no Backup permission');
$r = $ed->get('/backup/restore/check');
T::ok($r['status'] === 403 && $r['body'] === '', 'restore check → 403 for a signed-in user without Backup');
$s3->stop();
