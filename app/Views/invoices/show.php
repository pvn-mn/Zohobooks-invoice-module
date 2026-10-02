<?php
/**
 * Printable A4 Sri Lankan IRD tax invoice (standalone page, no layout).
 *
 * @var array          $inv
 * @var bool           $mismatch
 * @var bool           $sent
 * @var \Config\Invoice $config
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= esc($inv['invoice_number']) ?></title>
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
    <a class="sec" href="<?= site_url('invoices') ?>">&larr; Invoices</a>
    <a class="sec" href="<?= site_url('invoices/' . (int) $inv['id'] . '/edit') ?>">Edit</a>
    <span class="sp"></span>
    <button onclick="window.print()">Print</button>
  </div>

  <?php if ($sent && $inv['sync_status'] === 'synced'): ?>
    <div class="msg ok">Saved and sent to Zoho Books (Zoho ref <?= esc((string) $inv['zoho_invoice_number']) ?>).</div>
  <?php endif; ?>
  <?php if ($inv['sync_status'] !== 'synced'): ?>
    <div class="msg err">
      Saved here, but <strong>not sent to Zoho Books</strong>: <?= esc($inv['sync_error'] ?: 'not sent yet') ?>
      <form method="post" action="<?= site_url('invoices/' . (int) $inv['id'] . '/resync') ?>">
        <?= csrf_field() ?>
        <button type="submit">Send again</button>
      </form>
    </div>
  <?php endif; ?>
  <?php if ($mismatch): ?>
    <div class="msg warn">Zoho's total (<?= money($inv['zoho_total']) ?>) differs from this invoice (<?= money($inv['total']) ?>). Check that an <?= esc((string) $config->vatRate) ?>% VAT tax exists in Zoho Books.</div>
  <?php endif; ?>

  <div class="page">
    <h1>TAX INVOICE</h1>

    <div class="two">
      <table class="kv">
        <tr><td>Invoice Date:</td><td><?= esc(fdate($inv['invoice_date'])) ?></td></tr>
        <tr><td>Tax Invoice Number:</td><td><strong><?= esc($inv['invoice_number']) ?></strong></td></tr>
      </table>
      <table class="kv">
        <tr><td>Supply Date:</td><td><?= esc(fdate($inv['supply_date'])) ?></td></tr>
        <tr><td>Supply Place:</td><td><?= esc($inv['supply_place']) ?></td></tr>
        <tr><td>Customer P/O No:</td><td><?= esc($inv['po_number']) ?></td></tr>
        <tr><td>Our Ref No:</td><td><?= esc($inv['our_ref']) ?></td></tr>
        <tr><td>Exchange Rate:</td><td><?= number_format((float) $inv['exchange_rate'], 2) ?></td></tr>
      </table>
    </div>

    <div class="two">
      <div class="box">
        <h3>Supplier</h3>
        <table class="kv">
          <tr><td>Supplier TIN Number:</td><td><?= esc($config->supplierTin) ?></td></tr>
          <tr><td>Supplier Name:</td><td><?= esc($config->supplierName) ?></td></tr>
          <tr><td>Address:</td><td><?= nl2br(esc($config->supplierAddress)) ?></td></tr>
          <tr><td>Phone Number:</td><td><?= esc($config->supplierPhone) ?></td></tr>
        </table>
      </div>
      <div class="box">
        <h3>Purchaser</h3>
        <table class="kv">
          <tr><td>Purchaser TIN Number:</td><td><?= esc($inv['customer_tin']) ?></td></tr>
          <tr><td>Purchaser Name:</td><td><?= esc($inv['customer_name']) ?></td></tr>
          <tr><td>Address:</td><td><?= esc($inv['customer_address']) ?></td></tr>
          <tr><td>Phone Number:</td><td><?= esc($inv['customer_phone']) ?></td></tr>
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
          <td><?= esc($l['reference']) ?></td>
          <td><?= esc($l['description']) ?></td>
          <td class="r"><?= qty($l['quantity']) ?></td>
          <td class="r"><?= money($l['unit_price']) ?></td>
          <td class="r"><?= money($l['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>

    <table class="totals">
      <tr><td>Supply Total Value:</td><td class="r"><?= money($inv['sub_total']) ?></td></tr>
      <tr><td>VAT Amount (<?= esc(rtrim(rtrim((string) $inv['vat_rate'], '0'), '.')) ?>%):</td><td class="r"><?= money($inv['vat_amount']) ?></td></tr>
      <tr><td>Total Amount With VAT:</td><td class="r"><?= money($inv['total']) ?></td></tr>
    </table>

    <div class="words"><strong>Total Amount in Word:</strong><?= esc(amount_in_words($inv['total'])) ?></div>

    <div class="pay">
      <strong>Payment Method:</strong> <?= esc($config->paymentMethod) ?>
      <p><strong>Bank Details:</strong><br>
        Account Name - <?= esc($config->bankAccountName) ?><br>
        Bank - <?= esc($config->bankName) ?><br>
        Branch - <?= esc($config->bankBranch) ?><br>
        Bank &amp; Branch Code - <?= esc($config->bankBranchCode) ?><br>
        Account No - <?= esc($config->bankAccountNo) ?><br>
        Swift Code - <?= esc($config->bankSwift) ?>
      </p>
    </div>
  </div>
</body>
</html>
