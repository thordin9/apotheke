<?php

declare(strict_types=1);

namespace Tests\Core;

use Apotheke\Core\Exceptions\PlainHandler;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\SecurityPolicy;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/** SEC-N8: 404, 500 and UserError responses carry the security headers. */
final class PlainHandlerTest extends CIUnitTestCase
{
    /** @return iterable<string,array{0:Throwable,1:int,2:string}> */
    public static function errors(): iterable
    {
        yield '404' => [PageNotFoundException::forPageNotFound(), 404, 'Not found'];
        yield '500' => [new RuntimeException('internal detail'), 500, 'Server error'];
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

    public function testAStricterRouteCspIsKept(): void
    {
        $response = new Response(config(App::class));
        $response->setHeader('Content-Security-Policy', "default-src 'none'");
        $out = (new PlainHandler())->render(new RuntimeException('x'), $response);
        $this->assertSame("default-src 'none'", $out->getHeaderLine('Content-Security-Policy'));
        $this->assertSame('nosniff', $out->getHeaderLine('X-Content-Type-Options'));
    }
}
