<?php
/**
 * Page layout shared by the logged-in pages: styles, JS helpers (api, el, makePager) and nav.
 *
 * @var string $title
 * @var string $active
 */
$nav = ['invoices' => 'Invoices', 'customers' => 'Customers', 'items' => 'Items'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($title ?? '') ?></title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; margin: 0; color: #222; background: #fafafa; }
  header { background: #1f2937; color: #fff; }
  header .in { max-width: 1000px; margin: 0 auto; padding: .6rem 1rem; display: flex; gap: 1.25rem; align-items: center; }
  header a { color: #d1d5db; text-decoration: none; } header a.on { color: #fff; font-weight: 600; }
  header .sp { flex: 1; }
  main { max-width: 1000px; margin: 1.5rem auto; padding: 0 1rem; }
  h1 { font-size: 1.4rem; margin: 0 0 1rem; } h2 { font-size: 1.05rem; margin-top: 1.75rem; }
  form.card, .card { background: #fff; border: 1px solid #e5e7eb; padding: 1rem; border-radius: 8px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: .75rem; }
  .full { grid-column: 1 / -1; }
  label { display: flex; flex-direction: column; font-size: .85rem; gap: .25rem; }
  input, select { font: inherit; padding: .45rem; border: 1px solid #ccc; border-radius: 6px; width: 100%; box-sizing: border-box; background: #fff; }
  input.bad { border-color: #dc2626; background: #fef2f2; }
  button, a.btn { font: inherit; padding: .5rem 1rem; border: 0; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; text-decoration: none; display: inline-block; }
  .sec { background: #6b7280 !important; } .del { background: #dc2626 !important; padding: .35rem .65rem !important; }
  table { width: 100%; border-collapse: collapse; background: #fff; }
  th { text-align: left; border-bottom: 2px solid #222; padding: .5rem; font-size: .8rem; text-transform: uppercase; color: #444; }
  td { padding: .4rem .5rem; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  .r { text-align: right; }
  table.list tbody tr.row { cursor: pointer; } table.list tbody tr.row:hover { background: #eef4ff; }
  tr.detail td { background: #f8fafc; }
  .msg { padding: .6rem 1rem; border-radius: 6px; margin-bottom: 1rem; }
  .ok { background: #dcfce7; } .err { background: #fee2e2; } .warn { background: #fef3c7; }
  .muted { font-size: .8rem; color: #666; }
  .bar { display: flex; gap: .5rem; align-items: center; margin-bottom: .75rem; }
  .bar input { max-width: 360px; }
  .pager { display: flex; gap: .75rem; align-items: center; justify-content: flex-end; margin: .5rem 0; }
  .pager button:disabled { opacity: .5; cursor: default; }
  .badge { font-size: .75rem; padding: .1rem .45rem; border-radius: 999px; }
  .b-synced { background: #dcfce7; } .b-failed { background: #fee2e2; } .b-pending { background: #fef3c7; }
  dl.kv { display: grid; grid-template-columns: max-content 1fr; gap: .2rem 1rem; margin: 0; }
  dl.kv dt { color: #666; } dl.kv dd { margin: 0; }
</style>
<script>
const API_URL = <?= json_encode(site_url('api'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const LOGIN_URL = <?= json_encode(site_url('login'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
// api({ resource: 'customers', id: 123, refresh: 1 }) -> GET api/customers/123?refresh=1
async function api(params) {
  const { resource, id, ...query } = params;
  const qs = new URLSearchParams(query).toString();
  const url = API_URL + '/' + resource + (id ? '/' + encodeURIComponent(id) : '') + (qs ? '?' + qs : '');
  const res = await fetch(url);
  if (res.status === 401) { location.href = LOGIN_URL; throw new Error('Not logged in'); }
  const json = await res.json();
  if (!res.ok) throw new Error(json.error || res.statusText);
  return json.data;
}
function el(tag, props = {}, ...children) {
  const e = Object.assign(document.createElement(tag), props);
  e.append(...children.map(c => c ?? ''));
  return e;
}
// Pagination under a table. apply(rows) returns the current page's rows and redraws the controls;
// call reset() when the filter changes so the view goes back to page 1.
function makePager(table, onChange, perPage = 10) {
  const nav = el('div', { className: 'pager' });
  table.after(nav);
  let page = 1;
  const go = p => { page = p; onChange(); };
  return {
    reset() { page = 1; },
    apply(rows) {
      const pages = Math.max(1, Math.ceil(rows.length / perPage));
      page = Math.min(page, pages);
      const prev = el('button', { type: 'button', className: 'sec', textContent: '‹ Prev', disabled: page <= 1 });
      const next = el('button', { type: 'button', className: 'sec', textContent: 'Next ›', disabled: page >= pages });
      prev.addEventListener('click', () => go(page - 1));
      next.addEventListener('click', () => go(page + 1));
      nav.replaceChildren(...(rows.length > perPage
        ? [prev, el('span', { className: 'muted' }, 'Page ' + page + ' of ' + pages), next] : []));
      return rows.slice((page - 1) * perPage, page * perPage);
    },
  };
}
</script>
</head>
<body>
<header><div class="in">
  <strong>Tax Invoices</strong>
  <?php foreach ($nav as $path => $label): ?>
    <a href="<?= site_url($path) ?>" class="<?= ($active ?? '') === $path ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <span class="sp"></span>
  <span class="muted" style="color:#9ca3af"><?= esc((string) session('user')) ?></span>
  <a href="<?= site_url('logout') ?>">Log out</a>
</div></header>
<main>
<?= $this->renderSection('content') ?>
</main>
</body>
</html>
