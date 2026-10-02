<?php

namespace App\Controllers;

/**
 * Customer list (view only). Data comes from Zoho Books via the API & local cache.
 */
class Customers extends BaseController
{
    public function index(): string
    {
        return view('customers/index', ['title' => 'Customers', 'active' => 'customers']);
    }
}
