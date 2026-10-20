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
T::eq(422, $af->post('/settings/autofill', ['csrf' => $at, 'autofill_url' => $stub->base, 'autofill_timeout' => '61'])['status'], 'timeout above 60 s rejected');
T::eq(303, $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base, 'autofill_timeout' => '60'])['status'], 'timeout of 60 s accepted');
$af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base, 'autofill_timeout' => '10']);
$page = $af->get('/settings/autofill')['body'];
T::ok(str_contains($page, 'placeholder="http://192.168.1.10:8080"'), 'address placeholder is an example service address, not Apotheke\'s own');
T::ok(strpos($page, 'id="set-save"') < strpos($page, 'id="af-test-result"'), 'Save comes before the Test connection result');
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
T::ok(str_contains($r['json']['html'] ?? '', '<dd>10 MiB</dd>'), '… image limit shown as 10 MiB');
T::ok(str_contains($r['json']['html'] ?? '', 'id="af-name">echo<') && !str_contains($r['body'], $secret), '… rendered result, token not echoed');
$tokUrl = $stub->base . '/t/' . $secret;
$afSet(['autofill_url' => $tokUrl, 'autofill_token' => '']);
$r = $test($tokUrl);
T::eq('unauthorized', $r['json']['code'] ?? null, 'service wants a token, none set → unauthorized');
T::ok(str_contains($r['json']['message'] ?? '', 'requires a token') && !str_contains($r['json']['message'] ?? '', 'refused'), '… and says a token is required (not "refused")', $r['json']['message'] ?? '');
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
foreach (['file:///etc/passwd', 'gopher://127.0.0.1:70/x', 'ftp://example.org', 'not a url'] as $bad) {
    $r = $test($bad);
    T::ok(($r['json']['code'] ?? null) === 'invalid_url' && str_contains($r['json']['message'] ?? '', 'Only http:// and https://') && !str_contains($r['json']['message'] ?? '', 'Enter the service address'), "Test connection with \"$bad\" → invalid address message", json_encode($r['json']));
}
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

