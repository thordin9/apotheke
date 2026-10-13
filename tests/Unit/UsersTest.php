<?php
declare(strict_types=1);

/** User accounts, roles, the route → role map and the migration of the old single password. */
final class UsersTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        settings_cache(null, true);
        $this->migrateFresh(['seed' => false]);
        settings_cache(null, true);
        current_user(null, true);
    }

    protected function tearDown(): void
    {
        current_user(null, true);
        parent::tearDown();
    }

    public function testCreateValidateAndVerify(): void
    {
        $id = user_create('Anna', 'long-enough', 'editor');
        $this->assertSame('Anna', user_get($id)['username']);
        $this->assertSame($id, user_by_name('  anna ')['id'], 'names ignore case and spaces around');
        $this->assertNotNull(user_verify('ANNA', 'long-enough'));
        $this->assertNull(user_verify('anna', 'wrong-password'));
        $this->assertNull(user_verify('nobody', 'long-enough'));
        foreach ([['', 'Enter a user name'], ['two words', 'no spaces'], ['anna', 'taken'], [str_repeat('a', 41), 'at most']] as [$n, $msg]) {
            try { user_create($n, 'long-enough', 'viewer'); $this->fail("accepted “{$n}”"); }
            catch (UserError $e) { $this->assertStringContainsString($msg, $e->getMessage()); }
        }
        $this->assertGreaterThan(0, user_create('Ελένη.k@home', 'long-enough', 'viewer'), 'Unicode letters, . @ allowed');
        $this->expectException(UserError::class);
        user_create('bob', 'short', 'viewer');
    }

    public function testLastAdminIsProtected(): void
    {
        $a = user_create('root', 'long-enough', 'admin');
        $e = user_create('ed', 'long-enough', 'editor');
        try { user_set_role($a, 'viewer'); $this->fail('demoted the last admin'); } catch (UserError $x) { $this->assertSame(409, $x->getCode()); }
        try { user_delete($a); $this->fail('deleted the last admin'); } catch (UserError $x) { $this->assertSame(409, $x->getCode()); }
        user_set_role($e, 'admin');
        user_set_role($a, 'viewer');
        $this->assertSame(1, users_count('admin'));
        user_delete($a);
        $this->assertSame(1, users_count());
    }

    public function testPasswordChangeInvalidatesEpoch(): void
    {
        $id = user_create('pat', 'long-enough', 'viewer');
        $before = user_epoch(user_get($id));
        user_set_password($id, 'another-one');
        $this->assertNotSame($before, user_epoch(user_get($id)));
        $this->assertNotNull(user_verify('pat', 'another-one'));
    }

    public function testModeAndGuest(): void
    {
        $this->assertFalse(auth_enabled());
        $this->assertSame(['admin', true], [current_user()['role'], current_user()['guest']], 'no-login mode: everybody is admin');
        try { auth_turn_on(); $this->fail('turned on without an admin'); } catch (UserError) { $this->assertFalse(auth_enabled()); }
        user_create('root', 'long-enough', 'admin');
        auth_turn_on();
        settings_cache(null, true);
        current_user(null, true);
        $this->assertTrue(auth_enabled());
        $this->assertNull(current_user(), 'nobody signed in');
        $this->assertFalse(user_can('viewer'));
    }

    public function testRouteRoles(): void
    {
        $cases = [['GET', '/', 'viewer'], ['GET', '/item/5', 'viewer'], ['GET', '/export.csv', 'viewer'], ['GET', '/stocktake/3', 'viewer'],
            ['GET', '/new', 'editor'], ['GET', '/item/5/edit', 'editor'], ['GET', '/item/5/merge', 'editor'], ['POST', '/save', 'editor'],
            ['POST', '/item/5/delete', 'editor'], ['POST', '/stocktake', 'editor'], ['POST', '/theme', 'viewer'], ['POST', '/logout', 'viewer'],
            ['GET', '/account', 'viewer'], ['POST', '/account', 'viewer'], ['GET', '/settings', 'admin'], ['POST', '/settings/users', 'admin'],
            ['GET', '/backup', 'admin'], ['GET', '/backup.zip', 'admin'], ['POST', '/backup/restore', 'admin'], ['GET', '/settingsx', 'viewer']];
        foreach ($cases as [$m, $p, $role]) $this->assertSame($role, route_role($m, $p), "$m $p");
        $viewer = ['role' => 'viewer'];
        $this->assertTrue(user_can('viewer', $viewer));
        $this->assertFalse(user_can('editor', $viewer));
        $this->assertTrue(user_can('editor', ['role' => 'admin']));
    }

    public function testMigrationTurnsThePasswordIntoAdmin(): void
    {
        $pdo = db();
        $driver = db_driver();
        $pdo->exec('DROP TABLE users');
        $pdo->exec('ALTER TABLE activity DROP COLUMN actor');
        $hash = password_hash('old-shared-pw', PASSWORD_DEFAULT);
        $pdo->exec("DELETE FROM settings WHERE name IN ('auth_enabled', 'password_hash')");
        $ins = $pdo->prepare("INSERT INTO settings (name, value, updated_at) VALUES (?, ?, '2026-01-01 00:00:00')");
        $ins->execute(['auth_enabled', 'true']);
        $ins->execute(['password_hash', json_encode($hash)]);
        (require dirname(__DIR__, 2) . '/migrations/0018_users.php')($pdo, $driver);
        settings_cache(null, true);
        $u = user_verify('admin', 'old-shared-pw');
        $this->assertNotNull($u, 'the old password signs in as admin');
        $this->assertSame('admin', $u['role']);
        $this->assertSame('', setting('password_hash'), 'old hash cleared');
        $this->assertTrue(auth_enabled());
        $n = (int)$pdo->query('SELECT COUNT(*) FROM activity')->fetchColumn();
        activity_log(1, 'X', 'created', []);
        $this->assertSame($n + 1, (int)$pdo->query('SELECT COUNT(*) FROM activity WHERE actor IS NULL')->fetchColumn(), 'actor column added (empty from the CLI)');
    }
}
