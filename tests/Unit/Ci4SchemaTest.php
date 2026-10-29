<?php

declare(strict_types=1);

use Apotheke\Core\Config\ApothekeConfig;
use Apotheke\Core\Config\Ci4SchemaSession;
use Apotheke\Core\Config\DatabaseFactory;
use Apotheke\Core\Config\DsnTranslator;
use Apotheke\Core\Config\PdoSchemaSession;
use Apotheke\Core\Config\PgsqlSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__, 2) . '/modules/Core/Config/ApothekeConfig.php';
require_once dirname(__DIR__, 2) . '/modules/Core/Config/DsnTranslator.php';
require_once dirname(__DIR__, 2) . '/modules/Core/Config/PgsqlSchema.php';
require_once dirname(__DIR__, 2) . '/modules/Core/Config/DatabaseFactory.php';

/**
 * CI4 create-schema rules (#44): exact pg_namespace match, a race is not a
 * failure once the schema exists, and a role without CREATE gets a clear error.
 */
final class Ci4SchemaTest extends TestCase
{
    /** @var list<array{user:string,schema:?string}> */
    private array $roles = [];

    /** @var list<string> */
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

    public function testRoleWithoutCreateCanUseAnExistingSchema(): void
    {
        $schema = 's_' . bin2hex(random_bytes(4));
        $role = $this->role($schema);
        $admin = $this->admin();
        $can = $admin->query('SELECT has_database_privilege(' . $admin->quote($role['user']) . ", current_database(), 'CREATE')")->fetchColumn();
        $this->assertContains($can, [false, 'f', '0', 0], 'role has no CREATE on the database');

        $pdo = $this->connect($role['user'], $role['password'], $schema);
        $this->assertSame($schema, $pdo->query('SELECT current_schema()')->fetchColumn());
        $wrapped = $this->open($role['user'], $role['password']);
        $this->applyFactory($wrapped, $schema);
        $this->assertSame($schema, $wrapped->pdo->query('SELECT current_schema()')->fetchColumn());
    }

    public function testMissingSchemaWithoutCreateIsAClearError(): void
    {
        $schema = 'm_' . bin2hex(random_bytes(4));
        $role = $this->role(null);
        foreach (['runner', 'factory'] as $path) {
            try {
                if ($path === 'runner') {
                    $this->connect($role['user'], $role['password'], $schema);
                } else {
                    $this->applyFactory($this->open($role['user'], $role['password']), $schema);
                }
                $this->fail($path . ' connected without the schema');
            } catch (PDOException $e) {
                $this->fail($path . ' raised a raw database error: ' . $e->getMessage());
            } catch (RuntimeException $e) {
                $text = (string)$e;
                $this->assertStringContainsString($schema, $e->getMessage(), $path);
                $this->assertStringContainsString('does not exist', $e->getMessage(), $path);
                $this->assertStringContainsString('cannot create', $e->getMessage(), $path);
                $this->assertStringNotContainsString($role['password'], $text, $path);
                $this->assertStringNotContainsString('pgsql:', $text, $path);
            }
        }
    }

    public function testMixedCaseSchemaNameIsCreatedAndReusedExactly(): void
    {
        $this->admin();
        $created = 'Ab' . bin2hex(random_bytes(4));
        $existing = 'Cd' . bin2hex(random_bytes(4));
        $this->schemas[] = $created;
        $pg = test_pg_params();

        $pdo = $this->connect($pg['user'], $pg['password'], $created);
        $this->assertSame($created, $this->nspname($pdo, $created));
        $this->assertFalse($this->nspname($pdo, strtolower($created)));
        $pdo = null;
        $wrapped = $this->open($pg['user'], $pg['password']);
        $this->applyFactory($wrapped, $created);
        $this->assertSame($created, $wrapped->pdo->query('SELECT current_schema()')->fetchColumn());

        $role = $this->own($existing);
        $pdo = $this->connect($role['user'], $role['password'], $existing);
        $this->assertSame($existing, $pdo->query('SELECT current_schema()')->fetchColumn());
        $this->applyFactory($this->open($role['user'], $role['password']), $existing);
    }

