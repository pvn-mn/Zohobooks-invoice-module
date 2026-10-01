<?php
/**
 * Shared setup loaded by every page: config, database, login session,
 * Zoho customer/item cache, invoice numbering, helpers and page layout.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/zoho-client.php';

function cfg($name, $default = '') {
    return defined($name) ? constant($name) : $default;
}

// --- Login session -------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('zbinvoice');
    session_start();
}

function is_logged_in() {
    return !empty($_SESSION['user']);
}

function require_login($json = false) {
    if (is_logged_in()) return;
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    header('Location: login.php');
    exit;
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check() {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        die('This form has expired. Go back, reload the page and try again.');
    }
}

// --- Database (same wp-config.php lookup as before) -----------------------------
function db() {
    static $conn = null;
    if ($conn) return $conn;

    $db = ['host' => 'localhost', 'name' => 'local', 'user' => 'root', 'pass' => 'root'];
    $wpConfig = __DIR__ . '/wp-config.php';
    if (is_readable($wpConfig)) {
        $src = file_get_contents($wpConfig);
        foreach (['DB_HOST' => 'host', 'DB_NAME' => 'name', 'DB_USER' => 'user', 'DB_PASSWORD' => 'pass'] as $c => $k) {
            if (preg_match("/define\(\s*['\"]{$c}['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/", $src, $m)) $db[$k] = $m[1];
        }
    }
    $host = $db['host']; $port = null; $socket = null;
    if (strpos($host, ':') !== false) {
        [$host, $rest] = explode(':', $host, 2);
        ctype_digit($rest) ? $port = (int)$rest : $socket = $rest;
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($host, $db['user'], $db['pass'], $db['name'], $port, $socket);
    if ($conn->connect_errno) die('DB connection failed: ' . htmlspecialchars($conn->connect_error));
    $conn->set_charset('utf8mb4');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // from here on, DB errors throw

    // New tables (zb_ prefix). The old mvp_* tables are left alone.
    // Zoho IDs are 19 digits, so they are stored as VARCHAR, not INT.
    $conn->query("CREATE TABLE IF NOT EXISTS zb_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_number VARCHAR(40) NOT NULL UNIQUE,
        customer_id VARCHAR(32) NOT NULL,
        customer_name VARCHAR(200) NOT NULL,
        customer_tin VARCHAR(40) NOT NULL DEFAULT '',
        customer_address VARCHAR(500) NOT NULL DEFAULT '',
        customer_phone VARCHAR(60) NOT NULL DEFAULT '',
        invoice_date DATE NOT NULL,
        supply_date DATE NULL,
        supply_place VARCHAR(120) NOT NULL DEFAULT '',
        po_number VARCHAR(100) NOT NULL DEFAULT '',
        our_ref VARCHAR(100) NOT NULL DEFAULT '',
        exchange_rate DECIMAL(12,4) NOT NULL DEFAULT 1,
        sub_total DECIMAL(14,2) NOT NULL DEFAULT 0,
        vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        total DECIMAL(14,2) NOT NULL DEFAULT 0,
        zoho_invoice_id VARCHAR(32) NULL,
        zoho_invoice_number VARCHAR(40) NULL,
        zoho_total DECIMAL(14,2) NULL,
        sync_status VARCHAR(10) NOT NULL DEFAULT 'pending',
        sync_error TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS zb_invoice_lines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        line_no INT NOT NULL,
        item_id VARCHAR(32) NOT NULL,
        item_name VARCHAR(255) NOT NULL DEFAULT '',
        reference VARCHAR(100) NOT NULL DEFAULT '',
        description VARCHAR(500) NOT NULL,
        quantity DECIMAL(12,2) NOT NULL,
        unit_price DECIMAL(14,2) NOT NULL,
        amount DECIMAL(14,2) NOT NULL,
        KEY (invoice_id)
    ) DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS zb_cache (
        cache_key VARCHAR(100) PRIMARY KEY,
        payload LONGTEXT NOT NULL,
        fetched_at INT NOT NULL
    ) DEFAULT CHARSET=utf8mb4");
    $conn->query("CREATE TABLE IF NOT EXISTS zb_counters (
        name VARCHAR(40) PRIMARY KEY,
        value INT NOT NULL
    ) DEFAULT CHARSET=utf8mb4");

    return $conn;
}

// --- Local cache of Zoho data -------------------------------------------------
function cache_get($key, $ttl) {
    $stmt = db()->prepare('SELECT payload, fetched_at FROM zb_cache WHERE cache_key = ?');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return null;
    if ($ttl !== null && time() - (int)$row['fetched_at'] >= $ttl) return null;
    return json_decode($row['payload'], true);
}

function cache_put($key, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    $now = time();
    $stmt = db()->prepare('REPLACE INTO zb_cache (cache_key, payload, fetched_at) VALUES (?, ?, ?)');
    $stmt->bind_param('ssi', $key, $json, $now);
    $stmt->execute();
}

function cache_ttl() {
    return (int)cfg('CACHE_TTL', 900); // 15 minutes
}

// --- Zoho reads (customers, items, VAT tax) ----------------------------------
function zoho_ok($res) {
    return $res['status'] >= 200 && $res['status'] < 300 && (($res['data']['code'] ?? 0) == 0);
}

function zoho_error($res) {
    return $res['data']['message'] ?? ('HTTP ' . $res['status']);
}

// "One shot" fetch: pulls every page (200 records each) in one go
function zoho_fetch_all($endpoint, $listKey, $params = []) {
    $all = [];
    $page = 1;
    do {
        $res = ZohoClient::request('GET', $endpoint, $params + ['page' => $page, 'per_page' => 200]);
        if (!zoho_ok($res)) throw new Exception('Zoho ' . $endpoint . ': ' . zoho_error($res));
        $all = array_merge($all, $res['data'][$listKey] ?? []);
        $more = !empty($res['data']['page_context']['has_more_page']);
        $page++;
    } while ($more && $page <= 50);
    return $all;
}

// TIN is a custom field (api_name cf_tin_number). In your org it sits on the contact person,
// so Zoho returns it as contactperson_cf_tin_number; check every place it can appear.
function tin_from($c) {
    $field = cfg('ZOHO_TIN_FIELD', 'cf_tin_number');
    foreach ([$field, 'contactperson_' . $field] as $k) {
        if (isset($c[$k]) && $c[$k] !== '') return (string)$c[$k];
    }
    foreach (['custom_fields', 'contactperson_custom_fields'] as $k) {
        foreach ((array)($c[$k] ?? []) as $f) {
            if (($f['api_name'] ?? '') === $field && ($f['value'] ?? '') !== '') return (string)$f['value'];
        }
    }
    foreach ((array)($c['contact_persons'] ?? []) as $p) {
        $tin = tin_from($p);
        if ($tin !== '') return $tin;
    }
    return '';
}

function norm_customer($c) {
    return [
        'id' => (string)$c['contact_id'],
        'name' => (string)($c['contact_name'] ?? ''),
        'company' => (string)($c['company_name'] ?? ''),
        'phone' => (string)(($c['phone'] ?? '') ?: ($c['mobile'] ?? '')),
        'email' => (string)($c['email'] ?? ''),
        'tin' => tin_from($c),
    ];
}

function get_customers($refresh = false) {
    if (!$refresh && ($cached = cache_get('customers', cache_ttl())) !== null) return $cached;
    try {
        $rows = zoho_fetch_all('/contacts', 'contacts', ['filter_by' => 'Status.Active']);
    } catch (Exception $e) {
        $stale = cache_get('customers', null); // Zoho unreachable or rate-limited: use last copy
        if ($stale !== null) return $stale;
        throw $e;
    }
    $rows = array_filter($rows, fn($c) => ($c['contact_type'] ?? 'customer') === 'customer');
    $list = array_values(array_map('norm_customer', $rows));
    usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    cache_put('customers', $list);
    return $list;
}

// Full customer record (the list endpoint has no address), cached per customer
function get_customer($id, $refresh = false) {
    $id = (string)$id;
    $key = 'customer:' . $id;
    if (!$refresh && ($cached = cache_get($key, cache_ttl())) !== null) return $cached;

    $res = ZohoClient::request('GET', '/contacts/' . rawurlencode($id));
    if (!zoho_ok($res)) {
        $stale = cache_get($key, null);
        if ($stale !== null) return $stale;
        throw new Exception('Zoho customer ' . $id . ': ' . zoho_error($res));
    }
    $c = $res['data']['contact'];
    if (($c['contact_type'] ?? 'customer') !== 'customer') throw new Exception('Contact ' . $id . ' is not a customer.');

    $out = norm_customer($c);
    if ($out['tin'] === '') {
        foreach (get_customers() as $row) {
            if ($row['id'] === $out['id']) $out['tin'] = $row['tin'];
        }
    }
    $b = $c['billing_address'] ?? [];
    $parts = [];
    foreach (['address', 'street2', 'city', 'state', 'zip', 'country'] as $k) {
        if (trim((string)($b[$k] ?? '')) !== '') $parts[] = trim($b[$k]);
    }
    $out['address'] = implode(', ', $parts);
    cache_put($key, $out);
    return $out;
}

function norm_item($i) {
    return [
        'id' => (string)$i['item_id'],
        'name' => (string)($i['name'] ?? ($i['item_name'] ?? '')),
        'sku' => (string)($i['sku'] ?? ''),
        'unit' => (string)($i['unit'] ?? ''),
        'rate' => (float)($i['rate'] ?? 0),
        'description' => (string)($i['description'] ?? ''),
        'product_type' => (string)($i['product_type'] ?? ''),
    ];
}

function get_items($refresh = false) {
    if (!$refresh && ($cached = cache_get('items', cache_ttl())) !== null) return $cached;
    try {
        $rows = zoho_fetch_all('/items', 'items', ['filter_by' => 'Status.Active']);
    } catch (Exception $e) {
        $stale = cache_get('items', null);
        if ($stale !== null) return $stale;
        throw $e;
    }
    $rows = array_filter($rows, fn($i) => !isset($i['can_be_sold']) || $i['can_be_sold']);
    $list = array_values(array_map('norm_item', $rows));
    usort($list, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    cache_put('items', $list);
    return $list;
}

function find_item($id) {
    static $refreshed = false;
    foreach (get_items() as $item) {
        if ($item['id'] === (string)$id) return $item;
    }
    if (!$refreshed) { // item may have been added in Zoho after the last cache refresh
        $refreshed = true;
        foreach (get_items(true) as $item) {
            if ($item['id'] === (string)$id) return $item;
        }
    }
    return null;
}

// Zoho's tax_id for the VAT rate, so Zoho calculates the same VAT as the printed invoice
function vat_tax_id() {
    if (cfg('ZOHO_VAT_TAX_ID') !== '') return (string)cfg('ZOHO_VAT_TAX_ID');
    $cached = cache_get('vat_tax', 86400);
    if ($cached !== null) return $cached['id'];

    $res = ZohoClient::request('GET', '/settings/taxes');
    if (!zoho_ok($res)) return null; // don't cache a failure
    $id = null;
    foreach ($res['data']['taxes'] ?? [] as $t) {
        if (abs((float)$t['tax_percentage'] - vat_rate()) < 0.001) { $id = (string)$t['tax_id']; break; }
    }
    cache_put('vat_tax', ['id' => $id]);
    return $id;
}

function vat_rate() {
    return (float)cfg('VAT_RATE', 18);
}

// --- Invoice numbering: e.g. 26SEP_SAMM_00455 (YY + MON _ code _ running number) ---
function next_invoice_number($invoiceDate) {
    $conn = db();
    $start = max(1, (int)cfg('INVOICE_SEQ_START', 1)) - 1;
    $conn->query("INSERT IGNORE INTO zb_counters (name, value) VALUES ('invoice', $start)");
    $conn->query("UPDATE zb_counters SET value = LAST_INSERT_ID(value + 1) WHERE name = 'invoice'");
    $seq = (int)$conn->query('SELECT LAST_INSERT_ID()')->fetch_row()[0];
    $ts = strtotime($invoiceDate);
    return strtoupper(date('y', $ts) . date('M', $ts)) . '_' . cfg('INVOICE_CODE', 'SAMM') . '_' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
}

// --- Formatting helpers --------------------------------------------------------
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return number_format((float)$n, 2); }
function qty($n) { return number_format((float)$n, 2); }
function fdate($d) { return $d ? date('d/m/Y', strtotime($d)) : ''; }
function valid_date($d) {
    $dt = DateTime::createFromFormat('Y-m-d', (string)$d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function words_below_1000($n) {
    static $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    static $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $hundreds = intdiv($n, 100);
    $rest = $n % 100;
    $out = [];
    if ($hundreds) $out[] = $ones[$hundreds] . ' hundred';
    if ($rest) {
        $w = $rest < 20 ? $ones[$rest] : $tens[intdiv($rest, 10)] . ($rest % 10 ? ' ' . $ones[$rest % 10] : '');
        $out[] = $hundreds ? 'and ' . $w : $w;
    }
    return implode(' ', $out);
}

function number_to_words($n) {
    $n = (int)$n;
    if ($n === 0) return 'zero';
    $parts = [];
    $units = 0;
    foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand', 1 => ''] as $div => $label) {
        $chunk = intdiv($n, $div);
        $n %= $div;
        if ($div === 1) $units = $chunk;
        if ($chunk) $parts[] = trim(words_below_1000($chunk) . ' ' . $label);
    }
    if (count($parts) > 1 && $units > 0 && $units < 100) {
        $last = array_pop($parts);
        return implode(', ', $parts) . ' and ' . $last;
    }
    return implode(', ', $parts);
}

// 62976.60 -> "Sixty two thousand, nine hundred and seventy six Rupees sixty cents only"
function amount_in_words($amount) {
    $cents = (int)round((float)$amount * 100);
    $rupees = intdiv($cents, 100);
    $cents %= 100;
    $text = ucfirst(number_to_words($rupees)) . ' Rupees';
    if ($cents) $text .= ' ' . number_to_words($cents) . ' cents';
    return $text . ' only';
}

// --- Page layout ---------------------------------------------------------------
function page_start($title, $active = '') {
    $nav = ['invoices.php' => 'Invoices', 'customers.php' => 'Customers', 'items.php' => 'Items'];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
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
async function api(params) {
  const res = await fetch('api.php?' + new URLSearchParams(params));
  if (res.status === 401) { location.href = 'login.php'; throw new Error('Not logged in'); }
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
  <?php foreach ($nav as $href => $label): ?>
    <a href="<?= $href ?>" class="<?= $active === $href ? 'on' : '' ?>"><?= $label ?></a>
  <?php endforeach; ?>
  <span class="sp"></span>
  <span class="muted" style="color:#9ca3af"><?= h($_SESSION['user'] ?? '') ?></span>
  <a href="login.php?logout=1">Log out</a>
</div></header>
<main>
    <?php
}

function page_end() {
    echo "</main>\n</body>\n</html>\n";
}
