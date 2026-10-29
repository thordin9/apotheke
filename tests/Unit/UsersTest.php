<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/legacy-bootstrap.php';

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

    public function testOnlyAnAdminCanGrantTheAdminRole(): void
    {
        $root = user_create('root', 'long-enough', 'admin');
        $desk = role_create('Helpdesk', ['view', 'users']);
        $id = user_create('desk', 'long-enough', $desk);
        auth_turn_on();
        settings_cache(null, true);
        current_user(user_get($id) + ['guest' => false, 'anon' => false]);
        try {
            try {
                user_set_role($id, 'admin');
                $this->fail('a Users account granted itself admin');
            } catch (UserError $e) {
                $this->assertSame(403, $e->getCode());
            }
            $this->assertSame($desk, user_get($id)['role']);
            try {
                user_create('extra', 'long-enough', 'admin');
                $this->fail('a Users account created an admin');
            } catch (UserError $e) {
                $this->assertSame(403, $e->getCode());
            }
            $this->assertNull(user_by_name('extra'));
            current_user(user_get($root) + ['guest' => false, 'anon' => false]);
            user_set_role($id, 'admin');
            $this->assertSame('admin', user_get($id)['role']);
        } finally {
            current_user(null, true);
        }
    }

    public function testOnlyAnAdminCanChangeAnAdminAccount(): void
    {
        $root = user_create('root', 'long-enough', 'admin');
        $second = user_create('second', 'long-enough', 'admin');
        $editor = user_create('ed', 'long-enough', 'editor');
        $desk = role_create('Helpdesk', ['view', 'users']);
        $id = user_create('desk', 'long-enough', $desk);
        $pending = user_create('waiting', 'long-enough', 'admin', null, 'pending');
        auth_turn_on();
        settings_cache(null, true);
        current_user(user_get($id) + ['guest' => false, 'anon' => false]);
        $hash = user_get($root)['password_hash'];
        try {
            foreach ([
                fn() => user_set_role($root, 'viewer'),
                fn() => user_set_role($root, 'admin'),
                fn() => user_set_password($root, 'another-one', 'another-one'),
                fn() => user_rename($root, 'renamed'),
                fn() => user_delete($root),
                fn() => user_approve($pending),
            ] as $act) {
                try {
                    $act();
                    $this->fail('a Users account changed an admin');
                } catch (UserError $e) {
                    $this->assertSame(403, $e->getCode());
                }
            }
            $this->assertSame('admin', user_get($root)['role']);
            $this->assertSame('root', user_get($root)['username']);
            $this->assertSame($hash, user_get($root)['password_hash']);
            $this->assertNotNull(user_verify('root', 'long-enough'));
            $this->assertSame('pending', user_get($pending)['status']);
            user_set_role($editor, 'viewer');
            user_set_password($editor, 'another-one', 'another-one');
            user_rename($editor, 'eddie');
            $this->assertSame(['eddie', 'viewer'], [user_get($editor)['username'], user_get($editor)['role']]);
            $this->assertNotNull(user_verify('eddie', 'another-one'));
            current_user(user_get($root) + ['guest' => false, 'anon' => false]);
            user_set_role($second, 'viewer');
            user_set_password($second, 'fresh-pass', 'fresh-pass');
            user_rename($second, 'former');
            user_approve($pending);
            $this->assertSame('viewer', user_get($second)['role']);
            $this->assertSame('former', user_get($second)['username']);
            $this->assertNotNull(user_verify('former', 'fresh-pass'));
            $this->assertSame('active', user_get($pending)['status']);
            user_delete($second);
            $this->assertNull(user_get($second));
        } finally {
            current_user(null, true);
        }
    }

    public function testOnlyAnAdminCanTurnSignInOff(): void
    {
        $root = user_create('root', 'long-enough', 'admin');
        $desk = role_create('Helpdesk', ['view', 'users']);
        $id = user_create('desk', 'long-enough', $desk);
        auth_turn_on();
        settings_cache(null, true);
        current_user(user_get($id) + ['guest' => false, 'anon' => false]);
        try {
            try {
                auth_turn_off();
                $this->fail('a Users account turned sign-in off');
            } catch (UserError $e) {
                $this->assertSame(403, $e->getCode());
            }
            settings_cache(null, true);
            $this->assertTrue(auth_enabled());
            current_user(user_get($root) + ['guest' => false, 'anon' => false]);
            auth_turn_off();
            settings_cache(null, true);
            $this->assertFalse(auth_enabled());
        } finally {
            current_user(null, true);
        }
    }

    public function testCommandLineIsAdminWhenGuestBrowsingIsOn(): void
    {
        $this->assertSame('cli', PHP_SAPI);
        $root = user_create('root', 'long-enough', 'admin');
        $editor = user_create('ed', 'long-enough', 'editor');
        $deskRole = role_create('Helpdesk', ['view', 'users']);
        $desk = user_create('desk', 'long-enough', $deskRole);
        auth_turn_on();
        settings_store(['auth_guest_disabled' => false]);
        settings_cache(null, true);
        current_user(null, true);
        try {
            $guest = current_user();
            $this->assertSame(['guest', true, true], [$guest['role'], $guest['guest'], $guest['anon']], 'guest browsing is the current user on the command line');
            $this->assertTrue(actor_is_admin(), 'the command line is an admin when the current user is the anonymous guest');
            auth_turn_off();
            settings_cache(null, true);
            $this->assertFalse(auth_enabled(), 'the command line can turn sign-in off while guest browsing is on');
            auth_turn_on();
            settings_store(['auth_guest_disabled' => false]);
            settings_cache(null, true);
            current_user(null, true);
            user_set_password($root, 'fresh-pass');
            $this->assertNotNull(user_verify('root', 'fresh-pass'), 'the command line can change an admin password');
            user_set_role($editor, 'admin');
            $this->assertSame('admin', user_get($editor)['role'], 'the command line can grant admin');

            current_user(user_get($desk) + ['guest' => false, 'anon' => false]);
            $this->assertFalse(actor_is_admin(), 'a signed-in non-admin is still not an admin');
            try {
                user_set_password($root, 'other-pass');
                $this->fail('a signed-in non-admin changed an admin');
            } catch (UserError $e) {
                $this->assertSame(403, $e->getCode());
            }
            $this->assertNotNull(user_verify('root', 'fresh-pass'));
            try {
                user_set_role($desk, 'admin');
                $this->fail('a signed-in non-admin granted admin');
            } catch (UserError $e) {
                $this->assertSame(403, $e->getCode());
            }
            $this->assertSame($deskRole, user_get($desk)['role']);
        } finally {
            current_user(null, true);
        }
    }

    /** A web SAPI does not treat the anonymous guest as admin, even when that guest's role is admin. The command line does. */
    public function testAnonymousGuestIsNotAdminOnAWebSapi(): void
    {
        current_user([
            'id' => null,
            'username' => '',
            'role' => 'admin',
            'status' => 'active',
            'guest' => true,
            'anon' => true,
        ]);
        try {
            $this->assertFalse(actor_is_admin('fpm-fcgi'));
            $this->assertFalse(actor_is_admin('cli-server'));
            $this->assertTrue(actor_is_admin('cli'));
            $this->assertTrue(actor_is_admin());
            current_user([
                'id' => null,
                'username' => '',
                'role' => 'admin',
                'status' => 'active',
                'guest' => true,
                'anon' => false,
            ]);
            $this->assertTrue(actor_is_admin('fpm-fcgi'), 'with sign-in off, the anonymous admin stays an admin on the web');
        } finally {
            current_user(null, true);
        }
    }

    public function testPasswordChangeInvalidatesEpoch(): void
    {
        $id = user_create('pat', 'long-enough', 'viewer');
        $this->pdo->prepare('UPDATE users SET session_epoch = ? WHERE id = ?')->execute(['aaaaaaaaaaaaaaaa', $id]);
        settings_store(['auth_enabled' => true]);
        settings_cache(null, true);
        $_SESSION = ['uid' => $id, 'auth_epoch' => 'aaaaaaaaaaaaaaaa'];
        current_user(null, true);
        $this->assertSame($id, current_user()['id']);
        user_set_password($id, 'another-one');
        $this->assertNotSame('aaaaaaaaaaaaaaaa', user_epoch(user_get($id)));
        current_user(null, true);
        $this->assertNull(current_user(), 'a session from before the password change is dropped');
        $this->assertNotNull(user_verify('pat', 'another-one'));
        $_SESSION = [];
        current_user(null, true);
    }

    public function testSignOutEverywhereRenewsTheEpochAndDropsOlderSessions(): void
    {
        $id = user_create('pat', 'long-enough', 'viewer');
        settings_store(['auth_enabled' => true]);
        settings_cache(null, true);
        $epoch = user_epoch(user_get($id));
        $_SESSION = ['uid' => $id, 'auth_epoch' => $epoch];
        current_user(null, true);
        $this->assertSame($id, current_user()['id']);

        $other = $_SESSION;
        user_sign_out_everywhere($id);
        $renewed = user_epoch(user_get($id));
        $this->assertNotSame($epoch, $renewed);
        $this->assertNotNull(user_verify('pat', 'long-enough'), 'the password stays the same');

        $_SESSION = $other;
        current_user(null, true);
        $this->assertNull(current_user(), 'a session from before the action is dropped');

        $_SESSION['auth_epoch'] = $renewed;
        current_user(null, true);
        $this->assertSame($id, current_user()['id'], 'a session that holds the new epoch stays signed in');
        $_SESSION = [];
        current_user(null, true);
    }

    public function testMigration0028StoresAnEpochWithoutChangingTheCurrentOne(): void
    {
        $id = user_create('pat', 'long-enough', 'viewer');
        $hash = user_get($id)['password_hash'];
        $legacy = substr(hash('sha256', $hash . '|' . $id), 0, 16);
        $this->pdo->prepare("UPDATE users SET session_epoch = '' WHERE id = ?")->execute([$id]);
        $run = require migrations_dir() . '/0028_session_epoch.php';
        $run($this->pdo, $this->driver);
        $this->assertSame($legacy, (string)user_get($id)['session_epoch'], 'an empty epoch becomes the one existing sessions already hold');
        $this->assertSame($legacy, user_epoch(user_get($id)));
        user_sign_out_everywhere($id);
        $renewed = (string)user_get($id)['session_epoch'];
        $this->assertNotSame($legacy, $renewed);
        $run($this->pdo, $this->driver);
        $this->assertSame($renewed, (string)user_get($id)['session_epoch'], 'a second run does not replace a renewed epoch');
        if ($this->driver === 'pgsql') {
            $have = $this->pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'session_epoch'")->fetchColumn();
        } else {
            $have = false;
            foreach ($this->pdo->query('PRAGMA table_info(users)') as $col) {
                if ($col['name'] === 'session_epoch') $have = true;
            }
        }
        $this->assertNotEmpty($have);
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
        $this->assertFalse(user_can('view'));
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
