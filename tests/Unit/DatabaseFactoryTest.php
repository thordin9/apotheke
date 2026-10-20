<?php

declare(strict_types=1);

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\DatabaseFactory;
use Apotheke\Core\Config\DsnTranslator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Config/ApothekeConfig.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Config/DsnTranslator.php';
require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Config/DatabaseFactory.php';

final class DatabaseFactoryTest extends TestCase
{
    public function testSqliteGroupAndPragmas(): void
    {
        $config = ApothekeConfig::load('/repo', null, ['SQLITE_PATH' => '/tmp/catalog.sqlite']);
        $factory = new DatabaseFactory($config, new DsnTranslator());
        $group = $factory->group();
        $this->assertSame('/tmp/catalog.sqlite', $group['database']);
        $this->assertSame(5000, $group['busyTimeout']);
        $path = sys_get_temp_dir() . '/apo-pragma-' . bin2hex(random_bytes(3)) . '.sqlite';
        $db = new SQLite3($path);
        $factory->applySqlite3($db);
        $this->assertSame('wal', $db->querySingle('PRAGMA journal_mode'));
        $this->assertSame(1, $db->querySingle('PRAGMA foreign_keys'));
        $this->assertSame(1, $db->querySingle('PRAGMA synchronous'));
        $this->assertSame(5000, $db->querySingle('PRAGMA busy_timeout'));
        $db->close();
        @unlink($path);
    }
}
