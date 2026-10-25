<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Exceptions\PlainHandler;
use Apotheke\Core\Exceptions\UserError;
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
}
