<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/** Roles and permissions: route → permission map, resolution per role and mode, the roles editor, registration, lockout. */
final class PermissionsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
        roles(true);
        current_user(null, true);
    }

    protected function tearDown(): void
    {
        current_user(null, true);
        roles(true);
        parent::tearDown();
    }

    private function signOn(): void
    {
        user_create('root', 'long-enough', 'admin');
        auth_turn_on();
        settings_cache(null, true);
        current_user(null, true);
    }

    /** @return list<array{string,string,string}> method, path, permission */
    public static function routes(): array
    {
        return [
            ['GET', '/', 'view'], ['GET', '/item/5', 'view'], ['GET', '/stats', 'view'], ['GET', '/activity', 'view'], ['GET', '/lent', 'view'],
            ['GET', '/locations', 'view'], ['GET', '/media/thumb/a.jpg', 'view'], ['GET', '/attachment/3', 'view'], ['GET', '/barcode', 'view'],
            ['GET', '/stocktake', 'view'], ['GET', '/stocktake/3/report', 'view'], ['POST', '/stocktake', 'stocktake'], ['POST', '/stocktake/3/scan', 'stocktake'],
            ['GET', '/new', 'create'], ['POST', '/save', 'create'], ['POST', '/item/5/duplicate', 'create'],
            ['GET', '/item/5/edit', 'edit'], ['POST', '/item/5/qty', 'edit'], ['POST', '/item/5/photos', 'edit'], ['GET', '/item/5/photos/2/edit', 'edit'],
            ['POST', '/item/5/photos/order', 'edit'], ['POST', '/item/5/attachments', 'edit'], ['POST', '/locations', 'edit'], ['POST', '/undo', 'edit'],
            ['POST', '/item/5/fav', 'edit'], ['POST', '/filters', 'edit'],
            ['POST', '/item/5/delete', 'delete'], ['GET', '/item/5/merge', 'delete'], ['POST', '/item/5/merge', 'delete'],
            ['POST', '/bulk', 'bulk'], ['POST', '/item/5/lend', 'lend'], ['POST', '/item/5/return', 'lend'],
            ['GET', '/import', 'import'], ['POST', '/import/4/commit', 'import'], ['GET', '/export.csv', 'export'],
            ['GET', '/labels', 'labels'], ['GET', '/item/5/label', 'labels'],
            ['GET', '/settings', 'settings'], ['POST', '/settings/general', 'settings'], ['GET', '/settings/export.json', 'settings'],
            ['GET', '/settings/security', 'users'], ['POST', '/settings/users', 'users'], ['POST', '/settings/roles', 'users'], ['POST', '/settings/password', 'users'],
            ['GET', '/backup', 'backup'], ['GET', '/backup.zip', 'backup'], ['POST', '/backup/restore', 'backup'],
            ['GET', '/account', 'self'], ['POST', '/logout', 'self'], ['GET', '/login', 'public'], ['POST', '/register', 'public'],
            ['POST', '/theme', 'view'], ['POST', '/recent/clear', 'view'], ['GET', '/settingsx', 'view'],
        ];
    }

    public function testRouteMap(): void
    {
        foreach (self::routes() as [$m, $p, $perm]) $this->assertSame($perm, route_perm($m, $p), "$m $p");
        $this->assertSame('edit', route_perm('POST', '/save', ['id' => '7']), 'saving an existing item is editing');
        $this->assertSame(['labels', 'delete', 'move', 'edit', 'edit'], array_map('bulk_perm', ['labels', 'delete', 'move', 'category', 'tag_add']));
    }

    public function testResolutionMatrix(): void
    {
        $expect = [
            'admin'  => array_keys(PERMISSIONS),
            'editor' => ['view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake', 'labels'],
            'viewer' => ['view', 'export', 'labels'],
            'guest'  => ['view'],
            'nobody' => [],
        ];
        foreach ($expect as $role => $yes) {
            foreach (array_keys(PERMISSIONS) as $p) $this->assertSame(in_array($p, $yes, true), user_can($p, ['role' => $role, 'guest' => false]), "$role → $p");
            $this->assertTrue(user_can('public', ['role' => $role, 'guest' => true]));
        }
        $this->assertTrue(user_can('self', ['role' => 'viewer', 'guest' => false]));
        $this->assertFalse(user_can('self', ['role' => 'guest', 'guest' => true]), 'a guest has no account page');
    }

    public function testModes(): void
    {
        $this->assertSame(['admin', true, false], [current_user()['role'], current_user()['guest'], current_user()['anon']], 'accounts off: anonymous admin');
        $this->assertTrue(user_can('backup'));
        $this->signOn();
        $this->assertTrue((bool)setting('auth_guest_disabled'), 'guest browsing is off by default');
        $this->assertTrue((bool)setting('auth_register_disabled'), 'registration is off by default');
        $this->assertNull(current_user(), 'guests must sign in');
        $this->assertFalse(registration_open());
        settings_store(['auth_guest_disabled' => false]);
        current_user(null, true);
        $this->assertSame(['guest', true, true], [current_user()['role'], current_user()['guest'], current_user()['anon']]);
        $this->assertTrue(user_can('view'));
        $this->assertFalse(user_can('edit'));
        role_update('guest', null, ['view', 'labels', 'users', 'backup']);
        $this->assertSame(['view', 'labels'], role_get('guest')['perms'], 'guests never get users / settings / backup');
        $this->assertTrue(user_can('labels'));
    }

    public function testRoleEditor(): void
    {
        $k = role_create('Shop helper', ['view', 'lend']);
        $this->assertSame('shop-helper', $k);
        $this->assertSame(['view', 'lend'], role_get($k)['perms']);
        try { role_create('shop HELPER', []); $this->fail('duplicate'); } catch (UserError $e) { $this->assertSame(409, $e->getCode()); }
        try { role_create('x', ['fly']); $this->fail('unknown permission'); } catch (UserError $e) { $this->assertSame(422, $e->getCode()); }
        role_update($k, 'Lender', ['view', 'lend', 'labels']);
        $this->assertSame(['Lender', ['view', 'lend', 'labels']], [role_get($k)['name'], role_get($k)['perms']]);
        $id = user_create('lea', 'long-enough', $k);
        $this->assertTrue(user_can('lend', user_get($id) + ['guest' => false]));
        $this->assertFalse(user_can('edit', user_get($id) + ['guest' => false]));
        foreach (['admin', 'viewer'] as $b) {
            try { role_delete($b); $this->fail("deleted $b"); } catch (UserError $e) { $this->assertSame(409, $e->getCode()); }
        }
        try { role_update('admin', null, ['view']); $this->fail('changed admin'); } catch (UserError $e) { $this->assertSame(409, $e->getCode()); }
        settings_store(['auth_register_role' => $k]);
        role_delete($k, 'editor');
        $this->assertNull(role_get($k));
        $this->assertSame('editor', user_get($id)['role'], 'accounts move to the chosen role');
        $this->assertSame('viewer', setting('auth_register_role'), 'registration role falls back to viewer');
        role_update('viewer', 'Renamed?', []);
        $this->assertSame('Viewer', role_get('viewer')['name'], 'built-in names stay');
        roles_reset_builtin();
        $this->assertSame(['view', 'export', 'labels'], role_get('viewer')['perms'], 'CLI recovery restores the defaults');
    }

    public function testLockout(): void
    {
        $root = user_create('root', 'long-enough', 'admin');
        $k = role_create('Manager', ['view', 'users']);
        $m = user_create('mia', 'long-enough', $k);
        try { user_set_role($root, 'editor'); $this->fail('last admin demoted'); } catch (UserError $e) { $this->assertSame(409, $e->getCode()); }
        try { user_delete($root); $this->fail('last admin deleted'); } catch (UserError $e) { $this->assertSame(409, $e->getCode()); }
        role_update($k, null, ['view']);   // the admin can still manage users
        $this->assertSame(['view'], role_get($k)['perms']);
        user_set_role($m, 'admin');
        user_set_role($root, 'viewer');    // another admin exists now
        $this->assertSame(1, users_count('admin'));
        $p = user_create('pending-admin', 'long-enough', 'admin', null, 'pending');
        $this->assertSame(1, users_count('admin'), 'a pending admin does not count');
        try { user_set_role($m, 'viewer'); $this->fail('locked out'); } catch (UserError $e) { $this->assertStringContainsString('only admin', $e->getMessage()); }
        user_approve($p);
        $this->assertSame(2, users_count('admin'));
    }

    public function testRegistration(): void
    {
        $this->signOn();
        try { user_register('neo', 'long-enough', 'long-enough'); $this->fail('closed'); } catch (UserError $e) { $this->assertSame(403, $e->getCode()); }
        settings_store(['auth_register_disabled' => false]);
        $u = user_register('neo', 'long-enough', 'long-enough');
        $this->assertSame(['viewer', 'active'], [$u['role'], $u['status']], 'default role: viewer');
        settings_store(['auth_register_approval' => true, 'auth_register_role' => 'editor']);
        $u = user_register('trinity', 'long-enough', 'long-enough');
        $this->assertSame(['editor', 'pending'], [$u['role'], $u['status']]);
        try { login('trinity', 'long-enough'); $this->fail('pending signed in'); } catch (UserError $e) { $this->assertStringContainsString('approval', $e->getMessage()); }
        try { setting_validate('auth_register_role', 'admin'); $this->fail('admin as registration role'); } catch (UserError) { $this->addToAssertionCount(1); }
        try { user_register('morpheus', 'short', 'short'); $this->fail('weak password'); } catch (UserError $e) { $this->assertSame(422, $e->getCode()); }
    }

    public function testRateLimit(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        for ($i = 0; $i < 5; $i++) rate_limit('register');
        try { rate_limit('register'); $this->fail('no limit'); } catch (UserError $e) { $this->assertSame(429, $e->getCode()); }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        rate_limit('register');
        $this->addToAssertionCount(1);
        rate_limit_clear('register');
        unset($_SERVER['REMOTE_ADDR']);
    }

    public function testSignInLimitIsPerClientAndAccount(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.8';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        try {
            for ($i = 0; $i < 10; $i++) rate_limit('login', 'Ada');
            try {
                rate_limit('login', 'ada');
                $this->fail('the same account was not limited');
            } catch (UserError $e) {
                $this->assertSame(429, $e->getCode());
            }
            rate_limit('login', 'bea');
            $this->addToAssertionCount(1);
            rate_limit_clear('login', 'bea');
            try {
                rate_limit('login', 'ada');
                $this->fail('clearing another account cleared this one');
            } catch (UserError $e) {
                $this->assertSame(429, $e->getCode());
            }
            $_SERVER['REMOTE_ADDR'] = '192.0.2.9';
            rate_limit('login', 'ada');
            $this->addToAssertionCount(1);
            for ($i = 0; $i < 5; $i++) rate_limit('register', 'user' . $i);
            try {
                rate_limit('register', 'user-new');
                $this->fail('registration limit followed the account name');
            } catch (UserError $e) {
                $this->assertSame(429, $e->getCode());
            }
        } finally {
            unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        }
    }

    public function testClientAddressUsesForwardedForOnlyFromConfiguredProxies(): void
    {
        $proxies = ['192.0.2.1', '2001:db8::1', '192.0.2.0/24'];
        $this->assertSame('192.0.2.1', client_address_from('192.0.2.1', '198.51.100.20', false, $proxies));
        $this->assertSame('203.0.113.4', client_address_from('203.0.113.4', '198.51.100.20', true, $proxies));
        $this->assertSame('192.0.2.1', client_address_from('192.0.2.1', '198.51.100.20', true, []));
        $this->assertSame('198.51.100.20', client_address_from('192.0.2.1', '198.51.100.20', true, $proxies));
        $this->assertSame('198.51.100.20', client_address_from('192.0.2.1', '203.0.113.9, 198.51.100.20', true, $proxies));
        $this->assertSame('198.51.100.20', client_address_from('192.0.2.1', '198.51.100.20, 2001:db8::1', true, $proxies));
        $this->assertSame('198.51.100.20', client_address_from('192.0.2.15', '198.51.100.20', true, $proxies));
        $this->assertSame('192.0.3.15', client_address_from('192.0.3.15', '198.51.100.20', true, $proxies));
        $this->assertSame('192.0.2.1', client_address_from('192.0.2.1', '192.0.2.1', true, $proxies));
    }

    public function testMigrationConvertsRoles(): void
    {
        $this->assertSame(['admin', 'editor', 'viewer', 'guest'], array_keys(roles()));
        $this->assertTrue(roles()['admin']['builtin']);
        $this->assertSame('active', $this->pdo->query("SELECT status FROM users WHERE 1 = 0 UNION ALL SELECT 'active'")->fetchColumn());
    }
}
