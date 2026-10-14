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
$afSet(['autofill_token' => '']);
$r = $test($stub->base . '/t/' . $secret);
T::eq('unauthorized', $r['json']['code'] ?? null, 'service wants a token, none set → unauthorized');
$afSet(['autofill_token' => 'wrong']);
T::eq('unauthorized', $test($stub->base . '/t/' . $secret)['json']['code'] ?? null, 'wrong token → unauthorized');
$afSet(['autofill_token' => $secret]);
T::eq(true, $test($stub->base . '/t/' . $secret)['json']['ok'] ?? null, 'right token → ok');
T::eq('unreachable', $test('http://127.0.0.1:1')['json']['code'] ?? null, 'nothing listening → unreachable');
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
T::ok(!$afs->logHas($secret), 'token never written to the server log');
$afs->stop();
