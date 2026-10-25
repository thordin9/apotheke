<?php

declare(strict_types=1);

namespace Apotheke\Layout;

use Apotheke\Core\Http\ErrorPage as ErrorPageContract;
use Closure;
use Throwable;

/**
 * Legacy view_error(): the message on the app's page, which never starts a
 * session. When the page cannot be drawn (the settings cannot be read, say
 * while the database is down), legacy's plain page with the same message;
 * only the exception's class is logged, never its text.
 */
final class ErrorPage implements ErrorPageContract
{
    public const FALLBACK_PREFIX = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Error</title></head><body><p>';

    /**
     * @param Closure(): Page $page this request's page shell, built on first use
     * @param Closure(string): void|null $log
     */
    public function __construct(private readonly Closure $page, private readonly ?Closure $log = null)
    {
    }

    public function html(int $status, string $message): string
    {
        try {
            $page = ($this->page)();

            return $page->start('Error', '', false)
                . "<main class=\"wrap narrow login\">\n"
                . '  <div class="empty">' . Icons::svg('box') . '<p>' . Page::e($message) . '</p><a class="btn btn-primary" href="/">Back to catalog</a></div>' . "\n"
                . "</main>\n"
                . $page->end();
        } catch (Throwable $e) {
            ($this->log ?? static function (string $line): void {
                error_log($line);
            })('[apotheke] error page: ' . $e::class);

            return self::FALLBACK_PREFIX . Page::e($message) . '</p></body></html>';
        }
    }
}
