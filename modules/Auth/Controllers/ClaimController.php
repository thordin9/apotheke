<?php

declare(strict_types=1);

namespace Apotheke\Auth\Controllers;

use Apotheke\Auth\Accounts;
use Apotheke\Auth\FirstAdminClaim;
use Apotheke\Auth\SessionEpoch;
use Apotheke\Auth\Views\AuthViews;
use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\ErrorPage;
use Apotheke\Core\Http\SessionGate;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * /claim: the first-admin claim of a hosted installation (operator console C1). The link carries the token after
 * `#`, which app.js copies into the form. 404 without APOTHEKE_HOSTED. Like /register the route is `none` and skips
 * the global CSRF filter, so the page answers 404 before any token check; a POST checks the token here.
 */
final class ClaimController extends AuthPages
{
    public function claim(): ResponseInterface
    {
        /** @var FirstAdminClaim $claim */
        $claim = service('firstAdminClaim');
        if (!$claim->enabled()) {
            /** @var ErrorPage $error */
            $error = service('errorPage');

            return $this->page($error->html(404, 'Page not found.'), 404);
        }
        if (!$this->posted()) {
            return $this->page($this->claimPage(null, ''), 200, true);
        }
        $this->checkCsrf();
        $who = \Apotheke\Core\Libraries\Text::cleanLine($this->post('username'), Accounts::USERNAME_MAX * 2);
        try {
            $this->limiter()->hit('register');
            $user = $claim->claim($this->post('token'), str_contains($this->post('username'), "\0") ? $this->post('username') : $who, $this->post('new'), $this->post('confirm'));
        } catch (UserError $e) {
            $code = (int)$e->getCode();

            return $this->page($this->claimPage($e->getMessage(), $who), $code !== 0 ? $code : 422, true);
        }
        (new SessionGate(time()))->signIn($user['id'], (new SessionEpoch())->of($user));

        return $this->redirectTo('/?done=welcome');
    }

    private function claimPage(?string $error, string $username): string
    {
        return AuthViews::claim($this->layout(), [
            'appName' => (string)$this->settings()->get('app_name'),
            'usernameMax' => Accounts::USERNAME_MAX,
            'passwordMin' => Accounts::PASSWORD_MIN,
        ], $error, $username);
    }
}
