<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\AdminGuard;
use Apotheke\Auth\CurrentUser;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\SignOutEverywhere;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\ActorGate;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Core\Security\Nobody;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * SEC-M1 (main #71): who counts as an admin, the admin-only account actions,
 * and the settings import gate with a real request user. SEC-N6: sign out
 * everywhere.
 */
final class AccountActionsTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @var array<array-key,mixed> the request's $_SESSION */
    private array $requestSession = [];

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
        parent::tearDown();
    }

    #[DataProvider('drivers')]
    public function testWhoIsTheCurrentUser(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $user = $this->currentUser();
        $this->assertSame('admin', $user->resolve()['role'] ?? null, 'sign-in off: the anonymous admin');
        $this->assertTrue($user->isAdmin());

        $root = $this->account('root', 'admin');
        $desk = $this->account('desk', 'helpdesk');
        $this->signInOn();
        $this->assertNull($user->resolve(), 'guests must sign in (the default)');
        $this->assertFalse($user->isAdmin());
        $this->settings(new CommandLine())->store(['auth_guest_disabled' => false]);
        $this->assertSame(['guest', true], [$user->resolve()['role'] ?? null, $user->resolve()['anon'] ?? null]);
        $this->assertFalse($user->isAdmin());

        $this->signInAs($root);
        $this->assertSame(['root', 'admin', false], [$user->resolve()['username'] ?? null, $user->resolve()['role'] ?? null, $user->resolve()['guest'] ?? null]);
        $this->assertTrue($user->isAdmin());
        $this->signInAs($desk);
        $this->assertFalse($user->isAdmin());
        // A stale epoch (password changed elsewhere) or a pending account is not signed in.
        $this->signInAs($root);
        $this->requestSession['auth_epoch'] = 'stale';
        $this->assertSame('guest', $user->resolve()['role'] ?? null);
        $this->database->db->table('users')->where('id', $root)->update(['status' => 'pending']);
        $this->signInAs($root);
        $this->assertFalse($user->isAdmin());
    }

    /**
     * Main #77 / #79 QA L-1: the anonymous guest is never admin on the web, even
     * when it carries the admin role. CI4 has no SAPI branch: this runs on the
     * CLI SAPI, where main's rule would say admin, and CurrentUser still says no.
     * Only the explicit CommandLine actor is admin on the command line.
     */
    #[DataProvider('drivers')]
    public function testTheAnonymousGuestIsNeverAdmin(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $user = $this->currentUser();
        $this->assertSame('cli', PHP_SAPI);
        $guest = ['id' => null, 'username' => '', 'role' => 'admin', 'status' => 'active', 'guest' => true, 'anon' => true];
        $this->assertFalse($user->admits($guest), 'an anonymous guest with the admin role');
        $this->assertFalse($user->admits(null), 'nobody');
        $this->assertTrue($user->admits(['anon' => false] + $guest), 'sign-in off: the anonymous admin');
        $this->assertTrue((new CommandLine())->isAdmin());

        $this->account('root', 'admin');
        $this->signInOn();
        $this->settings(new CommandLine())->store(['auth_guest_disabled' => false]);
        $this->assertTrue($user->resolve()['anon'] ?? false);
        $this->assertFalse($user->isAdmin());
        $file = (string)json_encode(['format' => 'apotheke-settings', 'settings' => ['session_days' => 7, 'trust_proxy' => true]]);
        $this->assertSame(['session_days', 'trust_proxy'], $this->settings($user)->import($file)['ignored'], 'guest browsing: the web guest imports no sign-in settings');
        $this->assertSame([], $this->settings(new CommandLine())->import($file)['ignored'], 'the command line does');
        $this->assertSame([7, true], [$this->settings()->get('session_days'), $this->settings()->get('trust_proxy')]);
    }

    #[DataProvider('drivers')]
    public function testOnlyAnAdminImportsSignInSettings(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $root = $this->account('root', 'admin');
        $desk = $this->account('desk', 'helpdesk');
        $this->signInOn();
        $file = (string)json_encode(['format' => 'apotheke-settings', 'settings' => ['app_name' => 'Shed', 'auth_register_disabled' => false, 'auth_register_role' => 'editor']]);

        $this->signInAs($desk);
        $r = $this->settings($this->currentUser())->import($file);
        $this->assertSame(['auth_register_disabled', 'auth_register_role'], $r['ignored']);
        $this->assertSame([true, 'viewer'], [$this->settings()->get('auth_register_disabled'), $this->settings()->get('auth_register_role')]);
        $this->assertSame('Shed', $this->settings()->get('app_name'));

        $this->signInAs($root);
        $this->assertSame([], $this->settings($this->currentUser())->import($file)['ignored']);
        $this->assertSame([false, 'editor'], [$this->settings()->get('auth_register_disabled'), $this->settings()->get('auth_register_role')]);
    }

    public function testAdminOnlyAccountActions(): void
    {
        $admin = ['role' => 'admin'];
        $editor = ['role' => 'editor'];
        $guard = new AdminGuard(new Nobody());
        foreach ([fn () => $guard->target($admin), fn () => $guard->grant('admin'), fn () => $guard->signInOff()] as $action) {
            try {
                $action();
                $this->fail('a non-admin passed an admin-only action');
            } catch (UserError $e) {
                $this->assertSame(403, $e->getCode());
            }
        }
        $guard->target($editor);
        $guard->grant('viewer');
        $cli = new AdminGuard(new CommandLine());
        $cli->target($admin);
        $cli->grant('admin');
        $cli->signInOff();
        $this->addToAssertionCount(5);
    }

    /**
     * SEC-N6, main #84: sign out everywhere stores a new session epoch. Every
     * session holding the old one is signed out; the password is unchanged,
     * and the caller's session signs in again with the new epoch.
     */
    #[DataProvider('drivers')]
    public function testSignOutEverywhere(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $root = $this->account('root', 'admin', 'placeholder-pass');
        $desk = $this->account('desk', 'helpdesk');
        $this->signInOn();
        $users = new UserRepository($this->database->db);
        $this->signInAs($desk);
        $deskSession = $this->requestSession;
        $this->signInAs($root);
        $other = $this->requestSession;
        $before = $users->find($root);
        $this->assertNotNull($before);

        $epoch = (new SignOutEverywhere($users))->run($root);
        $after = $users->find($root);
        $this->assertNotNull($after);
        $this->assertSame($before['password_hash'], $after['password_hash'], 'the password is unchanged');
        $this->assertSame($epoch, $after['session_epoch']);
        $this->assertSame($epoch, (new SessionEpoch())->of($after), 'the stored epoch wins');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $epoch);
        $this->requestSession = $other;
        $this->assertFalse($this->currentUser()->isAdmin(), 'another device is signed out');
        $this->requestSession = ['uid' => $root, 'auth_epoch' => $epoch];
        $this->assertTrue($this->currentUser()->isAdmin(), 'this session keeps the new epoch');
        $this->requestSession = $deskSession;
        $this->assertSame('desk', $this->currentUser()->resolve()['username'] ?? null, 'other accounts stay signed in');

        $this->assertNotSame($epoch, (new SignOutEverywhere($users))->run($root), 'a new value every time');
        try {
            (new SignOutEverywhere($users))->run(9999);
            $this->fail('signed out an account that does not exist');
        } catch (UserError $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    /** Main #84: a new password stores a new epoch too, so it signs the account out everywhere. */
    #[DataProvider('drivers')]
    public function testANewPasswordSignsTheAccountOutEverywhere(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $root = $this->account('root', 'admin');
        $this->signInOn();
        $users = new UserRepository($this->database->db);
        $this->signInAs($root);
        $other = $this->requestSession;
        $epoch = $users->setPasswordHash($root, password_hash('another-pass', PASSWORD_DEFAULT));
        $after = $users->find($root);
        $this->assertNotNull($after);
        $this->assertTrue(password_verify('another-pass', $after['password_hash']));
        $this->assertSame($epoch, $after['session_epoch']);
        $this->requestSession = $other;
        $this->assertFalse($this->currentUser()->isAdmin(), 'the old session is signed out');
        $this->requestSession = ['uid' => $root, 'auth_epoch' => $epoch];
        $this->assertTrue($this->currentUser()->isAdmin(), 'a session that signs in again with the new epoch');
    }

    /**
     * Migration 0028 (copied byte for byte from main): every account gets the
     * fingerprint its sessions already hold, so the upgrade signs nobody out.
     * An account without a stored epoch falls back to that fingerprint.
     */
    #[DataProvider('drivers')]
    public function testMigration0028KeepsExistingSessions(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $root = $this->account('root', 'admin');
        $user = (new UserRepository($this->db()))->find($root);
        $this->assertNotNull($user);
        $fingerprint = substr(hash('sha256', $user['password_hash'] . '|' . $root), 0, 16);
        $this->assertSame('', $user['session_epoch'], 'an account created after 0028 starts without one');
        $this->assertSame($fingerprint, (new SessionEpoch())->of($user), 'no stored epoch: the fingerprint');

        $pdo = $this->database->pdo();
        $run = require dirname(__DIR__, 3) . '/migrations/0028_session_epoch.php';
        $run($pdo, $driver);
        $run($pdo, $driver);
        $user = (new UserRepository($this->db()))->find($root);
        $this->assertNotNull($user);
        $this->assertSame($fingerprint, $user['session_epoch'], 'filled with the fingerprint; running it again changes nothing');
        $this->signInOn();
        $this->requestSession = ['uid' => $root, 'auth_epoch' => $fingerprint];
        $this->assertTrue($this->currentUser()->isAdmin(), 'a session from before the upgrade stays signed in');
    }

    private function currentUser(): CurrentUser
    {
        return new CurrentUser(fn (): SettingsService => $this->settings(), new UserRepository($this->db()), fn (): array => $this->requestSession);
    }

    private function settings(?ActorGate $actor = null): SettingsService
    {
        $database = $this->database;
        $this->assertNotNull($database);

        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null, $actor);
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }

    private function account(string $name, string $role, string $password = 'long-enough'): int
    {
        $this->db()->table('users')->insert(['username' => $name, 'username_key' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
        $row = $this->db()->table('users')->select('id')->where('username_key', $name)->get()->getRowArray();

        return (int)($row['id'] ?? 0);
    }

    private function signInOn(): void
    {
        $this->settings(new CommandLine())->store(['auth_enabled' => true]);
    }

    private function signInAs(int $id): void
    {
        $user = (new UserRepository($this->db()))->find($id);
        $this->assertNotNull($user);
        $this->requestSession = ['uid' => $id, 'auth_epoch' => (new SessionEpoch())->of($user)];
    }
}
