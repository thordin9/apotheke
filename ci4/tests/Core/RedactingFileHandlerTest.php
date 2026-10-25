<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Log\RedactingFileHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Logger;

/** #97 N-33: the driver's error line reaches the log without the row detail. */
final class RedactingFileHandlerTest extends CIUnitTestCase
{
    public function testTheRowDetailIsDropped(): void
    {
        $this->assertArrayHasKey(RedactingFileHandler::class, config(Logger::class)->handlers, 'the shipped log uses it');
        $dir = sys_get_temp_dir() . '/apotheke-log-' . bin2hex(random_bytes(4)) . '/';
        mkdir($dir);
        try {
            $handler = new RedactingFileHandler(['path' => $dir, 'handles' => ['error'], 'fileExtension' => 'log', 'filePermissions' => 0640]);
            $handler->setDateFormat('Y-m-d');
            $handler->handle('error', "pg_query(): Query failed: ERROR:  duplicate key value violates unique constraint \"k\"\nDETAIL:  Key (name)=(placeholder-secret\nmore) already exists.\nin SYSTEMPATH/Database/Postgre/Connection.php on line 210.\n#0 trace");
            $handler->handle('error', 'ERROR:  x DETAIL:  Failing row contains (placeholder-secret).');
            $text = (string)file_get_contents($dir . 'log-' . date('Y-m-d') . '.log');
            $this->assertStringNotContainsString('placeholder-secret', $text);
            $this->assertStringContainsString("DETAIL: (removed)\nin SYSTEMPATH/Database/Postgre/Connection.php on line 210.\n#0 trace", $text);
            $this->assertStringContainsString('ERROR:  x DETAIL: (removed)', $text);
            $this->assertSame('no detail here', RedactingFileHandler::strip('no detail here'));
        } finally {
            array_map('unlink', glob($dir . '*') ?: []);
            rmdir($dir);
        }
    }

    /** #99 N-53: a value holding the driver's line text, or a lower-case detail:, is still dropped whole. */
    public function testTheStripRunsToTheEndOfTheDetail(): void
    {
        $tail = "\nin SYSTEMPATH/Database/Postgre/Connection.php on line 210.\n#0 trace";
        $this->assertSame(
            'ERROR:  dup' . "\n" . 'DETAIL: (removed)' . $tail,
            RedactingFileHandler::strip("ERROR:  dup\nDETAIL:  Key (name)=(a\nin x on line 1.\nplaceholder-secret) already exists." . $tail),
        );
        $this->assertSame('ERROR:  x DETAIL: (removed)', RedactingFileHandler::strip('ERROR:  x detail:  Failing row contains (placeholder-secret).'));
        $this->assertSame('ERROR:  x DETAIL: (removed)', RedactingFileHandler::strip("ERROR:  x Detail:  (placeholder-secret\nsecond line)"));
    }
}
