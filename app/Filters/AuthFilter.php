<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Login check. Pages redirect to the login form; with the "json" argument
 * (auth:json, used by the API) a 401 JSON error is returned instead.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! empty(session('user'))) {
            return null;
        }

        if (in_array('json', (array) $arguments, true)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['error' => 'Not logged in']);
        }

        helper('url');

        return redirect()->to(site_url('login'));
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
