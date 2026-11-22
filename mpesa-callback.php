<?php
/* Safaricom posts the STK push result here. The key in the URL is set automatically in Settings. */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/mpesa.php';
header('Content-Type: application/json');

$k = (string)($_GET['k'] ?? '');
if (!db_ready() || setting('callback_key') === '' || !hash_equals(setting('callback_key'), $k) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit('{}');
}
$raw = (string)file_get_contents('php://input', false, null, 0, 65536);
$payload = json_decode($raw, true);
try {
    if (is_array($payload)) mpesa_handle_callback(db(), $payload);
} catch (Throwable $e) {
    error_log('mpesa callback: ' . $e->getMessage());
}
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
