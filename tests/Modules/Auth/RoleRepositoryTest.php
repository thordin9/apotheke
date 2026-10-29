<?php

declare(strict_types=1);

namespace Tests\Auth;

use Apotheke\Auth\Permissions;
use Apotheke\Auth\RoleRepository;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * #91 N-14: the built-in roles stand in only when the database fails (no
 * `roles` table yet, no connection), as legacy roles() does on a
 * PDOException. Any other error propagates.
 */
final class RoleRepositoryTest extends CIUnitTestCase
{
    /** @return iterable<string,array{Throwable}> */
    public static function databaseErrors(): iterable
    {
        yield 'CI4 database error' => [new DatabaseException('placeholder: no such table')];
        yield 'PDO error' => [new PDOException('placeholder: connection refused')];
    }

    #[DataProvider('databaseErrors')]
    public function testADatabaseErrorGivesTheBuiltInRoles(Throwable $error): void
    {
        $this->assertBuiltIns(new RoleRepository($this->failing($error)));
    }

    /** @return iterable<string,array{Throwable}> */
    public static function otherErrors(): iterable
    {
        yield 'logic error' => [new LogicException('placeholder: a bug')];
        yield 'runtime error' => [new RuntimeException('placeholder: something else')];
        yield 'type error' => [new TypeError('placeholder: a bug')];
    }

    #[DataProvider('otherErrors')]
    public function testAnyOtherErrorPropagates(Throwable $error): void
    {
        $roles = new RoleRepository($this->failing($error));
        try {
            $roles->has('viewer', 'view');
            $this->fail('the error was swallowed');
        } catch (Throwable $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testNoConnectionGivesTheBuiltInRoles(): void
    {
        $db = Database::connect([
            'DSN' => '',
            'hostname' => '',
            'username' => '',
            'password' => '',
            'database' => sys_get_temp_dir() . '/apotheke-no-such-dir-' . bin2hex(random_bytes(6)) . '/db.sqlite',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => false,
        ], false);
        $this->assertBuiltIns(new RoleRepository($db));
    }

    private function assertBuiltIns(RoleRepository $roles): void
    {
        foreach (Permissions::ROLE_DEFAULTS as $key => [$name, $perms]) {
            $this->assertSame(['name' => $name, 'perms' => $perms], $roles->all()[$key]);
        }
        $this->assertTrue($roles->has('viewer', 'view'));
        $this->assertFalse($roles->has('viewer', 'settings'));
    }

    /** @return BaseConnection<object|resource, object|resource> */
    private function failing(Throwable $error): BaseConnection
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('table')->willThrowException($error);

        return $db;
    }
}
