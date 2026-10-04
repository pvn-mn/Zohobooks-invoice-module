<?php

namespace App\Database\Migrations;

use App\Libraries\ZohoBooks;
use CodeIgniter\Database\Migration;
use Config\Invoice;

/**
 * Per-invoice copy of the supplier / payment / bank details (JSON), taken when the
 * invoice is created, so later changes in Zoho or .env never alter an issued invoice.
 * Existing invoices are filled with the current .env values, which is what they printed until now.
 */
class AddSupplierSnapshot extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('supplier_snapshot', 'zb_invoices')) {
            $this->db->query('ALTER TABLE zb_invoices ADD supplier_snapshot TEXT NULL AFTER customer_phone');
        }

        $config   = config(Invoice::class);
        $snapshot = [];

        foreach (ZohoBooks::SUPPLIER_FIELDS as $field) {
            $snapshot[$field] = (string) $config->{$field};
        }

        $this->db->table('zb_invoices')
            ->where('supplier_snapshot', null)
            ->update(['supplier_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
    }

    public function down()
    {
        $this->db->query('ALTER TABLE zb_invoices DROP COLUMN supplier_snapshot');
    }
}
