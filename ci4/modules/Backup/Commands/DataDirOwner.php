<?php

declare(strict_types=1);

namespace Apotheke\Backup\Commands;

/**
 * The restore command line runs as the user that owns DATA_DIR. Started as
 * root, it switches to that user (and DATA_DIR's group) before it does any
 * restore work, so every file it writes belongs to the data-dir owner. A
 * root-owned DATA_DIR, a missing posix extension or a switch that fails stops
 * it with exit 1. Has no dependencies: bin/restore.php loads it before
 * CodeIgniter starts.
 */
final class DataDirOwner
{
    /**
     * Not root: true, nothing changes. Root: true once the process runs as
     * DATA_DIR's owner and group, else false after a message on $err.
     *
     * @param resource $err
     */
    public static function become(string $dataDir, mixed $err): bool
    {
        if (!function_exists('posix_geteuid')) {
            if (!self::looksLikeRoot()) {
                return true;
            }
            fwrite($err, "The restore needs the posix extension to check which user runs it.\n");

            return false;
        }
        $euid = posix_geteuid();
        if ($euid !== 0) {
            return true;
        }
        clearstatcache(true, $dataDir);
        $st = $dataDir === '' ? false : @stat($dataDir);
        if ($st === false || !is_dir($dataDir)) {
            fwrite($err, "Running as root, but the data directory ($dataDir) is missing. Run the restore as the user that owns it.\n");

            return false;
        }
        $uid = (int)$st['uid'];
        $gid = (int)$st['gid'];
        $pw = $uid === 0 ? false : posix_getpwuid($uid);
        if ($uid === 0 || $pw === false) {
            fwrite($err, 'Running as root: the data directory (' . $dataDir . ') is owned by '
                . ($uid === 0 ? 'root' : 'uid ' . $uid . ', which has no user entry') . ".\n"
                . "Run the restore as the user that owns the data directory, for example: sudo -u <owner> php bin/restore.php backup.zip\n");

            return false;
        }
        $name = (string)$pw['name'];
        $switched = @posix_setgid($gid) && @posix_initgroups($name, $gid) && @posix_setuid($uid);
        if (!$switched || [posix_getuid(), posix_geteuid(), posix_getgid(), posix_getegid()] !== [$uid, $uid, $gid, $gid]) {
            fwrite($err, "Running as root: could not switch to the data directory's owner ($name). Run the restore as that user, for example: sudo -u $name php bin/restore.php backup.zip\n");

            return false;
        }
        putenv('HOME=' . (string)$pw['dir']);
        putenv('USER=' . $name);
        putenv('LOGNAME=' . $name);

        return true;
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
