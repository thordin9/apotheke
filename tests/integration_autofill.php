<?php
declare(strict_types=1);

/*
 * AI autofill client against tests/stubs/autofill-stub.php (a PHP stand-in for the service's echo backend).
 * Included by tests/integration.php (shares $newDir, T, Server, Client).
 */

T::section('AI autofill: settings and Test connection');
$stub = new StubServer();
$afd = $newDir('autofill');
$afs = new Server($afd);
$afdb = testdb($afd);
$af = $afs->client();
$at = $af->csrf();
$afSet = function (array $v) use ($afdb): void {
    foreach ($v as $k => $val) {
        $afdb->prepare('DELETE FROM settings WHERE name = ?')->execute([$k]);
        $afdb->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?,?,?)')->execute([$k, json_encode($val), gmdate('Y-m-d H:i:s')]);
    }
};
$afGet = fn(string $k) => json_decode((string)$afdb->query("SELECT value FROM settings WHERE name = '$k'")->fetchColumn(), true);
$page = $af->get('/settings/autofill');
T::eq(200, $page['status'], 'GET /settings/autofill → 200');
T::ok(str_contains($page['body'], 'name="autofill_enabled"') && str_contains($page['body'], 'name="autofill_url"') && str_contains($page['body'], 'name="autofill_timeout"'), 'section shows switch, address and timeout');
T::ok(str_contains($page['body'], 'id="af-test"') && str_contains($page['body'], 'id="af-token-form"'), 'Test connection button and token form');
T::ok(str_contains($page['body'], 'No token set.'), 'no token by default');
T::ok(str_contains($af->get('/settings')['body'], 'href="/settings/autofill"'), 'Control Panel home links the AI autofill section');
T::ok($afGet('autofill_enabled') === false || $afGet('autofill_enabled') === null, 'autofill is off by default');

$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base, 'autofill_timeout' => '10']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved'), 'settings saved');
T::eq(true, $afGet('autofill_enabled'), 'switch stored in the DB');
T::eq($stub->base, $afGet('autofill_url'), 'address stored in the DB');
T::eq(10, $afGet('autofill_timeout'), 'timeout stored in the DB');
foreach (['ftp://example.org', 'not a url', 'javascript:alert(1)'] as $bad) {
    T::eq(422, $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $bad, 'autofill_timeout' => '10'])['status'], "address \"$bad\" rejected");
}
T::eq(422, $af->post('/settings/autofill', ['csrf' => $at, 'autofill_url' => $stub->base, 'autofill_timeout' => '1'])['status'], 'timeout below 3 s rejected');
T::eq(422, $af->post('/settings/autofill', ['csrf' => $at, 'autofill_url' => $stub->base, 'autofill_timeout' => '121'])['status'], 'timeout above 120 s rejected');
T::eq(419, $af->post('/settings/autofill', ['csrf' => 'nope', 'autofill_url' => 'http://evil.example'])['status'], 'settings save without CSRF token → 419');
T::eq($stub->base, $afGet('autofill_url'), '… and nothing changed');

