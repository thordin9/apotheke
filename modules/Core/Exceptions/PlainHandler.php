<?php

declare(strict_types=1);

namespace Apotheke\Core\Exceptions;

use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\ApiSecurityPolicy;
use Apotheke\Core\Http\CsrfCheck;
use Apotheke\Core\Http\ErrorPage;
use Apotheke\Core\Http\LegacyPathRouter;
use Apotheke\Core\Http\RequestScheme;
use Apotheke\Core\Http\SecurityPolicy;
use Apotheke\Core\Http\WantsJson;
use Apotheke\Core\Log\RedactingFileHandler;
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
 * Under /api every answer is problem+json with the API headers, $page or
 * not (QA L-1142); a 5xx detail is generic, never the exception's text.
 * The response skips the global after filters, so the security headers are
 * set here, as legacy view_error() pages have them (SEC-N8).
 */
final class PlainHandler implements ExceptionHandlerInterface
{
    /**
     * @param Closure(): ErrorPage|null $page
     * @param Closure(): void|null $cleanup the request's RequestCleanup, run before exit (a fatal error included)
     * @param (Closure(string): void)|null $log error_log() when null
     * @param (Closure(): string)|null $publicUrl PUBLIC_URL from the environment or config.php (never the database),
     *        for the HSTS decision of an /api answer
     */
    public function __construct(
        private readonly ?Closure $page = null,
        private readonly ?Closure $cleanup = null,
        private readonly ?Closure $log = null,
        private readonly ?Closure $publicUrl = null,
    )
    {
    }

    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        $this->beforeAnswer($exception);
        if (PHP_SAPI !== 'cli') {
            $server = $request instanceof IncomingRequest ? $request->getServer() : [];
            $this->render($exception, $response, is_array($server) ? $server : [])->send();
        } else {
            fwrite(STDERR, $this->status($exception)[1] . "\n");
        }
        exit($exitCode);
    }

    /**
     * Run the request's cleanup and log a server error, before the answer
     * goes out. Log lines hold a class, and for a 500 the message without a
     * database error's row detail (N-33); a cleanup failure's message can
     * hold a path, so it is never logged.
     */
    public function beforeAnswer(Throwable $exception): void
    {
        if ($this->cleanup !== null) {
            try {
                ($this->cleanup)();
            } catch (Throwable $e) {
                // The answer still goes out.
                $this->log('[apotheke] request cleanup failed (' . $e::class . ')');
            }
        }
        if ($this->status($exception)[0] === 500 && !$exception instanceof UserError) {
            $this->log($exception::class . ': ' . RedactingFileHandler::strip($exception->getMessage()));
        }
    }

    /**
     * Status, body and headers for $exception, without sending.
     *
     * @param array<string,mixed> $server the request's server values (for WantsJson)
     */
    public function render(Throwable $exception, ResponseInterface $response, array $server = []): ResponseInterface
    {
        $uri = $server['REQUEST_URI'] ?? null;
        if (ApiResponse::isApi(LegacyPathRouter::legacyPath(is_string($uri) ? $uri : '/'))) {
            return $this->problem($exception, $response, $server);
        }
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

    /**
     * The /api answer: RFC 9457 problem+json with a stable code. A UserError's
     * message is written for the user and is kept below 500; anything else gets a
     * fixed detail. 419 (no reason phrase in CI4) is the API's 403 csrf.invalid.
     *
     * @param array<string,mixed> $server
     */
    private function problem(Throwable $exception, ResponseInterface $response, array $server): ResponseInterface
    {
        [$status, $body] = $this->status($exception);
        $status = $status === CsrfCheck::STATUS ? 403 : $status;
        $code = match ($status) {
            400 => 'bad_request',
            401 => 'auth.required',
            403 => $exception instanceof UserError && $exception->getCode() === CsrfCheck::STATUS ? 'csrf.invalid' : 'permission.denied',
            404 => 'not_found',
            413 => 'upload.too_large',
            422 => 'validation.failed',
            429 => 'rate_limited',
            default => $status >= 500 ? 'server_error' : 'request.failed',
        };
        $detail = match (true) {
            $status >= 500 => 'Something went wrong on the server.',
            $exception instanceof UserError => $body,
            $status === 404 => 'No such API endpoint.',
            default => 'Bad request.',
        };
        // HSTS as on the success path (an https:// PUBLIC_URL), read from the config, not the database, which may be
        // what failed (QA L-1220); a direct HTTPS connection counts too.
        $https = (new RequestScheme(false))->isHttps($server) || str_starts_with(strtolower($this->publicUrl()), 'https://');
        foreach ((new ApiSecurityPolicy())->headers($https) as $name => $value) {
            $response->setHeader($name, $value);
        }

        return ApiResponse::problem($response, $status, $code, $detail);
    }

    private function publicUrl(): string
    {
        try {
            return $this->publicUrl !== null ? ($this->publicUrl)() : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function log(string $line): void
    {
        $this->log !== null ? ($this->log)($line) : error_log($line);
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
