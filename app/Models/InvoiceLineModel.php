<?php

namespace App\Models;

use CodeIgniter\Model;

class InvoiceLineModel extends Model
{
    protected $table         = 'zb_invoice_lines';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'invoice_id', 'line_no', 'item_id', 'item_name', 'reference',
        'description', 'quantity', 'unit_price', 'amount',
    ];
}
