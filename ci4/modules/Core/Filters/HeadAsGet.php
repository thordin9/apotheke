<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\Request;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** HEAD is a GET for routing. Required filters ignore a returned request, so this mutates it. */
final class HeadAsGet implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        if (strtoupper($request->getMethod()) === 'HEAD' && $request instanceof Request) {
            $request->setMethod('GET');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
