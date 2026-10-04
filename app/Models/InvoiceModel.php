<?php

namespace App\Models;

use App\Libraries\ZohoBooks;
use CodeIgniter\Model;
use Config\Invoice;

class InvoiceModel extends Model
{
    protected $table         = 'zb_invoices';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'invoice_number', 'customer_id', 'customer_name', 'customer_tin', 'customer_address',
        'customer_phone', 'supplier_snapshot', 'invoice_date', 'supply_date', 'supply_place', 'po_number', 'our_ref',
        'exchange_rate', 'sub_total', 'vat_rate', 'vat_amount', 'total',
        'zoho_invoice_id', 'zoho_invoice_number', 'zoho_total', 'sync_status', 'sync_error',
    ];

    /**
     * Invoice row with its lines under 'lines', or null when not found.
     */
    public function findWithLines(int $id): ?array
    {
        $inv = $this->find($id);
        if (! $inv) {
            return null;
        }
        $inv['lines'] = model(InvoiceLineModel::class)
            ->where('invoice_id', $id)
            ->orderBy('line_no')
            ->findAll();

        return $inv;
    }

    /**
     * The invoice's saved supplier / payment / bank details; any missing field uses the current .env value.
     */
    public static function supplierDetails(array $inv): array
    {
        $config   = config(Invoice::class);
        $snapshot = json_decode((string) ($inv['supplier_snapshot'] ?? ''), true);
        $out      = [];

        foreach (ZohoBooks::SUPPLIER_FIELDS as $field) {
            $out[$field] = (string) ($snapshot[$field] ?? $config->{$field});
        }

        return $out;
    }

    /**
     * Next invoice number, e.g. 26SEP_SAMM_00455 (YY + MON _ code _ running number).
     */
    public function nextInvoiceNumber(string $invoiceDate): string
    {
        $config = config(Invoice::class);
        $start  = max(1, $config->invoiceSeqStart) - 1;

        $this->db->query("INSERT IGNORE INTO zb_counters (name, value) VALUES ('invoice', ?)", [$start]);
        $this->db->query("UPDATE zb_counters SET value = LAST_INSERT_ID(value + 1) WHERE name = 'invoice'");
        $seq = (int) $this->db->query('SELECT LAST_INSERT_ID() AS seq')->getRow()->seq;

        $ts = strtotime($invoiceDate);

        return strtoupper(date('y', $ts) . date('M', $ts)) . '_' . $config->invoiceCode . '_'
            . str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }
}
