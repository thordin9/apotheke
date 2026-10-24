<?php

declare(strict_types=1);

use Apotheke\Core\Http\SessionToken;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Test only (tests/ci4-router.php, APOTHEKE_TEST_SESSION_ON_DEMAND=1): asks for
 * the CSRF token as a page with a form will, which starts the session on demand.
 * Health and branding requests stay without a session, as SessionCookie skips them.
 */
final class ApothekeTestSessionOnDemand implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        $path = strtolower(trim($request->getUri()->getPath(), '/'));
        if (in_array($path, ['healthz', 'health', 'theme.css', 'labels.css', 'manifest.webmanifest', 'favicon.ico'], true) || str_starts_with($path, 'brand/')) {
            return null;
        }
        (new SessionToken(time()))->get();

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
