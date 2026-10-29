<?php

declare(strict_types=1);

namespace Apotheke\Backup\Commands;

/**
 * The command-line tools that use DATA_DIR run as the user that owns it.
 * Started as root, they switch to that user (and DATA_DIR's group, or the
 * owner's own group when DATA_DIR's group is root's: N-621) before they do
 * any work, so every file they write belongs to the data-dir owner. A
 * root-owned DATA_DIR, a missing posix function or a switch that fails stops
 * them with exit 1. Every other spark command started as root does the same
 * before CodeIgniter boots (L-700). Has no dependencies but ApothekeConfig:
 * the bin/ tools and spark load it before CodeIgniter starts.
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

    /** What the message suggests for any other spark command (L-700), run from the install folder. */
    public const SPARK = 'php ci4/spark';

    /**
     * The posix functions the switch needs (N-702, N-781). Without any of
     * them it refuses with the message and exit 1, before it changes anything.
     */
    public const FUNCTIONS = ['posix_getpwuid', 'posix_setgid', 'posix_initgroups', 'posix_setuid',
        'posix_getuid', 'posix_geteuid', 'posix_getgid', 'posix_getegid', 'posix_getgroups'];

    /**
     * The start of every CI4 command-line tool, before CodeIgniter boots: not
     * root, true; root (or a user that cannot be told, N-625), true once it
     * runs as DATA_DIR's owner (from the environment, as ApothekeConfig reads
     * it), else false after the message on $err. Loads its own dependencies.
     *
     * @param resource $err
     */
    public static function forCommandLine(mixed $err, string $example): bool
    {
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return true;
        }
        if (!function_exists('posix_geteuid') && !self::looksLikeRoot()) {
            return true;
        }
        require_once dirname(__DIR__, 2) . '/Core/Config/ApothekeConfig.php';
        try {
            $dataDir = \Apotheke\Core\Config\ApothekeConfig::fromEnvironment()->dataDir();
        } catch (\Throwable $e) {
            fwrite($err, $e->getMessage() . "\n");

            return false;
        }

        return self::become($dataDir, $err, $example);
    }

    /**
     * The command a spark command line ($args, without the script) runs, as
     * the message names it: a data command's own tool (COMMANDS), else
     * `php ci4/spark` and the command's name (L-700).
     *
     * @param list<string> $args
     */
    public static function example(array $args): string
    {
        $args = array_map(static fn (mixed $a): string => strtolower((string)$a), $args);
        foreach (self::COMMANDS as $command => $example) {
            if (in_array($command, $args, true)) {
                return $example;
            }
        }
        foreach ($args as $arg) {
            if ($arg !== '' && $arg[0] !== '-') {
                return preg_match('/^[a-z0-9:_-]{1,64}$/D', $arg) === 1 ? self::SPARK . ' ' . $arg : self::SPARK;
            }
        }

        return self::SPARK;
    }

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
        $pw = $uid === 0 || !function_exists('posix_getpwuid') ? false : posix_getpwuid($uid);
        if ($uid === 0 || $pw === false) {
            return self::refuse($err, null, $example);
        }
        $name = (string)$pw['name'];
        // N-702, N-781: every function the switch and its check use, or a refusal (not a fatal error).
        foreach (self::FUNCTIONS as $function) {
            if (!function_exists($function)) {
                return self::refuse($err, $name, $example);
            }
        }
        // N-621: never root's group; the owner's own group when DATA_DIR's group is root's.
        $gid = (int)$st['gid'] !== 0 ? (int)$st['gid'] : (int)$pw['gid'];
        if ($gid === 0) {
            return self::refuse($err, $name, $example);
        }
        $switched = @posix_setgid($gid) && @posix_initgroups($name, $gid) && @posix_setuid($uid);
        $groups = $switched ? posix_getgroups() : false;
        if (!$switched || [posix_getuid(), posix_geteuid(), posix_getgid(), posix_getegid()] !== [$uid, $uid, $gid, $gid]
            || !is_array($groups) || in_array(0, $groups, true)) {
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
