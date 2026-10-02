<?php
/**
 * Customer list (view only). Click a row to see the customer's details.
 */
?>
<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>
<h1>Customers</h1>
<div class="bar">
  <input type="search" id="q" placeholder="Search name, company, phone or TIN…">
  <button type="button" class="sec" id="refresh" title="Re-fetch from Zoho Books">&#8635; Refresh</button>
</div>
<div class="muted" id="status">Loading…</div>
<table class="list" id="list">
  <thead><tr><th>Name</th><th>Company</th><th>Phone</th><th>TIN</th></tr></thead>
  <tbody></tbody>
</table>
<p class="muted">View only. Customers are added and edited in Zoho Books.</p>

<script>
const tbody = document.querySelector('#list tbody');
let customers = [];
const pager = makePager(document.getElementById('list'), () => render());

async function load(refresh) {
  document.getElementById('status').textContent = 'Loading…';
  try {
    customers = await api(refresh ? { resource: 'customers', refresh: 1 } : { resource: 'customers' });
    render();
  } catch (e) {
    document.getElementById('status').textContent = 'Could not load customers: ' + e.message;
  }
}

function render() {
  const q = document.getElementById('q').value.trim().toLowerCase();
  const rows = customers.filter(c => !q || [c.name, c.company, c.phone, c.tin].join(' ').toLowerCase().includes(q));
  tbody.replaceChildren(...pager.apply(rows).map(c => {
    const tr = el('tr', { className: 'row' },
      el('td', {}, c.name), el('td', {}, c.company), el('td', {}, c.phone), el('td', {}, c.tin));
    tr.addEventListener('click', () => toggleDetail(tr, c));
    return tr;
  }));
  if (!rows.length) tbody.append(el('tr', {}, el('td', { colSpan: 4 }, 'No customers found.')));
  document.getElementById('status').textContent = rows.length + ' of ' + customers.length + ' customers';
}

async function toggleDetail(tr, c) {
  if (tr.nextElementSibling?.classList.contains('detail')) { tr.nextElementSibling.remove(); return; }
  const td = el('td', { colSpan: 4 }, 'Loading details…');
  tr.after(el('tr', { className: 'detail' }, td));
  try {
    const d = await api({ resource: 'customers', id: c.id });
    const dl = el('dl', { className: 'kv' });
    [['Name', d.name], ['Company', d.company], ['TIN', d.tin], ['Phone', d.phone],
     ['Email', d.email], ['Address', d.address], ['Zoho ID', d.id]]
      .forEach(([k, v]) => dl.append(el('dt', {}, k), el('dd', {}, v || '—')));
    td.replaceChildren(dl);
  } catch (e) {
    td.textContent = 'Could not load details: ' + e.message;
  }
}

document.getElementById('q').addEventListener('input', () => { pager.reset(); render(); });
document.getElementById('refresh').addEventListener('click', () => load(true));
load(false);
</script>
<?= $this->endSection() ?>
