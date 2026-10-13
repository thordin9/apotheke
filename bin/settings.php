#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Apotheke – Control Panel settings from the command line (recovery, scripting).
 *   php bin/settings.php list                 all settings with their values
 *   php bin/settings.php get KEY
 *   php bin/settings.php set KEY VALUE        validated like the web form
 *   php bin/settings.php reset SECTION        back to the config.php/env defaults
 *   php bin/settings.php password             set a new password (asks twice; or pass it as the next argument)
 *   php bin/settings.php password --off       turn the password off
 *   php bin/settings.php export > s.json      /  import s.json
 */

if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/items.php';

$cmd = $argv[1] ?? 'list';
try {
    switch ($cmd) {
        case 'list':
            foreach (settings_export()['settings'] as $k => $v) printf("%-24s %s\n", $k, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            printf("%-24s %s\n", 'password', auth_enabled() ? 'on' : 'off');
            break;
        case 'get':
            echo json_encode(setting((string)($argv[2] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            break;
        case 'set':
            $k = (string)($argv[2] ?? '');
            $d = settings_schema()[$k] ?? null;
            if ($d === null || in_array($d[2], ['secret', 'internal'], true)) throw new UserError("Unknown setting “{$k}” (use `password` for the password).");
            $err = settings_save([$k => (string)($argv[3] ?? '')]);
            if ($err) throw new UserError($err[$k]);
            echo "$k = ", json_encode(setting($k), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
            break;
        case 'reset':
            settings_reset_section((string)($argv[2] ?? ''));
            echo "reset\n";
            break;
        case 'password':
            if (($argv[2] ?? '') === '--off') {
                settings_store(['auth_enabled' => false, 'password_hash' => '']);
                echo "Password turned off.\n";
                break;
            }
            $pw = $argv[2] ?? null;
            if ($pw === null) {
                echo 'New password: ';
                $pw = rtrim((string)fgets(STDIN), "\r\n");
                echo 'Repeat: ';
                if (rtrim((string)fgets(STDIN), "\r\n") !== $pw) throw new UserError('The passwords do not match.');
            }
            if (mb_strlen($pw) < 8) throw new UserError('Use at least 8 characters.');
            settings_store(['auth_enabled' => true, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
            echo "Password set; other devices have to sign in again.\n";
            break;
        case 'export':
            echo json_encode(settings_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            break;
        case 'import':
            $r = settings_import((string)file_get_contents((string)($argv[2] ?? 'php://stdin')));
            echo "Imported {$r['applied']} settings.\n";
            foreach ($r['errors'] as $k => $m) echo "  skipped $k: $m\n";
            break;
        default:
            fwrite(STDERR, "Unknown command. See the comment at the top of bin/settings.php.\n");
            exit(2);
    }
} catch (UserError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
