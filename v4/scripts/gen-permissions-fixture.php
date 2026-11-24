<?php

declare(strict_types=1);

/**
 * The permission matrix fixture (plan §5 S1.3, increment 2b): what v3's `Permissions::ALL`,
 * `Permissions::ROLE_DEFAULTS` and `RoleAdmin::NOT_FOR_GUESTS` say, captured once and committed.
 *
 *     v4/scripts/gen-permissions-fixture.sh            # regenerate test/fixtures/php-permissions.json
 *     v4/scripts/gen-permissions-fixture.sh --check    # hash check only (no PHP needed; what CI runs)
 *
 * Why a fixture at all: `src/auth/permissions.ts` spells the same three lists by hand, and the roles
 * editor shows them to a person and writes them into the roles table. A typo there is a permission
 * granted or withheld silently - `test/auth/permissions.spec.ts` compares the TypeScript against this
 * file, so the typing cannot drift from v3 without a red run.
 *
 * The matrix the *routes* record is asserted elsewhere and is not repeated here:
 * `test/http/routes-parity.spec.ts` compares each route's permission column against `php-routes.json`.
 *
 * Only those two files are read, and neither needs CI4, a database, or a PHP extension: both are
 * plain arrays and strings, so this runs on any PHP 8.4 CLI.
 */

namespace {

    $root = dirname(__DIR__, 2);
    require $root . '/modules/Auth/Permissions.php';
    require $root . '/modules/Auth/RoleAdmin.php';

    $sources = [];
    foreach (['modules/Auth/Permissions.php', 'modules/Auth/RoleAdmin.php'] as $file) {
        $sources[$file] = (string)hash_file('sha256', $root . '/' . $file);
    }

    $all = [];
    foreach (\Apotheke\Auth\Permissions::ALL as $key => [$label, $hint]) {
        $all[] = [$key, $label, $hint];
    }

    $defaults = [];
    foreach (\Apotheke\Auth\Permissions::ROLE_DEFAULTS as $key => [$name, $perms]) {
        $defaults[] = [$key, $name, array_values($perms)];
    }

    echo json_encode([
        'sources' => $sources,
        'all' => $all,
        'roleDefaults' => $defaults,
        'notForGuests' => \Apotheke\Auth\RoleAdmin::NOT_FOR_GUESTS,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
}
