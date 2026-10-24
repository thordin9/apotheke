<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\CurrentUser;
use Apotheke\Auth\PermissionCheck;
use Apotheke\Auth\Permissions;
use Apotheke\Auth\RoleRepository;
use Apotheke\Auth\RoutePermission;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\UserRepository;
use Apotheke\Core\Libraries\Clock;
use Apotheke\Core\Security\CommandLine;
use Apotheke\Settings\LocalBrandStore;
use Apotheke\Settings\SettingsRepository;
use Apotheke\Settings\SettingsSchema;
use Apotheke\Settings\SettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Filters;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestDatabase;

/**
 * PLAN PR 7: CI4 applies legacy's per-route permission check (route_perm(),
 * user_can()) to every request with the required Permission filter. The
 * golden test against legacy itself is tests/Unit/Ci4PermissionParityTest.php.
 */
final class PermissionFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    /** The CI4 routes that change state, with the permission legacy needs for each. */
    private const POST_ROUTES = [
        'autofill/suggest' => 'create',
        'settings/autofill/token' => 'settings',
        'settings/autofill/test' => 'settings',
        'settings/autofill/test-photo' => 'settings',
    ];

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

    /** @return iterable<string,array{0:string,1:string}> */
    public static function postRoutes(): iterable
    {
        foreach (self::POST_ROUTES as $route => $need) {
            yield $route => [$route, $need];
        }
    }

    protected function tearDown(): void
    {
        $this->database?->drop();
        $this->database = null;
        $this->requestSession = [];
        Services::reset(true);
        parent::tearDown();
    }

    public function testThePermissionFilterRunsOnEveryRequestBeforeCsrf(): void
    {
        $filters = config(Filters::class);
        $required = $filters->required['before'];
        $this->assertContains('permission', $required, 'a required filter runs for every request, routed or not');
        $at = array_search('permission', $required, true);
        $this->assertGreaterThan(array_search('session', $required, true), $at);
        $this->assertGreaterThan(array_search('sameorigin', $required, true), $at);
        // Required filters run before the global ones, so before CSRF, in legacy's order.
        $this->assertContains('sessioncsrf', $filters->globals['before']);
        $this->assertNotContains('permission', $filters->globals['before']);
        $this->assertSame(\Apotheke\Auth\Filters\PermissionFilter::class, $filters->aliases['permission']);
    }

    /** Every route that is not a GET needs a permission the anonymous visitor lacks: a new route cannot be left open. */
    public function testEveryNonGetRouteNeedsAPermission(): void
    {
        $collection = Services::routes();
        $collection->loadRoutes();
        $rules = new RoutePermission();
        $check = new PermissionCheck(new RoleRepository($this->unreachableDb()));
        $seen = [];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($collection->getRoutes($verb)) as $route) {
                $path = '/' . ltrim((string)$route, '/');
                $seen[] = $path;
                $this->assertFalse($rules->isBeforeCheck($verb, $path), $verb . ' ' . $path);
                $need = $rules->need($verb, $path, []);
                $this->assertNotSame(Permissions::PUBLIC, $need, $verb . ' ' . $path);
                $this->assertSame(PermissionCheck::SIGN_IN, $check->refusal(null, $need), $verb . ' ' . $path);
                $guest = ['role' => 'guest', 'guest' => true, 'anon' => true];
                $this->assertSame(PermissionCheck::SIGN_IN, $check->refusal($guest, $need), $verb . ' ' . $path);
            }
        }
        sort($seen);
        $expected = array_map(static fn (string $r): string => '/' . $r, array_keys(self::POST_ROUTES));
        sort($expected);
        $this->assertSame($expected, $seen, 'a new state-changing route: add it to POST_ROUTES with its legacy permission');
        foreach (self::POST_ROUTES as $route => $need) {
            $this->assertSame($need, $rules->need('POST', '/' . $route, []));
        }
    }

    /** Every GET route is either answered before legacy's check or needs "view". */
    public function testEveryGetRouteIsPublicOrNeedsView(): void
    {
        $collection = Services::routes();
        $collection->loadRoutes();
        $rules = new RoutePermission();
        foreach (array_keys($collection->getRoutes('GET')) as $route) {
            $path = '/' . ltrim((string)$route, '/');
            if (str_contains($path, '(')) {
                $path = '/brand/logo.png';
            }
            $this->assertTrue($rules->isBeforeCheck('GET', $path) || $rules->need('GET', $path) === 'view', $path);
        }
    }

    public function testRouteNeedsMatchLegacy(): void
    {
        $rules = new RoutePermission();
        $cases = [
            ['GET', '/login', [], 'public'], ['POST', '/register', [], 'public'],
            ['GET', '/logout', [], 'self'], ['POST', '/account', [], 'self'],
            ['POST', '/settings/users/3/role', [], 'users'], ['GET', '/settings/security', [], 'users'],
            ['POST', '/settings/autofill', [], 'settings'], ['GET', '/settings.json', [], 'settings'],
            ['GET', '/backup.zip', [], 'backup'], ['POST', '/backup/restore', [], 'backup'],
            ['POST', '/import/csv', [], 'import'], ['GET', '/export.csv', [], 'export'],
            ['GET', '/stocktake/4', [], 'view'], ['POST', '/stocktake/4', [], 'stocktake'],
            ['GET', '/labels', [], 'labels'], ['GET', '/item/9/label', [], 'labels'],
            ['GET', '/new', [], 'create'], ['POST', '/autofill/suggest', [], 'create'],
            ['POST', '/item/9/duplicate', [], 'create'], ['POST', '/item/9/lend', [], 'lend'],
            ['POST', '/lent', [], 'lend'], ['GET', '/lent', [], 'view'], ['POST', '/bulk', [], 'bulk'],
            ['POST', '/item/9/delete', [], 'delete'], ['POST', '/save', ['id' => '7'], 'edit'],
            ['POST', '/save', ['id' => '0'], 'create'], ['POST', '/save', [], 'create'],
            ['POST', '/save', ['id' => ['x']], 'edit'], ['POST', '/save', ['id' => []], 'create'],
            ['POST', '/item/9/merge', [], 'delete'], ['GET', '/item/9/photos/2/edit', [], 'edit'],
            ['GET', '/anything', [], 'view'], ['PUT', '/anything', [], 'view'],
            ['POST', '/theme', [], 'view'], ['POST', '/recent/clear', [], 'view'], ['POST', '/anything', [], 'edit'],
        ];
        foreach ($cases as [$method, $path, $post, $need]) {
            $this->assertSame($need, $rules->need($method, $path, $post), $method . ' ' . $path);
        }
        $this->assertTrue($rules->isBeforeCheck('POST', '/healthz'));
        $this->assertTrue($rules->isBeforeCheck('GET', '/brand/logo.png'));
        $this->assertFalse($rules->isBeforeCheck('POST', '/theme.css'), 'legacy serves branding to GET only');
        $this->assertTrue($rules->isBeforeCheck('POST', '/logout'));
        $this->assertFalse($rules->isBeforeCheck('GET', '/logout'));
    }

    #[DataProvider('drivers')]
    public function testRolesComeFromTheTable(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $roles = new RoleRepository($this->db());
        $this->assertSame(['admin', 'editor', 'viewer', 'guest'], array_keys($roles->all()));
        $this->assertSame(['view', 'export', 'labels'], $roles->all()['viewer']['perms']);
        $this->db()->table('roles')->insert(['role_key' => 'desk', 'name' => 'Help desk', 'perms' => json_encode(['bogus', 'settings', 'view', 'view']), 'builtin' => 0, 'created_at' => '2026-01-01 00:00:00']);
        $this->db()->table('roles')->where('role_key', 'admin')->update(['perms' => '[]']);
        $this->assertSame(['view', 'settings'], $roles->all()['desk']['perms'], 'known permissions, legacy order');
        $this->assertSame(array_keys(Permissions::ALL), $roles->all()['admin']['perms']);
        $this->assertTrue($roles->has('admin', 'backup'));
        $this->assertTrue($roles->has('desk', 'settings'));
        $this->assertFalse($roles->has('desk', 'create'));
        $this->assertFalse($roles->has('nosuch', 'view'), 'an unknown role grants nothing');
        $this->assertSame('Help desk', $roles->name('desk'));
        $this->assertSame('Nosuch', $roles->name('nosuch'));
        $this->db()->table('roles')->where('role_key', 'viewer')->update(['perms' => json_encode(['view', 'settings'])]);
        $this->assertTrue($roles->has('viewer', 'settings'), 'no cache: a changed role counts on the next read');
    }

    public function testRolesBeforeTheTableAreTheBuiltIns(): void
    {
        $this->database = TestDatabase::create('sqlite', false);
        $roles = new RoleRepository($this->db());
        foreach (Permissions::ROLE_DEFAULTS as $key => [$name, $perms]) {
            $this->assertSame(['name' => $name, 'perms' => $perms], $roles->all()[$key]);
        }
    }

    #[DataProvider('drivers')]
    public function testWhoMayDoWhat(string $driver): void
    {
        $this->database = TestDatabase::create($driver);
        $check = new PermissionCheck(new RoleRepository($this->db()));
        $user = $this->currentUser();
        $this->db()->table('roles')->insert(['role_key' => 'desk', 'name' => 'Help desk', 'perms' => json_encode(['view', 'settings']), 'builtin' => 0, 'created_at' => '2026-01-01 00:00:00']);

        // Sign-in off: the anonymous admin may do everything but the own-account pages.
        foreach (self::POST_ROUTES as $need) {
            $this->assertNull($check->refusal($user->resolve(), $need));
        }
        $this->assertSame('Your role (Admin) does not allow this. Ask an admin.', $check->refusal($user->resolve(), Permissions::SELF));

        $this->account('owner', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->assertNull($user->resolve());
        $this->assertSame(PermissionCheck::SIGN_IN, $check->refusal($user->resolve(), 'create'));
        $this->assertNull($check->refusal($user->resolve(), Permissions::PUBLIC));
        $this->settings()->store(['auth_guest_disabled' => false]);
        $this->assertNull($check->refusal($user->resolve(), 'view'), 'the guest may look');
        $this->assertSame(PermissionCheck::SIGN_IN, $check->refusal($user->resolve(), 'settings'), 'anonymous is never treated as signed in');
        $this->assertSame(PermissionCheck::SIGN_IN, $check->refusal($user->resolve(), Permissions::SELF));

        $expect = [
            'viewer' => ['create' => 'Your role (Viewer) does not allow this – it needs the “Add items” permission. Ask an admin.',
                'settings' => 'Your role (Viewer) does not allow this – it needs the “Settings” permission. Ask an admin.'],
            'editor' => ['create' => null,
                'settings' => 'Your role (Editor) does not allow this – it needs the “Settings” permission. Ask an admin.'],
            'desk' => ['create' => 'Your role (Help desk) does not allow this – it needs the “Add items” permission. Ask an admin.',
                'settings' => null],
            'admin' => ['create' => null, 'settings' => null],
            'gone' => ['create' => 'Your role (Gone) does not allow this – it needs the “Add items” permission. Ask an admin.',
                'settings' => 'Your role (Gone) does not allow this – it needs the “Settings” permission. Ask an admin.'],
        ];
        foreach ($expect as $role => $byNeed) {
            $this->signInAs($this->account('u-' . $role, $role));
            foreach ($byNeed as $need => $refusal) {
                $this->assertSame($refusal, $check->refusal($user->resolve(), $need), $role . ' ' . $need);
            }
            $this->assertNull($check->refusal($user->resolve(), Permissions::SELF), $role . ' own account');
        }
        // A stale epoch falls back to the guest: sign in first, not 403.
        $this->requestSession['auth_epoch'] = 'stale';
        $this->assertSame(PermissionCheck::SIGN_IN, $check->refusal($user->resolve(), 'create'));
    }

    #[DataProvider('postRoutes')]
    public function testTheFilterAnswersLikeLegacy(string $route, string $need): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();

        // Sign-in off: the filter lets it through; CSRF (419) is next, as in legacy.
        $this->post($route)->assertStatus(419);

        $ada = $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $signedOut = $this->post($route);
        $signedOut->assertStatus(303);
        $this->assertSame('/login', $signedOut->response()->getHeaderLine('Location'));
        $json = $this->filter($route, ['HTTP_X_REQUESTED_WITH' => 'fetch']);
        $this->assertSame(401, $json->getStatusCode());
        $this->assertSame('{"ok":false,"error":"Not signed in","redirect":"/login"}', $json->getBody());
        $this->assertSame('application/json; charset=utf-8', $json->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $json->getHeaderLine('Cache-Control'));
        $this->assertNotSame('', $json->getHeaderLine('Content-Security-Policy'), 'security headers on a refusal');

        $this->signInAs($this->account('vera', 'viewer'));
        $message = 'Your role (Viewer) does not allow this – it needs the “' . Permissions::ALL[$need][0] . '” permission. Ask an admin.';
        $denied = $this->filter($route, ['HTTP_ACCEPT' => 'text/html, application/json']);
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(['ok' => false, 'error' => $message], json_decode((string)$denied->getBody(), true));
        $page = $this->post($route);
        $page->assertStatus(403);
        $this->assertStringContainsString('<p>' . htmlspecialchars($message) . '</p>', (string)$page->response()->getBody());
        $this->assertStringStartsWith('text/html', $page->response()->getHeaderLine('Content-Type'));

        $this->signInAs($ada);
        $this->post($route)->assertStatus(419);
    }

    public function testAnUnknownPathIsCheckedToo(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->assertSame(303, $this->post('no-such-route')->response()->getStatusCode());
        $this->assertSame(303, $this->get('no-such-page')->response()->getStatusCode());
        $this->assertSame(401, $this->get('media/x.jpg')->response()->getStatusCode());
        $this->assertSame('', (string)$this->get('media/x.jpg')->response()->getBody());
        $this->get('healthz')->assertStatus(200);
    }

    /**
     * The filter alone, for a request with these $_SERVER entries (the
     * feature requests carry no $_SERVER headers).
     *
     * @param array<string,string> $server
     */
    private function filter(string $route, array $server): \CodeIgniter\HTTP\ResponseInterface
    {
        $config = config(\Config\App::class);
        $request = new \CodeIgniter\HTTP\IncomingRequest($config, new \CodeIgniter\HTTP\SiteURI($config, $route), null, new \CodeIgniter\HTTP\UserAgent());
        $request = $request->withMethod('POST');
        $saved = $_SERVER;
        try {
            $request->setGlobal('server', $server + $_SERVER);
            $response = (new \Apotheke\Auth\Filters\PermissionFilter())->before($request);
        } finally {
            $request->setGlobal('server', $saved);
            $_SERVER = $saved;
        }
        $this->assertNotNull($response);

        return $response;
    }

    private function wire(): void
    {
        Services::injectMock('settings', $this->settings());
        Services::injectMock('currentUser', $this->currentUser());
        Services::injectMock('roles', new RoleRepository($this->db()));
    }

    private function currentUser(): CurrentUser
    {
        return new CurrentUser(fn (): SettingsService => $this->settings(), new UserRepository($this->db()), fn (): array => $this->requestSession);
    }

    private function settings(): SettingsService
    {
        $database = $this->database;
        $this->assertNotNull($database);

        return new SettingsService(new SettingsRepository($database->db), new SettingsSchema(), new LocalBrandStore($database->dataDir() . '/brand'), new Clock(), $database->config->values(), static fn (string $m): null => null, new CommandLine());
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function db(): BaseConnection
    {
        $this->assertNotNull($this->database);

        return $this->database->db;
    }

    /** @return BaseConnection<object|resource, object|resource> a connection that is never queried */
    private function unreachableDb(): BaseConnection
    {
        $this->database ??= TestDatabase::create('sqlite', false);

        return $this->database->db;
    }

    private function account(string $name, string $role): int
    {
        $this->db()->table('users')->insert(['username' => $name, 'username_key' => $name, 'password_hash' => password_hash('long-enough', PASSWORD_DEFAULT), 'role' => $role, 'status' => 'active', 'created_at' => '2026-01-01 00:00:00']);
        $row = $this->db()->table('users')->select('id')->where('username_key', $name)->get()->getRowArray();

        return (int)($row['id'] ?? 0);
    }

    private function signInAs(int $id): void
    {
        $user = (new UserRepository($this->db()))->find($id);
        $this->assertNotNull($user);
        $this->requestSession = ['uid' => $id, 'auth_epoch' => (new SessionEpoch())->of($user)];
    }
}
