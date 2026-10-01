<?php
/**
 * Invoices: list, create, edit, send to Zoho Books, and print the Sri Lankan IRD tax invoice.
 *
 *   invoices.php            invoice list + new invoice form
 *   invoices.php?edit=ID    edit an invoice
 *   invoices.php?view=ID    A4 tax invoice with Print button
 *
 * "Save & Send" saves the invoice locally first (with our own invoice number),
 * then creates (POST) or updates (PUT) it in Zoho Books. If Zoho fails, the local
 * invoice is kept, marked "failed", and can be re-sent from the invoice view.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/create-invoice.php';
require_once __DIR__ . '/update-invoice.php';
require_login();

function load_invoice($id) {
    $stmt = db()->prepare('SELECT * FROM zb_invoices WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $inv = $stmt->get_result()->fetch_assoc();
    if (!$inv) return null;
    $stmt = db()->prepare('SELECT * FROM zb_invoice_lines WHERE invoice_id = ? ORDER BY line_no');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $inv['lines'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    return $inv;
}

// Push the saved local invoice to Zoho: POST the first time, PUT afterwards
function sync_to_zoho($id) {
    $inv = load_invoice($id);
    try {
        $taxId = vat_tax_id();
        $lines = array_map(fn($l) => [
            'item_id' => $l['item_id'],
            'quantity' => (float)$l['quantity'],
            'rate' => (float)$l['unit_price'],
            // only send a description when it differs from the item name (avoids duplicates in Zoho)
            'description' => $l['description'] !== $l['item_name'] ? $l['description'] : '',
            'tax_id' => $taxId ?? '',
        ], $inv['lines']);
        // our tax invoice number goes into Zoho's notes so the two records can be matched up
        $extra = ['notes' => 'Tax Invoice No: ' . $inv['invoice_number']];

        if ($inv['zoho_invoice_id']) {
            $z = updateZohoInvoice($inv['zoho_invoice_id'], $inv['customer_id'], $inv['po_number'],
                $inv['invoice_date'], $inv['supply_date'], $lines, $extra);
        } else {
            $z = createZohoInvoice($inv['customer_id'], $inv['po_number'],
                $inv['invoice_date'], $inv['supply_date'], $lines, $extra);
        }

        $zid = (string)$z['invoice_id'];
        $zno = (string)$z['invoice_number'];
        $ztotal = (float)$z['total'];
        $stmt = db()->prepare("UPDATE zb_invoices SET zoho_invoice_id = ?, zoho_invoice_number = ?, zoho_total = ?,
                               sync_status = 'synced', sync_error = NULL WHERE id = ?");
        $stmt->bind_param('ssdi', $zid, $zno, $ztotal, $id);
        $stmt->execute();
        return true;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $stmt = db()->prepare("UPDATE zb_invoices SET sync_status = 'failed', sync_error = ? WHERE id = ?");
        $stmt->bind_param('si', $msg, $id);
        $stmt->execute();
        return false;
    }
}

// Validate the posted form and save it locally. Returns [invoice id, error message].
function save_invoice_from_post() {
    $id = (int)($_POST['id'] ?? 0);
    $customerId = trim((string)($_POST['customer_id'] ?? ''));
    $invoiceDate = (string)($_POST['invoice_date'] ?? '');
    $supplyDate = (string)($_POST['supply_date'] ?? '');
    $supplyPlace = trim((string)($_POST['supply_place'] ?? ''));
    $poNumber = trim((string)($_POST['po_number'] ?? ''));
    $ourRef = trim((string)($_POST['our_ref'] ?? ''));

    $lines = [];
    foreach ((array)($_POST['lines'] ?? []) as $l) {
        $itemId = trim((string)($l['item_id'] ?? ''));
        $desc = trim((string)($l['description'] ?? ''));
        if ($itemId === '' && $desc === '' && trim((string)($l['unit_price'] ?? '')) === '') continue; // empty row
        $lines[] = [
            'item_id' => $itemId,
            'reference' => trim((string)($l['reference'] ?? '')),
            'description' => $desc,
            'quantity' => (float)($l['quantity'] ?? 0),
            'unit_price' => (float)($l['unit_price'] ?? 0),
        ];
    }

    $errors = [];
    if (!ctype_digit($customerId)) $errors[] = 'Pick a customer from the list.';
    if (!valid_date($invoiceDate)) $errors[] = 'Enter the invoice date.';
    if ($supplyDate !== '' && !valid_date($supplyDate)) $errors[] = 'Supply date is not a valid date.';
    if (!$lines) $errors[] = 'Add at least one line item.';
    foreach ($lines as $i => &$l) {
        $n = $i + 1;
        $item = ctype_digit($l['item_id']) ? find_item($l['item_id']) : null;
        if (!$item) { $errors[] = "Line $n: pick an item from the list."; continue; }
        if ($l['quantity'] <= 0) $errors[] = "Line $n: quantity must be more than 0.";
        if ($l['unit_price'] < 0) $errors[] = "Line $n: unit price can't be negative.";
        $l['item_name'] = $item['name'];
        if ($l['description'] === '') $l['description'] = $item['name'];
        $l['amount'] = round($l['quantity'] * $l['unit_price'], 2);
    }
    unset($l);
    if ($errors) return [0, implode(' ', $errors)];

    try {
        $cust = get_customer($customerId); // snapshot of customer details at time of invoicing
    } catch (Exception $e) {
        return [0, 'Could not load the customer from Zoho Books: ' . $e->getMessage()];
    }

    $subTotal = round(array_sum(array_column($lines, 'amount')), 2);
    $vatRate = vat_rate();
    $vatAmount = round($subTotal * $vatRate / 100, 2);
    $total = round($subTotal + $vatAmount, 2);
    $supplyDateOrNull = $supplyDate === '' ? null : $supplyDate;

    $conn = db();
    $conn->begin_transaction();
    try {
        if ($id) {
            if (!load_invoice($id)) throw new Exception('Invoice not found.');
            $stmt = $conn->prepare('UPDATE zb_invoices SET customer_id = ?, customer_name = ?, customer_tin = ?, customer_address = ?,
                customer_phone = ?, invoice_date = ?, supply_date = ?, supply_place = ?, po_number = ?, our_ref = ?,
                sub_total = ?, vat_rate = ?, vat_amount = ?, total = ? WHERE id = ?');
            $stmt->bind_param('ssssssssssddddi', $customerId, $cust['name'], $cust['tin'], $cust['address'], $cust['phone'],
                $invoiceDate, $supplyDateOrNull, $supplyPlace, $poNumber, $ourRef, $subTotal, $vatRate, $vatAmount, $total, $id);
            $stmt->execute();
            $stmt = $conn->prepare('DELETE FROM zb_invoice_lines WHERE invoice_id = ?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
        } else {
            $number = next_invoice_number($invoiceDate);
            $stmt = $conn->prepare('INSERT INTO zb_invoices (invoice_number, customer_id, customer_name, customer_tin, customer_address,
                customer_phone, invoice_date, supply_date, supply_place, po_number, our_ref, sub_total, vat_rate, vat_amount, total)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssssssssdddd', $number, $customerId, $cust['name'], $cust['tin'], $cust['address'], $cust['phone'],
                $invoiceDate, $supplyDateOrNull, $supplyPlace, $poNumber, $ourRef, $subTotal, $vatRate, $vatAmount, $total);
            $stmt->execute();
            $id = $conn->insert_id;
        }

        $stmt = $conn->prepare('INSERT INTO zb_invoice_lines (invoice_id, line_no, item_id, item_name, reference, description, quantity, unit_price, amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($lines as $i => $l) {
            $lineNo = $i + 1;
            $stmt->bind_param('iissssddd', $id, $lineNo, $l['item_id'], $l['item_name'], $l['reference'], $l['description'],
                $l['quantity'], $l['unit_price'], $l['amount']);
            $stmt->execute();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return [0, 'Could not save the invoice: ' . $e->getMessage()];
    }
    return [$id, ''];
}

// --- POST: save or re-send ------------------------------------------------------
$error = '';
$posted = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'resync') {
        $id = (int)($_POST['id'] ?? 0);
        $ok = load_invoice($id) && sync_to_zoho($id);
        header('Location: ?view=' . $id . ($ok ? '&sent=1' : ''));
        exit;
    }
    [$id, $error] = save_invoice_from_post();
    if (!$error) {
        $ok = sync_to_zoho($id);
        header('Location: ?view=' . $id . ($ok ? '&sent=1' : ''));
        exit;
    }
    $posted = $_POST; // redisplay the form with what the user typed
}

// --- View: printable A4 tax invoice ----------------------------------------------
if (isset($_GET['view'])) {
    $inv = load_invoice((int)$_GET['view']);
    if (!$inv) { http_response_code(404); die('Invoice not found.'); }
    $mismatch = $inv['sync_status'] === 'synced' && $inv['zoho_total'] !== null && abs((float)$inv['zoho_total'] - (float)$inv['total']) > 0.01;
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= h($inv['invoice_number']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font: 13px/1.45 system-ui, sans-serif; background: #e5e7eb; margin: 0; color: #111; }
  .toolbar { max-width: 210mm; margin: 1rem auto 0; display: flex; gap: .5rem; align-items: center; }
  .toolbar .sp { flex: 1; }
  .toolbar a, .toolbar button { font: inherit; font-size: 14px; padding: .5rem 1rem; border: 0; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; text-decoration: none; }
  .toolbar a.sec { background: #6b7280; }
  .msg { max-width: 210mm; margin: .75rem auto 0; padding: .6rem 1rem; border-radius: 6px; font-size: 14px; }
  .ok { background: #dcfce7; } .err { background: #fee2e2; } .warn { background: #fef3c7; }
  .msg form { display: inline; } .msg button { font: inherit; margin-left: .5rem; padding: .25rem .75rem; border: 0; border-radius: 5px; background: #dc2626; color: #fff; cursor: pointer; }
  .page { width: 210mm; min-height: 297mm; margin: 1rem auto; padding: 14mm; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.15); }
  h1 { text-align: center; font-size: 22px; letter-spacing: 3px; margin: 0 0 6mm; }
  .two { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-bottom: 5mm; align-items: start; }
  .box { border: 1px solid #999; padding: 3mm 4mm; }
  .box h3 { margin: 0 0 2mm; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #444; }
  table.kv { border-collapse: collapse; width: 100%; }
  table.kv td { padding: .5mm 0; vertical-align: top; }
  table.kv td:first-child { width: 42%; color: #444; }
  table.lines { width: 100%; border-collapse: collapse; margin-top: 2mm; }
  table.lines th, table.lines td { border: 1px solid #999; padding: 1.5mm 2mm; }
  table.lines th { background: #f3f4f6; font-size: 11.5px; text-align: left; }
  .r { text-align: right; }
  table.totals { margin-left: auto; margin-top: 3mm; border-collapse: collapse; min-width: 95mm; }
  table.totals td { border: 1px solid #999; padding: 1.5mm 2mm; }
  table.totals tr:last-child td { font-weight: 700; }
  .words { margin-top: 4mm; } .words strong { display: block; }
  .pay { margin-top: 5mm; }
  @media print {
    body { background: #fff; }
    .toolbar, .msg { display: none; }
    .page { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; }
    @page { size: A4; margin: 12mm; }
  }
</style>
</head>
<body>
  <div class="toolbar">
    <a class="sec" href="invoices.php">&larr; Invoices</a>
    <a class="sec" href="?edit=<?= (int)$inv['id'] ?>">Edit</a>
    <span class="sp"></span>
    <button onclick="window.print()">Print</button>
  </div>

  <?php if (isset($_GET['sent']) && $inv['sync_status'] === 'synced'): ?>
    <div class="msg ok">Saved and sent to Zoho Books (Zoho ref <?= h($inv['zoho_invoice_number']) ?>).</div>
  <?php endif; ?>
  <?php if ($inv['sync_status'] !== 'synced'): ?>
    <div class="msg err">
      Saved here, but <strong>not sent to Zoho Books</strong>: <?= h($inv['sync_error'] ?: 'not sent yet') ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="resync">
        <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
        <button type="submit">Send again</button>
      </form>
    </div>
  <?php endif; ?>
  <?php if ($mismatch): ?>
    <div class="msg warn">Zoho's total (<?= money($inv['zoho_total']) ?>) differs from this invoice (<?= money($inv['total']) ?>). Check that an <?= h(vat_rate()) ?>% VAT tax exists in Zoho Books.</div>
  <?php endif; ?>

  <div class="page">
    <h1>TAX INVOICE</h1>

    <div class="two">
      <table class="kv">
        <tr><td>Invoice Date:</td><td><?= h(fdate($inv['invoice_date'])) ?></td></tr>
        <tr><td>Tax Invoice Number:</td><td><strong><?= h($inv['invoice_number']) ?></strong></td></tr>
      </table>
      <table class="kv">
        <tr><td>Supply Date:</td><td><?= h(fdate($inv['supply_date'])) ?></td></tr>
        <tr><td>Supply Place:</td><td><?= h($inv['supply_place']) ?></td></tr>
        <tr><td>Customer P/O No:</td><td><?= h($inv['po_number']) ?></td></tr>
        <tr><td>Our Ref No:</td><td><?= h($inv['our_ref']) ?></td></tr>
        <tr><td>Exchange Rate:</td><td><?= number_format((float)$inv['exchange_rate'], 2) ?></td></tr>
      </table>
    </div>

    <div class="two">
      <div class="box">
        <h3>Supplier</h3>
        <table class="kv">
          <tr><td>Supplier TIN Number:</td><td><?= h(cfg('SUPPLIER_TIN')) ?></td></tr>
          <tr><td>Supplier Name:</td><td><?= h(cfg('SUPPLIER_NAME')) ?></td></tr>
          <tr><td>Address:</td><td><?= nl2br(h(cfg('SUPPLIER_ADDRESS'))) ?></td></tr>
          <tr><td>Phone Number:</td><td><?= h(cfg('SUPPLIER_PHONE')) ?></td></tr>
        </table>
      </div>
      <div class="box">
        <h3>Purchaser</h3>
        <table class="kv">
          <tr><td>Purchaser TIN Number:</td><td><?= h($inv['customer_tin']) ?></td></tr>
          <tr><td>Purchaser Name:</td><td><?= h($inv['customer_name']) ?></td></tr>
          <tr><td>Address:</td><td><?= h($inv['customer_address']) ?></td></tr>
          <tr><td>Phone Number:</td><td><?= h($inv['customer_phone']) ?></td></tr>
        </table>
      </div>
    </div>

    <table class="lines">
      <tr>
        <th>Reference</th><th>Item Description</th><th class="r">Quantity</th>
        <th class="r">Unit Price (LKR)</th><th class="r">Without VAT Value (LKR)</th>
      </tr>
      <?php foreach ($inv['lines'] as $l): ?>
        <tr>
          <td><?= h($l['reference']) ?></td>
          <td><?= h($l['description']) ?></td>
          <td class="r"><?= qty($l['quantity']) ?></td>
          <td class="r"><?= money($l['unit_price']) ?></td>
          <td class="r"><?= money($l['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <table class="totals">
      <tr><td>Supply Total Value:</td><td class="r"><?= money($inv['sub_total']) ?></td></tr>
      <tr><td>VAT Amount (<?= h(rtrim(rtrim($inv['vat_rate'], '0'), '.')) ?>%):</td><td class="r"><?= money($inv['vat_amount']) ?></td></tr>
      <tr><td>Total Amount With VAT:</td><td class="r"><?= money($inv['total']) ?></td></tr>
    </table>

    <div class="words"><strong>Total Amount in Word:</strong><?= h(amount_in_words($inv['total'])) ?></div>

    <div class="pay">
      <strong>Payment Method:</strong> <?= h(cfg('PAYMENT_METHOD')) ?>
      <p><strong>Bank Details:</strong><br>
        Account Name - <?= h(cfg('BANK_ACCOUNT_NAME')) ?><br>
        Bank - <?= h(cfg('BANK_NAME')) ?><br>
        Branch - <?= h(cfg('BANK_BRANCH')) ?><br>
        Bank &amp; Branch Code - <?= h(cfg('BANK_BRANCH_CODE')) ?><br>
        Account No - <?= h(cfg('BANK_ACCOUNT_NO')) ?><br>
        Swift Code - <?= h(cfg('BANK_SWIFT')) ?>
      </p>
    </div>
  </div>
</body>
</html>
    <?php
    exit;
}

// --- Form data: edit, redisplay after error, or new -------------------------------
$editing = null;
if (isset($_GET['edit'])) {
    $editing = load_invoice((int)$_GET['edit']);
    if (!$editing) { http_response_code(404); die('Invoice not found.'); }
}

if ($posted) {
    $form = [
        'id' => (int)($posted['id'] ?? 0),
        'customer_id' => (string)($posted['customer_id'] ?? ''),
        'customer_label' => (string)($posted['customer_label'] ?? ''),
        'invoice_date' => (string)($posted['invoice_date'] ?? ''),
        'supply_date' => (string)($posted['supply_date'] ?? ''),
        'supply_place' => (string)($posted['supply_place'] ?? ''),
        'po_number' => (string)($posted['po_number'] ?? ''),
        'our_ref' => (string)($posted['our_ref'] ?? ''),
        'lines' => array_values(array_map(fn($l) => [
            'item_id' => (string)($l['item_id'] ?? ''), 'item_name' => (string)($l['item_label'] ?? ''),
            'reference' => (string)($l['reference'] ?? ''), 'description' => (string)($l['description'] ?? ''),
            'quantity' => (string)($l['quantity'] ?? ''), 'unit_price' => (string)($l['unit_price'] ?? ''),
        ], (array)($posted['lines'] ?? []))),
    ];
    $editing = $form['id'] ? load_invoice($form['id']) : null;
} elseif ($editing) {
    $form = [
        'id' => (int)$editing['id'],
        'customer_id' => $editing['customer_id'],
        'customer_label' => $editing['customer_name'] . ($editing['customer_tin'] ? ' — TIN ' . $editing['customer_tin'] : ''),
        'customer' => ['name' => $editing['customer_name'], 'tin' => $editing['customer_tin'],
                       'phone' => $editing['customer_phone'], 'address' => $editing['customer_address']],
        'invoice_date' => $editing['invoice_date'],
        'supply_date' => (string)$editing['supply_date'],
        'supply_place' => $editing['supply_place'],
        'po_number' => $editing['po_number'],
        'our_ref' => $editing['our_ref'],
        'lines' => array_map(fn($l) => [
            'item_id' => $l['item_id'], 'item_name' => $l['item_name'], 'reference' => $l['reference'],
            'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'],
        ], $editing['lines']),
    ];
} else {
    $today = date('Y-m-d');
    $form = ['id' => 0, 'customer_id' => '', 'customer_label' => '', 'invoice_date' => $today, 'supply_date' => $today,
             'supply_place' => cfg('SUPPLY_PLACE_DEFAULT', 'Main'), 'po_number' => '', 'our_ref' => '', 'lines' => []];
}

$rows = db()->query('SELECT id, invoice_number, customer_name, invoice_date, total, sync_status, zoho_invoice_number
                     FROM zb_invoices ORDER BY id DESC');

page_start('Invoices', 'invoices.php');
?>
<h1><?= $editing ? 'Edit invoice ' . h($editing['invoice_number']) : 'New invoice' ?></h1>
<?php if ($error): ?><div class="msg err"><?= h($error) ?></div><?php endif; ?>

<form method="post" id="invform" class="card" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
  <input type="hidden" name="customer_id" id="customer_id" value="<?= h($form['customer_id']) ?>">

  <div class="grid">
    <label class="full">Customer
      <input type="search" name="customer_label" id="cust_search" list="custlist" placeholder="Search by name or TIN…"
             value="<?= h($form['customer_label']) ?>" required>
    </label>
    <div class="full muted" id="custcard"></div>
    <label>Invoice date<input type="date" name="invoice_date" value="<?= h($form['invoice_date']) ?>" required></label>
    <label>Supply date<input type="date" name="supply_date" value="<?= h($form['supply_date']) ?>"></label>
    <label>Supply place<input name="supply_place" value="<?= h($form['supply_place']) ?>"></label>
    <label>Customer P/O No<input name="po_number" value="<?= h($form['po_number']) ?>"></label>
    <label>Our Ref No<input name="our_ref" value="<?= h($form['our_ref']) ?>"></label>
  </div>
  <datalist id="custlist"></datalist>
  <datalist id="itemlist"></datalist>

  <h2>Line items</h2>
  <table id="lines">
    <thead><tr><th style="width:12%">Reference</th><th style="width:24%">Item</th><th>Description</th>
      <th style="width:10%">Qty</th><th style="width:13%">Unit price</th><th class="r" style="width:12%">Amount</th><th></th></tr></thead>
    <tbody></tbody>
  </table>
  <p><button type="button" class="sec" id="addline">+ Add line</button></p>

  <table style="max-width:340px;margin-left:auto">
    <tr><td>Supply total</td><td class="r" id="t_sub">0.00</td></tr>
    <tr><td>VAT (<?= h(vat_rate()) ?>%)</td><td class="r" id="t_vat">0.00</td></tr>
    <tr><td><strong>Total with VAT</strong></td><td class="r"><strong id="t_total">0.00</strong></td></tr>
  </table>

  <div class="bar" style="margin-top:1rem">
    <button type="submit"><?= $editing ? 'Update &amp; Send' : 'Save &amp; Send' ?></button>
    <?php if ($editing): ?><a class="btn sec" href="invoices.php">Cancel</a><?php endif; ?>
    <span class="sp" style="flex:1"></span>
    <span class="muted" id="apistatus"></span>
    <button type="button" class="sec" id="refresh" title="Re-fetch customers and items from Zoho Books">&#8635;</button>
  </div>
</form>

<h2>Invoices</h2>
<div class="bar"><input type="search" id="invq" placeholder="Search invoice no. or customer…"></div>
<table class="list" id="invlist">
  <thead><tr><th>Tax Invoice No.</th><th>Customer</th><th>Date</th><th class="r">Total (LKR)</th><th>Zoho</th><th></th></tr></thead>
  <tbody>
  <?php if ($rows->num_rows === 0): ?>
    <tr><td colspan="6">No invoices yet.</td></tr>
  <?php endif; ?>
  <?php while ($r = $rows->fetch_assoc()): ?>
    <tr class="row" onclick="location.href='?view=<?= (int)$r['id'] ?>'">
      <td><?= h($r['invoice_number']) ?></td>
      <td><?= h($r['customer_name']) ?></td>
      <td><?= h(fdate($r['invoice_date'])) ?></td>
      <td class="r"><?= money($r['total']) ?></td>
      <td><span class="badge b-<?= h($r['sync_status']) ?>"><?= h($r['sync_status'] === 'synced' ? $r['zoho_invoice_number'] : $r['sync_status']) ?></span></td>
      <td class="r"><a href="?edit=<?= (int)$r['id'] ?>" onclick="event.stopPropagation()">Edit</a></td>
    </tr>
  <?php endwhile; ?>
  </tbody>
</table>

<script>
const FORM = <?= json_encode($form, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const VAT_RATE = <?= json_encode(vat_rate()) ?>;
const $ = id => document.getElementById(id);
const tbody = document.querySelector('#lines tbody');
const custByLabel = new Map(), itemByLabel = new Map();
let rowIndex = 0;

const custLabel = c => c.name + (c.tin ? ' — TIN ' + c.tin : '');
const itemLabel = i => i.name + (i.sku ? ' [' + i.sku + ']' : '');
const round2 = n => Math.round((n + Number.EPSILON) * 100) / 100;
const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Customers and items come from api.php (cached copy of Zoho Books)
async function loadMasterData(refresh) {
  $('apistatus').textContent = 'Loading customers and items…';
  try {
    const extra = refresh ? { refresh: 1 } : {};
    const [customers, items] = await Promise.all([
      api({ resource: 'customers', ...extra }),
      api({ resource: 'items', ...extra }),
    ]);
    custByLabel.clear(); itemByLabel.clear();
    $('custlist').replaceChildren(...customers.map(c => { custByLabel.set(custLabel(c), c); return el('option', { value: custLabel(c) }); }));
    $('itemlist').replaceChildren(...items.map(i => { itemByLabel.set(itemLabel(i), i); return el('option', { value: itemLabel(i) }); }));
    $('apistatus').textContent = customers.length + ' customers, ' + items.length + ' items from Zoho Books';
  } catch (e) {
    $('apistatus').textContent = 'Could not load customers/items: ' + e.message;
  }
}

// Customer search -> selected customer's details
function renderCard(c) {
  if (!c) { $('custcard').replaceChildren(); return; }
  $('custcard').replaceChildren(
    el('div', {}, 'TIN: ' + (c.tin || '—') + '   ·   Phone: ' + (c.phone || '—')),
    el('div', {}, 'Address: ' + (c.address || '—')));
}

$('cust_search').addEventListener('input', async () => {
  const input = $('cust_search');
  const c = custByLabel.get(input.value);
  input.classList.toggle('bad', !c && input.value !== '');
  if (!c) { $('customer_id').value = ''; renderCard(null); return; }
  $('customer_id').value = c.id;
  renderCard(c);
  try { renderCard(await api({ resource: 'customers', id: c.id })); } catch (e) { /* keep list data */ }
});

