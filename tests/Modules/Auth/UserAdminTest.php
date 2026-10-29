<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\AdminGuard;
use Apotheke\Auth\RoleAdmin;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\SignInSwitch;
use Apotheke\Auth\UserAdmin;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Exceptions\WriteFailed;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\ActorGate;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Users & sign-in (legacy src/users.php, src/roles.php, auth_turn_on/off()):
 * who may change which account, the last active admin, users nobody could
 * manage any more, the checks in legacy's order, and every write failing
 * loudly with nothing changed. The page itself is golden-tested against
 * legacy in tests/Unit/Ci4UsersRolesParityTest.php.
 */
final class UserAdminTest extends CIUnitTestCase
{
    private const PW = 'long-enough';

    private ?TestDatabase $database = null;

    private bool $admin = true;

    /** @return iterable<string,array{0:string}> */
    public static function drivers(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        $this->admin = true;
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testAccounts(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $ada = $this->account('ada', 'admin');
        $users = $this->users();
        $id = $users->create(' Neo ', self::PW, 'editor', self::PW);
        $row = $this->repo()->find($id);
        $this->assertSame(['Neo', 'editor', 'active'], [$row['username'] ?? null, $row['role'] ?? null, $row['status'] ?? null]);
        $this->assertTrue(password_verify(self::PW, $row['password_hash'] ?? ''));
        $this->assertUserError(422, 'Choose a role: admin, editor, viewer.', fn () => $users->create('zed', self::PW, 'guest'));
        $this->assertUserError(422, 'Passwords cannot contain a null character.', fn () => $users->create('zed', "long\0enough", 'editor'));
        $this->assertUserError(404, 'User not found.', fn () => $users->setRole(99999, 'editor'));
        $this->assertUserError(404, 'User not found.', fn () => $users->setPassword(PHP_INT_MAX, self::PW));
        $this->assertUserError(409, '“ada” is the only admin – make someone else an admin first.', fn () => $users->setRole($ada, 'editor'));
        $this->assertUserError(409, '“ada” is the only admin and cannot be deleted.', fn () => $users->delete($ada));
        // A pending admin does not count as the other admin.
        $pal = $this->account('pal', 'admin', 'pending');
        $this->assertUserError(409, '“ada” is the only admin and cannot be deleted.', fn () => $users->delete($ada));
        $users->approve($pal);
        $users->setRole($ada, 'editor');
        $this->assertSame('editor', $this->repo()->find($ada)['role'] ?? null);
        $this->assertUserError(409, '“pal” is the only admin and cannot be deleted.', fn () => $users->delete($pal));
        $users->setRole($ada, 'admin');
        $users->delete($pal);
        $this->assertNull($this->repo()->find($pal));
        $users->rename($id, 'NEO');
        $this->assertSame('NEO', $this->repo()->find($id)['username'] ?? null);
        $this->assertUserError(422, null, fn () => $users->rename($id, 'Ada'));
        // N-261: a null character anywhere is refused before trimming, never trimmed into a shorter name.
        $rule = 'User names use letters, digits and . _ @ - (at most 40 characters, no spaces).';
        foreach (["\0zed", "zed\0", "z\0ed", " zed\0 "] as $nul) {
            $this->assertUserError(422, $rule, fn () => $users->create($nul, self::PW, 'editor', self::PW), (string)json_encode($nul));
            $this->assertUserError(422, $rule, fn () => $users->rename($id, $nul), (string)json_encode($nul));
        }
        $this->assertNull($this->repo()->findByName('zed'), 'no account was created');
        $this->assertSame('NEO', $this->repo()->find($id)['username'] ?? null, 'nothing was renamed');
        // A new password renews the account's session epoch and returns it.
        $before = $this->repo()->find($id)['session_epoch'] ?? null;
        $epoch = $users->setPassword($id, 'newer-password', 'newer-password');
        $this->assertNotSame($before, $epoch);
        $this->assertSame($epoch, $this->repo()->find($id)['session_epoch'] ?? null);
        // A role change keeps the sessions (legacy).
        $users->setRole($id, 'viewer');
        $this->assertSame($epoch, $this->repo()->find($id)['session_epoch'] ?? null);
        $this->assertSame(1, $users->activeAdmins());
    }

    /** N-111: a pending admin may be demoted while one active admin remains (legacy counts only active admins). */
    #[DataProvider('drivers')]
    public function testAPendingAdminCanBeDemotedBesideTheOnlyActiveAdmin(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $ada = $this->account('ada', 'admin');
        $pal = $this->account('pal', 'admin', 'pending');
        $this->users()->setRole($pal, 'editor');
        $this->assertSame(['editor', 'pending'], [$this->repo()->find($pal)['role'] ?? null, $this->repo()->find($pal)['status'] ?? null]);
        $this->assertSame(1, $this->users()->activeAdmins());
        $this->assertUserError(409, '“ada” is the only admin – make someone else an admin first.', fn () => $this->users()->setRole($ada, 'editor'));
    }

    /**
     * The last-admin check reads the account again under the admin lock: a
     * change committed between the first read and the lock (here a trigger
     * on the lock's own write makes ada the only admin) is what it checks.
     */
    #[DataProvider('drivers')]
    public function testTheLastAdminCheckReadsTheAccountUnderTheLock(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $ada = $this->account('ada', 'editor');
        $bea = $this->account('bea', 'admin');
        $db = $this->db();
        $db->query('CREATE TABLE lock_hook (armed INTEGER)');
        $swap = "UPDATE users SET role = 'admin' WHERE username = 'ada'; UPDATE users SET role = 'editor' WHERE username = 'bea';";
        if ($driver === 'pgsql') {
            $db->query("CREATE FUNCTION lock_hook() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NOT EXISTS (SELECT 1 FROM lock_hook) THEN RETURN NULL; END IF; DELETE FROM lock_hook; "
                . str_replace("'", "''", $swap) . " RETURN NULL; END'");
            $db->query('CREATE TRIGGER lock_hook AFTER UPDATE ON users FOR EACH ROW EXECUTE FUNCTION lock_hook()');
        } else {
            $db->query('CREATE TRIGGER lock_hook AFTER UPDATE ON users WHEN EXISTS (SELECT 1 FROM lock_hook) BEGIN DELETE FROM lock_hook; ' . $swap . ' END');
        }
        $users = $this->users();
        foreach (['demote' => [fn () => $users->setRole($ada, 'viewer'), '“ada” is the only admin – make someone else an admin first.'],
            'delete' => [fn () => $users->delete($ada), '“ada” is the only admin and cannot be deleted.']] as $what => [$change, $message]) {
            $db->query('DELETE FROM lock_hook');   // a rolled-back change keeps it armed
            $this->repo()->setRole($ada, 'editor');
            $this->repo()->setRole($bea, 'admin');
            $db->query('INSERT INTO lock_hook (armed) VALUES (1)');
            $this->assertUserError(409, $message, $change, $what);
            $this->assertSame(['editor', 'admin'], [$this->repo()->find($ada)['role'] ?? null, $this->repo()->find($bea)['role'] ?? null], $what . ': rolled back');
        }
    }

    /** Only an admin changes an admin account or grants admin; checks run in legacy's order. */
    #[DataProvider('drivers')]
    public function testANonAdminCannotTouchAnAdmin(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $ada = $this->account('ada', 'admin');
        $pal = $this->account('pal', 'admin', 'pending');
        $eda = $this->account('eda', 'editor');
        $this->admin = false;
        $users = $this->users();
        $before = $this->snapshot();
        $this->assertUserError(403, 'Only an admin can change an admin account.', fn () => $users->setRole($ada, 'viewer'));
        $this->assertUserError(403, 'Only an admin can change an admin account.', fn () => $users->setPassword($ada, 'x'));
        $this->assertUserError(403, 'Only an admin can change an admin account.', fn () => $users->rename($ada, 'a b<'));
        $this->assertUserError(403, 'Only an admin can change an admin account.', fn () => $users->delete($ada));
        $this->assertUserError(403, 'Only an admin can change an admin account.', fn () => $users->approve($pal));
        $this->assertUserError(403, 'Only an admin can grant the admin role.', fn () => $users->setRole($eda, 'admin'));
        $this->assertUserError(403, 'Only an admin can grant the admin role.', fn () => $users->create('zed', 'short', 'admin'));
        $this->assertUserError(422, 'Choose a role: admin, editor, viewer.', fn () => $users->setRole($ada, 'nope'), 'the role is checked before the target, as legacy');
        $this->assertUserError(403, 'Only an admin can turn sign-in off.', fn () => $this->switch()->off());
        $this->assertSame($before, $this->snapshot());
        $users->setRole($eda, 'viewer');
        $users->setPassword($eda, 'newer-password');
        $this->assertSame('viewer', $this->repo()->find($eda)['role'] ?? null);
    }

    #[DataProvider('drivers')]
    public function testSignInOnAndOff(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->account('pal', 'admin', 'pending');
        $this->assertUserError(422, 'Create an admin account first.', fn () => $this->switch()->on());
        $this->account('ada', 'admin');
        $this->switch()->on();
        $this->assertTrue($this->settings()->get('auth_enabled'));
        $this->switch()->off();
        $this->assertFalse($this->settings()->get('auth_enabled'));
    }

    #[DataProvider('drivers')]
    public function testRoles(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $this->account('ada', 'admin');
        $roles = $this->roles();
        $this->assertSame('help-team', $roles->create(' Help  team ', ['users', 'view', 'view']));
        $this->assertSame(['view', 'users'], $roles->all()['help-team']['perms']);
        $this->assertFalse($roles->all()['help-team']['builtin']);
        $this->assertUserError(409, 'A role “Help team” exists already.', fn () => $roles->create('help TEAM', []));
        $this->assertUserError(422, 'Unknown permission “fly”.', fn () => $roles->create('Pilots', ['fly']));
        $this->assertUserError(422, 'Use letters or digits in the role name.', fn () => $roles->create('!!!', []));
        $this->assertUserError(409, 'The admin role always has every permission.', fn () => $roles->update('admin', null, []));
        $this->assertUserError(404, 'Role not found.', fn () => $roles->update('nosuch', null, []));
        $roles->update('guest', 'Ignored', ['view', 'users', 'settings', 'backup', 'export']);
        $this->assertSame(['name' => 'Guest', 'perms' => ['view', 'export']], array_intersect_key($roles->all()['guest'], ['name' => 1, 'perms' => 1]), 'never admin powers for guests, built-in names stay');
        $this->assertUserError(409, 'Built-in roles cannot be deleted.', fn () => $roles->delete('editor'));
        foreach (['guest', 'help-team', 'nosuch'] as $to) {
            $this->assertUserError(422, 'Choose another role for its accounts.', fn () => $roles->delete('help-team', $to));
        }
        // The only one who may manage users is on a custom role.
        $hal = $this->account('hal', 'help-team');
        $this->db()->query("UPDATE users SET status = 'pending' WHERE username = 'ada'");
        $before = $this->snapshot();
        $this->assertUserError(409, 'Nobody could manage users any more – keep the Users permission or make someone an admin first.', fn () => $roles->update('help-team', null, ['view']));
        $this->assertUserError(409, 'Nobody could manage users any more – make someone an admin first.', fn () => $roles->delete('help-team', 'viewer'));
        $this->assertSame($before, $this->snapshot());
        $this->db()->query("UPDATE users SET status = 'active' WHERE username = 'ada'");
        // With an active admin, the custom role may lose the Users permission.
        $roles->update('help-team', null, ['view']);
        $this->assertSame(['view'], $roles->all()['help-team']['perms']);
        $this->settings()->store(['auth_register_role' => 'help-team']);
        $roles->delete('help-team', 'editor');
        $this->assertSame('editor', $this->repo()->find($hal)['role'] ?? null);
        $this->assertArrayNotHasKey('help-team', $roles->all());
        $this->assertSame('viewer', $this->settings()->stored('auth_register_role'), 'registrations fall back to viewer');
    }

    /** @return iterable<string,array{0:string,1:string,2:string,3:string}> */
    public static function failingWrites(): iterable
    {
        $cases = [
            'create' => ['users', 'INSERT'], 'role' => ['users', 'UPDATE'], 'password' => ['users', 'UPDATE'], 'rename' => ['users', 'UPDATE'],
            'approve' => ['users', 'UPDATE'], 'delete' => ['users', 'DELETE'], 'role create' => ['roles', 'INSERT'], 'role update' => ['roles', 'UPDATE'],
            'role delete: roles' => ['roles', 'DELETE'], 'role delete: moving users' => ['users', 'UPDATE'], 'sign-in on' => ['settings', 'INSERT'],
        ];
        foreach (TestDatabase::drivers() as $driver) {
            foreach ($cases as $what => [$table, $op]) {
                yield $driver . ' ' . $what => [$driver, $what, $table, $op];
            }
        }
    }

    #[DataProvider('failingWrites')]
    public function testEveryWriteFailsLoudly(string $driver, string $what, string $table, string $op): void
    {
        $this->database = TestDatabase::create($driver);
        $this->account('ada', 'admin');
        $eda = $this->account('eda', 'editor', 'pending');
        $this->roles()->create('Temp', ['view']);
        $this->account('tim', 'temp');
        $write = match ($what) {
            'create' => fn () => $this->users()->create('neo', self::PW, 'editor'),
            'role' => fn () => $this->users()->setRole($eda, 'viewer'),
            'password' => fn () => $this->users()->setPassword($eda, 'newer-password'),
            'rename' => fn () => $this->users()->rename($eda, 'edda'),
            'approve' => fn () => $this->users()->approve($eda),
            'delete' => fn () => $this->users()->delete($eda),
            'role create' => fn () => $this->roles()->create('Packers', ['view']),
            'role update' => fn () => $this->roles()->update('temp', 'Tmp', ['view', 'labels']),
            'role delete: roles', 'role delete: moving users' => fn () => $this->roles()->delete('temp', 'editor'),
            'sign-in on' => fn () => $this->switch()->on(),
        };
        $before = $this->snapshot();
        $this->failOn($table, $op);
        try {
            $write();
            $this->fail('the failed write must throw');
        } catch (WriteFailed $e) {
            $this->assertStringStartsWith($table === 'settings' ? 'Could not save the settings.' : 'Database write failed', $e->getMessage());
        }
        $this->assertSame($before, $this->snapshot(), 'nothing changed');
    }

    private function assertUserError(int $code, ?string $message, callable $fn, string $why = ''): void
    {
        try {
            $fn();
            $this->fail('expected a refusal ' . $why);
        } catch (UserError $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage() . ' ' . $why);
            if ($message !== null) {
                $this->assertSame($message, $e->getMessage(), $why);
            }
        }
    }

