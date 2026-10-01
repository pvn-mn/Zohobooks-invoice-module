<?php
/**
 * Item list (view only). Data comes from Zoho Books via api.php & local cache.
 * - click a row to see the item's details.
 */
require_once __DIR__ . '/bootstrap.php';
require_login();
page_start('Items', 'items.php');
?>
<h1>Items</h1>
<div class="bar">
  <input type="search" id="q" placeholder="Search name, SKU or description…">
  <button type="button" class="sec" id="refresh" title="Re-fetch from Zoho Books">&#8635; Refresh</button>
</div>
<div class="muted" id="status">Loading…</div>
<table class="list" id="list">
  <thead><tr><th>Name</th><th>SKU</th><th>Unit</th><th class="r">Rate (LKR)</th></tr></thead>
  <tbody></tbody>
</table>
<p class="muted">View only. Items are added and edited in Zoho Books.</p>

<script>
const tbody = document.querySelector('#list tbody');
let items = [];
const pager = makePager(document.getElementById('list'), () => render());
const fmt = n => Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function load(refresh) {
  document.getElementById('status').textContent = 'Loading…';
  try {
    items = await api(refresh ? { resource: 'items', refresh: 1 } : { resource: 'items' });
    render();
  } catch (e) {
    document.getElementById('status').textContent = 'Could not load items: ' + e.message;
  }
}

function render() {
  const q = document.getElementById('q').value.trim().toLowerCase();
  const rows = items.filter(i => !q || [i.name, i.sku, i.description].join(' ').toLowerCase().includes(q));
  tbody.replaceChildren(...pager.apply(rows).map(i => {
    const tr = el('tr', { className: 'row' },
      el('td', {}, i.name), el('td', {}, i.sku), el('td', {}, i.unit), el('td', { className: 'r' }, fmt(i.rate)));
    tr.addEventListener('click', () => toggleDetail(tr, i));
    return tr;
  }));
  if (!rows.length) tbody.append(el('tr', {}, el('td', { colSpan: 4 }, 'No items found.')));
  document.getElementById('status').textContent = rows.length + ' of ' + items.length + ' items';
}

function toggleDetail(tr, i) {
  if (tr.nextElementSibling?.classList.contains('detail')) { tr.nextElementSibling.remove(); return; }
  const dl = el('dl', { className: 'kv' });
  [['Name', i.name], ['SKU', i.sku], ['Unit', i.unit], ['Rate (LKR)', fmt(i.rate)],
   ['Type', i.product_type], ['Description', i.description], ['Zoho ID', i.id]]
    .forEach(([k, v]) => dl.append(el('dt', {}, k), el('dd', {}, v || '—')));
  tr.after(el('tr', { className: 'detail' }, el('td', { colSpan: 4 }, dl)));
}

document.getElementById('q').addEventListener('input', () => { pager.reset(); render(); });
document.getElementById('refresh').addEventListener('click', () => load(true));
load(false);
</script>
<?php page_end();
