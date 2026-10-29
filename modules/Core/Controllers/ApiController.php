<?php

declare(strict_types=1);

namespace Apotheke\Core\Controllers;

use Apotheke\Core\Http\ApiResponse;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/** /api/v1/openapi.json, and the /api paths no endpoint answers: 404 problem+json (API plan §3). */
final class ApiController extends Controller
{
    /** The hand-written OpenAPI document; the contract tests keep it in step with the answers. */
    public const SPEC = __DIR__ . '/../Api/openapi.json';

    public function openapi(): ResponseInterface
    {
        return $this->response->setStatusCode(200)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setBody((string)file_get_contents(self::SPEC));
    }

    public function notFound(): ResponseInterface
    {
        return ApiResponse::problem($this->response, 404, 'not_found', 'No such API endpoint.');
    }
}
