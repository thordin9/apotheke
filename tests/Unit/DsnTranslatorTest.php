<?php

declare(strict_types=1);

use Apotheke\Core\Config\DsnException;
use Apotheke\Core\Config\DsnTranslator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/Core/Config/DsnTranslator.php';

final class DsnTranslatorTest extends TestCase
{
    private DsnTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new DsnTranslator();
    }

    public function testSqliteAbsoluteRelativeAndMemory(): void
    {
        $absolute = $this->translator->fromPdoDsn('sqlite:/var/lib/catalog.sqlite', ['driver' => 'sqlite']);
        $this->assertSame('SQLite3', $absolute['DBDriver']);
        $this->assertSame('/var/lib/catalog.sqlite', $absolute['database']);
        $this->assertTrue($absolute['foreignKeys']);
        $this->assertSame(5000, $absolute['busyTimeout']);
        $this->assertSame(1, $absolute['synchronous']);

        $relative = $this->translator->translate(['driver' => 'sqlite', 'sqlite_path' => 'data/catalog.sqlite']);
        $this->assertSame('data/catalog.sqlite', $relative['database']);

        $memory = $this->translator->fromPdoDsn('sqlite::memory:', []);
        $this->assertSame(':memory:', $memory['database']);
    }

    public function testPgsqlHostPortDbnameAndSslmode(): void
    {
        $group = $this->translator->fromPdoDsn(
            'pgsql:host=db.example;port=5432;dbname=apotheke;sslmode=require;application_name=apotheke',
            ['user' => 'apotheke', 'password' => 'secret', 'schema' => 'catalog']
        );
        $this->assertSame('Postgre', $group['DBDriver']);
        $this->assertSame('db.example', $group['hostname']);
        $this->assertSame(5432, $group['port']);
        $this->assertSame('apotheke', $group['database']);
        $this->assertSame('require', $group['sslmode']);
        $this->assertSame('catalog', $group['schema']);
        $this->assertStringContainsString("application_name='apotheke'", (string)$group['DSN']);
        $this->assertStringContainsString("host='db.example'", (string)$group['DSN']);
        $this->assertStringContainsString("password='secret'", (string)$group['DSN']);
    }

    public function testPgsqlQuotesEveryValueForLibpq(): void
    {
        $passwords = [
            'a\\b' => "password='a\\\\b'",
            'trail\\' => "password='trail\\\\'",
            "o'clock" => "password='o\\'clock'",
            'has space' => "password='has space'",
            'a=b' => "password='a=b'",
        ];
        foreach ($passwords as $password => $fragment) {
            $group = $this->translator->fromPdoDsn(
                "pgsql:host=db.example;port=5432;dbname=apotheke;application_name='my app'",
                ['user' => 'apotheke', 'password' => $password]
            );
            $dsn = (string)$group['DSN'];
            $this->assertStringContainsString($fragment, $dsn, $password);
            $this->assertStringContainsString("host='db.example'", $dsn);
            $this->assertStringContainsString("port='5432'", $dsn);
            $this->assertStringContainsString("dbname='apotheke'", $dsn);
            $this->assertStringContainsString("user='apotheke'", $dsn);
            $this->assertStringContainsString("application_name='my app'", $dsn);
            $this->assertStringNotContainsString('host=db.example', str_replace("host='db.example'", '', $dsn));
        }

        $user = $this->translator->fromPdoDsn(
            'pgsql:host=db.example;port=5432;dbname=apotheke',
            ['user' => "o'brien\\ops", 'password' => 'x']
        );
        $this->assertStringContainsString("user='o\\'brien\\\\ops'", (string)$user['DSN']);
    }

    public function testSpacedPgsqlKeysReachTheCi4Group(): void
    {
        $dsn = 'pgsql:host = db.example;port = 5432;dbname = catalog;password = s9d44';
        $group = $this->translator->fromPdoDsn($dsn, ['user' => 'apotheke', 'password' => '']);
        $this->assertSame('db.example', $group['hostname']);
        $this->assertSame(5432, $group['port']);
        $this->assertSame('catalog', $group['database']);
        $this->assertStringContainsString("host='db.example'", (string)$group['DSN']);
        // DB_PASSWORD (empty here) wins over the DSN's spaced password, as legacy on PHP 8.3.
        $this->assertSame('', $group['password']);
        $this->assertStringNotContainsString('s9d44', (string)$group['DSN']);

        $trailing = $this->translator->fromPdoDsn(
            'pgsql:host = db.example;port = 5432 ;dbname = catalog ;application_name = app1 ',
            []
        );
        $this->assertSame('catalog', $trailing['database']);
        $this->assertStringContainsString("application_name='app1' ", (string)$trailing['DSN']);

        $log = DsnTranslator::logLine('pgsql', $dsn);
        $this->assertSame('Database pgsql host=db.example port=5432 dbname=catalog', $log);
        $this->assertStringNotContainsString('s9d44', $log);
        $this->assertStringNotContainsString('password', $log);
    }

    /** PDO turns ";" into a space; libpq ends an unquoted value at whitespace and unquotes a quoted one (QA #53 L-D). */
    public function testValuesAreReadTheWayLibpqReadsThem(): void
    {
        $base = 'pgsql:host=db.example;port=5432;dbname=catalog;';
        $values = [
            'application_name=PwA1 ;sslmode=disable' => 'PwA1',
            'application_name = PwA1 ' => 'PwA1',
            "application_name='Pw A1'" => 'Pw A1',
            "application_name='it\\'s;x'" => "it's x",
            'application_name=a\\ b' => 'a b',
            'application_name=PwA1;application_name = PwA2' => 'PwA2',
        ];
        foreach ($values as $tail => $expected) {
            $group = $this->translator->fromPdoDsn($base . $tail, []);
            $this->assertStringContainsString($this->libpqValue('application_name', $expected) . ' ', (string)$group['DSN'], $tail);
            $this->assertSame(1, substr_count((string)$group['DSN'], 'application_name='), $tail);
        }

        $sslmode = $this->translator->fromPdoDsn($base . 'password=PwA1 ;sslmode=disable', []);
        $this->assertSame('disable', $sslmode['sslmode']);

        $spaced = $this->translator->fromPdoDsn('pgsql:host = db.example ; port = 5432 ; dbname = catalog ;', []);
        $this->assertSame('db.example', $spaced['hostname']);
        $this->assertSame(5432, $spaced['port']);
        $this->assertSame('catalog', $spaced['database']);

        $quoted = $this->translator->fromPdoDsn("pgsql:host='db.example';port='5432';dbname='cat alog'", []);
        $this->assertSame('cat alog', $quoted['database']);

        // libpq rejects these as syntax errors, so boot stops on them too.
        $this->expectBad($base . 'password=Pw A1');
        $this->expectBad($base . "password='PwA1");
        $this->expectBad($base . '=PwA1');
        $this->expectBad($base . 'password');
    }

    public function testDbUserWinsOverASpacedDsnUser(): void
    {
        $group = $this->translator->fromPdoDsn(
            'pgsql:host=db.example;port=5432;dbname=catalog;user = zzu',
            ['user' => 'apotheke', 'password' => 'x']
        );
        $this->assertSame('apotheke', $group['username']);
        $this->assertStringContainsString("user='apotheke'", (string)$group['DSN']);
        $this->assertStringNotContainsString('zzu', (string)$group['DSN']);
    }

    public function testDbPasswordIsQuotedInTheConnectStringAndTheDsnPasswordIsDropped(): void
    {
        $group = $this->translator->fromPdoDsn(
            "pgsql:host=db.example;port=5432;dbname=apotheke;user=other;password=dsn'pw",
            ['user' => 'apotheke', 'password' => "s'ecret"]
        );
        $this->assertSame("s'ecret", $group['password']);
        $this->assertStringContainsString("password='s\\'ecret'", (string)$group['DSN']);
        $this->assertStringNotContainsString('dsn', (string)$group['DSN']);
        $this->assertStringNotContainsString('other', (string)$group['DSN']);
    }

    /**
     * Golden cases from legacy db_connect() on PHP 8.3.36 against PostgreSQL 17:
     * DB_USER and DB_PASSWORD always win, the default user and an empty password
     * included. The pg_connect string and the PDO arguments carry exactly them.
     */
    public function testCredentialsFollowLegacyOnPhp83(): void
    {
        $base = 'pgsql:host=db.example;port=5432;dbname=catalog';
        $cases = [
            'a DSN only (defaults)' => [$base . ';user=dsnu;password=dsnp', ['user' => 'apotheke', 'password' => ''], 'apotheke', '', true],
            'b env only' => [$base, ['user' => 'envu', 'password' => 'envp'], 'envu', 'envp', false],
            'c both, differing' => [$base . ';user=dsnu;password=dsnp', ['user' => 'envu', 'password' => 'envp'], 'envu', 'envp', true],
            'd both, empty env password' => [$base . ';user = dsnu;password = dsnp', ['user' => 'envu', 'password' => ''], 'envu', '', true],
            'same values' => [$base . ';user=envu;password=envp', ['user' => 'envu', 'password' => 'envp'], 'envu', 'envp', false],
        ];
        foreach ($cases as $name => [$dsn, $db, $user, $password, $differs]) {
            $group = $this->translator->fromPdoDsn($dsn, $db);
            $this->assertSame($user, $group['username'], $name);
            $this->assertSame($password, $group['password'], $name);
            $this->assertStringEndsWith($this->libpqValue('user', $user) . ' ' . $this->libpqValue('password', $password), (string)$group['DSN'], $name);
            $this->assertSame(1, substr_count((string)$group['DSN'], 'user='), $name);
            $this->assertSame(1, substr_count((string)$group['DSN'], 'password='), $name);
            $this->assertStringNotContainsString('dsn', (string)$group['DSN'], $name);

            [$pdoDsn, $pdoUser, $pdoPassword, $pdoDiffers] = DsnTranslator::forPdo($dsn, $db);
            $this->assertSame("pgsql:host='db.example' port='5432' dbname='catalog'", $pdoDsn, $name);
            $this->assertSame([$user, $password, $differs], [$pdoUser, $pdoPassword, $pdoDiffers], $name);
        }

        $this->assertSame(['sqlite:/x.sqlite', 'u', 'p', false], DsnTranslator::forPdo('sqlite:/x.sqlite', ['user' => 'u', 'password' => 'p']));
        $this->assertSame(["pgsql:host='unclosed", 'u', '', false], DsnTranslator::forPdo("pgsql:host='unclosed", ['user' => 'u']));
    }

    public function testMysqlEmptySqliteAndMissingDbnameFailAtTranslation(): void
    {
        $this->expectBad('mysql:host=localhost;dbname=app;password=s3cret');
        $this->expectBad('sqlite:');
        $this->expectBad('pgsql:host=db.example;port=5432');
        $this->expectBad('pgsql:');
    }

    public function testBootMessageHasNoDsnParts(): void
    {
        try {
            $this->translator->fromPdoDsn('mysql:host=localhost;password=s3cret;dbname=app', []);
            $this->fail('expected DsnException');
        } catch (DsnException $e) {
            $this->assertSame(DsnException::PUBLIC_MESSAGE, $e->getMessage());
            $this->assertStringNotContainsString('s3cret', $e->getMessage());
            $this->assertStringNotContainsString('localhost', $e->getMessage());
            $this->assertStringNotContainsString('mysql:', $e->getMessage());
            $log = DsnTranslator::logLine('pgsql', $e->dsn);
            $this->assertSame('Database pgsql host=localhost dbname=app', $log);
            $this->assertStringNotContainsString('s3cret', $log);
        }
    }

    private function libpqValue(string $key, string $value): string
    {
        return $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    /**
     * Only when another setting mentions user= or password= are the resolved
     * user and password written into the PDO DSN as escaped top-level keys. PDO
     * turns every ";" into a space, so a credential with ";" is passed to PDO
     * instead, and refused only when PHP 8.4's PDO would ignore it (the DSN has
     * the matching user= or password= text). The message names no values.
     */
    public function testCredentialsAreEmbeddedOnlyWhenNeededAndAnUnpassableSemicolonIsRefused(): void
    {
        $base = 'pgsql:host=db.example;port=5432;dbname=catalog;user = dsnu;password = dsnp;';
        $tails = [
            'options' => "options='-c search_path=user=q7canary'",
            'application_name' => "application_name='x user=q7canary password=q7canary'",
            'sslpassword' => 'sslpassword=q7canary',
        ];
        foreach ($tails as $label => $tail) {
            [$pdoDsn, $user, $password] = DsnTranslator::forPdo($base . $tail, ['user' => "e'u\\", 'password' => 'p w']);
            $this->assertStringEndsWith(" user='e\\'u\\\\' password='p w'", $pdoDsn, $label);
            $this->assertSame([null, null], [$user, $password], $label);
            $this->assertSame("e'u\\", $this->translator->fromPdoDsn($base . $tail, ['user' => "e'u\\", 'password' => 'p w'])['username'], $label);
        }

        // A ";" credential goes to PDO, unless the DSN has the text that makes PHP 8.4 ignore it.
        $refused = [
            ['options', 'zu;7k', 'zp6k'], ['application_name', 'zu7k', 'zp;6k'], ['application_name', 'zu;7k', 'zp6k'], ['sslpassword', 'zu7k', 'zp;6k'],
        ];
        foreach ($refused as [$label, $envUser, $envPassword]) {
            foreach (['translator', 'forPdo'] as $path) {
                try {
                    if ($path === 'translator') {
                        $this->translator->fromPdoDsn($base . $tails[$label], ['user' => $envUser, 'password' => $envPassword]);
                    } else {
                        DsnTranslator::forPdo($base . $tails[$label], ['user' => $envUser, 'password' => $envPassword]);
                    }
                    $this->fail($path . ' accepted ' . $label);
                } catch (DsnException $e) {
                    $this->assertSame(DsnException::SEMICOLON_CREDENTIAL, $e->getMessage(), $path . ' ' . $label);
                    foreach (['q7canary', 'dsnu', 'dsnp', $envUser, $envPassword, 'db.example'] as $value) {
                        $this->assertStringNotContainsString($value, $e->getMessage(), $path . ' ' . $label);
                    }
                }
            }
        }
        $this->assertSame(
            ["pgsql:host='db.example' port='5432' dbname='catalog' options='-c search_path=user=q7canary' user='u'", null, 'p;1', true],
            DsnTranslator::forPdo($base . $tails['options'], ['user' => 'u', 'password' => 'p;1'])
        );
        $this->assertSame(
            ["pgsql:host='db.example' port='5432' dbname='catalog' sslpassword='q7canary' password='p'", 'u;1', null, true],
            DsnTranslator::forPdo($base . $tails['sslpassword'], ['user' => 'u;1', 'password' => 'p'])
        );
        // A plain DSN needs no embedding: the credentials, ";" included, go to PDO as 56f4efb did.
        $this->assertSame(
            ["pgsql:host='db.example' port='5432' dbname='catalog'", 'u;1', 'p;1', true],
            DsnTranslator::forPdo('pgsql:host=db.example;port=5432;dbname=catalog;user=x', ['user' => 'u;1', 'password' => 'p;1'])
        );
    }

    private function expectBad(string $dsn): void
    {
        try {
            $this->translator->fromPdoDsn($dsn, []);
            $this->fail('expected DsnException for ' . $dsn);
        } catch (DsnException) {
            $this->addToAssertionCount(1);
        }
    }
}