// B1 bypass: saving a new address drops the stored token, so it cannot be redirected to another host
$stub2 = new StubServer();
$afSet(['autofill_enabled' => true, 'autofill_url' => $stub->base, 'autofill_timeout' => 5, 'autofill_token' => '']);
$af->post('/settings/autofill/token', ['csrf' => $at, 'token' => $secret]);
T::eq($secret, $afGet('autofill_token'), 'token set for the saved address');
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base . '/', 'autofill_timeout' => '5']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved') && !str_contains($r['headers']['location'] ?? '', 'token-cleared') && $afGet('autofill_token') === $secret, 'saving the same address (normalised) keeps the token');
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub2->base, 'autofill_timeout' => '5']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved-token-cleared'), 'saving a different address → token-cleared notice', $r['headers']['location'] ?? '');
T::eq('', $afGet('autofill_token'), '… and the stored token is cleared in the same save');
$page = $af->get('/settings/autofill?done=saved-token-cleared')['body'];
T::ok(str_contains($page, 'token was cleared because the service address changed') && str_contains($page, 'No token set.') && !str_contains($page, 'A token is <b>set</b>'), '… the page says it was cleared and no longer says a token is set');
$stub2->clear();
$af->api('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $stub2->base, 'autofill_timeout' => '5']);
T::eq(null, $stub2->lastInfoAuth(), '… Test connection sends no Authorization header to the new host');
$png1 = (function (): string { $im = imagecreatetruecolor(20, 20); ob_start(); imagepng($im); return (string)ob_get_clean(); })();
$af->api('/autofill/suggest', ['csrf' => $at], ['image' => ['name' => 'p.png', 'type' => 'image/png', 'data' => $png1]]);
T::ok($stub2->last() !== null && ($stub2->last()['auth'] ?? null) === null, '… and suggest sends no Authorization header to the new host');
// a token entered in the same save as the new address is kept
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base, 'autofill_timeout' => '5', 'autofill_token' => 'fresh-token']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved') && !str_contains($r['headers']['location'] ?? '', 'token-cleared') && $afGet('autofill_token') === 'fresh-token', 'new address with a new token in the same save → the new token is stored');
// resetting the section to a different (default) address also drops the token
$r = $af->post('/settings/autofill/reset', ['csrf' => $at]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=reset-token-cleared') && $afGet('autofill_token') === '', 'reset to another address clears the token');
// settings import and backup restore go through settings_import(): moving the address there clears the token too
$afCheckNoAuth = function (string $what) use ($af, $at, $stub2, $afGet, $png1): void {
    T::eq('', $afGet('autofill_token'), "$what: the stored token is cleared in the DB");
    $page = $af->get('/settings/autofill')['body'];
    T::ok(str_contains($page, 'No token set.') && !str_contains($page, 'A token is <b>set</b>'), "$what: the page says no token is set");
    $stub2->clear();
    $af->api('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $stub2->base, 'autofill_timeout' => '5']);
    T::eq(null, $stub2->lastInfoAuth(), "$what: Test connection sends no Authorization header to the new host");
    $stub2->clear();
    $af->api('/autofill/suggest', ['csrf' => $at], ['image' => ['name' => 'p.png', 'type' => 'image/png', 'data' => $png1]]);
    T::ok($stub2->last() !== null && ($stub2->last()['auth'] ?? null) === null, "$what: suggest reaches the new host without an Authorization header");
};
$afImport = fn(string $url) => $af->post('/settings/import', ['csrf' => $at], ['file' => ['name' => 's.json', 'type' => 'application/json', 'data' => json_encode(['format' => 'apotheke-settings', 'settings' => ['autofill_enabled' => true, 'autofill_url' => $url]])]]);
$afSet(['autofill_enabled' => true, 'autofill_url' => $stub->base, 'autofill_token' => $secret]);
$r = $afImport(strtoupper(substr($stub->base, 0, 4)) . substr($stub->base, 4) . '/');
T::ok($r['status'] === 200 && !str_contains($r['body'], 'token was cleared') && $afGet('autofill_token') === $secret, 'settings import of the same address (normalised) keeps the token');
$r = $afImport($stub2->base);
T::ok($r['status'] === 200 && str_contains($r['body'], 'AI autofill token was cleared because the service address changed'), 'settings import moving the address → notice on the result page', substr($r['body'], 0, 300));
$afCheckNoAuth('settings import');
if (class_exists('ZipArchive')) {
    $afBackup = function (string $url) use ($af, $afSet, $stub, $secret): string {
        $afSet(['autofill_url' => $stub->base, 'autofill_token' => $secret]);
        $f = tempnam(sys_get_temp_dir(), 'afbk');
        file_put_contents($f, $af->get('/backup.zip')['body']);
        $z = new ZipArchive();
        $z->open($f);
        $d = json_decode((string)$z->getFromName('backup.json'), true);
        $d['settings']['autofill_url'] = $url;
        $z->addFromString('backup.json', json_encode($d));
        $z->close();
        $data = (string)file_get_contents($f);
        unlink($f);
        return $data;
    };
    $afRestore = function (string $zip) use ($af, $at): array {
        $r = $af->post('/backup/restore', ['csrf' => $at], ['backup' => ['name' => 'b.zip', 'type' => 'application/zip', 'data' => $zip]]);
        preg_match('/name="token" value="([a-f0-9]{32})"/', $r['body'], $m);
        return $af->post('/backup/restore/confirm', ['csrf' => $at, 'token' => $m[1] ?? '', 'confirm' => '1']);
    };
    $r = $afRestore($afBackup($stub->base . '/'));
    T::ok(($r['headers']['location'] ?? '') === '/backup?restored=1' && $afGet('autofill_token') === $secret, 'backup restore of the same address (normalised) keeps the token', $r['headers']['location'] ?? '');
    $r = $afRestore($afBackup($stub2->base));
    T::eq('/backup?restored=token-cleared', $r['headers']['location'] ?? '', 'backup restore moving the address → token-cleared redirect');
    T::ok(str_contains($af->get('/backup?restored=token-cleared')['body'], 'AI autofill token was cleared because the service address changed'), '… and the restore result page says so');
    $afCheckNoAuth('backup restore');
}
// CLI: `php bin/settings.php set autofill_url …` and `reset autofill` share settings_store(), so they clear it too
$afCli = fn(string $a) => (string)shell_exec('cd ' . escapeshellarg(ROOT) . ' && ' . implode(' ', array_map(fn($k, $v) => $k . '=' . escapeshellarg((string)$v), array_keys($e = ['DATA_DIR' => $afd, 'APOTHEKE_CONFIG' => '/nonexistent'] + test_db_env($afd)), $e)) . ' php bin/settings.php ' . $a . ' 2>&1');
$afSet(['autofill_enabled' => true, 'autofill_url' => $stub->base, 'autofill_token' => $secret]);
$o = $afCli('set autofill_url ' . escapeshellarg($stub->base . '/'));
T::ok(!str_contains($o, 'token was cleared') && $afGet('autofill_token') === $secret, 'CLI set of the same address (normalised) keeps the token', $o);
$o = $afCli('set autofill_timeout 20');
T::ok(!str_contains($o, 'token was cleared') && $afGet('autofill_token') === $secret, 'CLI set of another autofill key keeps the token', $o);
$o = $afCli('set autofill_url ' . escapeshellarg($stub2->base));
T::ok(str_contains($o, 'AI autofill token was cleared because the service address changed'), 'CLI set moving the address prints a notice', $o);
T::eq($stub2->base, $afGet('autofill_url'), '… new address stored');
$afCheckNoAuth('CLI set');
$afSet(['autofill_url' => $stub2->base, 'autofill_token' => $secret]);
$o = $afCli('reset autofill');
T::ok(str_contains($o, 'AI autofill token was cleared') && $afGet('autofill_token') === '', 'CLI reset moving the address clears the token with a notice', $o);
$afSet(['autofill_url' => $stub->base, 'autofill_token' => $secret]);
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub2->base, 'autofill_timeout' => '5', 'autofill_token' => 'fresh-token']);
T::ok(!str_contains($r['headers']['location'] ?? '', 'token-cleared') && $afGet('autofill_token') === 'fresh-token', 'form: new address + new token in one save stores the new token, no clear notice');
$afSet(['autofill_token' => '']);
$stub2->stop();
$afSet(['autofill_token' => '']);
// upper-case scheme: accepted and normalised, like host case
$afSet(['autofill_url' => $stub->base, 'autofill_token' => $secret]);
$upper = 'HTTP://' . substr($stub->base, strlen('http://'));
$stub->clear();
$r = $af->api('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $upper, 'autofill_timeout' => '5']);
T::ok(($r['json']['ok'] ?? null) === true && $stub->lastInfoAuth() === 'Bearer ' . $secret, 'Test connection: HTTP:// is the saved address (scheme case ignored)', json_encode($r['json']));
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $upper, 'autofill_timeout' => '5']);
T::ok($r['status'] === 303 && !str_contains($r['headers']['location'] ?? '', 'token-cleared'), 'saving HTTP:// is accepted and keeps the token (same address)');
T::eq($stub->base, $afGet('autofill_url'), '… stored with a lower-case scheme');
T::eq($secret, $afGet('autofill_token'), '… token kept');
T::eq(303, $af->post('/settings/autofill', ['csrf' => $at, 'autofill_url' => 'HtTpS://example.org', 'autofill_timeout' => '5'])['status'], 'mixed-case https:// accepted');
T::eq('https://example.org', $afGet('autofill_url'), '… stored as https://');
$afSet(['autofill_url' => $stub->base, 'autofill_token' => '']);

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
$r = $suggest();
T::ok(($r['json']['code'] ?? null) === 'unauthorized' && str_contains($r['json']['message'] ?? '', 'refused'), 'bad token → unauthorized, "refused the access token"', $r['body']);
$afSet(['autofill_token' => '']);
$r = $suggest();
T::ok(($r['json']['code'] ?? null) === 'unauthorized' && str_contains($r['json']['message'] ?? '', 'requires an access token') && !str_contains($r['json']['message'] ?? '', 'refused'), 'no token set, service wants one → "requires an access token"', $r['body']);
$afSet(['autofill_url' => $stub->base, 'autofill_token' => '']);
// failure modes: all soft (HTTP 200, ok:false, a message for the status line)
foreach ([['http://127.0.0.1:1', 'unavailable', 'service down'], [$stub->base . '/m/error', 'busy', 'backend error (503)'],
          [$stub->base . '/m/malformed', 'unavailable', 'malformed response'], [$stub->base . '/m/toolarge', 'too_large', 'service says too large (413)'],
          [$stub->base . '/m/unsupported', 'unsupported', 'service says unsupported type (415)'], [$stub->base . '/m/badinfo/nope', 'bad_response', 'unexpected HTTP status (404)']] as [$u, $code, $what]) {
    $afSet(['autofill_url' => $u]);
    $r = $suggest();
    T::ok($r['status'] === 200 && ($r['json']['ok'] ?? null) === false && ($r['json']['code'] ?? '') === $code && ($r['json']['message'] ?? '') !== '', "$what → $code", $r['body']);
}
$afSet(['autofill_url' => 'http://10.255.255.1:8080', 'autofill_timeout' => 10]);
$r = $suggest();
T::ok(($r['json']['code'] ?? '') === 'unavailable' && str_contains($r['json']['message'] ?? '', 'unavailable'), 'suggest: connect timeout → unavailable, not "did not answer within"', $r['body']);
$afSet(['autofill_url' => $stub->base . '/m/empty', 'autofill_timeout' => 5]);
$r = $suggest();
T::ok(($r['json']['ok'] ?? null) === true && ($r['json']['code'] ?? '') === 'empty', 'empty answer {} → no suggestions, not an error');
$afSet(['autofill_url' => $stub->base . '/m/slow', 'autofill_timeout' => 3]);
$t0 = microtime(true);
$r = $suggest();
T::ok(($r['json']['code'] ?? '') === 'unavailable' && str_contains($r['json']['message'] ?? '', 'unavailable') && microtime(true) - $t0 < 6, 'slow service → unavailable after the configured 3 s');
$afSet(['autofill_url' => $stub->base, 'autofill_timeout' => 5]);
// odd images
$stub->clear();
$r = $suggest(['image' => ['name' => 'x.heic', 'type' => 'image/heic', 'data' => "\x00\x00\x00\x18ftypheic" . str_repeat('x', 500)]]);
T::ok(($r['json']['code'] ?? '') === 'unsupported' && str_contains($r['json']['message'] ?? '', 'cannot be read as a photo') && $stub->last() === null, 'unreadable HEIC is not sent');
foreach (['doc.pdf' => "%PDF-1.7\n" . str_repeat('x', 500), 'notes.txt' => 'hello'] as $fn => $data) {
    $stub->clear();
    $r = $suggest(['image' => ['name' => $fn, 'type' => 'application/octet-stream', 'data' => $data]]);
    T::ok(($r['json']['code'] ?? '') === 'unsupported' && $stub->last() === null, "unreadable non-image ($fn) rejected locally, never sent to the service", $r['body']);
}
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

