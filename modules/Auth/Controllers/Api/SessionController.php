<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers\Api;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\Controllers\AuthPages;
use Apotheke\Auth\PermissionCheck;
use Apotheke\Auth\Permissions;
use Apotheke\Auth\RateLimiter;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\ApiResponse;
use Apotheke\Core\Http\SessionGate;
use Apotheke\Core\Http\SessionToken;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * /api/v1/session and /api/v1/me (API plan §2.1, §3). Sign-in and sign-out
 * use the same code as /login and /logout; the CSRF check is the api's
 * (ApiCsrfFilter), so a client reads the token from GET /api/v1/session
 * first.
 */
final class SessionController extends AuthPages
{
    /** GET /api/v1/session: who is signed in, what they may do, and the CSRF token. */
    public function show(): ResponseInterface
    {
        $this->firstAdmin();

        return ApiResponse::json($this->response, $this->state());
    }

    /** POST /api/v1/session {username, password}: sign in. */
    public function create(): ResponseInterface
    {
        // A failed sign-in answers at a fixed deadline from here (Auth\SignInPace).
        $started = $this->accounts()->startSignIn();
        $this->firstAdmin();
        if (!$this->settings()->signInEnabled()) {
            return ApiResponse::problem($this->response, 409, 'auth.sign_in_off', 'Sign-in is turned off on this server.');
        }
        try {
            $body = json_decode((string)$this->request->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ApiResponse::problem($this->response, 400, 'bad_request', 'The body is not valid JSON.');
        }
        $body = is_array($body) ? $body : [];
        $errors = [];
        foreach (['username', 'password'] as $field) {
            if (!is_string($body[$field] ?? null) || $body[$field] === '') {
                $errors[$field] = 'Required.';
            }
        }
        if ($errors !== []) {
            return ApiResponse::problem($this->response, 422, 'validation.failed', 'Enter a user name and password.', $errors);
        }
        $raw = (string)$body['username'];
        $who = \Apotheke\Core\Libraries\Text::cleanLine($raw, Accounts::USERNAME_MAX * 2);
        try {
            if ($this->attemptSignIn($started, $raw, $who, (string)$body['password']) === null) {
                return ApiResponse::problem($this->response, 401, 'auth.invalid_credentials', 'Wrong user name or password.');
            }
        } catch (UserError $e) {
            if ($e->getCode() === 429) {
                return ApiResponse::problem($this->response, 429, 'rate_limited', $e->getMessage(), [], RateLimiter::LIMITS['login'][1]);
            }

            return ApiResponse::problem($this->response, 403, 'auth.pending_approval', $e->getMessage());
        }

        return ApiResponse::json($this->response, $this->state());
    }

    /** DELETE /api/v1/session: sign out. ApiCsrf already needed this session's token. */
    public function delete(): ResponseInterface
    {
        (new SessionGate(time()))->signOut();

        return $this->response->setStatusCode(204)->setBody('');
    }

    /** GET /api/v1/me: your own account (permission self). */
    public function account(): ResponseInterface
    {
        $me = $this->me();
        $me = is_array($me) ? $me : [];

        return ApiResponse::json($this->response, [
            'id' => $me['id'] ?? null,
            'username' => $me['username'] ?? '',
            'role' => $me['role'] ?? '',
            'permissions' => $this->permissions($me === [] ? null : $me),
        ]);
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $me = $this->me();
        $signedIn = $me !== null && !$me['guest'];
        $settings = $this->settings();

        return [
            'signed_in' => $signedIn,
            'sign_in_enabled' => $settings->signInEnabled(),
            'registration_open' => $settings->signInEnabled() && !$settings->get('auth_register_disabled'),
            'user' => $signedIn ? ['id' => $me['id'], 'username' => $me['username'], 'role' => $me['role']] : null,
            'permissions' => $this->permissions($me),
            'csrf_token' => (new SessionToken(time()))->get(),
        ];
    }

    /**
     * @param array{role:string,guest:bool,anon:bool}|null $me
     * @return list<string>
     */
    private function permissions(?array $me): array
    {
        $check = new PermissionCheck($this->roles());

        return array_values(array_filter(array_keys(Permissions::ALL), static fn (string $p): bool => $check->allows($me, $p)));
    }
}
