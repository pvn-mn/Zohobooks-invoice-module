<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->addRedirect('/', 'invoices');

// Single-user login
$routes->match(['GET', 'POST'], 'login', 'Auth::login');
$routes->get('logout', 'Auth::logout');

// Pages (login required)
$routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes): void {
    $routes->get('invoices', 'Invoices::index');
    $routes->post('invoices', 'Invoices::save');
    $routes->get('invoices/(:num)', 'Invoices::show/$1');
    $routes->get('invoices/(:num)/edit', 'Invoices::edit/$1');
    $routes->post('invoices/(:num)/resync', 'Invoices::resync/$1');
    $routes->get('customers', 'Customers::index');
    $routes->get('items', 'Items::index');
});

// Read-only JSON API for the pages (login required, 401 JSON when logged out)
$routes->group('api', ['filter' => 'auth:json'], static function (RouteCollection $routes): void {
    $routes->get('customers', 'Api::customers');
    $routes->get('customers/(:num)', 'Api::customers/$1');
    $routes->get('items', 'Api::items');
    $routes->get('items/(:num)', 'Api::items/$1');
});
