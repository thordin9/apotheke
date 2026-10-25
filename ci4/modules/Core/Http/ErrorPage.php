<?php

declare(strict_types=1);

namespace Apotheke\Core\Http;

/**
 * The HTML page for an error answer (legacy view_error()). Core only names
 * it; the Layout module draws it, so Core filters and the exception handler
 * can answer with the app's page without depending on Layout.
 */
interface ErrorPage
{
    /** A whole HTML document for $status with $message (plain text, escaped by the page). */
    public function html(int $status, string $message): string;
}
