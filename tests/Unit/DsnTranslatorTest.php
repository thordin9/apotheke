<?php

declare(strict_types=1);

use Apotheke\Core\Config\DsnException;
use Apotheke\Core\Config\DsnTranslator;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/ci4/modules/Core/Config/DsnTranslator.php';

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
                'pgsql:host=db.example;port=5432;dbname=apotheke;application_name=my app',
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

    public function testPgsqlPasswordFromTheDsnIsQuotedInTheConnectString(): void
    {
        $group = $this->translator->fromPdoDsn(
            "pgsql:host=db.example;port=5432;dbname=apotheke;user=apotheke;password=s'ecret",
            []
        );
        $this->assertSame("s'ecret", $group['password']);
        $this->assertStringContainsString("password='s\\'ecret'", (string)$group['DSN']);
    }

    public function testMysqlEmptySqliteAndMissingDbnameFailAtTranslation(): void
    {
        $this->expectBad('mysql:host=localhost;dbname=app;password=s3cret');
        $this->expectBad('sqlite:');
        $this->expectBad('pgsql:host=db.example;port=5432');
        $this->expectBad('pgsql:');
    }

    public function testPasswordIsRedactedInTheBootMessage(): void
    {
        try {
            $this->translator->fromPdoDsn('mysql:host=localhost;password=s3cret;dbname=app', []);
            $this->fail('expected DsnException');
        } catch (DsnException $e) {
            $this->assertStringContainsString('password=***', $e->getMessage());
            $this->assertStringNotContainsString('s3cret', $e->getMessage());
            $this->assertStringContainsString('sqlite:<path>', $e->getMessage());
        }
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