// token: write-only
$secret = 'tok-' . bin2hex(random_bytes(12));
T::eq(419, $af->post('/settings/autofill/token', ['csrf' => 'nope', 'token' => $secret])['status'], 'token save without CSRF → 419');
T::eq(422, $af->post('/settings/autofill/token', ['csrf' => $at, 'token' => 'has space'])['status'], 'token with spaces rejected');
$r = $af->post('/settings/autofill/token', ['csrf' => $at, 'token' => $secret]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=token-saved'), 'token saved');
T::eq($secret, $afGet('autofill_token'), 'token stored server-side');
$page = $af->get('/settings/autofill?done=token-saved')['body'];
T::ok(str_contains($page, 'A token is <b>set</b>') && !str_contains($page, $secret), 'page says a token is set but never shows it');
T::ok(!str_contains($af->get('/settings/export.json')['body'], $secret) && !str_contains($af->get('/settings/export.json')['body'], 'autofill_token'), 'token not in the settings export');
if (class_exists('ZipArchive')) {
    $bz = $af->get('/backup.zip');
    T::ok($bz['status'] === 200 && !str_contains($bz['body'], $secret), 'token not in backup.zip (dump is uncompressed-searchable or absent)');
}
$r = $af->post('/settings/import', ['csrf' => $at], ['file' => ['name' => 's.json', 'type' => 'application/json', 'data' => json_encode(['format' => 'apotheke-settings', 'settings' => ['autofill_token' => 'injected']])]]);
T::eq($secret, $afGet('autofill_token'), 'settings import cannot set the token');

// Test connection
$test = fn(string $url, string $timeout = '5') => $af->api('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $url, 'autofill_timeout' => $timeout]);
$r = $test($stub->base);
T::ok(($r['json']['ok'] ?? null) === true && ($r['json']['info']['name'] ?? '') === 'echo', 'Test connection: ok with the echo stub', json_encode($r['json']));
T::eq(['name', 'category', 'tags', 'description'], $r['json']['info']['capabilities'] ?? null, '… reports capabilities');
T::ok(str_contains($r['json']['html'] ?? '', 'id="af-name">echo<') && !str_contains($r['body'], $secret), '… rendered result, token not echoed');
$tokUrl = $stub->base . '/t/' . $secret;
$afSet(['autofill_url' => $tokUrl, 'autofill_token' => '']);
$r = $test($tokUrl);
T::eq('unauthorized', $r['json']['code'] ?? null, 'service wants a token, none set → unauthorized');
$afSet(['autofill_token' => 'wrong']);
$r = $test($tokUrl);
T::ok(($r['json']['code'] ?? null) === 'unauthorized' && str_contains($r['json']['message'] ?? '', 'refused the token'), 'wrong token → unauthorized, "refused the token"');
$afSet(['autofill_token' => $secret]);
T::eq(true, $test($tokUrl)['json']['ok'] ?? null, 'right token → ok');
// B1: the stored token only goes to the stored address
$stub->clear();
$test($tokUrl . '/');
T::eq('Bearer ' . $secret, $stub->lastInfoAuth(), 'saved address (trailing slash normalised) → stored token sent');
foreach ([$stub->base, $stub->base . '/t/other', str_replace('127.0.0.1', 'localhost', $tokUrl), $stub->base . '/m/ok/t/' . $secret] as $foreign) {
    $stub->clear();
    $r = $test($foreign);
    T::eq(null, $stub->lastInfoAuth(), "typed address $foreign ≠ saved → no token sent");
    T::ok(!str_contains($r['body'], $secret), '… and the token is not in the answer');
}
$stub->clear();
$r = $af->api('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $stub->base . '/t/typed', 'autofill_timeout' => '5', 'autofill_token' => 'typed']);
T::ok(($r['json']['ok'] ?? null) === true && $stub->lastInfoAuth() === 'Bearer typed', 'foreign address with a token typed in the form → only the typed token is sent');
T::eq($secret, $afGet('autofill_token'), '… and the stored token is unchanged');
$afSet(['autofill_url' => $stub->base]);
T::eq('unreachable', $test('http://127.0.0.1:1')['json']['code'] ?? null, 'nothing listening → unreachable');
$t0 = microtime(true);
$r = $test('http://10.255.255.1:8080', '10');
T::ok(($r['json']['code'] ?? null) === 'unreachable' && !str_contains($r['json']['message'] ?? '', 'within'), 'unroutable address (connect timeout) → unreachable, not "no answer within"', json_encode($r['json']));
T::ok(microtime(true) - $t0 < 6, '… after the 3 s connect timeout, not the 10 s timeout');
$t0 = microtime(true);
T::eq('timeout', $test($stub->base . '/m/slow', '3')['json']['code'] ?? null, 'slow service → timeout');
T::ok(microtime(true) - $t0 < 6, '… after the configured timeout (3 s)');
T::eq('bad_response', $test($stub->base . '/m/badinfo')['json']['code'] ?? null, 'not an API v1 /v1/info → bad_response');
T::eq('bad_response', $test($stub->base . '/m/malformed/v1')['json']['code'] ?? null, 'wrong path (404) → bad_response');
T::eq('warming_up', $test($stub->base . '/m/warming')['json']['code'] ?? null, 'ready:false → warming_up');
T::eq('not_configured', $test('')['json']['code'] ?? null, 'empty address → not_configured');
T::eq(419, $af->api('/settings/autofill/test', ['csrf' => 'nope', 'autofill_url' => $stub->base])['status'], 'Test connection without CSRF → 419');
$r = $af->post('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $stub->base, 'autofill_timeout' => '5', 'autofill_enabled' => '1']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'data-code="ok"') && str_contains($r['body'], 'value="' . $stub->base . '"'), 'no-JS Test connection renders the page with the result and the typed values');
T::eq($stub->base, $afGet('autofill_url'), 'Test connection does not save anything');

// who may change it
$r = $af->post('/settings/password', ['csrf' => $at, 'mode' => 'change', 'username' => 'root', 'new' => 'root-password', 'confirm' => 'root-password']);
$at = $af->csrf();
$af->post('/settings/users', ['csrf' => $at, 'username' => 'vic', 'role' => 'viewer', 'new' => 'vic-password', 'confirm' => 'vic-password']);
$af->post('/settings/users', ['csrf' => $at, 'username' => 'eda', 'role' => 'editor', 'new' => 'eda-password', 'confirm' => 'eda-password']);
foreach (['vic' => 'viewer', 'eda' => 'editor'] as $n => $role) {
    $u = $afs->client();
    $u->post('/login', ['csrf' => $u->csrf(), 'username' => $n, 'password' => "$n-password"]);
    $ut = $u->csrf();
    T::eq(403, $u->get('/settings/autofill')['status'], "$role cannot open the autofill settings");
    T::eq(403, $u->post('/settings/autofill', ['csrf' => $ut, 'autofill_url' => 'http://evil.example'])['status'], "$role cannot change the address");
    T::eq(403, $u->post('/settings/autofill/token', ['csrf' => $ut, 'token' => 'x'])['status'], "$role cannot set the token");
    T::eq(403, $u->api('/settings/autofill/test', ['csrf' => $ut, 'autofill_url' => $stub->base])['status'], "$role cannot run Test connection");
}
T::eq($secret, $afGet('autofill_token'), 'token unchanged after the attempts');
$r = $af->post('/settings/autofill/token', ['csrf' => $at, 'action' => 'clear']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=token-cleared') && $afGet('autofill_token') === '', 'admin can clear the token');

T::section('AI autofill: suggestions after a photo');
$png = function (int $w, int $h): string { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 200, 60, 40)); ob_start(); imagepng($im); return (string)ob_get_clean(); };
$photo = ['image' => ['name' => 'p.png', 'type' => 'image/png', 'data' => $png(3000, 2000)]];
$suggest = fn(array $files = null, ?Client $c = null, ?string $tok = null) => ($c ?? $af)->api('/autofill/suggest', ['csrf' => $tok ?? $at], $files ?? $photo);
$afSet(['autofill_enabled' => false, 'autofill_url' => $stub->base, 'autofill_timeout' => 5, 'autofill_token' => '']);
$stub->clear();
$r = $suggest();
T::ok($r['status'] === 200 && ($r['json']['code'] ?? '') === 'off' && $r['json']['ok'] === false, 'off: answers "off"', $r['body']);
T::eq(null, $stub->last(), 'off: the service is never contacted');
$form = $af->get('/new')['body'];
T::ok(!str_contains($form, 'data-autofill') && !str_contains($form, 'id="af-status"'), 'off: the item form has no autofill hooks');
$afSet(['autofill_enabled' => true]);
$form = $af->get('/new')['body'];
T::ok(str_contains($form, 'data-autofill="1"') && str_contains($form, 'id="af-status"'), 'on: the item form has the autofill hooks');
T::ok(!str_contains($form, $stub->base), 'on: the service address is not exposed to the browser');
// context: existing categories and tags
$af->api('/categories', ['csrf' => $at, 'name' => 'Power tools']);
$af->api('/save', ['csrf' => $at, 'name' => 'Old drill', 'tags' => 'garage, bosch']);
$stub->clear();
$r = $suggest();
$s = $r['json']['suggestions'] ?? [];
T::ok(($r['json']['ok'] ?? false) === true && str_starts_with((string)($s['name'] ?? ''), 'Echo item '), 'happy path: name suggested', $r['body']);
T::eq('Power tools', $s['category'] ?? null, 'category reuses the existing spelling (from context)');
T::eq(['bosch', 'garage'], $s['tags'] ?? null, 'tags from the context');
T::ok(str_starts_with((string)($s['description'] ?? ''), 'JPEG image, '), 'description suggested');
$last = $stub->last();
T::eq(['categories' => ['Power tools'], 'tags' => ['bosch', 'garage'], 'locale' => 'en'], $last['context'] ?? null, 'context sent: categories, tags, locale');
T::ok(($last['type'] ?? '') === 'JPEG' && ($last['width'] ?? 0) === 1024 && ($last['height'] ?? 0) === 683, 'a 3000×2000 PNG is sent as a 1024×683 JPEG', json_encode($last));
T::eq(null, $last['auth'] ?? null, 'no token set → no Authorization header');
// token
$afSet(['autofill_url' => $stub->base . '/t/' . $secret, 'autofill_token' => $secret]);
$stub->clear();
$r = $suggest();
T::ok(($r['json']['ok'] ?? false) === true && ($stub->last()['auth'] ?? '') === 'Bearer ' . $secret, 'token sent as a bearer token');
T::ok(!str_contains($r['body'], $secret), 'token not in the answer to the browser');
$afSet(['autofill_token' => 'wrong']);
T::eq('unauthorized', $suggest()['json']['code'] ?? null, 'bad token → unauthorized, no suggestions');
$afSet(['autofill_url' => $stub->base, 'autofill_token' => '']);
// failure modes: all soft (HTTP 200, ok:false, a message for the status line)
foreach ([['http://127.0.0.1:1', 'unreachable', 'service down'], [$stub->base . '/m/error', 'busy', 'backend error (503)'],
          [$stub->base . '/m/malformed', 'bad_response', 'malformed response'], [$stub->base . '/m/toolarge', 'too_large', 'service says too large (413)'],
          [$stub->base . '/m/unsupported', 'unsupported', 'service says unsupported type (415)'], [$stub->base . '/m/badinfo/nope', 'bad_response', 'unexpected HTTP status (404)']] as [$u, $code, $what]) {
    $afSet(['autofill_url' => $u]);
    $r = $suggest();
    T::ok($r['status'] === 200 && ($r['json']['ok'] ?? null) === false && ($r['json']['code'] ?? '') === $code && ($r['json']['message'] ?? '') !== '', "$what → $code", $r['body']);
}
$afSet(['autofill_url' => 'http://10.255.255.1:8080', 'autofill_timeout' => 10]);
$r = $suggest();
T::ok(($r['json']['code'] ?? '') === 'unreachable' && str_contains($r['json']['message'] ?? '', 'not reachable'), 'suggest: connect timeout → "not reachable", not "did not answer within"', $r['body']);
$afSet(['autofill_url' => $stub->base . '/m/empty', 'autofill_timeout' => 5]);
$r = $suggest();
T::ok(($r['json']['ok'] ?? null) === true && ($r['json']['code'] ?? '') === 'empty', 'empty answer {} → no suggestions, not an error');
$afSet(['autofill_url' => $stub->base . '/m/slow', 'autofill_timeout' => 3]);
$t0 = microtime(true);
$r = $suggest();
T::ok(($r['json']['code'] ?? '') === 'timeout' && microtime(true) - $t0 < 6, 'slow service → timeout after the configured 3 s');
$afSet(['autofill_url' => $stub->base, 'autofill_timeout' => 5]);
// odd images
$stub->clear();
$r = $suggest(['image' => ['name' => 'x.heic', 'type' => 'image/heic', 'data' => "\x00\x00\x00\x18ftypheic" . str_repeat('x', 500)]]);
T::ok(($r['json']['code'] ?? '') === 'unsupported' && ($stub->last()['bytes'] ?? 0) === 512, 'unreadable image (HEIC) passed through; service 415 → unsupported');
$r = $suggest(['image' => ['name' => 'tiny.png', 'type' => 'image/png', 'data' => $png(1, 1)]]);
T::ok(($r['json']['ok'] ?? false) === true, '1×1 image is fine');
T::eq('no_image', $suggest([])['json']['code'] ?? null, 'no image → no_image');
// saving still works (suggest never blocks): plain save while the service is down
$afSet(['autofill_url' => 'http://127.0.0.1:1']);
$r = $af->api('/save', ['csrf' => $at, 'name' => 'Saved while down'], ['photo' => ['name' => 'p.png', 'type' => 'image/png', 'data' => $png(50, 50)]]);
T::ok($r['status'] === 200 && ($r['json']['ok'] ?? false), 'saving an item with a photo works while the service is down');
// security
T::eq(419, $suggest(null, null, 'nope')['status'], 'suggest without CSRF → 419');
$vi = $afs->client();
$vi->post('/login', ['csrf' => $vi->csrf(), 'username' => 'vic', 'password' => 'vic-password']);
T::eq(403, $suggest(null, $vi, $vi->csrf())['status'], 'viewer cannot ask for suggestions');
T::ok(!str_contains($vi->get('/')['body'], 'data-autofill'), 'viewer pages have no autofill hooks');
$anon = $afs->client();
T::ok(in_array($anon->api('/autofill/suggest', ['csrf' => $anon->csrf()], $photo)['status'], [401, 403], true), 'signed-out visitor cannot ask for suggestions');

T::ok(!$afs->logHas($secret), 'token never written to the server log');
$afs->stop();
$stub->stop();
