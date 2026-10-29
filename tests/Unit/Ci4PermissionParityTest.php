<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/legacy-bootstrap.php';
require_once dirname(__DIR__, 2) . '/modules/Auth/Permissions.php';
require_once dirname(__DIR__, 2) . '/modules/Auth/RoutePermission.php';

use Apotheke\Auth\Permissions;
use Apotheke\Auth\RoutePermission;

/**
 * Golden (PLAN PR 7): the CI4 Permission filter against legacy. The
 * permission list, the role defaults and route_perm() for a shared corpus;
 * then the same requests to a legacy and a CI4 server with the same data,
 * for every CI4 state-changing route, an unknown route and a few reads, and
 * every kind of visitor: same status, same Location, same JSON or message.
 */
final class Ci4PermissionParityTest extends TestCase
{
    private const ROUTES = ['/autofill/suggest', '/settings/autofill/token', '/settings/autofill/test', '/settings/autofill/test-photo'];

    public function testPermissionsAndRoleDefaultsMatchLegacy(): void
    {
        $this->assertSame(PERMISSIONS, Permissions::ALL);
        $this->assertSame(ROLE_DEFAULTS, Permissions::ROLE_DEFAULTS);
        foreach ([['admin', []], ['editor', ['bogus', 'view', 'settings', 'view']], ['x', ['backup', 'create']], ['y', []]] as [$role, $perms]) {
            $this->assertSame(role_perms_clean($role, $perms), Permissions::clean($role, $perms));
        }
    }

