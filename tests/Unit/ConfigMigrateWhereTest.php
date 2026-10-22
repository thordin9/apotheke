<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * config_migrate_where() and the DSN tokeniser, in this process.
 * bin/migrate.php prints only host, port, dbname, and DB_SCHEMA.
 * User and password are never included. A DSN that does not parse is
 * `<unparsed dsn>` and nothing more.
 */
final class ConfigMigrateWhereTest extends TestCase
{
    private const WHERE = 'host=127.0.0.1 port=1 dbname=keepdb (schema app)';

    public function testBuiltDsnPrintsHostPortDbnameAndSchema(): void
    {
        $where = config_migrate_where($this->db());
        $this->assertSame(self::WHERE, $where);
        $this->assertSecretsAbsent($where);
    }

    public function testEmptySchemaIsOmitted(): void
    {
        $where = config_migrate_where($this->db(['schema' => '']));
        $this->assertSame('host=127.0.0.1 port=1 dbname=keepdb', $where);
        $this->assertSecretsAbsent($where);
    }

    public function testKeywordDsnDropsUserAndPassword(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => 'pgsql:host=127.0.0.1;port=1;user=u-secret;password=p-secret;dbname=keepdb',
        ]));
        $this->assertSame(self::WHERE, $where);
        $this->assertSecretsAbsent($where);
    }

    public function testKeysAreCaseInsensitiveAndSpacesAroundEqualsAreIgnored(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => 'pgsql:HOST = 127.0.0.1; PORT =1 ; PASSWORD = p-secret ; USER = u-secret ; DBNAME = keepdb',
        ]));
        $this->assertSame(self::WHERE, $where);
        $this->assertSecretsAbsent($where);
    }

    public function testQuotedPasswordKeepsSpacesAndEscapedQuote(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => "pgsql:host=127.0.0.1;port=1;password='a b;p-secret';user='u-secret';dbname=keepdb",
        ]));
        $this->assertSame(self::WHERE, $where);
        $this->assertSecretsAbsent($where);

        $where = config_migrate_where($this->db([
            'dsn' => "pgsql:host=127.0.0.1;port=1;password='it\\'s a\\\\x';dbname=keepdb",
        ]));
        $this->assertSame(self::WHERE, $where);
        $this->assertStringNotContainsString("it's", $where);
        $this->assertSecretsAbsent($where);
    }

    public function testQuotedDbnameIsOneValue(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => "pgsql:host=127.0.0.1;port=1;password=hideme99;dbname='keepdb password=p-secret'",
        ]));
        $this->assertSame('host=127.0.0.1 port=1 dbname=keepdb password=p-secret (schema app)', $where);
        $this->assertStringNotContainsString('hideme99', $where);
        $this->assertStringNotContainsString('u-secret', $where);
    }

    public function testEscapedSpaceStaysInsideTheHostValue(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => "pgsql:host=h\\ p-secret;port=1;dbname=keepdb;user=u-secret",
        ]));
        $this->assertSame('host=h p-secret port=1 dbname=keepdb (schema app)', $where);
        $this->assertStringNotContainsString('u-secret', $where);
    }

    public function testPasswordValueIsNotCopiedIntoAField(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => 'pgsql:password=p-secret;user=u-secret;host=127.0.0.1;port=1',
        ]));
        $this->assertSame('host=127.0.0.1 port=1 (schema app)', $where);
        $this->assertSecretsAbsent($where);
    }

    public function testDuplicateKeysKeepTheLastAllowlistedValue(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => 'pgsql:host=oldhost;host=127.0.0.1;port=9;port=1;dbname=olddb;dbname=keepdb;password=firstpw;password=secondpw;user=u-secret',
        ]));
        $this->assertSame(self::WHERE, $where);
        foreach (['oldhost', 'olddb', 'firstpw', 'secondpw', 'u-secret', 'p-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $where);
        }
    }

    public function testEmptyAllowlistedValuesAreOmitted(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => 'pgsql:host=127.0.0.1;port=;dbname=keepdb;password=p-secret',
        ]));
        $this->assertSame('host=127.0.0.1 dbname=keepdb (schema app)', $where);
        $this->assertSecretsAbsent($where);
    }

    public function testUriDropsUserinfoAndQuery(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => 'postgresql://u-secret:p-secret@127.0.0.1:1/keepdb?password=p-secret&user=u-secret',
        ]));
        $this->assertSame(self::WHERE, $where);
        $this->assertSecretsAbsent($where);
    }

    public function testUriOmitsMissingPortAndASlashOnlyPath(): void
    {
        $where = config_migrate_where($this->db([
            'dsn' => 'postgresql://127.0.0.1/keepdb',
        ]));
        $this->assertSame('host=127.0.0.1 dbname=keepdb (schema app)', $where);

        $where = config_migrate_where($this->db([
            'dsn' => 'postgresql://127.0.0.1:1/',
        ]));
        $this->assertSame('host=127.0.0.1 port=1 (schema app)', $where);
        $this->assertSecretsAbsent($where);
    }

    public function testSqlitePrintsThePathOnly(): void
    {
        $where = config_migrate_where($this->db([
            'driver' => 'sqlite',
            'dsn' => 'sqlite:password=p-secret',
            'sqlite_path' => '/data/catalog.sqlite',
        ]));
        $this->assertSame('/data/catalog.sqlite', $where);
        $this->assertSecretsAbsent($where);
    }

    /** @return array<string, array{0:string}> */
    public static function unparsedDsns(): array
    {
        return [
            'junk' => ['not a dsn p-secret'],
            'unterminated quote' => ["pgsql:host=127.0.0.1;dbname='p-secret"],
            'missing equals' => ['pgsql:host=127.0.0.1;dbname;password=p-secret'],
            'trailing backslash' => ['pgsql:host=127.0.0.1;dbname=keep\\'],
            'quoted trailing backslash' => ["pgsql:host=127.0.0.1;password='ab\\"],
            'uri parse failure' => ['postgresql://u-secret:p-secret@'],
            'empty key' => ['pgsql:=p-secret;host=127.0.0.1'],
        ];
    }

    #[DataProvider('unparsedDsns')]
    public function testUnparsedDsnPrintsNothingElse(string $dsn): void
    {
        $where = config_migrate_where($this->db(['dsn' => $dsn]));
        $this->assertSame('<unparsed dsn>', $where);
        $this->assertStringNotContainsString('127.0.0.1', $where);
        $this->assertStringNotContainsString('schema', $where);
        $this->assertSecretsAbsent($where);
    }

    public function testParsedPasswordOnlyStillPrintsTheSchema(): void
    {
        $this->assertSame([], config_dsn_public_fields('pgsql:password=p-secret;user=u-secret'));
        $where = config_migrate_where($this->db([
            'dsn' => 'pgsql:password=p-secret;user=u-secret',
        ]));
        $this->assertSame('(schema app)', $where);
        $this->assertSecretsAbsent($where);

        $where = config_migrate_where($this->db([
            'dsn' => 'pgsql:password=p-secret',
            'schema' => '',
        ]));
        $this->assertSame('', $where);
    }

    public function testTokeniserDecodesQuotesEscapesAndDuplicates(): void
    {
        $tokens = config_dsn_keywords("host=h\\ p-secret;password='it\\'s a\\\\x';HOST=h2; port = 9 ; user = u-secret");
        $this->assertSame([
            'host' => 'h2',
            'password' => "it's a\\x",
            'port' => '9',
            'user' => 'u-secret',
        ], $tokens);
        $this->assertSame([], config_dsn_keywords(' ; '));
        $fields = config_dsn_public_fields("pgsql:host=h\\ p-secret;password='it\\'s a\\\\x';HOST=h2; port = 9");
        $this->assertSame(['host' => 'h2', 'port' => '9'], $fields);
    }

    public function testTokeniserRejectsBrokenInput(): void
    {
        $this->assertNull(config_dsn_keywords('host'));
        $this->assertNull(config_dsn_keywords("dbname='noend"));
        $this->assertNull(config_dsn_keywords('host=a\\'));
        $this->assertNull(config_dsn_keywords("password='ab\\"));
        $this->assertNull(config_dsn_keywords('=novalue'));
        $this->assertNull(config_dsn_public_fields('not a dsn'));
        $this->assertNull(config_dsn_public_fields('postgresql://u-secret:p-secret@'));
        $this->assertNull(config_dsn_public_fields('postgresql://'));
    }

    /** @param array<string, mixed> $over */
    private function db(array $over = []): array
    {
        return $over + [
            'driver' => 'pgsql',
            'dsn' => '',
            'host' => '127.0.0.1',
            'port' => 1,
            'name' => 'keepdb',
            'user' => 'u-secret',
            'password' => 'p-secret',
            'schema' => 'app',
            'sqlite_path' => '/data/catalog.sqlite',
        ];
    }

    private function assertSecretsAbsent(string $where): void
    {
        $this->assertStringNotContainsString('u-secret', $where);
        $this->assertStringNotContainsString('p-secret', $where);
    }
}
