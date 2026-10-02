<?php

namespace App\Controllers;

/**
 * Item list (view only). Data comes from Zoho Books via the API & local cache.
 */
class Items extends BaseController
{
    public function index(): string
    {
        return view('items/index', ['title' => 'Items', 'active' => 'items']);
    }
}
