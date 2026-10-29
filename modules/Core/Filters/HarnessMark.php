<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Proves a PHPUnit cell reached CI4 without changing /health bytes.
 * The test server sets APOTHEKE_HARNESS=1. Nothing else does.
 */
final class HarnessMark implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        if (getenv('APOTHEKE_HARNESS') === '1') {
            error_log('apotheke-harness app=ci4');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
