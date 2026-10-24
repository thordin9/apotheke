<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\AdminGuard;
use Apotheke\Auth\CurrentUser;
use Apotheke\Auth\SessionEpoch;
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
 * and the settings import gate with a real request user.
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
