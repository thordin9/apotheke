<?php

declare(strict_types=1);

namespace Apotheke\Core\Log;

use CodeIgniter\Log\Handlers\FileHandler;

/**
 * CI4's file log without the row detail of a database error (#97 N-33): a
 * PostgreSQL constraint failure carries "DETAIL:  Failing row contains (…)"
 * or "Key (…)=(…)", which can hold a stored value. Everything from DETAIL:
 * (any case) to the driver's last "in <file> on line <n>." (or the end) is
 * dropped.
 */
final class RedactingFileHandler extends FileHandler
{
    public function handle($level, $message): bool
    {
        return parent::handle($level, self::strip((string)$message));
    }

    public static function strip(string $message): string
    {
        // To the last driver line, so a value that contains one is still dropped whole.
        return (string)preg_replace('/\bDETAIL:(?:.*(?=\nin \S[^\n]* on line \d+\.)|.*)/is', 'DETAIL: (removed)', $message);
    }
}