    private function failOn(string $table, string $op): void
    {
        if ($this->db()->DBDriver === 'Postgre') {
            $this->db()->query("CREATE OR REPLACE FUNCTION apotheke_test_fail() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''test failure''; END'");
            $this->db()->query("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table FOR EACH ROW EXECUTE FUNCTION apotheke_test_fail()");

            return;
        }
        $this->db()->query("CREATE TRIGGER apotheke_test_fail BEFORE $op ON $table BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    }

    private function snapshot(): string
    {
        return (string)json_encode([
            $this->db()->query('SELECT id, username, username_key, role, status, password_hash, session_epoch FROM users ORDER BY id')->getResultArray(),
            $this->db()->query('SELECT role_key, name, perms, builtin FROM roles ORDER BY role_key')->getResultArray(),
            $this->db()->query('SELECT name, value FROM settings ORDER BY name')->getResultArray(),
        ]);
    }

    private function actor(): ActorGate
    {
        return new class (fn (): bool => $this->admin) implements ActorGate {
            /** @param \Closure():bool $admin */
            public function __construct(private readonly \Closure $admin)
            {
            }

            public function isAdmin(): bool
            {
                return ($this->admin)();
            }
        };
    }

    /**
     * L-110 on PostgreSQL: while one change to an admin holds the admin lock
     * (its transaction still open), a second client's admin change, role
     * change or role edit waits for it instead of counting the admins too
     * early; here it gives up after a short lock_timeout. The lock and the
     * write are one transaction (the same txid).
     */
    public function testTheAdminLockMakesAdminChangesWaitOnPostgres(): void
    {
        if (!in_array('pgsql', TestDatabase::drivers(), true)) {
            $this->assertFalse((bool)getenv('CI'), 'CI runs the PostgreSQL lock test');
            $this->markTestSkipped('PostgreSQL is not configured');
        }
        $this->database = TestDatabase::create('pgsql');
        $ada = $this->account('ada', 'admin');
        $bea = $this->account('bea', 'admin');
        $other = $this->database->connectAgain();
        try {
            $other->query("SET lock_timeout = '300ms'");
            $otherRepo = new UserRepository($other);
            $otherRoles = new RoleRepository($other);
            $otherUsers = new UserAdmin($otherRepo, $otherRoles, new Accounts($otherRepo, $otherRoles, new Clock()), new AdminGuard($this->actor()), new Clock());
            $otherRoleAdmin = new RoleAdmin($otherRoles, $otherRepo, $this->settings(), new Clock());
            $this->assertTrue($this->db()->transBegin());
            $this->users()->setRole($bea, 'editor');   // inside the open transaction: the admin rows stay locked
            foreach (['demote' => fn () => $otherUsers->setRole($ada, 'editor'), 'delete' => fn () => $otherUsers->delete($ada),
                'role edit' => fn () => $otherRoleAdmin->update('editor', null, ['view'])] as $what => $change) {
                try {
                    $change();
                    $this->fail($what . ' did not wait for the admin lock');
                } catch (WriteFailed) {
                    $this->assertTrue(true);
                }
            }
            $this->assertTrue($this->db()->transCommit());
            $this->assertUserError(409, '“ada” is the only admin – make someone else an admin first.', fn () => $otherUsers->setRole($ada, 'editor'));
            $this->assertSame(['admin', 'editor'], [$this->repo()->find($ada)['role'] ?? null, $this->repo()->find($bea)['role'] ?? null]);

            // Two admins deleting each other.
            $this->repo()->setRole($bea, 'admin');
            $this->assertTrue($this->db()->transBegin());
            $this->users()->delete($bea);
            try {
                $otherUsers->delete($ada);
                $this->fail('the second delete did not wait for the admin lock');
            } catch (WriteFailed) {
                $this->assertTrue(true);
            }
            $this->assertTrue($this->db()->transCommit());
            $this->assertUserError(409, '“ada” is the only admin and cannot be deleted.', fn () => $otherUsers->delete($ada));
            $bea = $this->account('bea', 'admin');
        } finally {
            $other->close();
        }

        // One transaction: every users write of a role change has the same txid.
        $this->repo()->setRole($bea, 'admin');
        $pdo = $this->database->pdo();
        $pdo->exec('CREATE TABLE txids (txid bigint)');
        $pdo->exec("CREATE FUNCTION note_txid() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN INSERT INTO txids VALUES (txid_current()); RETURN NEW; END'");
        $pdo->exec('CREATE TRIGGER note_txid BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION note_txid()');
        $this->users()->setRole($bea, 'editor');
        $this->assertSame(1, count(array_unique($this->db()->query('SELECT txid FROM txids')->getResultArray(), SORT_REGULAR)), 'the lock and the write in one transaction');
        $this->assertGreaterThanOrEqual(3, (int)($this->db()->query('SELECT COUNT(*) AS n FROM txids')->getRowArray()['n'] ?? 0), 'two admin rows locked, then the role written');
    }

    private function users(): UserAdmin
    {
        $roles = new RoleRepository($this->db());

        return new UserAdmin($this->repo(), $roles, new Accounts($this->repo(), $roles, new Clock()), new AdminGuard($this->actor()), new Clock());
    }

    private function roles(): RoleAdmin
    {
        return new RoleAdmin(new RoleRepository($this->db()), $this->repo(), $this->settings(), new Clock());
    }

    private function switch(): SignInSwitch
    {
        return new SignInSwitch($this->settings(), $this->repo(), new AdminGuard($this->actor()));
    }

    private function settings(): SettingsService
    {
        $database = $this->database;
        $this->assertNotNull($database);

        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null, new CommandLine());
    }

    private function repo(): UserRepository
    {
        return new UserRepository($this->db());
    }

    private function account(string $name, string $role, string $status = 'active'): int
    {
        return $this->repo()->create($name, password_hash(self::PW, PASSWORD_BCRYPT, ['cost' => 4]), $role, $status, '2026-01-01 00:00:00');
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }
}
