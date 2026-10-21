<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';

/**
 * PostgreSQL checks CREATE on the database before it notices that a schema
 * already exists. A role without that privilege must still boot when DB_SCHEMA
 * is present, and get a clear error (no password, no DSN) when it is not (#41).
 */
final class PgsqlSchemaCreateTest extends TestCase
{
    /** @var list<array{user:string,schema:?string}> */
    private array $roles = [];

    protected function tearDown(): void
    {
        foreach ($this->roles as $role) {
            try {
                test_pg_drop_role($role['user'], $role['schema']);
            } catch (Throwable) {
            }
        }
    }

    public function testRoleWithoutCreateCanConnectWhenSchemaExists(): void
    {
        $schema = 's_' . bin2hex(random_bytes(4));
        $role = $this->role($schema);
        $admin = $this->admin();
        $can = $admin->query('SELECT has_database_privilege(' . $admin->quote($role['user']) . ", current_database(), 'CREATE')")->fetchColumn();
        $this->assertContains($can, [false, 'f', '0', 0], 'role has no CREATE on the database');

        $pdo = db_connect($this->config($role['user'], $role['password'], $schema));

        $this->assertSame($schema, $pdo->query('SELECT current_schema()')->fetchColumn());
    }

    public function testMissingSchemaWithoutCreateIsAClearError(): void
    {
        $schema = 'm_' . bin2hex(random_bytes(4));
        $role = $this->role(null);
        try {
            db_connect($this->config($role['user'], $role['password'], $schema));
            $this->fail('expected a clear error when the schema is missing');
        } catch (PDOException $e) {
            $this->fail('raw database error instead of a clear message: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            $text = (string)$e;
            $this->assertStringContainsString($schema, $e->getMessage());
            $this->assertStringContainsString('does not exist', $e->getMessage());
            $this->assertStringContainsString('cannot create', $e->getMessage());
            $this->assertStringNotContainsString($role['password'], $e->getMessage());
            $this->assertStringNotContainsString($role['password'], $text);
            $this->assertStringNotContainsString('pgsql:', $e->getMessage());
            $this->assertStringNotContainsString('pgsql:', $text);
        }
    }

    /** @return array{user:string,password:string,schema:?string} */
    private function role(?string $schema): array
    {
        try {
            $role = test_pg_role_without_create($schema);
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $this->roles[] = ['user' => $role['user'], 'schema' => $schema];
        return $role;
    }

    private function admin(): PDO
    {
        try {
            return test_pg_admin();
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
    }

    /** @return array<string,mixed> */
    private function config(string $user, string $password, string $schema): array
    {
        $pg = test_pg_params();
        return [
            'driver' => 'pgsql',
            'dsn' => '',
            'host' => $pg['host'],
            'port' => (int)$pg['port'],
            'name' => $pg['name'],
            'user' => $user,
            'password' => $password,
            'schema' => $schema,
            'sqlite_path' => '',
        ];
    }
}
