<?php

declare(strict_types=1);

namespace Tests\Backup;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * N-880: after the switch, DataDirOwner::become() checks the process's
 * groups and refuses while root's group (0) is still among them, or when
 * they cannot be read. A child process replaces the posix calls (and stat)
 * in DataDirOwner's namespace, so this runs without root.
 */
final class DataDirOwnerGroupsTest extends CIUnitTestCase
{
    private const CHILD = <<<'PHP'
        <?php
        declare(strict_types=1);
        namespace Apotheke\Backup\Commands;

        const OWNER = 4242;
        $GLOBALS['uid'] = 0;
        $GLOBALS['gid'] = 0;
        function stat(string $path): array { return ['uid' => OWNER, 'gid' => OWNER]; }
        function posix_getpwuid(int $uid): array { return ['name' => 'owner', 'gid' => OWNER, 'dir' => '/nonexistent']; }
        function posix_setgid(int $gid): bool { $GLOBALS['gid'] = $gid; return true; }
        function posix_initgroups(string $name, int $gid): bool { return true; }
        function posix_setuid(int $uid): bool { $GLOBALS['uid'] = $uid; return true; }
        function posix_getuid(): int { return $GLOBALS['uid']; }
        function posix_geteuid(): int { return $GLOBALS['uid']; }
        function posix_getgid(): int { return $GLOBALS['gid']; }
        function posix_getegid(): int { return $GLOBALS['gid']; }
        function posix_getgroups(): array|false { return json_decode($GLOBALS['argv'][2], true); }

        require $argv[1];
        $err = fopen('php://memory', 'w+');
        $ok = DataDirOwner::become(sys_get_temp_dir(), $err);
        rewind($err);
        echo json_encode([$ok, stream_get_contents($err)]);
        PHP;

    /** @return iterable<string,array{0:list<int>|false,1:bool}> */
    public static function groupLists(): iterable
    {
        yield 'the owner\'s groups only' => [[4242, 100], true];
        yield 'root\'s group still there' => [[4242, 0], false];
        yield 'the groups cannot be read' => [false, false];
    }

    /** @param list<int>|false $groups */
    #[DataProvider('groupLists')]
    public function testTheSwitchIsRefusedWhileRootsGroupRemains(array|false $groups, bool $ok): void
    {
        if (!function_exists('posix_getgroups') || !function_exists('posix_initgroups')) {
            $this->markTestSkipped('needs the posix functions');
        }
        $child = tempnam(sys_get_temp_dir(), 'apotheke-n880-');
        $this->assertIsString($child);
        try {
            file_put_contents($child, self::CHILD);
            $source = dirname(__DIR__, 2) . '/modules/Backup/Commands/DataDirOwner.php';
            $out = (string)shell_exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $child, $source, json_encode($groups)])) . ' 2>&1');
        } finally {
            @unlink($child);
        }
        $refusal = 'Run this as the user that owns the data folder, not as root, for example: sudo -u owner php bin/restore.php backup.zip. Nothing was changed.' . "\n";
        $this->assertSame([$ok, $ok ? '' : $refusal], json_decode($out, true), $out);
    }
}
