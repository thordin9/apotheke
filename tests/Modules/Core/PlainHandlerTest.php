<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Database\Writes;
use Apotheke\Core\Exceptions\PlainHandler;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\ApiSecurityPolicy;
use Apotheke\Core\Http\CsrfCheck;
use Apotheke\Core\Http\ErrorPage;
use Apotheke\Core\Http\SecurityPolicy;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/** SEC-N8: 400, 404, 500 and UserError responses carry the security headers; text only without a page. */
final class PlainHandlerTest extends CIUnitTestCase
{
    /** @return iterable<string,array{0:Throwable,1:int,2:string}> */
    public static function errors(): iterable
    {
        yield '404' => [PageNotFoundException::forPageNotFound(), 404, 'Not found'];
        yield '500' => [new RuntimeException('internal detail'), 500, 'Server error'];
        yield '400' => [new BadRequestException('The URI you submitted has disallowed characters: "a;b"'), 400, 'Bad request'];
        yield 'UserError' => [new UserError('Name is required.', 422), 422, 'Name is required.'];
        yield 'UserError 419' => [new UserError('Your session expired. Reload the page and try again.', 419), 419, 'Your session expired. Reload the page and try again.'];
        yield 'UserError without status' => [new UserError('Bad input.'), 400, 'Bad input.'];
    }

    #[DataProvider('errors')]
    public function testErrorResponsesCarryTheSecurityHeaders(Throwable $error, int $status, string $body): void
    {
        $response = (new PlainHandler())->render($error, new Response(config(App::class)));
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame($body, $response->getBody());
        $this->assertSame('text/plain; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $this->assertSame($value, $response->getHeaderLine($name), $name);
        }
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
    }

