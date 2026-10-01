<?php
/**
 * Create an invoice in Zoho Books (POST /invoices).
 * Same function you tested, plus: tax_id per line (for 18% VAT), rate 0 allowed,
 * and an optional $extra array for additional invoice fields (e.g. notes).
 */
require_once __DIR__ . '/zoho-client.php';

function buildZohoInvoicePayload($customerId, $poNumber, $invoiceDate, $supplyDate, $lineItems, $extra = []) {
    $payload = [
        'customer_id' => $customerId,
        'date' => $invoiceDate,
        'reference_number' => $poNumber,
        'line_items' => array_map(function ($item) {
            $lineItem = [
                'item_id' => $item['item_id'],
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
    if (!empty($supplyDate)) {
        $payload['supply_date'] = $supplyDate;
    }
    return array_merge($payload, $extra);
}

function createZohoInvoice($customerId, $poNumber, $invoiceDate, $supplyDate, $lineItems, $extra = []) {
    $payload = buildZohoInvoicePayload($customerId, $poNumber, $invoiceDate, $supplyDate, $lineItems, $extra);
    $result = ZohoClient::request('POST', '/invoices', [], $payload);

    if (!in_array($result['status'], [200, 201], true) || ($result['data']['code'] ?? 0) != 0) {
        throw new Exception('Zoho invoice creation failed: ' . ($result['data']['message'] ?? json_encode($result['data'])));
    }

    return $result['data']['invoice']; // includes Zoho's invoice_id and invoice_number
}
