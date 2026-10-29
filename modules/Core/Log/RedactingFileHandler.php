<?php

declare(strict_types=1);

namespace Apotheke\Core\Log;

use CodeIgniter\Log\Handlers\FileHandler;

/**
 * CI4's file log without the row detail of a database error (#97 N-33): a
 * PostgreSQL constraint failure carries "DETAIL:  Failing row contains (…)"
 * or "Key (…)=(…)", which can hold a stored value. Everything from DETAIL:
 * (any case) to the driver's last "in <file> on line <n>." (or the end) is
 * dropped. A localised label (lc_messages, e.g. "DÉTAIL :", #101 N-75) is
 * not matched by name: every line after a failed query's first line, to the
 * driver's last line, is dropped the same way.
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
        $message = (string)preg_replace('/\bDETAIL:(?:.*(?=\nin \S[^\n]* on line \d+\.)|.*)/is', 'DETAIL: (removed)', $message);

        return (string)preg_replace('/(Query failed: [^\n]*)\n(?!in \S[^\n]* on line \d+\.)(?:.*(?=\nin \S[^\n]* on line \d+\.)|.*)/s', "$1\nDETAIL: (removed)", $message);
    }
}