    public function testRoutePermissionMatchesLegacyRoutePerm(): void
    {
        $paths = ['/', '/login', '/login/', '/register', '/logout', '/account', '/account/sessions', '/accounts',
            '/settings', '/settings/', '/settings.json', '/settings/general', '/settings/autofill', '/settings/autofill/token',
            '/settings/autofill/test', '/settings/autofill/test-photo', '/settings/security', '/settings/users', '/settings/users/4/role',
            '/settings/roles', '/settings/roles/x', '/settings/password', '/settings/passwords', '/settingsx', '/backup', '/backup.zip',
            '/backup/restore', '/backup/restore/check', '/backupx', '/import', '/import/csv', '/export.csv', '/export.csvx',
            '/stocktake', '/stocktake/3', '/labels', '/labels/x', '/item/3/label', '/item/x/label', '/new', '/autofill/suggest',
            '/autofill/suggestx', '/item/3/duplicate', '/item/3/lend', '/item/3/return', '/lent', '/bulk', '/item/3/delete',
            '/save', '/item/3/merge', '/item/3/edit', '/item/3/photos/4/edit', '/item/3/photos/x/edit', '/item/3', '/media/a.jpg',
            '/theme', '/theme.css', '/recent/clear', '/healthz', '/no-such-page', "/item/3/edit\n",
            '/autofill/suggest/', '//autofill/suggest', '/autofill/suggest ', '/autofill/suggest%20/', '/autofill%2Fsuggest',
            '/settings/autofill/token/', '//settings/autofill/token', '/settings%2Fautofill%2Ftoken', '/item/3/delete/'];
        $posts = [[], ['id' => '0'], ['id' => '5'], ['id' => '-1'], ['id' => 'abc'], ['id' => '7x'], ['id' => ['a']], ['id' => []], ['id' => '']];
        $rules = new RoutePermission();
        $n = 0;
        foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
            foreach ($paths as $path) {
                foreach ($path === '/save' ? $posts : [[]] as $post) {
                    $this->assertSame(route_perm($method, $path, $post), $rules->need($method, $path, $post), $method . ' ' . $path . ' ' . json_encode($post));
                    $n++;
                }
            }
        }
        $this->assertGreaterThan(200, $n);
    }

    /** Every CI4 state-changing route, every kind of visitor, both apps. */
    public function testTheSameRequestsGetTheSameAnswers(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $answers = [];
        foreach (['legacy', 'ci4'] as $app) {
            $dir = tmpdir('perm-' . $app);
            $server = new Server($dir, [], $app);
            try {
                $this->assertSame(200, $server->client()->get('/healthz')['status'], (string)@file_get_contents($server->log));
                $answers[$app] = $this->visit($server, $dir);
            } finally {
                $server->stop();
                testdb_drop($dir);
                rrmdir($dir);
                @unlink($dir . '.server.log');
            }
        }
        $this->assertGreaterThan(100, count($answers['legacy']));
        $this->assertSame(array_keys($answers['legacy']), array_keys($answers['ci4']));
        foreach ($answers['legacy'] as $case => $legacy) {
            // Routed variants too: the filter judges legacy's own path (#101 N-70), so CI4 answers them as legacy does.
            $this->assertSame($legacy, $answers['ci4'][$case], $case);
        }
    }

    /** @return array<string,array<string,mixed>> case => what matters in the answer */
    private function visit(Server $server, string $dir): array
    {
        $pdo = testdb($dir);
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('desk', 'Help desk', '[\"view\",\"settings\"]', 0, '2026-01-01 00:00:00')");
        // Edit without Add items or Settings: legacy's default rule for an unrouted POST is edit.
        $pdo->exec("INSERT INTO roles (role_key, name, perms, builtin, created_at) VALUES ('fixer', 'Fixer', '[\"view\",\"edit\"]', 0, '2026-01-01 00:00:00')");
        $ids = [];
        foreach (['ada' => 'admin', 'eda' => 'editor', 'vera' => 'viewer', 'dee' => 'desk', 'gus' => 'gone', 'pam' => 'editor', 'fay' => 'fixer'] as $name => $role) {
            $pdo->prepare('INSERT INTO users (username, username_key, password_hash, role, status, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$name, $name, password_hash('long-enough', PASSWORD_DEFAULT), $role, $name === 'pam' ? 'pending' : 'active', '2026-01-01 00:00:00']);
            $ids[$name] = (int)$pdo->query("SELECT id FROM users WHERE username_key = '" . $name . "'")->fetchColumn();
        }
        // A browser holding a live session (with a CSRF token), signed in as $account when given.
        $signIn = function (?string $account, bool $stale) use ($server, $pdo, $ids, $dir): array {
            $client = $server->client();
            $token = bin2hex(random_bytes(32));
            $session = 'csrf|s:64:"' . $token . '";last_activity|i:' . time() . ';signed_in_at|i:' . time() . ';';
            if ($account !== null) {
                $row = $pdo->query('SELECT id, password_hash FROM users WHERE id = ' . $ids[$account])->fetch();
                $epoch = $stale ? 'stale-epoch-0000' : substr(hash('sha256', $row['password_hash'] . '|' . $row['id']), 0, 16);
                $session = 'uid|i:' . $ids[$account] . ';auth_epoch|s:16:"' . $epoch . '";' . $session;
            }
            $sid = bin2hex(random_bytes(13));
            file_put_contents($dir . '/sessions/sess_' . $sid, $session);
            $client->cookies['apothekesid'] = $sid;

            return [$client, $token];
        };
        $out = [];
        $visitors = ['off' => null, 'signed-out' => null, 'guest' => null, 'ada' => 'ada', 'eda' => 'eda', 'vera' => 'vera', 'dee' => 'dee', 'gus' => 'gus', 'pam' => 'pam', 'fay' => 'fay', 'stale' => 'eda'];
        foreach ($visitors as $visitor => $account) {
            $this->setting($pdo, 'auth_enabled', $visitor === 'off' ? 'false' : 'true');
            $this->setting($pdo, 'auth_guest_disabled', $visitor === 'guest' ? 'false' : 'true');
            foreach ([...self::ROUTES, '/no-such-route'] as $path) {
                foreach (['form' => [], 'fetch' => ['X-Requested-With: fetch'], 'accept' => ['Accept: application/json']] as $kind => $headers) {
                    foreach (['token', 'no-token'] as $csrf) {
                        [$client, $token] = $signIn($account, $visitor === 'stale');
                        $fields = $csrf === 'token' ? ['csrf' => $token, 'token' => 'placeholder-token', 'action' => 'clear'] : [];
                        $r = $client->post($path, $fields, [], $headers);
                        $refused = in_array($r['status'], [401, 403], true) || ($r['status'] === 303 && ($r['headers']['location'] ?? '') === '/login');
                        $answer = ['status' => $r['status'], 'location' => $r['headers']['location'] ?? null];
                        if ($refused) {
                            $answer['json'] = $r['json'];
                            preg_match('#<p>([^<]*)</p>#', $r['body'], $m);
                            $answer['message'] = $r['json'] === null ? html_entity_decode($m[1] ?? '') : null;
                        }
                        $out[$visitor . ' ' . $path . ' ' . $kind . ' ' . $csrf] = $answer;
                    }
                }
            }
            // Paths CI4's router normalises to a route (trailing or doubled slash, an encoded space or slash).
            // Legacy has no such routes; CI4 derives the permission from the routed path.
            foreach (self::ROUTES as $route) {
                foreach ([$route . '/', '/' . $route, $route . '%20/', '/' . str_replace('/', '%2F', substr($route, 1)), str_replace('/', '//', $route)] as $variant) {
                    [$client, $token] = $signIn($account, $visitor === 'stale');
                    $r = $client->post($variant, ['csrf' => $token, 'token' => 'placeholder-token', 'action' => 'clear'], [], ['X-Requested-With: fetch']);
                    $out['variant ' . $visitor . ' ' . $route . ' ' . $variant] = ['status' => $r['status'], 'location' => $r['headers']['location'] ?? null,
                        'may' => $this->may($pdo, $visitor, $account, $route === '/autofill/suggest' ? 'create' : 'settings')];
                }
            }
            // Reads: a page CI4 does not have yet, a photo, and what legacy answers before its check.
            foreach (['/no-such-page', '/media/a.jpg', '/theme.css', '/healthz'] as $path) {
                foreach (['form' => [], 'fetch' => ['X-Requested-With: fetch']] as $kind => $headers) {
                    $r = $signIn($account, $visitor === 'stale')[0]->get($path, $headers);
                    $out[$visitor . ' GET ' . $path . ' ' . $kind] = ['status' => $r['status'], 'location' => $r['headers']['location'] ?? null,
                        'json' => in_array($r['status'], [401, 403], true) ? $r['json'] : null];
                }
            }
        }

        return $out;
    }

    /** Whether this visitor holds $perm (the fixture's roles; sign-in off is the anonymous admin). */
    private function may(PDO $pdo, string $visitor, ?string $account, string $perm): bool
    {
        if ($visitor === 'off') {
            return true;
        }
        if ($account === null || in_array($visitor, ['pam', 'stale'], true)) {
            return false;
        }
        $role = (string)$pdo->query("SELECT role FROM users WHERE username_key = '" . $account . "'")->fetchColumn();
        if ($role === 'admin') {
            return true;
        }
        $perms = $pdo->query("SELECT perms FROM roles WHERE role_key = '" . $role . "'")->fetchColumn();

        return in_array($perm, json_decode((string)$perms, true) ?: [], true);
    }

    private function setting(PDO $pdo, string $name, string $value): void
    {
        $pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([$name]);
        $pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')->execute([$name, $value, '2026-01-01']);
    }
}
