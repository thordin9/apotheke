<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/** Text only. UserError keeps its status; anything else is 500 "Server error". */
final class PlainHandler implements ExceptionHandlerInterface
{
    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        if ($exception instanceof UserError) {
            $code = $exception->getCode();
            $status = ($code >= 400 && $code <= 599) ? $code : 400;
            $body = $exception->getMessage();
        } elseif ($exception instanceof PageNotFoundException) {
            $status = 404;
            $body = 'Not found';
        } else {
            $status = 500;
            $body = 'Server error';
            error_log($exception::class . ': ' . $exception->getMessage());
        }
        if (PHP_SAPI !== 'cli') {
            $response->setStatusCode($status);
            $response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
            $response->setBody($body);
            $response->send();
        } else {
            fwrite(STDERR, $body . "\n");
        }
        exit($exitCode);
    }
}
