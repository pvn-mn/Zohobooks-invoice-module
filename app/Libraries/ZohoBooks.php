<?php

namespace App\Libraries;

use App\Models\ZbCacheModel;
use Config\Invoice;
use Exception;
use RuntimeException;

/**
 * Zoho Books reads (customers, items, VAT tax) through the local zb_cache table,
 * plus invoice create (POST) and update (PUT).
 */
class ZohoBooks
{
    private bool $itemsRefreshed = false;

    public function __construct(
        private readonly ZohoClient $client,
        private readonly ZbCacheModel $cache,
        private readonly Invoice $config,
    ) {
    }

    public static function ok(array $res): bool
    {
        return $res['status'] >= 200 && $res['status'] < 300 && (($res['data']['code'] ?? 0) == 0);
    }

    public static function error(array $res): string
    {
        return $res['data']['message'] ?? ('HTTP ' . $res['status']);
    }

    public function vatRate(): float
    {
        return $this->config->vatRate;
    }

    // "One shot" fetch: pulls every page (200 records each) in one go
    private function fetchAll(string $endpoint, string $listKey, array $params = []): array
    {
        $all  = [];
        $page = 1;

        do {
            $res = $this->client->request('GET', $endpoint, $params + ['page' => $page, 'per_page' => 200]);
            if (! self::ok($res)) {
                throw new RuntimeException('Zoho ' . $endpoint . ': ' . self::error($res));
            }
            $all  = array_merge($all, $res['data'][$listKey] ?? []);
            $more = ! empty($res['data']['page_context']['has_more_page']);
            $page++;
        } while ($more && $page <= 50);

        return $all;
    }

    // TIN is a custom field (api_name cf_tin_number). It may sit on the contact person,
    // so Zoho returns it as contactperson_cf_tin_number; check every place it can appear.
    private function tinFrom(array $c): string
    {
        $field = $this->config->zohoTinField;

        foreach ([$field, 'contactperson_' . $field] as $k) {
            if (isset($c[$k]) && $c[$k] !== '') {
                return (string) $c[$k];
            }
        }
        foreach (['custom_fields', 'contactperson_custom_fields'] as $k) {
            foreach ((array) ($c[$k] ?? []) as $f) {
                if (($f['api_name'] ?? '') === $field && ($f['value'] ?? '') !== '') {
                    return (string) $f['value'];
                }
            }
        }
        foreach ((array) ($c['contact_persons'] ?? []) as $p) {
            $tin = $this->tinFrom($p);
            if ($tin !== '') {
                return $tin;
            }
        }

        return '';
    }

    private function normCustomer(array $c): array
    {
        return [
            'id'      => (string) $c['contact_id'],
            'name'    => (string) ($c['contact_name'] ?? ''),
            'company' => (string) ($c['company_name'] ?? ''),
            'phone'   => (string) (($c['phone'] ?? '') ?: ($c['mobile'] ?? '')),
            'email'   => (string) ($c['email'] ?? ''),
            'tin'     => $this->tinFrom($c),
        ];
    }

    private static function normItem(array $i): array
    {
        return [
            'id'           => (string) $i['item_id'],
            'name'         => (string) ($i['name'] ?? ($i['item_name'] ?? '')),
            'sku'          => (string) ($i['sku'] ?? ''),
            'unit'         => (string) ($i['unit'] ?? ''),
            'rate'         => (float) ($i['rate'] ?? 0),
            'description'  => (string) ($i['description'] ?? ''),
            'product_type' => (string) ($i['product_type'] ?? ''),
        ];
    }

