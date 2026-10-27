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
use Apotheke\Core\Http\LegacyPathRouter;
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
        'locations' => 'edit',
        'locations/1/rename' => 'edit',
        'locations/1/default' => 'edit',
        'locations/1/delete' => 'edit',
        'locations/1/parent' => 'edit',
        'locations/1' => 'edit',
        'categories' => 'edit',
        'categories/1/rename' => 'edit',
        'categories/1/default' => 'edit',
        'categories/1/delete' => 'edit',
        'categories/1/parent' => 'edit',
        'categories/1' => 'edit',
        'filters' => 'edit',
        'filters/1/rename' => 'edit',
        'filters/1/delete' => 'edit',
        'item/1/fav' => 'edit',
        'save' => 'create',
        'item/1/delete' => 'delete',
        'undo' => 'edit',
        'item/1/photos' => 'edit',
        'item/1/photos/order' => 'edit',
        'item/1/photos/1/cover' => 'edit',
        'item/1/photos/1/left' => 'edit',
        'item/1/photos/1/right' => 'edit',
        'item/1/photos/1/delete' => 'edit',
        'item/1/photos/1/edit' => 'edit',
        'settings/security' => 'users',
        'settings/security/reset' => 'users',
        'settings/password' => 'users',
        'settings/users' => 'users',
        'settings/users/1' => 'users',
        'settings/users/1/role' => 'users',
        'settings/users/1/password' => 'users',
        'settings/users/1/rename' => 'users',
        'settings/users/1/delete' => 'users',
        'settings/users/1/approve' => 'users',
        'settings/roles' => 'users',
        'settings/roles/desk' => 'users',
        'settings/roles/desk/delete' => 'users',
        'item/1/attachments' => 'edit',
        'item/1/attachments/1/delete' => 'edit',
        'item/1/qty' => 'edit',
        'item/1/duplicate' => 'create',
        'item/1/merge' => 'delete',
        'bulk' => 'bulk',
        'item/1/lend' => 'lend',
        'item/1/return' => 'lend',
        'stocktake' => 'stocktake', 'stocktake/1' => 'stocktake', 'stocktake/1/cancel' => 'stocktake', 'stocktake/1/check' => 'stocktake',
        'stocktake/1/finish' => 'stocktake', 'stocktake/1/report' => 'stocktake',
        'import' => 'import', 'import/1' => 'import', 'import/1/preview' => 'import', 'import/1/commit' => 'import',
        'backup/restore' => 'backup', 'backup/restore/confirm' => 'backup',
    ];

    /** State-changing routes for your own account: signed in with an account (`self`), whatever the role. */
    private const SELF_POST_ROUTES = ['account' => 'self', 'account/signout' => 'self'];

    /** POST routes legacy answers before its permission check; they check the CSRF token themselves (Auth). */
    private const NONE_POST_ROUTES = ['login', 'logout', 'register'];

    /** State-changing routes that need only View (a viewer and the guest may use them, as on legacy). */
    private const VIEW_POST_ROUTES = ['theme' => 'view', 'recent/clear' => 'view'];

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
        $this->assertSame(['except' => ['login', 'register', 'logout']], $filters->globals['before']['sessioncsrf'] ?? null, 'only the routes that check the token themselves skip the CSRF filter');
        $this->assertNotContains('permission', $filters->globals['before']);
        $this->assertSame(\Apotheke\Auth\Filters\PermissionFilter::class, $filters->aliases['permission']);
    }

    /**
     * Every route that is not a GET records the permission legacy gives it,
     * and nobody signed out has it (the guest only View): a new route cannot
     * be left open.
     */
    public function testEveryNonGetRouteRecordsItsLegacyPermission(): void
    {
        $collection = Services::routes();
        $collection->loadRoutes();
        $rules = new RoutePermission();
        $check = new PermissionCheck(new RoleRepository($this->unreachableDb()));
        $seen = [];
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $verb) {
            foreach (array_keys($collection->getRoutes($verb)) as $route) {
                $need = $collection->getRoutesOptions((string)$route, $verb)['permission'] ?? null;
                foreach (self::sample((string)$route) as $path) {
                    $seen[] = $path;
                    if ($need === Permissions::NONE) {
                        $this->assertContains(ltrim($path, '/'), self::NONE_POST_ROUTES, $verb . ' ' . $path . ': only sign-in, registration and sign-out skip the check');
                        $this->assertTrue($rules->isBeforeCheck($verb, $path), $verb . ' ' . $path);
                        continue;
                    }
                    $this->assertSame($rules->need($verb, $path, []), $need, $verb . ' ' . $path . ': record legacy route_perm() as the `permission` option');
                    $this->assertNotSame(Permissions::PUBLIC, $need, $verb . ' ' . $path);
                    $this->assertSame(PermissionCheck::SIGN_IN, $check->refusal(null, (string)$need), $verb . ' ' . $path);
                    $guest = ['role' => 'guest', 'guest' => true, 'anon' => true];
                    // The anonymous guest may view, so it may pick a theme (POST /theme), as on legacy.
                    $this->assertSame($need === 'view' ? null : PermissionCheck::SIGN_IN, $check->refusal($guest, (string)$need), $verb . ' ' . $path);
                }
            }
        }
        // The sign-in, registration and account pages answer PUT, PATCH and DELETE as well (N-63), and so do the import pages (N-372).
        $this->assertSame(['/account', '/import', '/import/([0-9]+)', '/import/([0-9]+)/(preview|commit)', '/login', '/register'], self::pageVerbPaths($collection));
        $seen = array_values(array_unique($seen));
        sort($seen);
        $expected = array_map(static fn (string $r): string => '/' . $r, [...array_keys(self::POST_ROUTES + self::VIEW_POST_ROUTES + self::SELF_POST_ROUTES), ...self::NONE_POST_ROUTES]);
        sort($expected);
        $this->assertSame($expected, $seen, 'a new state-changing route: add it to POST_ROUTES with its legacy permission');
        foreach (self::POST_ROUTES + self::VIEW_POST_ROUTES + self::SELF_POST_ROUTES as $route => $need) {
            $this->assertSame($need, $rules->need('POST', '/' . $route, []));
        }
    }

    /**
     * The paths with a PUT, PATCH and DELETE route, each with the same
     * permission option as its POST route.
     *
     * @return list<string>
     */
    private static function pageVerbPaths(\CodeIgniter\Router\RouteCollection $collection): array
    {
        $paths = [];
        foreach (array_keys($collection->getRoutes('PUT')) as $route) {
            $route = (string)$route;
            $post = $collection->getRoutesOptions($route, 'POST')['permission'] ?? null;
            foreach (['PUT', 'PATCH', 'DELETE'] as $verb) {
                self::assertSame($post, $collection->getRoutesOptions($route, $verb)['permission'] ?? null, $verb . ' ' . $route);
            }
            $paths[] = '/' . $route;
        }
        foreach (['PATCH', 'DELETE'] as $verb) {
            self::assertSame(array_keys($collection->getRoutes('PUT')), array_keys($collection->getRoutes($verb)), $verb);
        }
        sort($paths);

        return $paths;
    }

    /** Every GET route is answered before legacy's check (`none`) or records legacy's permission. */
    public function testEveryGetRouteRecordsItsLegacyPermission(): void
    {
        $collection = Services::routes();
        $collection->loadRoutes();
        $rules = new RoutePermission();
        $n = 0;
        $paths = 0;
        foreach (['GET', 'HEAD'] as $verb) {
            foreach (array_keys($collection->getRoutes($verb)) as $route) {
                $need = $collection->getRoutesOptions((string)$route, $verb)['permission'] ?? null;
                foreach (self::sample((string)$route) as $path) {
                    $this->assertNotNull($need, $verb . ' ' . $path);
                    $this->assertTrue($need === Permissions::NONE ? $rules->isBeforeCheck('GET', $path) : $need === $rules->need('GET', $path), $verb . ' ' . $path);
                    $paths++;
                }
                $n++;
            }
        }
        $this->assertSame(80, $n, "45 GET and 35 HEAD routes");
        $this->assertSame(94, $paths, 'every alternative of a route is checked: (rename|default|parent) is three paths, (full|thumb) two');
    }

    /**
     * Every path a route pattern stands for: each alternative of a (a|b|c)
     * group, id 1 for a number, logo.png for a brand file, x.jpg for a media name,
     * desk for a role key.
     *
     * @return list<string>
     */
    private static function sample(string $route): array
    {
        $path = str_replace(['([0-9]+)', '([a-z0-9.\\-]+)', '([^/]+)', '([a-z0-9-]+)'], ['1', 'logo.png', 'x.jpg', 'desk'], '/' . ltrim($route, '/'));
        if (preg_match('#\(([a-z]+(?:\|[a-z]+)+)\)#', $path, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [$path];
        }
        $out = [];
        foreach (explode('|', $m[1][0]) as $word) {
            $out = [...$out, ...self::sample(substr_replace($path, $word, $m[0][1], strlen($m[0][0])))];
        }

        return $out;
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
     * #101 N-70: the permission comes from legacy's path (REQUEST_URI's path,
     * decoded once, not trimmed). A variant CI4 would trim into a route is no
     * route there, so it needs what legacy route_perm() gives that path.
     */
    #[DataProvider('postRoutes')]
    public function testVariantsNeedLegacysPermissionForTheirPath(string $route, string $need): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->signInAs($this->account('vera', 'viewer'));
        $rules = new RoutePermission();
        $variants = [$route . '/', '/' . $route, $route . '%20/', $route . '%20', $route . '+', str_replace('/', '%2F', $route), str_replace('/', '//', $route) . '/%20/'];
        foreach ($variants as $variant) {
            $uri = '/' . $variant;
            $legacy = $rules->need('POST', LegacyPathRouter::legacyPath($uri));
            $denied = $this->filter($variant, ['HTTP_ACCEPT' => 'application/json', 'REQUEST_URI' => $uri]);
            $this->assertSame(403, $denied->getStatusCode(), $variant);
            $message = 'Your role (Viewer) does not allow this – it needs the “' . Permissions::ALL[$legacy][0] . '” permission. Ask an admin.';
            $this->assertSame(['ok' => false, 'error' => $message], json_decode((string)$denied->getBody(), true), $variant);
        }
        $this->signInAs($this->account('ann', 'admin'));
        foreach ($variants as $variant) {
            $this->assertNull($this->filterOrNull($variant, ['REQUEST_URI' => '/' . $variant]), $variant);
        }
        // The exact path needs the route's permission; /save adds legacy's id rule.
        $this->signInAs($this->account('vera2', 'viewer'));
        $exact = $this->filter($route, ['HTTP_ACCEPT' => 'application/json', 'REQUEST_URI' => '/' . $route]);
        $this->assertStringContainsString('“' . Permissions::ALL[$need][0] . '”', (string)$exact->getBody());
    }

    /** #101 N-70: /save needs Edit with an id, else Add items (legacy route_perm()). */
    public function testSaveNeedsEditWithAnId(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->db()->table('roles')->insert(['role_key' => 'adder', 'name' => 'Adder', 'perms' => json_encode(['view', 'create']), 'builtin' => 0, 'created_at' => '2026-01-01 00:00:00']);
        $this->signInAs($this->account('al', 'adder'));
        $this->assertNull($this->filterOrNull('save', ['REQUEST_URI' => '/save']));
        $denied = $this->filter('save', ['HTTP_ACCEPT' => 'application/json', 'REQUEST_URI' => '/save'], 'POST', null, ['id' => '7']);
        $this->assertSame('Your role (Adder) does not allow this – it needs the “Edit” permission. Ask an admin.', json_decode((string)$denied->getBody(), true)['error'] ?? null);
    }

    /**
     * #101 N-70: an absolute-form request target (`GET http://host/healthz`)
     * is judged on its path, as legacy does: the health check and branding
     * stay public, a page needs View.
     */
    public function testAnAbsoluteFormTargetIsJudgedOnItsPath(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        foreach (['healthz', 'theme.css', 'favicon.ico', 'brand/logo.png'] as $path) {
            $this->assertNull($this->filterOrNull($path, ['REQUEST_URI' => 'http://apotheke.test/' . $path], 'GET'), $path);
        }
        $refused = $this->filter('stats', ['REQUEST_URI' => 'http://apotheke.test/stats'], 'GET');
        $this->assertSame([303, '/login'], [$refused->getStatusCode(), $refused->getHeaderLine('Location')]);
        $this->signInAs($this->account('vera', 'viewer'));
        $this->assertNull($this->filterOrNull('healthz', ['REQUEST_URI' => 'http://apotheke.test/healthz'], 'GET'));
        $this->assertNull($this->filterOrNull('stats', ['REQUEST_URI' => 'http://apotheke.test/stats'], 'GET'));
    }

    /** A PUBLIC_URL with a path: the rules apply to the path relative to the base URL. */
    public function testABaseUrlWithAPathKeepsTheRules(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->signInAs($this->account('eda', 'editor'));
        foreach (array_keys(self::POST_ROUTES) as $route) {
            if (self::POST_ROUTES[$route] !== 'settings') {
                continue;
            }
            $denied = $this->filter($route, ['HTTP_X_REQUESTED_WITH' => 'fetch'], 'POST', 'https://example.test/apotheke/');
            $this->assertSame(403, $denied->getStatusCode(), $route);
            $this->assertStringContainsString('“Settings” permission', (string)$denied->getBody(), $route);
        }
        $this->assertNull($this->filterOrNull('autofill/suggest', [], 'POST', 'https://example.test/apotheke/'));
    }

    /** A route without a recorded permission is refused to everyone (fail closed), whatever route_perm() says. */
    public function testARouteWithoutAPermissionIsRefused(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $routes = Services::routes();
        $routes->loadRoutes();
        $routes->post('test-unmapped', static fn (): string => 'ran');
        $routes->get('test-unmapped-page', static fn (): string => 'ran');
        $this->assertSame('edit', (new RoutePermission())->need('POST', '/test-unmapped'), 'legacy would let an editor through');

        $refused = $this->filter('test-unmapped', ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame(403, $refused->getStatusCode(), 'sign-in off: refused to the anonymous admin too');
        $this->assertSame('{"ok":false,"error":"Your role (Admin) does not allow this. Ask an admin."}', (string)$refused->getBody());
        $this->assertSame(403, $this->filter('test-unmapped-page', [], 'GET')->getStatusCode());

        $ada = $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->assertSame(303, $this->filter('test-unmapped')->getStatusCode(), 'signed out: sign in first');
        foreach (['eda' => 'editor', 'ann' => 'admin'] as $name => $role) {
            $this->signInAs($this->account($name, $role));
            $this->assertSame(403, $this->filter('test-unmapped')->getStatusCode(), $role);
        }
        $this->signInAs($ada);
        $this->post('test-unmapped')->assertStatus(403);
    }

    /** HEAD is a GET; the path is decoded; the role name is escaped on the HTML page. */
    public function testHeadDecodingAndEscaping(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        // Legacy answers GET /backup/restore/check before its check; HEAD is a GET there.
        $this->assertNull($this->filterOrNull('backup/restore/check', [], 'HEAD'));
        $this->assertSame(303, $this->filter('backup/restore/check', [], 'POST')->getStatusCode());

        $this->db()->table('roles')->insert(['role_key' => 'desk', 'name' => '<b>Desk</b> & "Co"', 'perms' => json_encode(['view', 'edit']), 'builtin' => 0, 'created_at' => '2026-01-01 00:00:00']);
        $this->signInAs($this->account('dee', 'desk'));
        // Unrouted: item/3%2Fdelete is /item/3/delete once decoded, which needs Delete, not Edit.
        $decoded = $this->filter('item/3%2Fdelete', ['HTTP_ACCEPT' => 'application/json']);
        $this->assertSame('Your role (<b>Desk</b> & "Co") does not allow this – it needs the “Delete” permission. Ask an admin.', json_decode((string)$decoded->getBody(), true)['error'] ?? null);
        // The request path is decoded once; the router decodes again (a space here) and trims it.
        $space = $this->filter('item/3/delete%2520', ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStringContainsString('“Delete” permission', (string)$space->getBody());
        $page = (string)$this->filter('item/3%2Fdelete')->getBody();
        $this->assertStringContainsString('Your role (&lt;b&gt;Desk&lt;/b&gt; &amp; &quot;Co&quot;) does not allow this', $page);
        $this->assertStringNotContainsString('<b>Desk', $page);
    }

    /**
     * #91 N-15: a `none` route (health, branding) skips the check only for the
     * exact path legacy answers first; a variant the router trims into it
     * needs what legacy route_perm() gives that path (View for a GET).
     */
    public function testOnlyTheExactPathOfAPublicRouteSkipsTheCheck(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $rules = new RoutePermission();
        foreach (['theme.css', 'labels.css', 'manifest.webmanifest', 'favicon.ico', 'brand/logo.png', 'healthz', 'health', 'theme%2Ecss'] as $exact) {
            $this->assertNull($this->filterOrNull($exact, [], 'GET'), $exact);
            $this->assertNull($this->filterOrNull($exact, [], 'HEAD'), $exact);
        }
        // #93 N-16: theme%252Ecss is decoded once (theme%2Ecss), as legacy decodes it; only the router decodes twice.
        foreach (['theme.css/', 'brand/logo.png/', 'healthz/', 'health/', 'manifest.webmanifest/', 'labels.css%20/', 'theme%252Ecss', 'favicon%252Eico'] as $variant) {
            $this->assertSame('view', $rules->need('GET', $rules->exact($variant)), $variant . ': legacy needs View');
            $refused = $this->filter($variant, [], 'GET');
            $this->assertSame([303, '/login'], [$refused->getStatusCode(), $refused->getHeaderLine('Location')], $variant);
        }
        // CI4's request URI already drops trailing spaces and a doubled leading slash, before
        // any filter: the path is the exact one, as CI4 dispatches it (a documented deviation).
        $this->assertNull($this->filterOrNull('favicon.ico%20', [], 'GET'));
        $this->assertNull($this->filterOrNull('//theme.css', [], 'GET'));
        $this->signInAs($this->account('vera', 'viewer'));
        foreach (['theme.css/', 'brand/logo.png/', 'healthz/', 'theme%252Ecss'] as $variant) {
            $this->assertNull($this->filterOrNull($variant, [], 'GET'), $variant . ': a viewer may');
        }
        $this->db()->table('roles')->insert(['role_key' => 'blind', 'name' => 'Blind', 'perms' => '[]', 'builtin' => 0, 'created_at' => '2026-01-01 00:00:00']);
        $this->signInAs($this->account('bo', 'blind'));
        $this->assertNull($this->filterOrNull('theme.css', [], 'GET'), 'the exact path needs nothing');
        $this->assertSame(403, $this->filter('theme.css/', [], 'GET')->getStatusCode(), 'a role without View');
    }

    /**
     * #91 N-15: a redirect route does not throw in the filter. It reaches no
     * controller, so it gets legacy route_perm() on the routed path, and the
     * redirect is sent only once that is met.
     */
    public function testARedirectRouteIsChecked(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $routes = Services::routes();
        $routes->loadRoutes();
        $routes->addRedirect('test-old-settings', 'healthz');
        $this->assertNull($this->filterOrNull('test-old-settings', [], 'GET'), 'sign-in off');
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->assertSame(303, $this->filter('test-old-settings', [], 'GET')->getStatusCode(), 'signed out');
        $this->signInAs($this->account('vera', 'viewer'));
        $this->assertNull($this->filterOrNull('test-old-settings', [], 'GET'));
        $this->assertNull($this->filterOrNull('test-old-settings/', [], 'GET'));
    }

    /** The permission is decided on the method after CI4's `_method` override. */
    public function testTheOverriddenMethodDecides(): void
    {
        $this->database = TestDatabase::create('sqlite');
        $this->wire();
        $routes = Services::routes();
        $routes->loadRoutes();
        $routes->put('test-put', static fn (): string => 'ran', ['permission' => 'settings']);
        $this->account('ada', 'admin');
        $this->settings()->store(['auth_enabled' => true]);
        $this->signInAs($this->account('eda', 'editor'));
        // As a plain POST no route matches and route_perm() says edit (an editor may);
        // overridden to PUT, the PUT route matches and needs settings.
        $this->assertNull($this->filterOrNull('test-put'));
        $this->post('test-put', ['_method' => 'PUT'])->assertStatus(403);
    }

    /**
     * The filter alone, for a request to $route (relative to the base URL,
     * as sent, still encoded) with these $_SERVER entries. The feature
     * requests carry no $_SERVER headers.
     *
     * @param array<string,string> $server
     * @param array<string,string> $post
     */
    private function filterOrNull(string $route, array $server = [], string $method = 'POST', ?string $baseURL = null, array $post = []): ?\CodeIgniter\HTTP\ResponseInterface
    {
        $config = clone config(\Config\App::class);
        if ($baseURL !== null) {
            $config->baseURL = $baseURL;
        }
        $request = new \CodeIgniter\HTTP\IncomingRequest($config, new \CodeIgniter\HTTP\SiteURI($config, $route), null, new \CodeIgniter\HTTP\UserAgent());
        $request = $request->withMethod($method);
        $saved = $_SERVER;
        try {
            $request->setGlobal('server', $server + $_SERVER);
            $request->setGlobal('post', $post);

            return (new \Apotheke\Auth\Filters\PermissionFilter())->before($request);
        } finally {
            $request->setGlobal('server', $saved);
            $_SERVER = $saved;
        }
    }

    /**
     * @param array<string,string> $server
     * @param array<string,string> $post
     */
    private function filter(string $route, array $server = [], string $method = 'POST', ?string $baseURL = null, array $post = []): \CodeIgniter\HTTP\ResponseInterface
    {
        $response = $this->filterOrNull($route, $server, $method, $baseURL, $post);
        $this->assertNotNull($response, $method . ' ' . $route);

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