T::section('AI autofill: progress hints, retry on busy, Test with a photo');
// the form carries the client timeout and (when the service advertised one) the expected time; nothing when off
$afSet(['autofill_enabled' => true, 'autofill_url' => $stub->base . '/l/12500', 'autofill_timeout' => 7, 'autofill_token' => '']);
@unlink($afd . '/autofill-info.json');
$form = $af->get('/new')['body'];
T::ok(str_contains($form, 'data-af-timeout="7"') && !str_contains($form, 'data-af-latency'), 'form: timeout hint; no expected time before the service advertised one');
T::ok(str_contains($form, 'id="af-progress"') && str_contains($form, 'role="progressbar"'), 'form: an accessible progress bar is ready (hidden)');
$r = $suggest();
T::ok(($r['json']['ok'] ?? false) === true && ($r['json']['avg_latency_ms'] ?? null) === 12500, 'suggest: the answer carries avg_latency_ms from /v1/info', $r['body']);
T::ok(str_contains($af->get('/new')['body'], 'data-af-latency="12500"'), 'form: data-af-latency after a successful suggestion');
// saving the section fetches /v1/info once, so the first photo has an estimate without a Test click
@unlink($afd . '/autofill-info.json');
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base . '/l/12500', 'autofill_timeout' => '7']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved') && str_contains($af->get('/new')['body'], 'data-af-latency="12500"'), 'saving the section caches the service info (estimate on the first photo)');
$t0 = microtime(true);
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => 'http://127.0.0.1:1', 'autofill_timeout' => '7']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved') && microtime(true) - $t0 < 5, 'saving with the service down still saves, quickly');
$t0 = microtime(true);
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base . '/m/slow', 'autofill_timeout' => '20']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved') && microtime(true) - $t0 < 7.5, 'saving with a slow service waits at most 5 s', (string)round(microtime(true) - $t0, 1));
$afSet(['autofill_url' => $stub->base . '/l/12500', 'autofill_timeout' => 7]);
$af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base . '/l/12500', 'autofill_timeout' => '7']);
// saving the token primes the info too: a token-protected service only answers /v1/info with it
$afSet(['autofill_enabled' => true, 'autofill_url' => $stub->base . '/l/11000/t/' . $secret, 'autofill_token' => '']);
@unlink($afd . '/autofill-info.json');
$af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base . '/l/11000/t/' . $secret, 'autofill_timeout' => '7']);
T::ok(!str_contains($af->get('/new')['body'], 'data-af-latency'), 'token-protected service, no token yet: nothing cached on save');
$r = $af->post('/settings/autofill/token', ['csrf' => $at, 'token' => $secret]);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=token-saved') && str_contains($af->get('/new')['body'], 'data-af-latency="11000"'), 'saving the token caches /v1/info: the first photo gets the bar');
@unlink($afd . '/autofill-info.json');
$r = $af->post('/settings/autofill/token', ['csrf' => $at, 'token' => 'wrong-one']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=token-saved') && !str_contains($af->get('/new')['body'], 'data-af-latency'), 'a wrong token still saves; nothing cached');
$afSet(['autofill_url' => $stub->base . '/m/slow/t/x', 'autofill_timeout' => 20]);
$t0 = microtime(true);
$r = $af->post('/settings/autofill/token', ['csrf' => $at, 'token' => 'x']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=token-saved') && microtime(true) - $t0 < 7.5, 'saving the token with a slow service waits at most 5 s');
$afSet(['autofill_url' => $stub->base . '/l/12500', 'autofill_token' => '', 'autofill_timeout' => 7]);
$af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $stub->base . '/l/12500', 'autofill_timeout' => '7']);
// testing an unsaved address (either button) never replaces the saved service's cached info
$af->api('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $stub->base . '/l/20000', 'autofill_timeout' => '5']);
$af->api('/settings/autofill/test-photo', ['csrf' => $at, 'autofill_url' => $stub->base . '/l/20000', 'autofill_timeout' => '5']);
T::ok(str_contains($af->get('/new')['body'], 'data-af-latency="12500"'), 'testing an unsaved address keeps the saved service\'s estimate');
// preselected defaults on a new item are marked (autofill may replace them while untouched); never on an edit form
$af->api('/categories', ['csrf' => $at, 'name' => 'General']);
$afdb->exec("UPDATE categories SET is_default = CASE WHEN name = 'General' THEN 1 ELSE 0 END");
$afSet(['default_tags' => 'home', 'default_notes' => '']);
$form = $af->get('/new')['body'];
T::ok(str_contains($form, 'data-af-defaults="category,tags"'), 'new item: the default category and default tags are marked as preselected');
$gid = (int)$afdb->query("SELECT id FROM categories WHERE name = 'General'")->fetchColumn();
T::ok(!str_contains($af->get('/new?cat=' . $gid)['body'], 'data-af-defaults="category'), 'new item with ?cat=: the category is the user\'s choice');
$iid = (int)$afdb->query('SELECT id FROM items ORDER BY id LIMIT 1')->fetchColumn();
T::ok(!str_contains($af->get("/item/$iid/edit")['body'], 'data-af-defaults'), 'edit form: stored values are the user\'s choice');
$afSet(['default_tags' => '']);
$afSet(['autofill_enabled' => false]);
$form = $af->get('/new')['body'];
T::ok(!str_contains($form, 'af-progress') && !str_contains($form, 'data-af-'), 'off: no progress bar, no hints');
$afSet(['autofill_enabled' => true, 'autofill_url' => '']);
T::ok(!str_contains($af->get('/new')['body'], 'af-progress'), 'on without an address: no progress bar');
// 503 backend_unavailable + Retry-After → retry_after in the JSON; the cold stub answers the second call
$afSet(['autofill_url' => $stub->base . '/m/busy/r/16', 'autofill_timeout' => 5]);
$r = $suggest();
T::ok(($r['json']['code'] ?? '') === 'busy' && ($r['json']['retry_after'] ?? null) === 16 && ($r['json']['ok'] ?? null) === false, 'busy with Retry-After: 16 → retry_after 16', $r['body']);
T::ok(($r['json']['retry_reason'] ?? '') === 'busy' && str_contains($r['json']['message'] ?? '', 'the service is busy'), '"backend busy, queue full" → retry_reason busy', $r['body']);
$afSet(['autofill_url' => $stub->base . '/m/warmup/r/15']);
$r = $suggest();
T::ok(($r['json']['retry_reason'] ?? '') === 'warming' && str_contains($r['json']['message'] ?? '', 'the model is warming up'), '"model … is warming up" → retry_reason warming', $r['body']);
$afSet(['autofill_url' => $stub->base . '/m/error']);
T::ok(!array_key_exists('retry_after', $suggest()['json'] ?? []), 'busy without Retry-After → no retry_after');
$afSet(['autofill_url' => $stub->base . '/m/fault']);
$r = $suggest();
T::ok(($r['json']['code'] ?? '') === 'backend_error' && str_contains($r['json']['message'] ?? '', 'had a problem with this photo') && !str_contains($r['json']['message'] ?? '', 'busy'), '503 backend_error → "had a problem with this photo", not "busy"', $r['body']);
$afSet(['autofill_url' => $stub->base . '/m/cold/r/1']);
$stub->clear();
$r1 = $suggest();
$r2 = $suggest();
T::ok(($r1['json']['retry_after'] ?? null) === 1 && ($r2['json']['ok'] ?? false) === true, 'cold model: refused once with Retry-After, then answered', $r1['body'] . ' / ' . $r2['body']);
// Test with a photo: the typed address/timeout, the stored token only for the saved address, never echoed
$photoTest = fn(array $p) => $af->api('/settings/autofill/test-photo', ['csrf' => $at] + $p);
$afSet(['autofill_url' => $stub->base . '/t/' . $secret, 'autofill_token' => $secret]);
$stub->clear();
$r = $photoTest(['autofill_url' => $stub->base . '/t/' . $secret, 'autofill_timeout' => '5']);
$j = $r['json'] ?? [];
T::ok(($j['ok'] ?? false) === true && ($j['checks'] ?? null) === ['reachable' => true, 'token' => true, 'ready' => true], 'photo test: reachable, token OK, model ready', $r['body']);
T::ok(str_starts_with((string)($j['suggestions']['name'] ?? ''), 'Echo item ') && is_int($j['elapsed_ms'] ?? null) && ($j['confidence']['name'] ?? null) === 0.5, 'photo test: suggestions, confidence and elapsed time');
T::ok(($stub->last()['type'] ?? '') === 'JPEG' && ($stub->last()['auth'] ?? '') === 'Bearer ' . $secret, 'photo test: the sample JPEG went through POST /v1/suggest with the stored token');
T::ok(str_contains($j['html'] ?? '', 'id="af-s-name"') && str_contains($j['html'] ?? '', 'data-check="token" data-state="ok"'), 'photo test: result HTML');
T::ok(!str_contains(str_replace($stub->base . '/t/' . $secret, '', $r['body']), $secret), 'photo test: token never in the answer');
$stub->clear();
$r = $photoTest(['autofill_url' => $stub->base . '/l/1/t/' . $secret, 'autofill_timeout' => '5']);
T::ok(($r['json']['code'] ?? '') === 'unauthorized' && ($r['json']['checks']['token'] ?? null) === false && $stub->last() === null, 'photo test: another address never gets the stored token, no photo sent', $r['body']);
$r = $photoTest(['autofill_url' => 'http://127.0.0.1:1', 'autofill_timeout' => '3']);
T::ok(($r['json']['code'] ?? '') === 'unreachable' && ($r['json']['checks']['reachable'] ?? null) === false, 'photo test: service down → unreachable');
$t0 = microtime(true);
$r = $photoTest(['autofill_url' => $stub->base . '/d/9000', 'autofill_timeout' => '3']);
T::ok(($r['json']['code'] ?? '') === 'unavailable' && str_contains($r['json']['message'] ?? '', 'unavailable') && microtime(true) - $t0 < 6, 'photo test: a slow suggest is unavailable, still bounded by the timeout', $r['body']);
$r = $photoTest(['autofill_url' => 'gopher://x', 'autofill_timeout' => '3']);
T::eq('invalid_url', $r['json']['code'] ?? null, 'photo test: invalid address');
// without JavaScript: a full page with the result, nothing saved
$r = $af->post('/settings/autofill/test-photo', ['csrf' => $at, 'autofill_url' => $stub->base, 'autofill_timeout' => '5', 'autofill_enabled' => '1']);
T::ok($r['status'] === 200 && str_contains($r['body'], 'id="af-suggestion"') && str_contains($r['body'], 'id="af-test-photo"'), 'photo test without JS: the settings page with the result', substr($r['body'], 0, 200));
T::eq($stub->base . '/t/' . $secret, $afGet('autofill_url'), 'photo test does not save anything');
T::eq(419, $af->api('/settings/autofill/test-photo', ['csrf' => 'nope', 'autofill_url' => $stub->base])['status'], 'photo test without CSRF → 419 (api)');
$ed = $afs->client();
$ed->post('/login', ['csrf' => $ed->csrf(), 'username' => 'vic', 'password' => 'vic-password']);
T::eq(403, $ed->api('/settings/autofill/test-photo', ['csrf' => $ed->csrf(), 'autofill_url' => $stub->base])['status'], 'viewer cannot run the photo test');
$afSet(['autofill_url' => $stub->base, 'autofill_token' => '']);

T::section('AI autofill 2.4: on demand, model and image size');
$afSet(['autofill_enabled' => true, 'autofill_url' => $stub->base, 'autofill_timeout' => 5, 'autofill_token' => '']);
T::ok($afGet('autofill_auto') === false, 'auto-analyze is off by default (seeded by migration 0025)');
$form = $af->get('/new')['body'];
T::ok(str_contains($form, 'id="af-analyze"') && str_contains($form, 'data-af-src="picked"'), 'the new-item form has an Analyze button for the picked photo');
T::ok(!str_contains($form, 'data-af-auto'), 'off: the form does not analyze on upload');
T::ok(str_contains($form, 'data-af-side="1024"'), 'a service without image sizes: 1024 px, as before');
$afSet(['autofill_auto' => true]);
T::ok(str_contains($af->get('/new')['body'], 'data-af-auto="1"'), 'auto-analyze on: the form says so');
$afSet(['autofill_auto' => false]);
// the settings page: no Model / Image size dropdowns while the service does not list them; the stored value survives a save
@unlink($afd . '/autofill-info.json');
$page = $af->get('/settings/autofill')['body'];
T::ok(str_contains($page, 'name="autofill_auto"') && str_contains($page, 'Analyze photos automatically'), 'settings: the auto-analyze switch');
T::ok(!str_contains($page, '<select name="autofill_model"') && str_contains($page, 'name="autofill_model" id="s-autofill_model" value="" data-af-hidden="1"'), 'settings: no Model dropdown without a models list (a hidden input keeps the value)');
T::ok(!str_contains($page, '<select name="autofill_image_size"'), 'settings: no Image size dropdown without image_sizes');
$v12 = $stub->base . '/n/' . rawurlencode('qwen2.5vl-jetson:3b') . '*=14000,qwen3-vl-2b=6000/z/448,672*,1024';
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $v12, 'autofill_timeout' => '5']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved'), 'saving a v1.2 service primes its info');
$page = $af->get('/settings/autofill')['body'];
T::ok(str_contains($page, '<select name="autofill_model" id="s-autofill_model"') && str_contains($page, 'Service default (qwen2.5vl-jetson:3b)') && str_contains($page, '>qwen3-vl-2b · about 6.0 s<'), 'settings: the Model dropdown from /v1/info', substr($page, (int)strpos($page, 's-autofill_model') - 100, 900));
T::ok(str_contains($page, '<select name="autofill_image_size"') && str_contains($page, '<option value="0" selected>Recommended (672 px)</option>'), 'settings: the Image size dropdown, recommended preselected');
T::ok(str_contains($af->get('/new')['body'], 'data-af-side="672"') && str_contains($af->get('/new')['body'], 'data-af-latency="14000"'), 'the form resizes to the recommended size and estimates with the default model\'s average');
T::eq(422, $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $v12, 'autofill_timeout' => '5', 'autofill_image_size' => '20'])['status'], 'an image size below 64 px is rejected');
$r = $af->post('/settings/autofill', ['csrf' => $at, 'autofill_enabled' => '1', 'autofill_url' => $v12, 'autofill_timeout' => '5', 'autofill_model' => 'qwen3-vl-2b', 'autofill_image_size' => '448']);
T::ok(str_contains($r['headers']['location'] ?? '', 'done=saved') && $afGet('autofill_model') === 'qwen3-vl-2b' && $afGet('autofill_image_size') === 448, 'model and size saved');
$form = $af->get('/new')['body'];
T::ok(str_contains($form, 'data-af-side="448"') && str_contains($form, 'data-af-latency="6000"'), 'the form uses the chosen size and that model\'s average');
$stub->clear();
$r = $suggest();
$last = $stub->last();
T::ok(($r['json']['ok'] ?? false) === true && ($last['context']['model'] ?? null) === 'qwen3-vl-2b', 'the chosen model goes along as context.model', json_encode($last));
T::ok(($last['width'] ?? 0) === 448 && ($last['height'] ?? 0) === 299, 'the photo is sent at the chosen long edge (448 px)', json_encode($last));
T::eq('qwen3-vl-2b', $r['json']['model_used'] ?? null, 'model_used is passed on to the browser');
T::eq(['type' => 'image/jpeg', 'bytes' => $last['bytes'] ?? -1, 'width' => 448, 'height' => 299], $r['json']['image'] ?? null, 'the answer says what was sent (debug hook)');
$r = $photoTest(['autofill_url' => $v12, 'autofill_timeout' => '5', 'autofill_model' => 'qwen3-vl-2b', 'autofill_image_size' => '448']);
T::ok(($r['json']['model_used'] ?? null) === 'qwen3-vl-2b' && str_contains($r['json']['html'] ?? '', 'id="af-s-model"') && str_contains($r['json']['html'] ?? '', 'id="af-s-size"'), 'Test with a photo: the chosen model and size, Model used shown', $r['body']);
$r = $photoTest(['autofill_url' => $v12, 'autofill_timeout' => '5', 'autofill_model' => 'not-installed']);
T::ok(($r['json']['model_used'] ?? null) === 'qwen2.5vl-jetson:3b' && str_contains($r['json']['html'] ?? '', '(asked for not-installed)'), 'Test with a photo: an unknown model falls back to the default, and the result says so', $r['body']);
// a model switch is a cold start: 503 + Retry-After for the browser's single retry
$afSet(['autofill_url' => $stub->base . '/m/switch/r/2/n/a*,b', 'autofill_model' => 'b']);
$af->post('/settings/autofill/test', ['csrf' => $at, 'autofill_url' => $stub->base . '/m/switch/r/2/n/a*,b', 'autofill_timeout' => '5']);
$r = $suggest();
T::ok(($r['json']['code'] ?? '') === 'busy' && ($r['json']['retry_after'] ?? 0) === 2 && ($r['json']['retry_reason'] ?? '') === 'warming', 'model switch → 503 warming + Retry-After', $r['body']);
$r = $suggest();
T::ok(($r['json']['ok'] ?? false) === true && ($r['json']['model_used'] ?? '') === 'b', '… and the retry gets the new model', $r['body']);
// stored photos (the edit form): analyzed by id, one Analyze button per photo
$afSet(['autofill_url' => $stub->base, 'autofill_model' => '', 'autofill_image_size' => 0]);
$r = $af->api('/save', ['csrf' => $at, 'name' => 'Item with photos'], ['photo' => ['name' => 'p.png', 'type' => 'image/png', 'data' => $png(900, 600)]]);
$iid = (int)$afdb->query("SELECT id FROM items WHERE name = 'Item with photos'")->fetchColumn();
$af->api('/item/' . $iid . '/photos', ['csrf' => $at], ['photos[]' => ['name' => 'q.png', 'type' => 'image/png', 'data' => $png(700, 500)]]);
$pids = array_map('intval', $afdb->query("SELECT id FROM item_photos WHERE item_id = $iid ORDER BY position")->fetchAll(PDO::FETCH_COLUMN));
T::eq(2, count($pids), 'the item has two stored photos');
$edit = $af->get('/item/' . $iid . '/edit')['body'];
T::ok(str_contains($edit, 'id="af-photos"') && str_contains($edit, 'data-af-photo="' . $pids[0] . '"') && str_contains($edit, 'data-af-photo="' . $pids[1] . '"'), 'the edit form has an Analyze button per stored photo');
T::ok(!str_contains($af->get('/new')['body'], 'id="af-photos"'), 'a new item has no stored photos to analyze');
$stub->clear();
$r = $af->api('/autofill/suggest', ['csrf' => $at, 'photo_id' => (string)$pids[1]]);
T::ok(($r['json']['ok'] ?? false) === true && ($stub->last()['width'] ?? 0) === 700, 'a stored photo is analyzed by id', $r['body']);
T::eq('no_image', $af->api('/autofill/suggest', ['csrf' => $at, 'photo_id' => '999999'])['json']['code'] ?? null, 'an unknown photo id → no_image');
T::eq('no_image', $af->api('/autofill/suggest', ['csrf' => $at, 'photo_id' => '../x'])['json']['code'] ?? null, 'a malformed photo id → no_image');
T::eq(403, $vi->api('/autofill/suggest', ['csrf' => $vi->csrf(), 'photo_id' => (string)$pids[0]])['status'], 'a viewer cannot analyze a stored photo');
$afSet(['autofill_enabled' => false]);
T::ok(!str_contains($af->get('/item/' . $iid . '/edit')['body'], 'af-analyze'), 'autofill off: no Analyze buttons');
$afSet(['autofill_enabled' => true]);

