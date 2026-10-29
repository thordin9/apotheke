<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Http\ApiResponse;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/** /api/v1 paths no endpoint answers: 404 problem+json (API plan §3). */
final class ApiController extends Controller
{
    public function notFound(): ResponseInterface
    {
        return ApiResponse::problem($this->response, 404, 'not_found', 'No such API endpoint.');
    }
}
