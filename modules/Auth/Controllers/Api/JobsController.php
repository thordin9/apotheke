<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers\Api;

use Apotheke\Auth\PermissionCheck;
use Apotheke\Core\Http\ApiResponse;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * GET /api/v1/jobs/{id} (API plan §3): a background job's status, progress and result. Only for a user with the
 * permission of the job's type (Services::jobPermissions()) who started it or is an admin; anyone else, and an id
 * that is not there, gets the same 404, so a job's existence is not given away.
 */
final class JobsController extends Controller
{
    public function show(string $id): ResponseInterface
    {
        $job = Services::jobQueue()->find((int)$id);
        $user = Services::currentUser();
        $me = $user->resolve();
        $need = $job === null ? null : (Services::jobPermissions()[$job->type] ?? null);
        $mine = $job !== null && $me !== null && !$me['guest'] && $job->actor !== null && $job->actor === $me['username'];
        if ($job === null || $need === null || !(new PermissionCheck(Services::roles()))->allows($me, $need) || !($mine || $user->admits($me))) {
            return ApiResponse::problem($this->response, 404, 'not_found', 'Job not found.');
        }

        return ApiResponse::json($this->response, $job->view());
    }
}