    public function testSchemaNameContainingAQuoteIsCreatedAndReusedExactly(): void
    {
        $this->admin();
        $created = 'q"' . bin2hex(random_bytes(4));
        $existing = 'r"' . bin2hex(random_bytes(4));
        $this->schemas[] = $created;
        $pg = test_pg_params();

        $pdo = $this->connect($pg['user'], $pg['password'], $created);
        $this->assertSame($created, $this->nspname($pdo, $created));
        $pdo = null;

        $role = $this->own($existing);
        $wrapped = $this->open($role['user'], $role['password']);
        $this->applyFactory($wrapped, $existing);
        $this->assertSame($existing, $wrapped->pdo->query('SELECT current_schema()')->fetchColumn());
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
        $race = $this->race($sqlstate, true);
        (new PgsqlSchema())->apply(new PdoSchemaSession($race), $schema);
        $this->assertSame($schema, $race->query('SELECT current_schema()')->fetchColumn());

        $again = $this->race($sqlstate, true);
        $this->applyFactory(new Ci4PdoConnection($again, $sqlstate, true), $schema);
        $this->assertSame($schema, $again->query('SELECT current_schema()')->fetchColumn());
    }

    #[DataProvider('duplicateSchemaCodes')]
    public function testDuplicateSchemaCreateStillFailsWhenTheSchemaIsMissing(string $sqlstate): void
    {
        $this->admin();
        $schema = 'y' . bin2hex(random_bytes(4));
        $this->schemas[] = $schema;
        foreach (['runner', 'factory'] as $path) {
            $caught = null;
            try {
                if ($path === 'runner') {
                    (new PgsqlSchema())->apply(new PdoSchemaSession($this->race($sqlstate, false)), $schema);
                } else {
                    $race = $this->race($sqlstate, false);
                    $this->applyFactory(new Ci4PdoConnection($race, $sqlstate, false), $schema);
                }
            } catch (PDOException $e) {
                $caught = $e;
            } catch (RuntimeException $e) {
                $this->fail($path . ' replaced ' . $sqlstate . ' with: ' . $e->getMessage());
            }
            $this->assertInstanceOf(PDOException::class, $caught, $path . ' ignored ' . $sqlstate);
            $this->assertSame($sqlstate, (string)$caught->getCode());
        }
    }

    public function testFailedSearchPathIsNotIgnored(): void
    {
        $this->admin();
        $schema = 'p' . bin2hex(random_bytes(4));
        $this->schemas[] = $schema;
        $pg = test_pg_params();
        $pdo = $this->open($pg['user'], $pg['password']);
        $pdo->failSearchPath = true;
        try {
            $this->applyFactory($pdo, $schema);
            $this->fail('a failed SET search_path was ignored');
        } catch (PDOException $e) {
            $this->assertSame('42501', (string)$e->getCode());
        }
        $this->assertNotSame($schema, $pdo->pdo->query('SELECT current_schema()')->fetchColumn());
    }

    /**
     * QA #53 N-A: on a server whose lc_messages is not English, CI4's Postgre
     * error() carries localized text and an empty code. The SQLSTATE must come
     * from the driver, so a role without CREATE still gets the clear error.
     */
    public function testLocalizedServerMessagesStillMapTheSqlstate(): void
    {
        if (!function_exists('pg_connect')) {
            $this->markTestSkipped('ext/pgsql is required');
        }
        $schema = 'l_' . bin2hex(random_bytes(4));
        $role = $this->role(null);
        $pg = test_pg_params();
        $conn = new Ci4LocalizedPostgre(sprintf(
            "host='%s' port='%s' dbname='%s' user='%s' password='%s'",
            $pg['host'],
            $pg['port'],
            $pg['name'],
            $role['user'],
            $role['password']
        ));
        try {
            $this->applyFactory($conn, $schema);
            $this->fail('connected without the schema');
        } catch (PDOException $e) {
            $this->fail('raised a raw database error (SQLSTATE "' . $e->getCode() . '") instead of the missing-schema message');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot create', $e->getMessage());
        }
    }

    /** The driver code decides, whatever the message says. */
    public function testDuplicateCreateIsReadFromTheCodeNotTheMessage(): void
    {
        $conn = new Ci4ScriptedConnection(['code' => '42P06', 'message' => 'FEHLER:  Schema existiert bereits']);
        (new PgsqlSchema())->apply(new Ci4SchemaSession($conn), 'raced');
        $this->assertSame(['SET search_path TO "raced"'], $conn->ran);

        $refused = new Ci4ScriptedConnection(['code' => '42501', 'message' => 'FEHLER:  keine Berechtigung'], false);
        try {
            (new PgsqlSchema())->apply(new Ci4SchemaSession($refused), 'nope');
            $this->fail('a refused CREATE was ignored');
        } catch (PDOException $e) {
            $this->fail('42501 was not mapped: SQLSTATE "' . $e->getCode() . '"');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot create', $e->getMessage());
        }
    }

