<?php
/**
 * Read-only JSON API for the pages, backed by Zoho Books through the local cache.
 * There are deliberately no write routes for customers or items: the end user can only view them.
 *
 *   api.php?resource=customers              -> all active customers (cached)
 *   api.php?resource=customers&id=XXXX      -> one customer incl. address (cached per customer)
 *   api.php?resource=items                  -> all active items (cached)
 *   api.php?resource=items&id=XXXX          -> one item
 *   add &refresh=1 to any call              -> ignore the cache and re-fetch from Zoho
 *
 * Responses: {"data": ...} on success, {"error": "..."} with 4xx/5xx on failure.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($payload, $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

require_login(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(['error' => 'This API is read-only.'], 405);

$resource = $_GET['resource'] ?? '';
$id = (string)($_GET['id'] ?? '');
$refresh = !empty($_GET['refresh']);
if ($id !== '' && !ctype_digit($id)) respond(['error' => 'Invalid id'], 400);

try {
    switch ($resource) {
        case 'customers':
            if ($id !== '') respond(['data' => get_customer($id, $refresh)]);
            respond(['data' => get_customers($refresh)]);

        case 'items':
            $items = get_items($refresh);
            if ($id !== '') {
                foreach ($items as $item) {
                    if ($item['id'] === $id) respond(['data' => $item]);
                }
                respond(['error' => "Item $id not found"], 404);
            }
            respond(['data' => $items]);

        default:
            respond(['error' => 'Unknown resource. Use customers or items.'], 404);
    }
} catch (Exception $e) {
    respond(['error' => $e->getMessage()], 502);
}
