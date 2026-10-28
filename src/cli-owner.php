<?php
declare(strict_types=1);

/*
 * Apotheke – command-line tools that use the data folder run as the folder's owner.
 */

/**
 * A process started as root continues as the user that owns $dataDir, before it reads the database or touches a
 * file, so everything it writes belongs to that user. The group is the folder's group, or the owner's own group when
 * the folder's group is root's (N-621); the process never keeps root's group. Returns null when the process runs as a
 * user other than root (from the start, or after the switch), or the message to print when it cannot switch: the
 * folder is missing or owned by root, the owner has no account entry or only root's group, the posix functions are
 * missing, or the switch did not take. A user that cannot be told counts as root (N-625). $example is the command the
 * message suggests.
 */
function cli_run_as_data_owner(string $dataDir, string $example = 'php bin/restore.php backup.zip'): ?string
{
    if (cli_effective_uid() > 0) return null;
    clearstatcache(true, $dataDir);
    $st = @stat($dataDir);
    $pw = $st !== false && $st['uid'] !== 0 && function_exists('posix_getpwuid') ? posix_getpwuid($st['uid']) : false;
    $name = is_array($pw) ? (string)$pw['name'] : '';
    if ($st === false || !is_dir($dataDir) || $st['uid'] === 0 || !is_array($pw) || !function_exists('posix_setuid') || !function_exists('posix_setgid')) {
        return cli_owner_message($name, $example);
    }
    $gid = $st['gid'] !== 0 ? $st['gid'] : (int)$pw['gid'];
    if ($gid === 0) return cli_owner_message($name, $example);
    $ok = @posix_setgid($gid);
    if ($ok && function_exists('posix_initgroups')) $ok = @posix_initgroups($name, $gid);
    $ok = $ok && @posix_setuid($st['uid']);
    $groups = function_exists('posix_getgroups') ? (posix_getgroups() ?: []) : [];
    if (!$ok || posix_geteuid() !== $st['uid'] || posix_getuid() !== $st['uid'] || posix_getegid() !== $gid || posix_getgid() !== $gid
        || in_array(0, $groups, true)) {
        return cli_owner_message($name, $example);
    }
    return null;
}

/** The effective user id, or -1 when it cannot be read (cli_run_as_data_owner() then treats it as root). */
function cli_effective_uid(): int
{
    if (function_exists('posix_geteuid')) return posix_geteuid();
    $status = @file_get_contents('/proc/self/status');
    if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $m)) return (int)$m[1];
    return -1;
}

function cli_owner_message(string $owner, string $example = 'php bin/restore.php backup.zip'): string
{
    $owner = $owner !== '' && $owner !== 'root' ? $owner : '<owner>';
    return "Run this as the user that owns the data folder, not as root, for example: sudo -u $owner $example. Nothing was changed.";
}

/**
 * The start of every bin/ tool that uses the data folder: started as root, continue as the data folder's owner
 * (cli_run_as_data_owner()), or print why not and exit 1. Call it before src/bootstrap.php.
 */
function cli_data_owner_or_exit(string $example): void
{
    $error = cli_run_as_data_owner(cfg()['data_dir'], $example);
    if ($error === null) return;
    fwrite(STDERR, $error . "\n");
    exit(1);
}
