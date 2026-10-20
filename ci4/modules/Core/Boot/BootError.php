<?php

declare(strict_types=1);

namespace Apotheke\Core\Boot;

/** A startup failure: one public sentence, the detail only in the error log. */
final class BootError
{
    public static function send(string $public, string $log): never
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo $public;
        }
        error_log($log);
        exit(1);
    }
}