    /**
     * With the app's page wired (web requests): legacy's view_error() pages,
     * or json_out() for a fetch. The exception's own text never reaches the
     * page; a refused path stays the plain 400.
     */
    public function testTheAppsPageOrJsonWhenAPageIsWired(): void
    {
        $seen = [];
        $page = new class ($seen) implements ErrorPage {
            /** @param list<array{int,string}> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function html(int $status, string $message): string
            {
                $this->seen[] = [$status, $message];

                return "<page $status>" . $message;
            }
        };
        $handler = new PlainHandler(static fn (): ErrorPage => $page);
        $cases = [
            [PageNotFoundException::forPageNotFound('internal'), 404, 'Page not found.'],
            [new RuntimeException('internal detail'), 500, 'Something went wrong on the server.'],
            [new UserError('Name is required.', 422), 422, 'Name is required.'],
        ];
        foreach ($cases as [$error, $status, $message]) {
            $html = $handler->render($error, new Response(config(App::class)));
            $this->assertSame([$status, "<page $status>" . $message, 'text/html; charset=UTF-8'], [$html->getStatusCode(), $html->getBody(), $html->getHeaderLine('Content-Type')]);
            $this->assertSame(SecurityPolicy::CSP, $html->getHeaderLine('Content-Security-Policy'));
        }
        $this->assertSame([[404, 'Page not found.'], [500, 'Something went wrong on the server.'], [422, 'Name is required.']], $seen);
        foreach ([['HTTP_X_REQUESTED_WITH' => 'fetch'], ['HTTP_ACCEPT' => 'application/json']] as $server) {
            $json = $handler->render(new RuntimeException('internal detail'), new Response(config(App::class)), $server);
            $this->assertSame([500, '{"ok":false,"error":"Server error"}', 'application/json; charset=utf-8', 'no-store'],
                [$json->getStatusCode(), $json->getBody(), $json->getHeaderLine('Content-Type'), $json->getHeaderLine('Cache-Control')]);
            $user = $handler->render(new UserError('Name is required.', 422), new Response(config(App::class)), $server);
            $this->assertSame('{"ok":false,"error":"Name is required."}', $user->getBody());
            // An unknown page is legacy's HTML page even for a fetch.
            $this->assertSame('<page 404>Page not found.', $handler->render(PageNotFoundException::forPageNotFound(), new Response(config(App::class)), $server)->getBody());
        }
        $bad = $handler->render(new BadRequestException('disallowed "a;b"'), new Response(config(App::class)));
        $this->assertSame([400, 'Bad request', 'text/plain; charset=UTF-8'], [$bad->getStatusCode(), $bad->getBody(), $bad->getHeaderLine('Content-Type')]);
        $this->assertCount(5, $seen);
    }

    public function testAStricterRouteCspIsKept(): void
    {
        $response = new Response(config(App::class));
        $response->setHeader('Content-Security-Policy', "default-src 'none'");
        $out = (new PlainHandler())->render(new RuntimeException('x'), $response);
        $this->assertSame("default-src 'none'", $out->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('nosniff', $out->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * QA L-1142: under /api every uncaught error is problem+json with the API headers, whether the
     * app's page is wired or not (the text-only fallback); a 5xx never shows the exception's text.
     */
    public function testApiPathsGetProblemJson(): void
    {
        $cases = [
            [new RuntimeException('internal detail in /data/secret.php:12'), 500, 'server_error', 'Something went wrong on the server.'],
            [new UserError('internal 500 text', 500), 500, 'server_error', 'Something went wrong on the server.'],
            [PageNotFoundException::forPageNotFound('internal'), 404, 'not_found', 'No such API endpoint.'],
            [new BadRequestException('The URI you submitted has disallowed characters: "a;b"'), 400, 'bad_request', 'Bad request.'],
            [new UserError('Name is required.', 422), 422, 'validation.failed', 'Name is required.'],
            [new UserError('Your session expired.', CsrfCheck::STATUS), 403, 'csrf.invalid', 'Your session expired.'],
            // The hosted refusal keeps its message and a stable code; other 5xx, and a 501 that is not a UserError, stay generic.
            [Writes::unavailable('CSV import'), 501, 'hosted.unavailable', 'CSV import is not available on the hosted database yet.'],
            [new UserError('internal 503 text', 503), 503, 'server_error', 'Something went wrong on the server.'],
            [new UserError('internal 502 text', 502), 502, 'server_error', 'Something went wrong on the server.'],
            [new \CodeIgniter\Database\Exceptions\DatabaseException('internal: no such table uow_guard', 501), 500, 'server_error', 'Something went wrong on the server.'],
            [new RuntimeException('internal D1 bridge text', 501), 500, 'server_error', 'Something went wrong on the server.'],
        ];
        $page = static fn (): ErrorPage => new class () implements ErrorPage {
            public function html(int $status, string $message): string
            {
                return '<page>';
            }
        };
        foreach ([new PlainHandler(), new PlainHandler($page)] as $handler) {
            foreach (['/api', '/api/v1/me?x=1', '/api/v1/%61'] as $uri) {
                foreach ($cases as [$error, $status, $code, $detail]) {
                    $r = $handler->render($error, new Response(config(App::class)), ['REQUEST_URI' => $uri, 'HTTP_ACCEPT' => 'text/html']);
                    $this->assertSame($status, $r->getStatusCode(), $uri . ' ' . $code);
                    $this->assertSame('application/problem+json', $r->getHeaderLine('Content-Type'));
                    $json = json_decode((string)$r->getBody(), true, 8, JSON_THROW_ON_ERROR);
                    $this->assertSame(['type' => 'about:blank', 'title' => $r->getReasonPhrase(), 'status' => $status, 'detail' => $detail, 'code' => $code], $json);
                    $this->assertStringNotContainsString('internal', (string)$r->getBody());
                    foreach ((new ApiSecurityPolicy())->headers(false) as $name => $value) {
                        $this->assertSame($value, $r->getHeaderLine($name), $name);
                    }
                    $this->assertFalse($r->hasHeader('X-Frame-Options'));
                    $this->assertFalse($r->hasHeader('Strict-Transport-Security'));
                }
            }
        }
        $https = (new PlainHandler())->render(new RuntimeException('x'), new Response(config(App::class)), ['REQUEST_URI' => '/api/v1/me', 'HTTPS' => 'on']);
        $this->assertSame('max-age=31536000', $https->getHeaderLine('Strict-Transport-Security'));
    }

    /** QA L-1220: HSTS on an /api error follows an https:// PUBLIC_URL from the config, as the success path does. */
    public function testApiErrorHstsFollowsTheConfiguredPublicUrl(): void
    {
        $hsts = static fn (?\Closure $url): string => (new PlainHandler(null, null, null, $url))
            ->render(new RuntimeException('x'), new Response(config(App::class)), ['REQUEST_URI' => '/api/v1/me', 'HTTP_X_FORWARDED_PROTO' => 'http'])
            ->getHeaderLine('Strict-Transport-Security');
        $this->assertSame('max-age=31536000', $hsts(static fn (): string => 'https://inv.example.test'));
        $this->assertSame('max-age=31536000', $hsts(static fn (): string => 'HTTPS://inv.example.test'));
        $this->assertSame('', $hsts(static fn (): string => 'http://inv.example.test'));
        $this->assertSame('', $hsts(static fn (): string => ''));
        $this->assertSame('', $hsts(null));
        $this->assertSame('', $hsts(static function (): string {
            throw new RuntimeException('config unreadable');
        }), 'a failing lookup does not break the answer');
        $page = (new PlainHandler(null, null, null, static fn (): string => 'https://inv.example.test'))
            ->render(new RuntimeException('x'), new Response(config(App::class)), ['REQUEST_URI' => '/']);
        $this->assertFalse($page->hasHeader('Strict-Transport-Security'), 'pages are unchanged');
        foreach (['/apix', '/', '/x/api/v1'] as $uri) {
            $plain = (new PlainHandler())->render(new RuntimeException('x'), new Response(config(App::class)), ['REQUEST_URI' => $uri]);
            $this->assertSame('text/plain; charset=UTF-8', $plain->getHeaderLine('Content-Type'), $uri);
        }
    }

    /** #97 N-33, N-36: the log lines before an answer: a cleanup failure by class only, a 500 without the row detail. */
    public function testTheLogLinesBeforeAnAnswer(): void
    {
        $lines = [];
        $log = static function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
        $handler = new PlainHandler(null, static function (): void {
            throw new RuntimeException('could not unlink /data/originals/secret-path');
        }, $log);
        $handler->beforeAnswer(new UserError('Name is required.', 422));
        $handler->beforeAnswer(new RuntimeException("pg_query(): Query failed: ERROR:  new row violates check constraint \"c\"\nDETAIL:  Failing row contains (autofill_token, \"placeholder-secret\n2nd line\")."));
        $this->assertSame([
            '[apotheke] request cleanup failed (RuntimeException)',
            '[apotheke] request cleanup failed (RuntimeException)',
            "RuntimeException: pg_query(): Query failed: ERROR:  new row violates check constraint \"c\"\nDETAIL: (removed)",
        ], $lines);
    }

    /**
     * QA-1854: in FrankenPHP worker mode an answered error returns to the worker loop. An exit() would end the worker
     * script, and FrankenPHP restarts it with a failure backoff on a non-zero status: every 404 would cost a boot.
     * (Without the flag handle() exits, so only the worker case can run in-process.) Config\Exceptions sets the flag
     * when public/frankenphp-worker.php defined APOTHEKE_PHP_WORKER, never otherwise.
     */
    public function testInWorkerModeTheHandlerReturnsInsteadOfExiting(): void
    {
        $lines = [];
        $handler = new PlainHandler(null, null, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, null, true);
        // On the CLI the answer is one line on STDERR; drop it here so the suite's own output stays clean.
        if (!\in_array('apotheke.test.drop', stream_get_filters(), true)) {
            stream_filter_register('apotheke.test.drop', DropStream::class);
        }
        $filter = stream_filter_append(STDERR, 'apotheke.test.drop', STREAM_FILTER_WRITE);
        try {
            $handler->handle(new RuntimeException('internal detail'), service('request'), new Response(config(App::class)), 500, EXIT_ERROR);
        } finally {
            if (\is_resource($filter)) {
                stream_filter_remove($filter);
            }
        }
        $this->assertSame(['RuntimeException: internal detail'], $lines, 'answered (and logged), then returned');
        $this->assertFalse(\defined('APOTHEKE_PHP_WORKER'));
        $this->assertInstanceOf(PlainHandler::class, (new \Config\Exceptions())->handler(500, new RuntimeException('x')));
        $flag = new \ReflectionProperty(PlainHandler::class, 'worker');
        $this->assertFalse($flag->getValue((new \Config\Exceptions())->handler(500, new RuntimeException('x'))), 'classic mode still exits');
    }
}

/** A write filter that drops everything (the CLI answer of the worker-mode test above). */
final class DropStream extends \php_user_filter
{
    /**
     * @param resource $in
     * @param resource $out
     * @param int $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
