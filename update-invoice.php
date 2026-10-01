<?php
/**
 * Update an existing Zoho Books invoice (PUT /invoices/{id}).
 * Reuses the payload builder from create-invoice.php so create and update send the same shape.
 */
require_once __DIR__ . '/create-invoice.php';

function updateZohoInvoice($invoiceId, $customerId, $poNumber, $invoiceDate, $supplyDate, $lineItems, $extra = []) {
    $payload = buildZohoInvoicePayload($customerId, $poNumber, $invoiceDate, $supplyDate, $lineItems, $extra);
    $result = ZohoClient::request('PUT', '/invoices/' . rawurlencode($invoiceId), [], $payload);

    if ($result['status'] !== 200 || ($result['data']['code'] ?? 0) != 0) {
        throw new Exception('Zoho invoice update failed: ' . ($result['data']['message'] ?? json_encode($result['data'])));
    }

    return $result['data']['invoice'];
}
