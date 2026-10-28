<?php
declare(strict_types=1);

/*
 * Apotheke – command-line tools that write to the data folder run as the folder's owner.
 */

/**
 * A process started as root continues as the user and group that own $dataDir, before it reads the database or
 * touches a file, so everything it writes belongs to that user. Returns null when the process runs as a user other
 * than root (from the start, or after the switch), or the message to print when it cannot switch: the folder is
 * missing or owned by root, the posix functions are missing, or the switch did not take.
 */
function cli_run_as_data_owner(string $dataDir): ?string
{
    if (cli_effective_uid() !== 0) return null;
    $st = @stat($dataDir);
    $pw = $st !== false && $st['uid'] !== 0 && function_exists('posix_getpwuid') ? posix_getpwuid($st['uid']) : false;
    $name = is_array($pw) ? (string)$pw['name'] : '';
    if ($st === false || $st['uid'] === 0 || !function_exists('posix_setuid') || !function_exists('posix_setgid')) {
        return cli_owner_message($name);
    }
    $ok = @posix_setgid($st['gid']);
    if ($ok && function_exists('posix_initgroups')) $ok = $name !== '' && @posix_initgroups($name, $st['gid']);
    $ok = $ok && @posix_setuid($st['uid']);
    if (!$ok || posix_geteuid() !== $st['uid'] || posix_getuid() !== $st['uid'] || posix_getegid() !== $st['gid']) {
        return cli_owner_message($name);
    }
    return null;
}

/** The effective user id, or -1 when it cannot be read. */
function cli_effective_uid(): int
{
    if (function_exists('posix_geteuid')) return posix_geteuid();
    $status = @file_get_contents('/proc/self/status');
    if (is_string($status) && preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $m)) return (int)$m[1];
    return -1;
}

function cli_owner_message(string $owner): string
{
    $owner = $owner !== '' && $owner !== 'root' ? $owner : '<owner>';
    return "Run this as the user that owns the data folder, not as root, for example: sudo -u $owner php bin/restore.php backup.zip. Nothing was changed.";
}
