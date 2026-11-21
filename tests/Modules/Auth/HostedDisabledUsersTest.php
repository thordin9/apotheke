<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\CurrentUser;
use Apotheke\Auth\HostedDisabledUsers;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Database\LocalBatchExecutor;
use Apotheke\Core\Database\UnitsOfWork;
use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Hosted\HostedLimits;
use Apotheke\Core\Hosted\PlanLimits;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\ActorGate;
use Apotheke\Core\Storage\LocalStorage;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * Hosted disable/enable of one user: sign-in is refused, that user's session rows matching uid|i:<id>; are removed,
 * a neighbouring id is left alone, and the last active admin cannot be disabled. Off without APOTHEKE_HOSTED.
 */
final class HostedDisabledUsersTest extends CIUnitTestCase
{
    private ?TestDatabase $database = null;

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function modes(): iterable
    {
        foreach (TestDatabase::drivers() as $driver) {
            yield $driver => [$driver, false];
            if ($driver !== 'd1') {
                yield $driver . ' batch' => [$driver, true];
            }
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        parent::tearDown();
    }

    private function open(string $driver): UserRepository
    {
        $this->database = TestDatabase::create($driver);

        return new UserRepository($this->database->db);
    }

    private function subject(bool $hosted = true, bool $batch = false): HostedDisabledUsers
    {
        $this->assertNotNull($this->database);
        $units = UnitsOfWork::forSite(
            $this->database->db,
            $batch ? UnitsOfWork::on(new LocalBatchExecutor($this->database->db)) : null,
        );

        return new HostedDisabledUsers($this->database->db, $units, new Clock(), $hosted);
    }

    private function accounts(HostedDisabledUsers $disabled): Accounts
    {
        $this->assertNotNull($this->database);
        $users = new UserRepository($this->database->db);

        return new Accounts($users, new RoleRepository($this->database->db), new Clock(), disabled: $disabled);
    }

    /** @return string the sessions.id key */
    private function putSession(string $sid, int $userId, ?string $data = null): string
    {
        $this->assertNotNull($this->database);
        $key = hash('sha256', $sid);
        $data ??= 'uid|i:' . $userId . ';auth_epoch|s:16:"0123456789abcdef";last_activity|i:1760000000;';
        $this->database->db->query(
            'INSERT INTO sessions (id, data, expires_at, lock_token, locked_until) VALUES (?, ?, ?, ?, ?)',
            [$key, $data, 2000000000, '', 0],
        );

        return $key;
    }

    /** @return list<string> */
    private function sessionKeys(): array
    {
        $this->assertNotNull($this->database);

        return array_map(static fn (array $r): string => (string)$r['id'], Writes::rows($this->database->db, 'SELECT id FROM sessions ORDER BY id'));
    }

    private function flagged(int $userId): bool
    {
        $this->assertNotNull($this->database);

        return Writes::rows($this->database->db, 'SELECT 1 AS one FROM hosted_disabled_users WHERE user_id = ?', [$userId]) !== [];
    }

    private function statusOf(int $userId): string
    {
        $this->assertNotNull($this->database);

        return (string)(Writes::rows($this->database->db, 'SELECT status FROM users WHERE id = ?', [$userId])[0]['status'] ?? '');
    }

    #[DataProvider('modes')]
    public function testDisableRefusesSignInAndEndsOnlyThatUsersSessions(string $driver, bool $batch): void
    {
        $users = $this->open($driver);
        $viewer = $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $other = $users->create('other', 'x', 'editor', 'active', '2026-01-01 00:00:00');
        $mine = $this->putSession('sess-look-aaaaaaaaaaaa', $viewer);
        $theirs = $this->putSession('sess-othr-aaaaaaaaaaaa', $other);
        $neighbour = $this->putSession('sess-near-aaaaaaaaaaaa', (int)((string)$viewer . '1'));
        $disabled = $this->subject(true, $batch);
        $accounts = $this->accounts($disabled);

        $accounts->admit(['id' => $viewer, 'status' => 'active']);
        $disabled->disable($viewer);

        $this->assertTrue($this->flagged($viewer));
        $this->assertSame('active', $this->statusOf($viewer), 'users.status is unchanged');
        try {
            $accounts->admit(['id' => $viewer, 'status' => 'active']);
            $this->fail('disabled sign-in');
        } catch (UserError $e) {
            $this->assertSame([403, HostedDisabledUsers::MESSAGE], [$e->getCode(), $e->getMessage()]);
        }
        $accounts->admit(['id' => $other, 'status' => 'active']);
        $this->assertEqualsCanonicalizing([$theirs, $neighbour], $this->sessionKeys());
        $this->assertNotContains($mine, $this->sessionKeys());

        $disabled->disable($viewer);
        $this->assertTrue($this->flagged($viewer), 'a second disable stays disabled');
    }

    #[DataProvider('modes')]
    public function testSessionMatchIsAnchoredOnTheUidKey(string $driver, bool $batch): void
    {
        $users = $this->open($driver);
        $viewer = $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $tail = 'last_activity|i:1760000000;';
        $first = $this->putSession('sess-frst-aaaaaaaaaaaa', $viewer);
        $later = $this->putSession('sess-latr-aaaaaaaaaaaa', $viewer, 'csrf|s:4:"tok1";uid|i:' . $viewer . ';' . $tail);
        $afterArray = $this->putSession('sess-arry-aaaaaaaaaaaa', $viewer, 'flash|a:0:{}uid|i:' . $viewer . ';' . $tail);
        $prefixed = $this->putSession('sess-pfxd-aaaaaaaaaaaa', $viewer, 'otheruid|i:' . $viewer . ';' . $tail);
        $prefixedLater = $this->putSession('sess-pfx2-aaaaaaaaaaaa', $viewer, 'csrf|s:4:"tok1";otheruid|i:' . $viewer . ';' . $tail);
        $longer = $this->putSession('sess-long-aaaaaaaaaaaa', $viewer, 'uid|i:' . $viewer . '0;' . $tail);

        $this->subject(true, $batch)->disable($viewer);

        $this->assertEqualsCanonicalizing([$prefixed, $prefixedLater, $longer], $this->sessionKeys());
        foreach ([$first, $later, $afterArray] as $gone) {
            $this->assertNotContains($gone, $this->sessionKeys());
        }
    }

    #[DataProvider('modes')]
    public function testCurrentUserDropsADisabledSession(string $driver, bool $batch): void
    {
        $users = $this->open($driver);
        $this->assertNotNull($this->database);
        $viewer = $users->create('looker', 'x', 'viewer', 'active', '2026-01-01 00:00:00');
        $user = $users->find($viewer);
        $this->assertNotNull($user);
        $session = ['uid' => $viewer, 'auth_epoch' => (new SessionEpoch())->of($user)];
        $disabled = $this->subject(true, $batch);
        $current = new CurrentUser(fn (): SettingsService => $this->settings(), $users, static fn (): array => $session, new SessionEpoch(), $disabled);
        $this->assertSame('looker', $current->resolve()['username'] ?? null);
        $disabled->disable($viewer);
        $resolved = $current->resolve();
        $this->assertNotSame($viewer, $resolved['id'] ?? null);
    }

    #[DataProvider('modes')]
    public function testLastActiveAdminIsRefusedAndASecondAdminCanBeDisabled(string $driver, bool $batch): void
    {
        $users = $this->open($driver);
        $boss = $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $other = $users->create('other', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $pending = $users->create('waiting', 'x', 'admin', 'pending', '2026-01-01 00:00:00');
        $sess = $this->putSession('sess-boss-aaaaaaaaaaaa', $boss);
        $disabled = $this->subject(true, $batch);
        $accounts = $this->accounts($disabled);

        $disabled->disable($other);
        $this->assertTrue($this->flagged($other));
        $accounts->admit(['id' => $boss, 'status' => 'active']);

        try {
            $disabled->disable($boss);
            $this->fail('last admin');
        } catch (UserError $e) {
            $this->assertSame([409, 'That change would leave the install without an admin.'], [$e->getCode(), $e->getMessage()]);
        }
        $this->assertFalse($this->flagged($boss));
        $this->assertSame([$sess], $this->sessionKeys());
        $accounts->admit(['id' => $boss, 'status' => 'active']);

        $disabled->disable($pending);
        $this->assertTrue($this->flagged($pending));
        $users->approve($pending);
        try {
            $accounts->admit(['id' => $pending, 'status' => 'active']);
            $this->fail('approved but still disabled');
        } catch (UserError $e) {
            $this->assertSame([403, HostedDisabledUsers::MESSAGE], [$e->getCode(), $e->getMessage()]);
        }
    }

    #[DataProvider('modes')]
    public function testUnknownUserIsNotFoundAndSelfHostedDoesNotConsultTheTable(string $driver, bool $batch): void
    {
        $users = $this->open($driver);
        $boss = $users->create('boss', 'x', 'admin', 'active', '2026-01-01 00:00:00');
        $this->assertNotNull($this->database);
        $this->database->db->query('INSERT INTO hosted_disabled_users (user_id, disabled_at) VALUES (?, ?)', [$boss, '2026-01-01 00:00:00']);
        $off = $this->subject(false, $batch);
        $this->assertFalse($off->isDisabled($boss));
        $this->accounts($off)->admit(['id' => $boss, 'status' => 'active']);

        $on = $this->subject(true, $batch);
        try {
            $on->disable(999);
            $this->fail('missing user');
        } catch (UserError $e) {
            $this->assertSame(404, $e->getCode());
        }
        $this->assertFalse($on->isDisabled(999));
        $on->enable($boss);
        $this->assertFalse($this->flagged($boss));
        $on->enable($boss);
        $this->assertFalse($this->flagged($boss), 'a second enable stays enabled');
    }

    private function settings(?ActorGate $actor = null): SettingsService
    {
        $database = $this->database;
        $this->assertNotNull($database);

        $hosted = HostedLimits::hosted(static fn (): PlanLimits => PlanLimits::fromBridge(['ok' => true, 'plan' => 't', 'limits' => []]) ?? throw new \LogicException('fixture'));

        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore(new LocalStorage($database->dataDir())), new Clock(), $database->config->values(), static fn (string $m): null => null, $actor, null, $hosted);
    }
}
