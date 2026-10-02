<?php
/**
 * Printable A4 Sri Lankan IRD tax invoice (standalone page, no layout).
 * Layout replicates the SAMM printed tax invoice template.
 *
 * @var array          $inv
 * @var bool           $mismatch
 * @var bool           $sent
 * @var \Config\Invoice $config
 */

// "Printed By" in the footer shows the supplier email from .env.
// Later: replace with session('user') to show the logged-in user.
$printedBy = $config->supplierEmail;
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= esc($inv['invoice_number']) ?></title>
<style>
  * { box-sizing: border-box; }
  body { font: 13px/1.35 "Times New Roman", Times, serif; background: #e5e7eb; margin: 0; color: #000; }

  /* Screen-only toolbar and banners (unchanged behaviour) */
  .toolbar { max-width: 210mm; margin: 1rem auto 0; display: flex; gap: .5rem; align-items: center; font-family: system-ui, sans-serif; }
  .toolbar .sp { flex: 1; }
  .toolbar a, .toolbar button { font: inherit; font-size: 14px; padding: .5rem 1rem; border: 0; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; text-decoration: none; }
  .toolbar a.sec { background: #6b7280; }
  .msg { max-width: 210mm; margin: .75rem auto 0; padding: .6rem 1rem; border-radius: 6px; font: 14px system-ui, sans-serif; }
  .ok { background: #dcfce7; } .err { background: #fee2e2; } .warn { background: #fef3c7; }
  .msg form { display: inline; } .msg button { font: inherit; margin-left: .5rem; padding: .25rem .75rem; border: 0; border-radius: 5px; background: #dc2626; color: #fff; cursor: pointer; }

  /* A4 sheet: flex column so the footer sits at the bottom */
  .page { width: 210mm; min-height: 297mm; margin: 1rem auto; padding: 12mm; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,.15); display: flex; flex-direction: column; }

  /* Letterhead */
  .letterhead { display: flex; align-items: center; gap: 6mm; padding-bottom: 3mm; border-bottom: 1.5px solid #000; }
  /* .logo-slot { width: 30mm; height: 30mm; flex: none; border: 1px dashed #999; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #999; } */
  .logo { width: 30mm; height: auto; flex: none; }
  .company { flex: none; text-align: center; white-space: nowrap; }
  .company .name { font-size: 22px; font-weight: 700; margin: 0 0 1mm; }
  .company div { font-size: 13px; }



  h1 { text-align: center; font-size: 20px; font-weight: 400; margin: 5mm 0 2mm; }

  /* Boxed rows */
  .row { display: grid; grid-template-columns: 1fr 1fr; gap: 20mm; margin-bottom: 4mm; }
  .box { border: 1px solid #000; padding: 1mm 1.5mm; }
  .full { border: 1px solid #000; border-bottom: 0; padding: 1mm 1.5mm; }
  b { font-weight: 700; }

  /* Line items + totals */
  table.lines { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.lines th { border: 1px solid #000; padding: 1.5mm 1mm; font-weight: 700; text-align: center; vertical-align: middle; }
  table.lines td { border-left: 1px solid #000; border-right: 1px solid #000; padding: .6mm 1mm; }
  table.lines tbody tr:last-child td { border-bottom: 1px solid #000; }
  table.lines tfoot td { border: 1px solid #000; font-weight: 700; padding: .6mm 1mm; }
  .c-ref { width: 12%; } .c-desc { width: 35%; } .c-qty { width: 17%; } .c-price { width: 16%; } .c-amt { width: 20%; }
  .r { text-align: right; }

  /* Words / payment / bank box */
  .info { border: 1px solid #000; margin-top: 4mm; }
  .info > div { padding: .6mm 1mm; }
  .info > div + div { border-top: 1px solid #000; }

  /* Footer */
  .footer { margin-top: auto; padding-top: 10mm; }
  .footer .rule { border-top: 1.5px solid #000; margin-bottom: 12mm; }
  .signs { display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; }
  .signs .dots { letter-spacing: 1px; }
  .printed { margin-top: 2mm; font-size: 9px; }

  @media print {
    body { background: #fff; }
    .toolbar, .msg { display: none; }
    .page { margin: 0; box-shadow: none; width: auto; min-height: 270mm; padding: 0; }
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

    <!-- Letterhead -->
    <div class="letterhead">
      <img class="logo" src="<?= base_url('img/logo.jpeg') ?>" alt="<?= esc($config->supplierName) ?>">
      <div class="company">
        <div class="name"><?= esc($config->supplierName) ?></div>
        <div><?= esc(preg_replace('/\s*\R\s*/', ', ', trim($config->supplierAddress))) ?></div>
        <div>
          Tel: <?= esc($config->supplierPhone) ?>
          <?php if ($config->supplierEmail !== ''): ?>
            &nbsp; Email: <?= esc($config->supplierEmail) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <h1>TAX INVOICE</h1>

    <!-- Invoice date / number -->
    <div class="row">
      <div class="box"><b>Invoice Date</b> : <?= esc(fdate($inv['invoice_date'])) ?></div>
      <div class="box"><b>Tax Invoice Number</b>: <?= esc($inv['invoice_number']) ?></div>
    </div>

    <!-- Supplier / Purchaser -->
    <div class="row">
      <div class="box">
        <b>Supplier Tin Number</b>: <?= esc($config->supplierTin) ?><br>
        <b>Supplier Name</b> : <?= esc($config->supplierName) ?><br>
        <b>Address</b> : <?= esc(preg_replace('/\s*\R\s*/', ', ', trim($config->supplierAddress))) ?><br>
        <b>Phone Number</b> : <?= esc($config->supplierPhone) ?>
      </div>
      <div class="box">
        <b>Purchaser Tin Number</b>: <?= esc($inv['customer_tin']) ?><br>
        <b>Purchaser Name</b> : <?= esc($inv['customer_name']) ?><br>
        <b>Address</b> : <?= esc($inv['customer_address']) ?><br>
        <b>Phone Number</b> : <?= esc($inv['customer_phone']) ?>
      </div>
    </div>

    <!-- Supply date / place -->
    <div class="row">
      <div class="box"><b>Supply Date</b>: <?= esc(fdate($inv['supply_date'])) ?></div>
      <div class="box"><b>Supply Place</b>:<?= esc($inv['supply_place']) ?></div>
    </div>

    <!-- Order details -->
    <div class="full">
      <b>Customer P/O No</b>: <?= esc($inv['po_number']) ?><br>
      <b>Our Ref No</b> : <?= esc($inv['our_ref']) ?><br>
      <b>Exchange Rate</b> : <?= number_format((float) $inv['exchange_rate'], 2) ?>
    </div>

    <!-- Line items + totals -->
    <table class="lines">
      <colgroup>
        <col class="c-ref"><col class="c-desc"><col class="c-qty"><col class="c-price"><col class="c-amt">
      </colgroup>
      <thead>
        <tr>
          <th>Reference</th>
          <th>Item Description</th>
          <th>Quantity</th>
          <th>Unit Price<br>(LKR)</th>
          <th>Without Vat<br>Value<br>(LKR)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inv['lines'] as $l): ?>
          <tr>
            <td><?= esc($l['reference']) ?></td>
            <td><?= esc($l['description']) ?></td>
            <td class="r"><?= qty($l['quantity']) ?></td>
            <td class="r"><?= money($l['unit_price']) ?></td>
            <td class="r"><?= money($l['amount']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="4">Supply Total Value :</td><td class="r"><?= money($inv['sub_total']) ?></td></tr>
        <tr><td colspan="4">VAT Amount (<?= esc(rtrim(rtrim((string) $inv['vat_rate'], '0'), '.')) ?>%)</td><td class="r"><?= money($inv['vat_amount']) ?></td></tr>
        <tr><td colspan="4">Total Amount With VAT</td><td class="r"><?= money($inv['total']) ?></td></tr>
      </tfoot>
    </table>

    <!-- Words / payment / bank -->
    <div class="info">
      <div><b>Total Amount in Word :</b> <?= esc(amount_in_words($inv['total'])) ?></div>
      <div><b>Payment Method : <?= esc($config->paymentMethod) ?></b></div>
      <div>
        <b>Bank Details :</b><br>
        Account Name - <?= esc($config->bankAccountName) ?><br>
        Bank - <?= esc($config->bankName) ?><br>
        Branch - <?= esc($config->bankBranch) ?><br>
        Bank &amp; Branch Code - <?= esc($config->bankBranchCode) ?><br>
        Account No - <?= esc($config->bankAccountNo) ?><br>
        Swift Code - <?= esc($config->bankSwift) ?>
      </div>
    </div>

    <!-- Footer -->
    <div class="footer">
      <div class="rule"></div>
      <div class="signs">
        <div><div class="dots">...............................</div>Prepared By</div>
        <div><div class="dots">...............................</div>Checked By</div>
        <div><div class="dots">...............................</div>Authorized By</div>
      </div>
      <div class="printed">Printed By : <?= esc($printedBy) ?> &nbsp;&nbsp; Printed At : <?= date('Y-m-d H:i:s') ?></div>
    </div>

  </div>
</body>
</html>