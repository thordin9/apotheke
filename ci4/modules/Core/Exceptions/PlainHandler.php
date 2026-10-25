<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use Apotheke\Core\Http\CsrfCheck;
use Apotheke\Core\Http\ErrorPage;
use Apotheke\Core\Http\SecurityPolicy;
use Apotheke\Core\Http\WantsJson;
use Closure;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * Legacy's catch-all in public/index.php. UserError keeps its status and
 * message; an unknown page is 404 "Page not found."; anything else is 500
 * "Something went wrong on the server." (logged). A fetch (WantsJson) gets
 * {ok:false,error} for a UserError or a 500, as legacy json_out(); everything
 * else gets the app's error page (Layout, through $page). A path the router
 * refuses (control characters, `;`) is a plain 400 "Bad request" and is not
 * logged. Without $page (spark, unit tests) the answers are text only.
 * The response skips the global after filters, so the security headers are
 * set here, as legacy view_error() pages have them (SEC-N8).
 */
final class PlainHandler implements ExceptionHandlerInterface
{
    /** @param Closure(): ErrorPage|null $page */
    public function __construct(private readonly ?Closure $page = null)
    {
    }

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
            $server = $request instanceof IncomingRequest ? $request->getServer() : [];
            $this->render($exception, $response, is_array($server) ? $server : [])->send();
        } else {
            fwrite(STDERR, $body . "\n");
        }
        exit($exitCode);
    }

    /**
     * Status, body and headers for $exception, without sending.
     *
     * @param array<string,mixed> $server the request's server values (for WantsJson)
     */
    public function render(Throwable $exception, ResponseInterface $response, array $server = []): ResponseInterface
    {
        [$status, $body] = $this->status($exception);
        $response->setStatusCode($status, $status === CsrfCheck::STATUS ? CsrfCheck::REASON : '');
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            // A route that already set its own, stricter CSP keeps it (as SecurityHeaders does).
            if ($name === 'Content-Security-Policy' && $response->hasHeader($name)) {
                continue;
            }
            $response->setHeader($name, $value);
        }
        if ($this->page === null || $exception instanceof BadRequestException) {
            return $response->setHeader('Content-Type', 'text/plain; charset=UTF-8')->setBody($body);
        }
        $json = !$exception instanceof PageNotFoundException && (new WantsJson())->check($server);
        if ($json) {
            return $response->setHeader('Content-Type', 'application/json; charset=utf-8')
                ->removeHeader('Cache-Control')->setHeader('Cache-Control', 'no-store')
                ->setBody((string)json_encode(['ok' => false, 'error' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $message = match (true) {
            $exception instanceof UserError => $body,
            $exception instanceof PageNotFoundException => 'Page not found.',
            default => 'Something went wrong on the server.',
        };

        return $response->setHeader('Content-Type', 'text/html; charset=UTF-8')->setBody(($this->page)()->html($status, $message));
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
        if ($exception instanceof BadRequestException) {
            return [400, 'Bad request'];
        }

        return [500, 'Server error'];
    }
}