// over the upload cap, still under PHP's upload_max_filesize: the status line states the tighter limit,
// which is the shipped service's 10 MiB when the cap is higher than that
$afSet(['max_upload_mb' => 15]);
$big = str_repeat('x', 16 * 1024 * 1024);
$r = $af->api('/autofill/suggest', ['csrf' => $at], ['image' => ['name' => 'big.bin', 'type' => 'application/octet-stream', 'data' => $big]]);
unset($big);
T::ok(($r['json']['code'] ?? '') === 'too_large'
    && str_contains((string)($r['json']['message'] ?? ''), '10 MiB')
    && str_contains((string)($r['json']['message'] ?? ''), '10485760'),
    'a photo over the upload cap says 10 MiB / 10485760 bytes', $r['json']['message'] ?? $r['body']);
$afSet(['max_upload_mb' => 1]);
$mid = str_repeat('y', 2 * 1024 * 1024);
$r = $af->api('/autofill/suggest', ['csrf' => $at], ['image' => ['name' => 'mid.bin', 'type' => 'application/octet-stream', 'data' => $mid]]);
unset($mid);
T::ok(($r['json']['code'] ?? '') === 'too_large'
    && str_contains((string)($r['json']['message'] ?? ''), '1 MiB')
    && str_contains((string)($r['json']['message'] ?? ''), '1048576'),
    'when the upload cap is tighter than the service, the message states that cap', $r['json']['message'] ?? $r['body']);
