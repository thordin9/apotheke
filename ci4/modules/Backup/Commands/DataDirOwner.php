<?php

declare(strict_types=1);

namespace Apotheke\Backup\Commands;

/**
 * The command-line tools that use DATA_DIR run as the user that owns it.
 * Started as root, they switch to that user (and DATA_DIR's group, or the
 * owner's own group when DATA_DIR's group is root's: N-621) before they do
 * any work, so every file they write belongs to the data-dir owner. A
 * root-owned DATA_DIR, a missing posix extension or a switch that fails stops
 * them with exit 1. Has no dependencies: bin/restore.php and spark load it
 * before CodeIgniter starts.
 */
final class DataDirOwner
{
    /** The command legacy bin/restore.php's message suggests. */
    public const RESTORE = 'php bin/restore.php backup.zip';

    /**
     * The spark commands that use DATA_DIR (L-622), with the command legacy's
     * message suggests for each.
     */
    public const COMMANDS = [
        'apotheke:restore' => self::RESTORE,
        'apotheke:backup' => 'php bin/backup.php',
        'apotheke:migrate' => 'php bin/migrate.php',
        'apotheke:settings' => 'php bin/settings.php list',
        'apotheke:accounts' => 'php bin/settings.php list',
    ];

    /**
     * Not root: true, nothing changes. Root: true once the process runs as
     * DATA_DIR's owner and group, else false after a message on $err that
     * suggests $example (legacy's message for that tool).
     *
     * @param resource $err
     */
    public static function become(string $dataDir, mixed $err, string $example = self::RESTORE): bool
    {
        if (!function_exists('posix_geteuid')) {
            if (!self::looksLikeRoot()) {
                return true;
            }
            return self::refuse($err, null, $example);
        }
        $euid = posix_geteuid();
        if ($euid !== 0) {
            return true;
        }
        clearstatcache(true, $dataDir);
        $st = $dataDir === '' ? false : @stat($dataDir);
        if ($st === false || !is_dir($dataDir)) {
            return self::refuse($err, null, $example);
        }
        $uid = (int)$st['uid'];
        $pw = $uid === 0 ? false : posix_getpwuid($uid);
        if ($uid === 0 || $pw === false) {
            return self::refuse($err, null, $example);
        }
        $name = (string)$pw['name'];
        // N-621: never root's group; the owner's own group when DATA_DIR's group is root's.
        $gid = (int)$st['gid'] !== 0 ? (int)$st['gid'] : (int)$pw['gid'];
        if ($gid === 0) {
            return self::refuse($err, $name, $example);
        }
        $switched = @posix_setgid($gid) && @posix_initgroups($name, $gid) && @posix_setuid($uid);
        if (!$switched || [posix_getuid(), posix_geteuid(), posix_getgid(), posix_getegid()] !== [$uid, $uid, $gid, $gid]
            || in_array(0, posix_getgroups() ?: [], true)) {
            return self::refuse($err, $name, $example);
        }
        putenv('HOME=' . (string)$pw['dir']);
        putenv('USER=' . $name);
        putenv('LOGNAME=' . $name);

        return true;
    }

    /**
     * The message legacy bin/restore.php prints, with the owner's name when it is known.
     *
     * @param resource $err
     */
    private static function refuse(mixed $err, ?string $owner, string $example): bool
    {
        fwrite($err, 'Run this as the user that owns the data folder, not as root, for example: sudo -u ' . ($owner ?? '<owner>') . " $example. Nothing was changed.\n");

        return false;
    }

    /** Without posix: the effective user is unknown, so a process that may be root is treated as root. */
    private static function looksLikeRoot(): bool
    {
        $status = @file_get_contents('/proc/self/status');
        if (!is_string($status) || preg_match('/^Uid:\s+\d+\s+(\d+)/m', $status, $m) !== 1) {
            return true;
        }

        return $m[1] === '0';
    }
}
