<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** APOTHEKE_APP=ci4 must not hand off when ci4/spark is absent. */
final class MigrateSparkMissingTest extends TestCase
{
    public function testMissingSparkExitsWithAClearMessage(): void
    {
        $root = sys_get_temp_dir() . '/apotheke-no-spark-' . bin2hex(random_bytes(4));
        mkdir($root . '/bin', 0775, true);
        copy(dirname(__DIR__, 2) . '/bin/migrate.php', $root . '/bin/migrate.php');
        $dsn = 'pgsql:host=db.example;password = s9d44;dbname=catalog';
        $proc = proc_open(
            [PHP_BINARY, $root . '/bin/migrate.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            [
                'PATH' => (string)getenv('PATH'),
                'APOTHEKE_APP' => 'ci4',
                'DB_DSN' => $dsn,
                'DB_USER' => 'zzu',
                'DB_PASSWORD' => 's9d44',
            ]
        );
        $this->assertIsResource($proc);
        try {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            $proc = null;
            $text = $out . $err;
            $this->assertSame(1, $code, $text);
            $this->assertSame("APOTHEKE_APP=ci4 is set but ci4/spark is not in this install.\n", $err);
            $this->assertStringNotContainsString('Could not open input file', $text);
            $this->assertStringNotContainsString('s9d44', $text);
            $this->assertStringNotContainsString($dsn, $text);
            $this->assertStringNotContainsString('zzu', $text);
        } finally {
            if (is_resource($proc)) {
                proc_terminate($proc);
                proc_close($proc);
            }
            @unlink($root . '/bin/migrate.php');
            @rmdir($root . '/bin');
            @rmdir($root);
        }
    }
}
