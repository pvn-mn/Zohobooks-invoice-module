<?php

namespace App\Libraries;

use App\Models\InvoiceLineModel;
use App\Models\InvoiceModel;
use Exception;
use Throwable;

/**
 * Saves invoices locally (with our own invoice number) and pushes them to Zoho Books.
 * If Zoho fails, the local invoice is kept and marked "failed" so it can be re-sent.
 */
class InvoiceService
{
    private ZohoBooks $zoho;
    private InvoiceModel $invoices;
    private InvoiceLineModel $lines;

    public function __construct()
    {
        helper('invoice');
        $this->zoho    = service('zohoBooks');
        $this->invoices = model(InvoiceModel::class);
        $this->lines    = model(InvoiceLineModel::class);
    }

    /**
     * Push the saved local invoice to Zoho: POST the first time, PUT afterwards.
     */
    public function syncToZoho(int $id): bool
    {
        $inv = $this->invoices->findWithLines($id);

        try {
            $taxId = $this->zoho->vatTaxId();
            $lines = array_map(static fn ($l) => [
                'item_id'  => $l['item_id'],
                'quantity' => (float) $l['quantity'],
                'rate'     => (float) $l['unit_price'],
                // only send a description when it differs from the item name (avoids duplicates in Zoho)
                'description' => $l['description'] !== $l['item_name'] ? $l['description'] : '',
                'tax_id'      => $taxId ?? '',
            ], $inv['lines']);
            // our tax invoice number goes into Zoho's notes so the two records can be matched up
            $extra = ['notes' => 'Tax Invoice No: ' . $inv['invoice_number']];

            if ($inv['zoho_invoice_id']) {
                $z = $this->zoho->updateInvoice($inv['zoho_invoice_id'], $inv['customer_id'], $inv['po_number'],
                    $inv['invoice_date'], $inv['supply_date'], $lines, $extra);
            } else {
                $z = $this->zoho->createInvoice($inv['customer_id'], $inv['po_number'],
                    $inv['invoice_date'], $inv['supply_date'], $lines, $extra);
            }

            $this->invoices->update($id, [
                'zoho_invoice_id'     => (string) $z['invoice_id'],
                'zoho_invoice_number' => (string) $z['invoice_number'],
                'zoho_total'          => (float) $z['total'],
                'sync_status'         => 'synced',
                'sync_error'          => null,
            ]);

            return true;
        } catch (Exception $e) {
            $this->invoices->update($id, ['sync_status' => 'failed', 'sync_error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Validate the posted form and save it locally.
     *
     * @return array{0: int, 1: string} [invoice id, error message]
     */
    public function saveFromPost(array $post): array
    {
        $id          = (int) ($post['id'] ?? 0);
        $customerId  = trim((string) ($post['customer_id'] ?? ''));
        $invoiceDate = (string) ($post['invoice_date'] ?? '');
        $supplyDate  = (string) ($post['supply_date'] ?? '');
        $supplyPlace = trim((string) ($post['supply_place'] ?? ''));
        $poNumber    = trim((string) ($post['po_number'] ?? ''));
        $ourRef      = trim((string) ($post['our_ref'] ?? ''));

        $lines = [];

        foreach ((array) ($post['lines'] ?? []) as $l) {
            $itemId = trim((string) ($l['item_id'] ?? ''));
            $desc   = trim((string) ($l['description'] ?? ''));
            if ($itemId === '' && $desc === '' && trim((string) ($l['unit_price'] ?? '')) === '') {
                continue; // empty row
            }
            $lines[] = [
                'item_id'     => $itemId,
                'reference'   => trim((string) ($l['reference'] ?? '')),
                'description' => $desc,
                'quantity'    => (float) ($l['quantity'] ?? 0),
                'unit_price'  => (float) ($l['unit_price'] ?? 0),
            ];
        }

        $errors = [];
        if (! ctype_digit($customerId)) {
            $errors[] = 'Pick a customer from the list.';
        }
        if (! valid_date($invoiceDate)) {
            $errors[] = 'Enter the invoice date.';
        }
        if ($supplyDate !== '' && ! valid_date($supplyDate)) {
            $errors[] = 'Supply date is not a valid date.';
        }
        if (! $lines) {
            $errors[] = 'Add at least one line item.';
        }

        try {
            foreach ($lines as $i => &$l) {
                $n    = $i + 1;
                $item = ctype_digit($l['item_id']) ? $this->zoho->findItem($l['item_id']) : null;
                if (! $item) {
                    $errors[] = "Line {$n}: pick an item from the list.";

                    continue;
                }
                if ($l['quantity'] <= 0) {
                    $errors[] = "Line {$n}: quantity must be more than 0.";
                }
                if ($l['unit_price'] < 0) {
                    $errors[] = "Line {$n}: unit price can't be negative.";
                }
                $l['item_name'] = $item['name'];
                if ($l['description'] === '') {
                    $l['description'] = $item['name'];
                }
                $l['amount'] = round($l['quantity'] * $l['unit_price'], 2);
            }
            unset($l);
        } catch (Exception $e) {
            return [0, 'Could not load items from Zoho Books: ' . $e->getMessage()];
        }
        if ($errors) {
            return [0, implode(' ', $errors)];
        }

        try {
            $cust = $this->zoho->getCustomer($customerId); // snapshot of customer details at time of invoicing
        } catch (Exception $e) {
            return [0, 'Could not load the customer from Zoho Books: ' . $e->getMessage()];
        }

        $subTotal  = round(array_sum(array_column($lines, 'amount')), 2);
        $vatRate   = $this->zoho->vatRate();
        $vatAmount = round($subTotal * $vatRate / 100, 2);
        $total     = round($subTotal + $vatAmount, 2);

        $data = [
            'customer_id'      => $customerId,
            'customer_name'    => $cust['name'],
            'customer_tin'     => $cust['tin'],
            'customer_address' => $cust['address'],
            'customer_phone'   => $cust['phone'],
            'invoice_date'     => $invoiceDate,
            'supply_date'      => $supplyDate === '' ? null : $supplyDate,
            'supply_place'     => $supplyPlace,
            'po_number'        => $poNumber,
            'our_ref'          => $ourRef,
            'sub_total'        => $subTotal,
            'vat_rate'         => $vatRate,
            'vat_amount'       => $vatAmount,
            'total'            => $total,
        ];

        $db = db_connect();
        $db->transBegin();

        try {
            if ($id) {
                if (! $this->invoices->find($id)) {
                    throw new Exception('Invoice not found.');
                }
                $this->invoices->update($id, $data);
                $this->lines->where('invoice_id', $id)->delete();
            } else {
                $data['invoice_number'] = $this->invoices->nextInvoiceNumber($invoiceDate);
                $id                     = (int) $this->invoices->insert($data);
            }

            foreach ($lines as $i => $l) {
                $this->lines->insert([
                    'invoice_id'  => $id,
                    'line_no'     => $i + 1,
                    'item_id'     => $l['item_id'],
                    'item_name'   => $l['item_name'],
                    'reference'   => $l['reference'],
                    'description' => $l['description'],
                    'quantity'    => $l['quantity'],
                    'unit_price'  => $l['unit_price'],
                    'amount'      => $l['amount'],
                ]);
            }
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            return [0, 'Could not save the invoice: ' . $e->getMessage()];
        }

        return [$id, ''];
    }
}
