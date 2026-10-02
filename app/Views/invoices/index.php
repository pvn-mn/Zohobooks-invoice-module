<?php
/**
 * Invoice form (new / edit / redisplay after error) + invoice list.
 *
 * @var array      $form
 * @var array|null $editing
 * @var string     $error
 * @var array      $rows
 * @var float      $vatRate
 */
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<h1><?= $editing ? 'Edit invoice ' . esc($editing['invoice_number']) : 'New invoice' ?></h1>
<?php if ($error): ?><div class="msg err"><?= esc($error) ?></div><?php endif; ?>

<form method="post" action="<?= site_url('invoices') ?>" id="invform" class="card" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
  <input type="hidden" name="customer_id" id="customer_id" value="<?= esc($form['customer_id']) ?>">

  <div class="grid">
    <label class="full">Customer
      <input type="search" name="customer_label" id="cust_search" list="custlist" placeholder="Search by name or TIN…"
             value="<?= esc($form['customer_label']) ?>" required>
    </label>
    <div class="full muted" id="custcard"></div>
    <label>Invoice date<input type="date" name="invoice_date" value="<?= esc($form['invoice_date']) ?>" required></label>
    <label>Supply date<input type="date" name="supply_date" value="<?= esc($form['supply_date']) ?>"></label>
    <label>Supply place<input name="supply_place" value="<?= esc($form['supply_place']) ?>"></label>
    <label>Customer P/O No<input name="po_number" value="<?= esc($form['po_number']) ?>"></label>
    <label>Our Ref No<input name="our_ref" value="<?= esc($form['our_ref']) ?>"></label>
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
    <tr><td>VAT (<?= esc((string) $vatRate) ?>%)</td><td class="r" id="t_vat">0.00</td></tr>
    <tr><td><strong>Total with VAT</strong></td><td class="r"><strong id="t_total">0.00</strong></td></tr>
  </table>

  <div class="bar" style="margin-top:1rem">
    <button type="submit"><?= $editing ? 'Update &amp; Send' : 'Save &amp; Send' ?></button>
    <?php if ($editing): ?><a class="btn sec" href="<?= site_url('invoices') ?>">Cancel</a><?php endif; ?>
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
  <?php if (! $rows): ?>
    <tr><td colspan="6">No invoices yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr class="row" onclick="location.href='<?= site_url('invoices/' . (int) $r['id']) ?>'">
      <td><?= esc($r['invoice_number']) ?></td>
      <td><?= esc($r['customer_name']) ?></td>
      <td><?= esc(fdate($r['invoice_date'])) ?></td>
      <td class="r"><?= money($r['total']) ?></td>
      <td><span class="badge b-<?= esc($r['sync_status']) ?>"><?= esc($r['sync_status'] === 'synced' ? (string) $r['zoho_invoice_number'] : $r['sync_status']) ?></span></td>
      <td class="r"><a href="<?= site_url('invoices/' . (int) $r['id'] . '/edit') ?>" onclick="event.stopPropagation()">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<script>
const FORM = <?= json_encode($form, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const VAT_RATE = <?= json_encode($vatRate) ?>;
const $ = id => document.getElementById(id);
const tbody = document.querySelector('#lines tbody');
const custByLabel = new Map(), itemByLabel = new Map();
let rowIndex = 0;

const custLabel = c => c.name + (c.tin ? ' — TIN ' + c.tin : '');
const itemLabel = i => i.name + (i.sku ? ' [' + i.sku + ']' : '');

const MAX_OPTIONS = 3;
let allCustomers = [], allItems = [];

// Rebuild a datalist with only the first MAX_OPTIONS matches for the typed text
function fillList(listId, records, labelFn, text, extraFields) {
  const q = text.trim().toLowerCase();
  const hits = !q ? records : records.filter(r =>
    labelFn(r).toLowerCase().includes(q) || extraFields.some(f => (r[f] || '').toLowerCase().includes(q)));
  $(listId).replaceChildren(...hits.slice(0, MAX_OPTIONS).map(r => el('option', { value: labelFn(r) })));
}
const round2 = n => Math.round((n + Number.EPSILON) * 100) / 100;
const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Customers and items come from the API (cached copy of Zoho Books)
async function loadMasterData(refresh) {
  $('apistatus').textContent = 'Loading customers and items…';
  try {
    const extra = refresh ? { refresh: 1 } : {};
    const [customers, items] = await Promise.all([
      api({ resource: 'customers', ...extra }),
      api({ resource: 'items', ...extra }),
    ]);
    allCustomers = customers; allItems = items;
    custByLabel.clear(); itemByLabel.clear();
    customers.forEach(c => custByLabel.set(custLabel(c), c)); // full maps, so validation still works
    items.forEach(i => itemByLabel.set(itemLabel(i), i));
    fillList('custlist', allCustomers, custLabel, '', ['tin']);
    fillList('itemlist', allItems, itemLabel, '', ['sku']);
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
  fillList('custlist', allCustomers, custLabel, input.value, ['tin']);
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
    fillList('itemlist', allItems, itemLabel, itemSearch.value, ['sku']);
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
const invRows = [...document.querySelectorAll('#invlist tbody tr.row')];
const invPager = makePager($('invlist'), () => renderInvoices());
function renderInvoices() {
  const q = $('invq').value.trim().toLowerCase();
  const matches = invRows.filter(tr => !q || tr.textContent.toLowerCase().includes(q));
  const visible = new Set(invPager.apply(matches));
  invRows.forEach(tr => { tr.style.display = visible.has(tr) ? '' : 'none'; });
}
$('invq').addEventListener('input', () => { invPager.reset(); renderInvoices(); });
renderInvoices();

$('addline').addEventListener('click', () => addRow());
$('refresh').addEventListener('click', () => loadMasterData(true));

renderCard(FORM.customer || null);
(FORM.lines.length ? FORM.lines : [{}]).forEach(addRow);
loadMasterData(false);
</script>
<?= $this->endSection() ?>
