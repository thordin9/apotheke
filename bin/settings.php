#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – Control Panel settings from the command line (recovery, scripting).
 *   php bin/settings.php list                 all settings with their values
 *   php bin/settings.php get KEY
 *   php bin/settings.php set KEY VALUE        validated like the web form
 *   php bin/settings.php reset SECTION        back to the config.php/env defaults
 *   php bin/settings.php users                list the accounts (name, role, last sign-in)
 *   php bin/settings.php user add NAME ROLE [PASSWORD]   ROLE: admin, editor, viewer or a custom role key (asks for the password if not given)
 *   php bin/settings.php user approve NAME    approve a self-registered account
 *   php bin/settings.php user password NAME [PASSWORD]   set a new password (signs that user out everywhere)
 *   php bin/settings.php user signout NAME    end every session of that account
 *   php bin/settings.php user role NAME ROLE
 *   php bin/settings.php user rename NAME NEWNAME
 *   php bin/settings.php user delete NAME
 *   php bin/settings.php roles                list the roles and their permissions
 *   php bin/settings.php roles reset          restore the built-in roles' permissions (custom roles stay)
 *   php bin/settings.php auth on|off          turn sign-in on (needs an admin account) or off
 *   php bin/settings.php auth status          on or off without migrating or seeding; exit 0 = on, 3 = off, 4 = APP_PASSWORD too short (Docker entrypoint)
 *   php bin/settings.php password [PASSWORD]  recovery shortcut: set the password of "admin" (created if missing) and turn sign-in on
 *   php bin/settings.php password --off       turn sign-in off
 *   php bin/settings.php export > s.json      /  import s.json
 *   php bin/settings.php key generate         print a new APP_KEY (base64 of 32 bytes) and store nothing
 *   php bin/settings.php key rotate           re-encrypt stored secrets; new key from APP_KEY_NEW or stdin, never argv
 */

if (PHP_SAPI !== 'cli') exit(1);
// key generate uses no configuration or data: answered before anything else, so it touches no data folder (L-680)
if (($argv[1] ?? '') === 'key' && ($argv[2] ?? '') === 'generate') {
    echo base64_encode(random_bytes(32)), "\n";
    exit(0);
}
// The settings forms and `key rotate` run as `spark apotheke:settings`, the account forms and `auth status` as
// `spark apotheke:accounts` (`auth status` does not migrate). Anything else gets apotheke:settings' usage answer.
$ci4Form = match (true) {
    in_array($argv[1] ?? 'list', ['list', 'get', 'set', 'reset', 'export', 'import'], true) => 'apotheke:settings',
    ($argv[1] ?? '') === 'key' && ($argv[2] ?? '') === 'rotate' => 'apotheke:settings',
    in_array($argv[1] ?? '', ['users', 'user', 'roles', 'auth', 'password'], true) => 'apotheke:accounts',
    default => 'apotheke:settings',
};
$spark = dirname(__DIR__) . '/spark';
// started as root: run as DATA_DIR's owner before anything else, or stop (L-622, N-625; DataDirOwner)
require_once dirname(__DIR__) . '/modules/Backup/Commands/DataDirOwner.php';
if (!\Apotheke\Backup\Commands\DataDirOwner::forCommandLine(STDERR, 'php bin/settings.php list')) exit(1);
$command = implode(' ', array_map('escapeshellarg', [PHP_BINARY, $spark, $ci4Form, '--no-header', ...array_slice($argv, 1)]));
passthru($command, $code);
exit($code);