// Line items
function input(name, type, value, extra = {}) {
  return el('input', { name, type, value: value ?? '', ...extra });
}

function addRow(l = {}) {
  const i = rowIndex++;
  const ref = input(`lines[${i}][reference]`, 'text', l.reference);
  const itemSearch = input(`lines[${i}][item_label]`, 'search', l.item_name, { placeholder: 'Search item…' });
  itemSearch.setAttribute('list', 'itemlist');
  const itemId = input(`lines[${i}][item_id]`, 'hidden', l.item_id);
  const desc = input(`lines[${i}][description]`, 'text', l.description);
  const q = input(`lines[${i}][quantity]`, 'number', l.quantity ?? 1, { step: '0.01', min: '0.01', required: true });
  const price = input(`lines[${i}][unit_price]`, 'number', l.unit_price ?? '', { step: '0.01', min: '0', required: true });
  const amount = el('span');
  const del = el('button', { type: 'button', className: 'del', textContent: '×', title: 'Remove line' });
  const tr = el('tr', {},
    el('td', {}, ref), el('td', {}, itemSearch, itemId), el('td', {}, desc),
    el('td', {}, q), el('td', {}, price), el('td', { className: 'r' }, amount), el('td', {}, del));

  itemSearch.addEventListener('input', () => {
    const it = itemByLabel.get(itemSearch.value);
    itemSearch.classList.toggle('bad', !it && itemSearch.value !== '');
    if (!it) { itemId.value = ''; recalc(); return; }
    itemId.value = it.id;
    desc.value = it.name;
    price.value = it.rate;
    recalc();
  });
  del.addEventListener('click', () => { tr.remove(); recalc(); });
  tr.addEventListener('input', recalc);
  tr._q = q; tr._price = price; tr._amount = amount;
  tbody.append(tr);
  recalc();
}

