<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Exception;

/**
 * Read-only JSON API for the pages, backed by Zoho Books through the local cache.
 * There are deliberately no write routes for customers or items: the end user can only view them.
 *
 *   GET api/customers           -> all active customers (cached)
 *   GET api/customers/{id}      -> one customer incl. address (cached per customer)
 *   GET api/items               -> all active items (cached)
 *   GET api/items/{id}          -> one item
 *   GET api/supplier            -> supplier / payment / bank details printed on invoices (Zoho, .env fallback)
 *   add ?refresh=1 to any call  -> ignore the cache and re-fetch from Zoho
 *
 * Responses: {"data": ...} on success, {"error": "..."} with 4xx/5xx on failure.
 */
class Api extends BaseController
{
    public function customers(?string $id = null): ResponseInterface
    {
        try {
            $zoho = service('zohoBooks');
            if ($id !== null) {
                return $this->respond(['data' => $zoho->getCustomer($id, $this->refresh())]);
            }

            return $this->respond(['data' => $zoho->getCustomers($this->refresh())]);
        } catch (Exception $e) {
            return $this->respond(['error' => $e->getMessage()], 502);
        }
    }

    public function items(?string $id = null): ResponseInterface
    {
        try {
            $items = service('zohoBooks')->getItems($this->refresh());
        } catch (Exception $e) {
            return $this->respond(['error' => $e->getMessage()], 502);
        }
        if ($id === null) {
            return $this->respond(['data' => $items]);
        }

        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $this->respond(['data' => $item]);
            }
        }

        return $this->respond(['error' => "Item {$id} not found"], 404);
    }

    public function supplier(): ResponseInterface
    {
        try {
            return $this->respond(['data' => service('zohoBooks')->getSupplierDetails($this->refresh())]);
        } catch (Exception $e) {
            return $this->respond(['error' => $e->getMessage()], 502);
        }
    }

    private function refresh(): bool
    {
        return ! empty($this->request->getGet('refresh'));
    }

    private function respond(array $payload, int $status = 200): ResponseInterface
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON($payload);
    }
}
