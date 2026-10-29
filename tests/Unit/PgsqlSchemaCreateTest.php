<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/legacy-bootstrap.php';

/**
 * PostgreSQL checks CREATE on the database before it notices that a schema
 * already exists. A role without that privilege must still boot when DB_SCHEMA
 * is present, and get a clear error (no password, no DSN) when it is not (#41).
 */
final class PgsqlSchemaCreateTest extends TestCase
{
    /** @var list<array{user:string,schema:?string}> */
    private array $roles = [];

    /** @var list<string> Schema names that need quoted DROP (mixed case or a " in the name). */
    private array $schemas = [];

    protected function tearDown(): void
    {
        try {
            if ($this->schemas !== []) {
                $admin = test_pg_admin();
                foreach ($this->schemas as $schema) {
                    $admin->exec('DROP SCHEMA IF EXISTS ' . self::quoteIdent($schema) . ' CASCADE');
                }
            }
        } catch (Throwable) {
        }
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

    public function testMixedCaseSchemaNameIsCreatedAndReusedExactly(): void
    {
        $this->admin();
        $created = 'Ab' . bin2hex(random_bytes(4));
        $existing = 'Cd' . bin2hex(random_bytes(4));
        $this->schemas[] = $created;
        $pg = test_pg_params();

        $pdo = db_connect($this->config($pg['user'], $pg['password'], $created));
        $this->assertSame($created, $pdo->query('SELECT current_schema()')->fetchColumn());
        $found = $pdo->prepare('SELECT nspname FROM pg_catalog.pg_namespace WHERE nspname = ?');
        $found->execute([$created]);
        $this->assertSame($created, $found->fetchColumn());
        $lower = $pdo->prepare('SELECT nspname FROM pg_catalog.pg_namespace WHERE nspname = ?');
        $lower->execute([strtolower($created)]);
        $this->assertFalse($lower->fetchColumn());
        $pdo = null;

        $role = $this->own($existing);
        $pdo = db_connect($this->config($role['user'], $role['password'], $existing));
        $this->assertSame($existing, $pdo->query('SELECT current_schema()')->fetchColumn());
    }

    public function testSchemaNameContainingAQuoteIsCreatedAndReusedExactly(): void
    {
        $this->admin();
        $created = 'q"' . bin2hex(random_bytes(4));
        $existing = 'r"' . bin2hex(random_bytes(4));
        $this->schemas[] = $created;
        $pg = test_pg_params();

        $pdo = db_connect($this->config($pg['user'], $pg['password'], $created));
        $this->assertSame($created, $pdo->query('SELECT current_schema()')->fetchColumn());
        $found = $pdo->prepare('SELECT nspname FROM pg_catalog.pg_namespace WHERE nspname = ?');
        $found->execute([$created]);
        $this->assertSame($created, $found->fetchColumn());
        $pdo = null;

        $role = $this->own($existing);
        $pdo = db_connect($this->config($role['user'], $role['password'], $existing));
        $this->assertSame($existing, $pdo->query('SELECT current_schema()')->fetchColumn());
    }

    /** @return list<array{0:string}> */
    public static function duplicateSchemaCodes(): array
    {
        return [['42P06'], ['23505']];
    }

    #[DataProvider('duplicateSchemaCodes')]
    public function testDuplicateSchemaCreateIsIgnoredWhenTheSchemaExistsNow(string $sqlstate): void
    {
        $this->admin();
        $schema = 'z' . bin2hex(random_bytes(4));
        $this->schemas[] = $schema;
        $pdo = $this->race($sqlstate, true);

        db_pgsql_use_schema($pdo, $schema);

        $this->assertSame($schema, $pdo->query('SELECT current_schema()')->fetchColumn());
    }

    #[DataProvider('duplicateSchemaCodes')]
    public function testDuplicateSchemaCreateStillFailsWhenTheSchemaIsMissing(string $sqlstate): void
    {
        $this->admin();
        $schema = 'y' . bin2hex(random_bytes(4));
        $this->schemas[] = $schema;
        $pdo = $this->race($sqlstate, false);
        $caught = null;
        try {
            db_pgsql_use_schema($pdo, $schema);
        } catch (PDOException $e) {
            $caught = $e;
        } catch (RuntimeException $e) {
            $this->fail($sqlstate . ' was replaced with: ' . $e->getMessage());
        }
        $this->assertInstanceOf(PDOException::class, $caught, $sqlstate . ' was ignored while the schema was still missing');
        $this->assertSame($sqlstate, (string)$caught->getCode());
        $this->assertFalse(db_pgsql_schema_exists($pdo, $schema));
    }

    private function race(string $sqlstate, bool $createFirst): SchemaRacePdo
    {
        $pg = test_pg_params();
        $pdo = new SchemaRacePdo(
            "pgsql:host={$pg['host']};port={$pg['port']};dbname={$pg['name']}",
            $pg['user'],
            $pg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->sqlstate = $sqlstate;
        $pdo->createFirst = $createFirst;
        return $pdo;
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

    /** @return array{user:string,password:string} */
    private function own(string $schema): array
    {
        $admin = $this->admin();
        $user = 'nocreate_' . bin2hex(random_bytes(4));
        $password = 'secret-' . bin2hex(random_bytes(12));
        $ident = test_pg_ident($user);
        $admin->exec('CREATE ROLE ' . $ident . ' LOGIN PASSWORD ' . $admin->quote($password) . ' NOSUPERUSER NOCREATEDB NOCREATEROLE');
        $db = test_pg_ident((string)$admin->query('SELECT current_database()')->fetchColumn());
        $admin->exec('REVOKE CREATE ON DATABASE ' . $db . ' FROM ' . $ident);
        $admin->exec('CREATE SCHEMA ' . self::quoteIdent($schema) . ' AUTHORIZATION ' . $ident);
        $this->roles[] = ['user' => $user, 'schema' => null];
        $this->schemas[] = $schema;
        return ['user' => $user, 'password' => $password];
    }

    private static function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
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

/**
 * CREATE SCHEMA that loses a race: the statement can apply, then PostgreSQL
 * still reports duplicate_schema (42P06) or unique_violation (23505).
 */
final class SchemaRacePdo extends PDO
{
    public string $sqlstate = '42P06';
    public bool $createFirst = true;

    public function exec(string $statement): int|false
    {
        if (preg_match('/^CREATE SCHEMA\b/i', $statement) === 1) {
            if ($this->createFirst) parent::exec($statement);
            $e = new PDOException('simulated ' . $this->sqlstate);
            (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, $this->sqlstate);
            throw $e;
        }
        return parent::exec($statement);
    }
}
