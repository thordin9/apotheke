<?php

declare(strict_types=1);

/**
 * The Users & sign-in page fixture (plan §5 S1.3): what v3's `SecurityView::page()` prints,
 * captured once and committed.
 *
 *     v4/scripts/gen-security-fixture.sh            # regenerate test/fixtures/php-security.json
 *     v4/scripts/gen-security-fixture.sh --check    # hash check only (no PHP needed; what CI runs)
 *
 * Why this is not part of `php-html.json`: that fixture boots the whole v3 app through `php -S`,
 * which needs PHP with `intl` *and* `pdo_sqlite`. This page is one pure function of
 * `SecurityView` plus the settings schema, so it is rendered here with a `Page` stub, the way
 * `gen-claim-fixture.php` renders `/claim`. The shell is not part of the captured body: `page.ts` is
 * pinned by the login golden test, and this fixture is about the section under it.
 *
 * The state is pinned so the fixture cannot drift on a clock:
 *
 *   * the token is `TOKEN`, not a minted one;
 *   * every timestamp is a fixed UTC string, formatted in a fixed zone (`Europe/Athens`) with a
 *     fixed `time_format`, so `last_login_at` renders the same on every run;
 *   * the five roles and the three accounts (one pending, one never signed in) are the seed below,
 *     not whatever a database had.
 *
 * `Page::e()` is v3's own escaping, so a name with quotes and angle brackets exercises the port's
 * `e()` as well as its whitespace.
 */

namespace Apotheke\Layout {

    /** The five `Page` methods `SecurityView::page()` calls, with markers where the shell would print. */
    final class Page
    {
        public function start(string $title, string $bodyClass = ''): string
        {
            return '';
        }

        public function simpleHeader(string $title, string $back): string
        {
            return '';
        }

        public function csrf(): string
        {
            return 'TOKEN';
        }

        public function end(): string
        {
            return '';
        }

        public static function e(?string $s): string
        {
            return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

namespace {

    use Apotheke\Auth\Views\SecurityView;
    use Apotheke\Layout\Page;
    use Apotheke\Settings\SettingsSchema;

    $root = dirname(__DIR__, 2);
    require $root . '/modules/Auth/Permissions.php';
    require $root . '/modules/Auth/RoleAdmin.php';
    require $root . '/modules/Layout/Icons.php';
    require $root . '/modules/Settings/SettingsSchema.php';
    require $root . '/modules/Auth/Views/SecurityView.php';

    /** The `<main>` fragment: the shell around it is `page.ts`'s job. */
    $body = static function (string $html): string {
        $start = strpos($html, '<main');
        $end = strpos($html, '</main>');

        return substr($html, $start, $end + 7 - $start);
    };

    $page = new Page();

    /** The roles, in the repository's order: built-in first, then a custom one. */
    $roles = [
        'admin' => ['key' => 'admin', 'name' => 'Admin', 'builtin' => true, 'users' => 1, 'perms' => [
            'view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake',
            'labels', 'settings', 'users', 'backup', 'restore',
        ]],
        'editor' => ['key' => 'editor', 'name' => 'Editor', 'builtin' => true, 'users' => 1, 'perms' => [
            'view', 'create', 'edit', 'delete', 'move', 'bulk', 'lend', 'import', 'export', 'stocktake',
            'labels', 'backup', 'restore',
        ]],
        'viewer' => ['key' => 'viewer', 'name' => 'Viewer', 'builtin' => true, 'users' => 1, 'perms' => [
            'view', 'export', 'labels', 'backup',
        ]],
        'guest' => ['key' => 'guest', 'name' => 'Guest', 'builtin' => true, 'users' => 0, 'perms' => ['view']],
        'manager' => ['key' => 'manager', 'name' => 'Manager', 'builtin' => false, 'users' => 0, 'perms' => ['view', 'users']],
    ];

    /** The accounts: one signed in now, one pending, one that never signed in, one that needs escaping. */
    $users = [
        ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'status' => 'active', 'created_at' => '2025-12-31 22:00:00', 'last_login_at' => '2026-01-02 03:04:05'],
        ['id' => 2, 'username' => 'bea', 'role' => 'editor', 'status' => 'active', 'created_at' => '2026-01-01 09:30:00', 'last_login_at' => null],
        ['id' => 3, 'username' => 'cy', 'role' => 'viewer', 'status' => 'pending', 'created_at' => '2026-01-02 10:00:00', 'last_login_at' => null],
        ['id' => 4, 'username' => 'own<er> "q" \'z\'', 'role' => 'viewer', 'status' => 'active', 'created_at' => '2026-01-02 11:00:00', 'last_login_at' => '2026-01-03 00:30:00'],
    ];

    /** `SecurityController::securityPage()`'s fields, from the real schema rather than typed here. */
    $schema = new SettingsSchema();
    $definitions = $schema->definitions();
    $fields = [];
    foreach ($schema->sectionKeys('security') as $key) {
        [, $label, $type, , $help, $options] = $definitions[$key];
        $fields[] = [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'default' => $schema->default($key, []),
            'help' => $help,
            'options' => $options,
            'value' => $schema->default($key, []),
        ];
    }

    $v = [
        'on' => true,
        'guestBrowsing' => false,
        'me' => ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'guest' => false],
        'admins' => 1,
        'users' => $users,
        'roles' => $roles,
        'fields' => $fields,
        'usernameMax' => 40,
        'passwordMin' => 8,
        'timeFormat' => 'H:i',
        'timezone' => 'Europe/Athens',
    ];

    $sources = [
        'modules/Auth/Views/SecurityView.php',
        'modules/Auth/Permissions.php',
        'modules/Auth/RoleAdmin.php',
        'modules/Layout/Icons.php',
        'modules/Settings/SettingsSchema.php',
    ];

    $fixture = [
        'generatedBy' => 'v4/scripts/gen-security-fixture.sh',
        'php' => PHP_VERSION,
        'note' => 'Raw output of SecurityView::page() with a Page stub, on a pinned state: five roles, four accounts (one pending, one never signed in, one that needs escaping), fixed UTC timestamps in Europe/Athens. Nothing normalised; the shell is not captured.',
        'sources' => [],
        'pages' => [
            'security' => [
                'csrf' => 'TOKEN',
                'body' => $body(SecurityView::page($page, $v, [], null)),
                'usersErrorBody' => $body(SecurityView::page($page, $v, [
                    'users' => 'The user name “admin” is taken.',
                ], null)),
            ],
        ],
    ];

    foreach ($sources as $file) {
        $fixture['sources'][$file] = hash_file('sha256', $root . '/' . $file);
    }

    echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