$afSet(['max_upload_mb' => 20]);

// Test with a photo: one retry when Retry-After is within the timeout, none when it is longer
file_get_contents($stub->base . '/_stub/uploads', false, stream_context_create(['http' => ['method' => 'DELETE']]));
$r = $af->api('/settings/autofill/test-photo', ['csrf' => $at, 'autofill_url' => $stub->base . '/m/switch/r/1/n/a*,b', 'autofill_timeout' => '5', 'autofill_model' => 'b']);
$ups = json_decode((string)file_get_contents($stub->base . '/_stub/uploads'), true);
T::ok(($r['json']['ok'] ?? false) === true && ($r['json']['model_used'] ?? '') === 'b' && count($ups) === 2, 'photo test retries a cold model once', $r['body'] . ' uploads=' . count((array)$ups));
file_get_contents($stub->base . '/_stub/uploads', false, stream_context_create(['http' => ['method' => 'DELETE']]));
$t0 = microtime(true);
$r = $af->api('/settings/autofill/test-photo', ['csrf' => $at, 'autofill_url' => $stub->base . '/m/warmup/r/40', 'autofill_timeout' => '5']);
$ups = json_decode((string)file_get_contents($stub->base . '/_stub/uploads'), true);
T::ok(($r['json']['code'] ?? '') === 'busy' && count($ups) === 1 && microtime(true) - $t0 < 4, 'photo test does not retry when Retry-After exceeds the timeout', $r['body']);

T::ok(!$afs->logHas($secret), 'token never written to the server log');
$afs->stop();
$stub->stop();