    public function getCustomers(bool $refresh = false): array
    {
        if (! $refresh && ($cached = $this->cache->getPayload('customers', $this->config->cacheTtl)) !== null) {
            return $cached;
        }

        try {
            $rows = $this->fetchAll('/contacts', 'contacts', ['filter_by' => 'Status.Active']);
        } catch (Exception $e) {
            $stale = $this->cache->getPayload('customers', null); // Zoho unreachable or rate-limited: use last copy
            if ($stale !== null) {
                return $stale;
            }

            throw $e;
        }

        $rows = array_filter($rows, static fn ($c) => ($c['contact_type'] ?? 'customer') === 'customer');
        $list = array_values(array_map(fn ($c) => $this->normCustomer($c), $rows));
        usort($list, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $this->cache->putPayload('customers', $list);

        return $list;
    }

    // Full customer record (the list endpoint has no address), cached per customer
    public function getCustomer(string $id, bool $refresh = false): array
    {
        $key = 'customer:' . $id;
        if (! $refresh && ($cached = $this->cache->getPayload($key, $this->config->cacheTtl)) !== null) {
            return $cached;
        }

        $res = $this->client->request('GET', '/contacts/' . rawurlencode($id));
        if (! self::ok($res)) {
            $stale = $this->cache->getPayload($key, null);
            if ($stale !== null) {
                return $stale;
            }

            throw new RuntimeException('Zoho customer ' . $id . ': ' . self::error($res));
        }
        $c = $res['data']['contact'];
        if (($c['contact_type'] ?? 'customer') !== 'customer') {
            throw new RuntimeException('Contact ' . $id . ' is not a customer.');
        }

        $out = $this->normCustomer($c);
        if ($out['tin'] === '') {
            foreach ($this->getCustomers() as $row) {
                if ($row['id'] === $out['id']) {
                    $out['tin'] = $row['tin'];
                }
            }
        }
        $b     = $c['billing_address'] ?? [];
        $parts = [];

        foreach (['address', 'street2', 'city', 'state', 'zip', 'country'] as $k) {
            if (trim((string) ($b[$k] ?? '')) !== '') {
                $parts[] = trim($b[$k]);
            }
        }
        $out['address'] = implode(', ', $parts);
        $this->cache->putPayload($key, $out);

        return $out;
    }

    public function getItems(bool $refresh = false): array
    {
        if (! $refresh && ($cached = $this->cache->getPayload('items', $this->config->cacheTtl)) !== null) {
            return $cached;
        }

        try {
            $rows = $this->fetchAll('/items', 'items', ['filter_by' => 'Status.Active']);
        } catch (Exception $e) {
            $stale = $this->cache->getPayload('items', null);
            if ($stale !== null) {
                return $stale;
            }

            throw $e;
        }

        $rows = array_filter($rows, static fn ($i) => ! isset($i['can_be_sold']) || $i['can_be_sold']);
        $list = array_values(array_map(self::normItem(...), $rows));
        usort($list, static fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        $this->cache->putPayload('items', $list);

        return $list;
    }

    public function findItem(string $id): ?array
    {
        foreach ($this->getItems() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        if (! $this->itemsRefreshed) { // item may have been added in Zoho after the last cache refresh
            $this->itemsRefreshed = true;

            foreach ($this->getItems(true) as $item) {
                if ($item['id'] === $id) {
                    return $item;
                }
            }
        }

        return null;
    }

    // Zoho's tax_id for the VAT rate, so Zoho calculates the same VAT as the printed invoice
    public function vatTaxId(): ?string
    {
        if ($this->config->zohoVatTaxId !== '') {
            return $this->config->zohoVatTaxId;
        }
        $cached = $this->cache->getPayload('vat_tax', 86400);
        if ($cached !== null) {
            return $cached['id'];
        }

        $res = $this->client->request('GET', '/settings/taxes');
        if (! self::ok($res)) {
            return null; // don't cache a failure
        }
        $id = null;

        foreach ($res['data']['taxes'] ?? [] as $t) {
            if (abs((float) $t['tax_percentage'] - $this->vatRate()) < 0.001) {
                $id = (string) $t['tax_id'];
                break;
            }
        }
        $this->cache->putPayload('vat_tax', ['id' => $id]);

        return $id;
    }

    // --- Invoices ----------------------------------------------------------------

    public static function buildInvoicePayload(string $customerId, string $poNumber, string $invoiceDate, ?string $supplyDate, array $lineItems, array $extra = []): array
    {
        $payload = [
            'customer_id'      => $customerId,
            'date'             => $invoiceDate,
            'reference_number' => $poNumber,
            'line_items'       => array_map(static function ($item) {
                $lineItem = [
                    'item_id'  => $item['item_id'],
                    'quantity' => $item['quantity'],
                ];

                // Optional per-line fields: only sent when they have a value
                foreach (['rate', 'description', 'tax_id'] as $key) {
                    if (isset($item[$key]) && $item[$key] !== '') {
                        $lineItem[$key] = $item[$key];
                    }
                }

                return $lineItem;
            }, $lineItems),
        ];
        if (! empty($supplyDate)) {
            $payload['supply_date'] = $supplyDate;
        }

        return array_merge($payload, $extra);
    }

    /**
     * POST /invoices. Returns Zoho's invoice (includes invoice_id and invoice_number).
     */
    public function createInvoice(string $customerId, string $poNumber, string $invoiceDate, ?string $supplyDate, array $lineItems, array $extra = []): array
    {
        $payload = self::buildInvoicePayload($customerId, $poNumber, $invoiceDate, $supplyDate, $lineItems, $extra);
        $result  = $this->client->request('POST', '/invoices', ['ignore_auto_number_generation' => 'true'], $payload);

        if (! in_array($result['status'], [200, 201], true) || ($result['data']['code'] ?? 0) != 0) {
            throw new RuntimeException('Zoho invoice creation failed: ' . self::error($result));
        }

        return $result['data']['invoice'];
    }

    /**
     * PUT /invoices/{id}. Same payload shape as createInvoice().
     */
    public function updateInvoice(string $invoiceId, string $customerId, string $poNumber, string $invoiceDate, ?string $supplyDate, array $lineItems, array $extra = []): array
    {
        $payload = self::buildInvoicePayload($customerId, $poNumber, $invoiceDate, $supplyDate, $lineItems, $extra);
        $result  = $this->client->request('PUT', '/invoices/' . rawurlencode($invoiceId), ['ignore_auto_number_generation' => 'true'], $payload);

        if ($result['status'] !== 200 || ($result['data']['code'] ?? 0) != 0) {
            throw new RuntimeException('Zoho invoice update failed: ' . self::error($result));
        }

        return $result['data']['invoice'];
    }
}