    public function testHealthzRejectsAMissingSchemaAndAcceptsAnExistingOne(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/vendor/autoload.php') || PHP_VERSION_ID < 80400) {
            $this->markTestSkipped('CI4 vendor and PHP 8.4 are required');
        }
        $schema = 'h_' . bin2hex(random_bytes(4));
        $role = $this->role($schema);
        $dir = tmpdir('ci4-schema-ok');
        $server = new Server($dir, [
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => test_pg_params()['host'],
            'DB_PORT' => (string)test_pg_params()['port'],
            'DB_NAME' => test_pg_params()['name'],
            'DB_USER' => $role['user'],
            'DB_PASSWORD' => $role['password'],
            'DB_SCHEMA' => $schema,
            'DB_DSN' => null,
            'APOTHEKE_TEST_DB' => null,
        ], 'ci4');
        try {
            $response = $server->client()->get('/healthz');
            $log = is_file($server->log) ? (string)file_get_contents($server->log) : '';
            $this->assertSame(200, $response['status'], $log);
            $this->assertSame('ok', $response['body']);
            $this->assertStringNotContainsString($role['password'], $response['body'] . $log);
        } finally {
            $server->stop();
            rrmdir($dir);
        }

        $missing = 'm_' . bin2hex(random_bytes(4));
        $dir = tmpdir('ci4-schema-missing');
        $server = new Server($dir, [
            'DB_DRIVER' => 'pgsql',
            'DB_HOST' => test_pg_params()['host'],
            'DB_PORT' => (string)test_pg_params()['port'],
            'DB_NAME' => test_pg_params()['name'],
            'DB_USER' => $role['user'],
            'DB_PASSWORD' => $role['password'],
            'DB_SCHEMA' => $missing,
            'DB_DSN' => null,
            'APOTHEKE_TEST_DB' => null,
        ], 'ci4');
        try {
            $response = $server->client()->get('/healthz');
            $log = is_file($server->log) ? (string)file_get_contents($server->log) : '';
            $this->assertNotSame(200, $response['status'], $response['body']);
            $this->assertSame(500, $response['status']);
            $shown = $response['body'] . $log;
            $this->assertStringContainsString($missing, $log);
            $this->assertStringContainsString('cannot create', $log);
            $this->assertStringNotContainsString($role['password'], $shown);
            $this->assertStringNotContainsString('pgsql:', $shown);
            $this->assertStringNotContainsString($role['password'], $response['body']);
        } finally {
            $server->stop();
            rrmdir($dir);
        }
    }

    private function connect(string $user, string $password, string $schema): PDO
    {
        $pdo = $this->open($user, $password)->pdo;
        (new PgsqlSchema())->apply(new PdoSchemaSession($pdo), $schema);

        return $pdo;
    }

    private function open(string $user, string $password): Ci4PdoConnection
    {
        try {
            $pg = test_pg_params();
            $pdo = new PDO(
                "pgsql:host={$pg['host']};port={$pg['port']};dbname={$pg['name']}",
                $user,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }

        return new Ci4PdoConnection($pdo, '', false);
    }

    private function applyFactory(object $connection, string $schema): void
    {
        $config = ApothekeConfig::load(ROOT, null, [
            'DB_DRIVER' => 'pgsql',
            'DB_SCHEMA' => $schema,
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_NAME' => 'apotheke',
            'DATA_DIR' => sys_get_temp_dir(),
        ]);
        (new DatabaseFactory($config, new DsnTranslator()))->apply($connection);
    }

    private function race(string $sqlstate, bool $createFirst): Ci4SchemaRacePdo
    {
        $pg = test_pg_params();
        $pdo = new Ci4SchemaRacePdo(
            "pgsql:host={$pg['host']};port={$pg['port']};dbname={$pg['name']}",
            $pg['user'],
            $pg['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $pdo->sqlstate = $sqlstate;
        $pdo->createFirst = $createFirst;

        return $pdo;
    }

    private function nspname(PDO $pdo, string $schema): string|false
    {
        $found = $pdo->prepare('SELECT nspname FROM pg_catalog.pg_namespace WHERE nspname = ?');
        $found->execute([$schema]);
        $value = $found->fetchColumn();

        return $value === false ? false : (string)$value;
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
}

/** PDO shaped like CI4's connection so DatabaseFactory can run on PHP 8.3. It has no connID, so Ci4SchemaSession reads error()['code']. */
final class Ci4PdoConnection
{
    public string $DBDriver = 'Postgre';
    public bool $failSearchPath = false;
    private string $error = '';
    private string $code = '';

    public function __construct(public PDO $pdo, private string $sqlstate, private bool $createFirst)
    {
    }

    /** Already connected, as CI4's initialize() is once connID is set. */
    public function initialize(): void
    {
    }

    public function escape(string $value): string
    {
        return $this->pdo->quote($value);
    }

    public function query(string $sql): Ci4PdoResult|false
    {
        if ($this->failSearchPath && str_starts_with($sql, 'SET search_path')) {
            $this->error = 'FEHLER:  keine Berechtigung für Schema';
            $this->code = '42501';

            return false;
        }
        if ($this->sqlstate !== '' && preg_match('/^CREATE SCHEMA\b/i', $sql) === 1) {
            if ($this->createFirst) {
                $this->pdo->exec($sql);
            }
            $this->error = 'FEHLER:  simuliert';
            $this->code = $this->sqlstate;

            return false;
        }
        try {
            if (preg_match('/^(SET|CREATE)\b/i', $sql) === 1) {
                $this->pdo->exec($sql);

                return new Ci4PdoResult(null);
            }
            $statement = $this->pdo->query($sql);

            return new Ci4PdoResult($statement instanceof PDOStatement ? $statement : null);
        } catch (PDOException $e) {
            $this->error = 'FEHLER:  ' . $e->getMessage();
            $this->code = (string)$e->getCode();

            return false;
        }
    }

    /** Like a CI4 driver that fills in the code (Postgre leaves it ''); the message is localized. */
    public function error(): array
    {
        return ['code' => $this->code, 'message' => $this->error];
    }
}

final class Ci4PdoResult
{
    public function __construct(private ?PDOStatement $statement)
    {
    }

    /** @return array<string,mixed> */
    public function getRowArray(): array
    {
        if (!$this->statement instanceof PDOStatement) {
            return [];
        }
        $row = $this->statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }
}

/** CREATE SCHEMA that loses a race after, or instead of, applying. */
final class Ci4SchemaRacePdo extends PDO
{
    public string $sqlstate = '42P06';
    public bool $createFirst = true;

    public function exec(string $statement): int|false
    {
        if (preg_match('/^CREATE SCHEMA\b/i', $statement) === 1) {
            if ($this->createFirst) {
                parent::exec($statement);
            }
            $e = new PDOException('simulated ' . $this->sqlstate);
            (new ReflectionProperty(PDOException::class, 'code'))->setValue($e, $this->sqlstate);
            throw $e;
        }

        return parent::exec($statement);
    }
}

/**
 * The parts of CI4's Postgre connection that Ci4SchemaSession uses: a public
 * PgSql\Connection in connID, query() that returns false on error, and
 * error() with an empty code. The message is what a German server sends.
 */
final class Ci4LocalizedPostgre
{
    public string $DBDriver = 'Postgre';
    public PgSql\Connection $connID;

    public function __construct(string $dsn)
    {
        $link = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if (!$link instanceof PgSql\Connection) {
            throw new RuntimeException('pg_connect failed');
        }
        $this->connID = $link;
    }

    /** Already connected, as CI4's initialize() is once connID is set. */
    public function initialize(): void
    {
    }

    public function escape(string $value): string
    {
        return "'" . pg_escape_string($this->connID, $value) . "'";
    }

    public function query(string $sql): Ci4LocalizedResult|false
    {
        $result = @pg_query($this->connID, $sql);

        return $result === false ? false : new Ci4LocalizedResult($result);
    }

    /** @return array{code:string,message:string} */
    public function error(): array
    {
        return ['code' => '', 'message' => 'FEHLER:  keine Berechtigung'];
    }
}

final class Ci4LocalizedResult
{
    public function __construct(private PgSql\Result $result)
    {
    }

    /** @return array<string,mixed> */
    public function getRowArray(): array
    {
        $row = pg_fetch_assoc($this->result);

        return is_array($row) ? $row : [];
    }
}

/** No database: the schema is missing, CREATE fails with $error, and afterwards the schema exists when $raced. */
final class Ci4ScriptedConnection
{
    /** @var list<string> */
    public array $ran = [];
    private bool $created = false;

    /** @param array{code:string,message:string} $error */
    public function __construct(private array $error, private bool $raced = true)
    {
    }

    public function escape(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    public function query(string $sql): Ci4ScriptedResult|false
    {
        if (str_starts_with($sql, 'CREATE SCHEMA')) {
            $this->created = $this->raced;

            return false;
        }
        if (str_contains($sql, 'pg_namespace')) {
            return new Ci4ScriptedResult($this->created ? ['present' => 1] : []);
        }
        if (str_starts_with($sql, 'SELECT current_schema()')) {
            return new Ci4ScriptedResult(['schema' => 'raced']);
        }
        $this->ran[] = $sql;

        return new Ci4ScriptedResult([]);
    }

    /** @return array{code:string,message:string} */
    public function error(): array
    {
        return $this->error;
    }
}

final class Ci4ScriptedResult
{
    /** @param array<string,mixed> $row */
    public function __construct(private array $row)
    {
    }

    /** @return array<string,mixed> */
    public function getRowArray(): array
    {
        return $this->row;
    }
}