function recalc() {
  let sub = 0;
  tbody.querySelectorAll('tr').forEach(tr => {
    const line = round2((parseFloat(tr._q.value) || 0) * (parseFloat(tr._price.value) || 0));
    tr._amount.textContent = fmt(line);
    sub += line;
  });
  sub = round2(sub);
  const vat = round2(sub * VAT_RATE / 100);
  $('t_sub').textContent = fmt(sub);
  $('t_vat').textContent = fmt(vat);
  $('t_total').textContent = fmt(round2(sub + vat));
}

// Block submit until customer and every item were picked from the lists
$('invform').addEventListener('submit', e => {
  const problems = [];
  if (!$('customer_id').value) problems.push('Pick a customer from the list.');
  tbody.querySelectorAll('tr').forEach((tr, n) => {
    if (!tr.querySelector('input[type=hidden]').value) problems.push('Line ' + (n + 1) + ': pick an item from the list.');
  });
  if (!tbody.children.length) problems.push('Add at least one line item.');
  if (problems.length) { e.preventDefault(); alert(problems.join('\n')); }
});

// Invoice list search
$('invq').addEventListener('input', () => {
  const q = $('invq').value.trim().toLowerCase();
  document.querySelectorAll('#invlist tbody tr.row').forEach(tr => {
    tr.style.display = !q || tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
});

$('addline').addEventListener('click', () => addRow());
$('refresh').addEventListener('click', () => loadMasterData(true));

renderCard(FORM.customer || null);
(FORM.lines.length ? FORM.lines : [{}]).forEach(addRow);
loadMasterData(false);
</script>
<?php page_end();
