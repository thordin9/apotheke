<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use Apotheke\Core\Http\SecurityPolicy;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Text only. UserError keeps its status; anything else is 500 "Server error".
 * The response skips the global after filters, so the security headers are set
 * here, as legacy view_error() pages have them (SEC-N8).
 */
final class PlainHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        [$status, $body] = $this->status($exception);
        if ($status === 500 && !$exception instanceof UserError) {
            error_log($exception::class . ': ' . $exception->getMessage());
        }
        if (PHP_SAPI !== 'cli') {
            $this->render($exception, $response)->send();
        } else {
            fwrite(STDERR, $body . "\n");
        }
        exit($exitCode);
    }

    /** Status, body and headers for $exception, without sending. */
    public function render(Throwable $exception, ResponseInterface $response): ResponseInterface
    {
        [$status, $body] = $this->status($exception);
        $response->setStatusCode($status);
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            // A route that already set its own, stricter CSP keeps it (as SecurityHeaders does).
            if ($name === 'Content-Security-Policy' && $response->hasHeader($name)) {
                continue;
            }
            $response->setHeader($name, $value);
        }
        $response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $response->setBody($body);

        return $response;
    }

    /** @return array{0:int,1:string} */
    private function status(Throwable $exception): array
    {
        if ($exception instanceof UserError) {
            $code = $exception->getCode();

            return [($code >= 400 && $code <= 599) ? $code : 400, $exception->getMessage()];
        }
        if ($exception instanceof PageNotFoundException) {
            return [404, 'Not found'];
        }

        return [500, 'Server error'];
    }
}
