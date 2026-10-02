<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use Config\Invoice;

/**
 * Single-user login (no registration). Username and password hash come from
 * invoice.appUsername / invoice.appPasswordHash in .env.
 */
class Auth extends BaseController
{
    public function login(): RedirectResponse|string
    {
        if (! empty(session('user'))) {
            return redirect()->to(site_url('invoices'));
        }

        $error = '';
        if ($this->request->is('post')) {
            $config = config(Invoice::class);
            $user   = trim((string) $this->request->getPost('username'));
            $pass   = (string) $this->request->getPost('password');

            if (in_array($config->appUsername, ['', 'REPLACE_ME'], true) || in_array($config->appPasswordHash, ['', 'REPLACE_ME'], true)) {
                $error = 'Login is not set up yet: set invoice.appUsername and invoice.appPasswordHash in .env.';
            } elseif (hash_equals($config->appUsername, $user) && password_verify($pass, $config->appPasswordHash)) {
                session()->regenerate(true);
                session()->set('user', $user);

                return redirect()->to(site_url('invoices'));
            } else {
                usleep(500000); // slow down guessing
                $error = 'Wrong username or password.';
            }
        }

        return view('auth/login', ['error' => $error]);
    }

    public function logout(): RedirectResponse
    {
        session()->destroy();

        return redirect()->to(site_url('login'));
    }
}
