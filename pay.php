<?php
/* Starts an M-Pesa STK push for an invoice (?t=doc token) or a shop order (?o=order token), and reports status (?status=poll). */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/mpesa.php';
require_once __DIR__ . '/includes/waafi.php';
header('Content-Type: application/json');
header('X-Robots-Tag: noindex, nofollow');

$out = function (array $a, int $code = 200): never { http_response_code($code); echo json_encode($a); exit; };
if (!db_ready()) $out(['ok' => false, 'error' => 'Not available'], 503);
$pdo = db();

if (isset($_GET['status'])) {
    $st = $pdo->prepare('SELECT status, result_desc FROM mpesa_requests WHERE poll = ?');
    $st->execute([(string)$_GET['status']]);
    $r = $st->fetch();
    $out($r ? ['status' => $r['status'], 'message' => $r['result_desc']] : ['status' => 'unknown'], $r ? 200 : 404);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) $out(['ok' => false, 'error' => 'The page expired. Refresh and try again.'], 400);

// Max 5 prompts per 10 minutes from one address
$_SESSION['stk'] = array_values(array_filter($_SESSION['stk'] ?? [], fn($t) => $t > time() - 600));
if (count($_SESSION['stk']) >= 5) $out(['ok' => false, 'error' => 'Too many attempts. Wait a few minutes and try again.'], 429);
$_SESSION['stk'][] = time();

$phone = (string)($_POST['phone'] ?? '');
$evc = ($_POST['method'] ?? '') === 'evc';

/* EVC Plus: WaafiPay answers on the same call, so the result is known straight away. */
$evcPay = function (float $due, string $ref, string $desc, array $target, ?string $cur = null) use ($pdo, $phone, $out): never {
    $r = waafi_purchase($phone, $due, $ref, $desc, $cur);
    $poll = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO mpesa_requests (document_id, order_id, phone, amount, checkout_id, status, result_desc, mpesa_ref, poll, channel, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?, 'evc', ?, ?)")
        ->execute([$target['document_id'] ?? null, $target['order_id'] ?? null, $r['msisdn'], $due, null, $r['ok'] ? 'paid' : 'failed', $r['message'], $r['tx'] ?: null, $poll, now(), now()]);
    if (!$r['ok']) $out(['ok' => false, 'error' => $r['message']], 400);
    $charged = $r['charged'][0] . ' ' . number_format($r['charged'][1], 2);
    if (!empty($target['document_id'])) {
        record_payment($pdo, (int)$target['document_id'], $due, 'evc', $r['tx'] ?: null, date('Y-m-d'), "EVC Plus online ($charged from {$r['msisdn']})", true, $m);
    } else {
        order_mark_paid($pdo, (int)$target['order_id'], $due, $r['tx'] ?: null);
    }
    $out(['ok' => true, 'status' => 'paid', 'poll' => $poll, 'message' => 'Payment approved. Thank you!']);
};
try {
    if (!empty($_POST['t'])) {
        $st = $pdo->prepare("SELECT id FROM documents WHERE token = ? AND type = 'invoice'");
        $st->execute([(string)$_POST['t']]);
        $id = (int)$st->fetchColumn();
        if (!$id) $out(['ok' => false, 'error' => 'Invoice not found.'], 404);
        $d = doc_load($pdo, $id);
        if ($d['status'] === 'cancelled') $out(['ok' => false, 'error' => 'This invoice was cancelled.'], 400);
        [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
        $due = round($total - doc_paid($pdo, $id), 2);
        if ($due <= 0) $out(['ok' => false, 'error' => 'This invoice is already paid.'], 400);
        if ($evc) $evcPay($due, $d['number'], 'Invoice ' . $d['number'], ['document_id' => $id], doc_cur($d));
        if (doc_cur($d) !== 'KES') $out(['ok' => false, 'error' => 'M-Pesa takes Kenya Shillings only. This invoice is in ' . doc_cur($d) . '.'], 400);
        $r = mpesa_stk($pdo, $phone, $due, $d['number'], 'Invoice', ['document_id' => $id]);
    } elseif (!empty($_POST['o'])) {
        $st = $pdo->prepare('SELECT * FROM orders WHERE token = ?');
        $st->execute([(string)$_POST['o']]);
        $o = $st->fetch();
        if (!$o) $out(['ok' => false, 'error' => 'Order not found.'], 404);
        $due = round((float)$o['total'] - (float)$o['paid'], 2);
        if ($due <= 0) $out(['ok' => false, 'error' => 'This order is already paid.'], 400);
        if ($evc) $evcPay($due, $o['number'], 'Order ' . $o['number'], ['order_id' => (int)$o['id']]);
        $r = mpesa_stk($pdo, $phone ?: $o['phone'], $due, $o['number'], 'Shop order', ['order_id' => (int)$o['id']]);
    } else {
        $out(['ok' => false, 'error' => 'Nothing to pay.'], 400);
    }
    $out(['ok' => true, 'poll' => $r['poll'], 'message' => $r['message']]);
} catch (Throwable $e) {
    $out(['ok' => false, 'error' => $e->getMessage()], 400);
}
