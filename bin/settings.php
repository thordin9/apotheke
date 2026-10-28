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
require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/cli-owner.php';
// started as root: continue as the data folder's owner before anything is read or written, or stop (L-622)
if (($argv[1] ?? '') !== 'key' || ($argv[2] ?? '') !== 'generate') cli_data_owner_or_exit('php bin/settings.php list');   // key generate uses no data
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';

$cmd = $argv[1] ?? 'list';
try {
    switch ($cmd) {
        case 'list':
            foreach (settings_export()['settings'] as $k => $v) printf("%-24s %s\n", $k, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            printf("%-24s %s\n", 'sign-in', auth_enabled() ? 'on' : 'off');
            printf("%-24s %d\n", 'users', users_count());
            break;
        case 'get':
            echo json_encode(setting((string)($argv[2] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            break;
        case 'set':
            $k = (string)($argv[2] ?? '');
            $d = settings_schema()[$k] ?? null;
            if ($d === null || in_array($d[2], ['secret', 'internal'], true)) throw new UserError("Unknown setting “{$k}” (use `user` / `auth` for accounts and sign-in).");
            $err = settings_save([$k => (string)($argv[3] ?? '')]);
            if ($err) throw new UserError($err[$k]);
            echo "$k = ", json_encode(setting($k), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            if (settings_token_cleared()) echo "The AI autofill token was cleared because the service address changed: enter it again under AI autofill.\n";
            break;
        case 'reset':
            settings_token_cleared(false);
            settings_reset_section((string)($argv[2] ?? ''));
            echo "reset\n";
            if (settings_token_cleared()) echo "The AI autofill token was cleared because the service address changed: enter it again under AI autofill.\n";
            break;
        case 'users':
            if (!users_count()) echo "No accounts.\n";
            foreach (users_list() as $u) printf("%-24s %-7s %s\n", $u['username'], $u['role'] . ($u['status'] === 'pending' ? ' (pending)' : ''), $u['last_login_at'] ? 'last sign-in ' . $u['last_login_at'] . ' UTC' : 'never signed in');
            printf("sign-in is %s\n", auth_enabled() ? 'on' : 'off');
            break;
        case 'user':
            $sub = (string)($argv[2] ?? '');
            $name = (string)($argv[3] ?? '');
            $find = fn() => user_by_name($name) ?? throw new UserError("No user “{$name}”. Run `php bin/settings.php users`.");
            switch ($sub) {
                case 'add':
                    user_create($name, $argv[5] ?? cli_ask_password(), (string)($argv[4] ?? ''));
                    echo "Added {$name}.\n";
                    break;
                case 'password':
                    user_set_password($find()['id'], $argv[4] ?? cli_ask_password());
                    echo "Password set; {$name} has to sign in again on every device.\n";
                    break;
                case 'role':
                    user_set_role($find()['id'], (string)($argv[4] ?? ''));
                    echo "{$name} is now " . ($argv[4] ?? '') . ".\n";
                    break;
                case 'approve':
                    user_approve($find()['id']);
                    echo "{$name} can sign in now.\n";
                    break;
                case 'rename':
                    user_rename($find()['id'], (string)($argv[4] ?? ''));
                    echo "Renamed.\n";
                    break;
                case 'delete':
                    user_delete($find()['id']);
                    echo "Deleted {$name}.\n";
                    break;
                case 'signout':
                    user_sign_out_everywhere($find()['id']);
                    echo "Signed {$name} out on every device.\n";
                    break;
                default:
                    throw new UserError('Use: user add|password|role|approve|rename|delete|signout NAME …');
            }
            break;
        case 'roles':
            if (($argv[2] ?? '') === 'reset') { roles_reset_builtin(); echo "Built-in roles restored.\n"; break; }
            foreach (roles() as $k => $r) printf("%-14s %-16s %s\n", $k, $r['name'], implode(',', $r['perms']));
            break;
        case 'auth':
            if (($argv[2] ?? '') === 'on') { auth_turn_on(); echo "Sign-in turned on.\n"; }
            elseif (($argv[2] ?? '') === 'off') { auth_turn_off(); echo "Sign-in turned off.\n"; }
            elseif (($argv[2] ?? '') === 'status') {
                // no migration or seeding, and a missing SQLite file stays missing (connecting may still create an empty
                // DB_SCHEMA or switch an older SQLite file to WAL, as the app does)
                $db = cfg()['db'];
                $db['connect_timeout'] = 5;   // each try of the entrypoint's wait (#75 QA N-2)
                try {
                    $pdo = $db['driver'] === 'sqlite' && !is_file((string)$db['sqlite_path']) ? null : db_connect($db);
                } catch (PDOException) {
                    fwrite(STDERR, db_connect_failure_message((string)$db['driver'], config_migrate_where($db)) . "\n");
                    exit(1);
                }
                $pw = (string)cfg()['app_password'];
                $on = auth_on_at_start($pdo, $pw);
                // APP_PASSWORD decides only on a new install; there it must meet PASSWORD_MIN (hardening, #75 QA L-2)
                if ($on !== auth_on_at_start($pdo, '') && ($problem = app_password_problem($pw)) !== null) {
                    fwrite(STDERR, $problem . "\n");
                    exit(4);
                }
                echo $on ? "on\n" : "off\n";
                exit($on ? 0 : 3);
            }
            else throw new UserError('Use: auth on|off|status');
            break;
        case 'password':
            if (($argv[2] ?? '') === '--off') {
                auth_turn_off();
                echo "Sign-in turned off.\n";
                break;
            }
            $pw = $argv[2] ?? cli_ask_password();
            $u = user_by_name('admin');
            if ($u) {
                user_set_password($u['id'], $pw);
                user_set_role($u['id'], 'admin');
                user_approve($u['id']);
            } else {
                user_create('admin', $pw, 'admin');
            }
            auth_turn_on();
            echo "The account “admin” has a new password set and sign-in is on; other devices have to sign in again.\n";
            break;
        case 'export':
            echo json_encode(settings_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            break;
        case 'key':
            $sub = (string)($argv[2] ?? '');
            if ($sub === 'generate') {
                echo base64_encode(random_bytes(32)), "\n";
                break;
            }
            if ($sub === 'rotate') {
                if (isset($argv[3])) throw new UserError('The new key is not read from the command line. Set APP_KEY_NEW or pass it on stdin.');
                $new = getenv('APP_KEY_NEW');
                if (!is_string($new) || $new === '') {
                    $new = stream_get_contents(STDIN);
                    if (!is_string($new)) $new = '';
                }
                $c = secret_rotate($new);
                echo 'rotated ' . $c['rotated'] . ', encrypted ' . $c['encrypted'] . "\n";
                break;
            }
            throw new UserError('Use: key generate | key rotate');
        case 'import':
            $r = settings_import((string)file_get_contents((string)($argv[2] ?? 'php://stdin')));
            echo "Imported {$r['applied']} settings.\n";
            foreach ($r['errors'] as $k => $m) echo "  skipped $k: $m\n";
            break;
        default:
            fwrite(STDERR, "Unknown command. See the comment at the top of bin/settings.php.\n");
            exit(2);
    }
} catch (UserError|AppPasswordError $e) {   // a short APP_PASSWORD exits 1 with its message (#81 QA L-1)
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

function cli_ask_password(): string
{
    echo 'New password: ';
    $pw = rtrim((string)fgets(STDIN), "\r\n");
    echo 'Repeat: ';
    if (rtrim((string)fgets(STDIN), "\r\n") !== $pw) throw new UserError('The passwords do not match.');
    return $pw;
}
