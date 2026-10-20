<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Http\SecurityPolicy;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** The four headers legacy sends after boot. Not applied to an originals 404. */
final class SecurityHeaders implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ResponseInterface
    {
        foreach ((new SecurityPolicy())->headers() as $name => $value) {
            $response->setHeader($name, $value);
        }

        return $response;
    }
}
