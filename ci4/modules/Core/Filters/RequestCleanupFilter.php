<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** End of every request: run what Core\Http\RequestCleanup still holds. An exception is covered by the handler. */
final class RequestCleanupFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        service('requestCleanup')->run();

        return null;
    }
}
