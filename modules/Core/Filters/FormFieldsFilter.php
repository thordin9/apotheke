<?php

declare(strict_types=1);

namespace Apotheke\Core\Filters;

use Apotheke\Core\Exceptions\UserError;
use Apotheke\Core\Http\FormFields;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * A page or form write with a field sent as a list it does not take (FormFields) is a 422, as the
 * app's other validation errors: the error page, or {ok:false,error} for fetch. It runs after the
 * sign-in, same-origin and CSRF checks. /api reads JSON and validates it itself.
 */
final class FormFieldsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): null
    {
        if (!$request instanceof IncomingRequest || in_array(strtoupper($request->getMethod()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        $post = $request->getPost();
        $rule = new FormFields();
        $field = is_array($post) ? $rule->invalid($post) : null;
        if ($field !== null) {
            throw new UserError($rule->message($field), FormFields::STATUS);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): null
    {
        return null;
    }
}
