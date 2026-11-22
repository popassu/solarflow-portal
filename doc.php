<?php
/* Client view of a quotation, proposal, invoice (?t=token) or receipt (?r=token). */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/doc-templates.php';
require_once __DIR__ . '/includes/doc-render.php';
require_once __DIR__ . '/includes/mpesa.php';
require_once __DIR__ . '/includes/waafi.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$notFound = function (): never {
    http_response_code(404);
    exit('<!doctype html><meta charset="utf-8"><title>Not found</title><p style="font-family:sans-serif;padding:2rem">This link is not valid. Please contact ' . e(setting('company_name')) . ' on '
        . e(setting('phone_kenya')) . '.</p>');
};
if (!db_ready()) $notFound();
$pdo = db();
pp_migrate($pdo);

$tok = (string)($_GET['t'] ?? $_GET['r'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $tok)) $notFound();

$receipt = null;
if (isset($_GET['r'])) {
    $st = $pdo->prepare('SELECT * FROM payments WHERE token = ?');
    $st->execute([$tok]);
    $receipt = $st->fetch() ?: $notFound();
    $doc = doc_load($pdo, (int)$receipt['document_id']) ?: $notFound();
} else {
    $st = $pdo->prepare('SELECT id FROM documents WHERE token = ?');
    $st->execute([$tok]);
    $id = (int)$st->fetchColumn() ?: $notFound();
    $doc = doc_load($pdo, $id);
}

// The client opened the link: record it (staff previews are not counted)
if (!$receipt && !is_admin()) {
    $pdo->prepare("UPDATE documents SET view_count = view_count + 1, viewed_at = COALESCE(viewed_at, ?), status = CASE WHEN status = 'sent' THEN 'viewed' ELSE status END WHERE id = ?")
        ->execute([now(), $doc['id']]);
    if ($doc['status'] === 'sent') $doc['status'] = 'viewed';
}
$canAccept = !$receipt && $doc['type'] !== 'invoice' && in_array($doc['status'], ['draft', 'sent', 'viewed'], true)
    && strtotime($doc['doc_date']) + 86400 * ((int)$doc['valid_days'] + 1) > time();
$msg = '';

if ($canAccept && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = mb_substr(trim((string)($_POST['accept_name'] ?? '')), 0, 120);
    if (!csrf_ok()) {
        $msg = 'The page timed out. Please try again.';
    } elseif ($name === '' || empty($_POST['agree'])) {
        $msg = 'Type your name and tick the box to accept.';
    } else {
        $pdo->prepare("UPDATE documents SET status='accepted', accepted_name=?, accepted_at=?, updated_at=? WHERE id=?")
            ->execute([$name, now(), now(), $doc['id']]);
        require __DIR__ . '/includes/mailer.php';
        [, , , $total] = doc_totals($doc['items'], doc_rate($doc), (float)$doc['discount']);
        pp_mail(setting('notify_email'), "Accepted: {$doc['number']} — " . ($doc['client_company'] ?: $doc['client_name']),
            "{$name} accepted {$doc['number']}" . ($doc['title'] ? " ({$doc['title']})" : '') . " online on " . now() . ".\n\nTotal: " . doc_cur($doc) . ' ' . money($total)
            . "\n\nConvert it to an invoice: " . site_url("admin/documents.php?id={$doc['id']}") . "\n", $doc['client_email'] ?: null);
        redirect('doc.php?t=' . $tok . '&accepted=1');
    }
}
if (($_GET['download'] ?? '') === 'pdf' && is_file(__DIR__ . '/includes/doc-pdf.php')) {
    require_once __DIR__ . '/includes/doc-pdf.php';
    $bytes = $receipt ? receipt_pdf($pdo, $receipt, $doc) : doc_pdf($pdo, $doc);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $receipt ? $receipt['receipt_no'] : $doc['number']) . '.pdf"');
    echo $bytes;
    exit;
}
if (!$receipt && ($_GET['download'] ?? '') === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $doc['number']) . '.html"');
    echo doc_html_export($pdo, $doc);
    exit;
}
$typeLabel = $receipt ? 'Receipt' : doc_types()[$doc['type']];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($typeLabel . ' ' . ($receipt['receipt_no'] ?? $doc['number'])) ?> — <?= e(setting('company_name')) ?></title>
<link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" href="assets/img/favicon-32.png" type="image/png" sizes="32x32"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Poppins:wght@600;700;800&display=swap">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="stylesheet" href="assets/css/doc.css">
</head>
<body class="doc-page">
<div class="doc-actions">
  <strong><?= e($typeLabel) ?> from <?= e(setting('company_name')) ?></strong>
  <span class="spacer"></span>
  <a class="btn ghost" href="<?= e(wa_link(wa_number(), 'Hello Sunbeam, about ' . ($receipt['receipt_no'] ?? $doc['number']) . ': ')) ?>" target="_blank" rel="noopener">Ask on WhatsApp</a>
  <a class="btn ghost" href="doc.php?<?= $receipt ? 'r' : 't' ?>=<?= e($tok) ?>&amp;download=pdf">Download PDF</a>
  <button class="btn" type="button" data-print>Print</button>
</div>

<?php if (isset($_GET['accepted'])): ?>
  <div class="doc-actions"><p class="note ok grow">Thank you — your acceptance is recorded. We will contact you to confirm the start date.</p></div>
<?php endif; ?>

<?php if ($canAccept): ?>
  <form method="post" class="doc-actions accept-box no-print">
    <?= csrf_field() ?>
    <div style="flex:1">
      <h2>Accept this <?= e(strtolower($typeLabel)) ?></h2>
      <?php if ($msg): ?><p class="note err"><?= e($msg) ?></p><?php endif; ?>
      <div class="accept-row">
        <label>Your full name<input name="accept_name" required maxlength="120" autocomplete="name"></label>
        <label class="check"><input type="checkbox" name="agree" value="1" required> I accept <?= e($doc['number']) ?> and its terms</label>
        <button class="btn">Accept</button>
      </div>
    </div>
  </form>
<?php endif; ?>

<?php
$isInvoice = !$receipt && $doc['type'] === 'invoice';
if ($isInvoice) {
    [, , , $invTotal] = doc_totals($doc['items'], doc_rate($doc), (float)$doc['discount']);
    $invDue = max(round($invTotal - doc_paid($pdo, (int)$doc['id']), 2), 0);
}
if ($isInvoice && isset($_GET['paid'])): ?>
  <div class="doc-actions"><p class="note ok grow">Payment received. Thank you! Your receipt is listed on the invoice below and has been emailed to you.</p></div>
<?php endif; ?>
<?php if ($isInvoice && $invDue > 0 && $doc['status'] !== 'cancelled'): ?>
  <section class="pay-online no-print" data-stk>
    <div class="apops-pay-head"><h2>Pay <?= e(doc_cur($doc)) ?> <?= money($invDue) ?> online</h2><span class="apops-badge"><img src="assets/img/apops-mark.png" alt="" width="20" height="21"> Processed by <strong>APOPS Pay</strong></span></div>
    <p class="apops-note">Payments go straight to <?= e(setting('company_name')) ?>'s own M-Pesa, EVC Plus or bank account. APOPS Pay only processes and records them; it never holds your money.</p>
    <?php if (mpesa_ready() && doc_cur($doc) === 'KES'): ?>
      <p><strong>M-Pesa (Kenya):</strong> enter your Safaricom number. A prompt will appear on your phone: enter your M-Pesa PIN to pay.</p>
      <form class="pay-row" method="post" action="pay.php">
        <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($doc['token']) ?>">
        <label>M-Pesa phone number<input name="phone" inputmode="tel" required maxlength="20" value="<?= str_starts_with(preg_replace('/\D/', '', (string)$doc['client_phone']), '252') ? '' : e((string)$doc['client_phone']) ?>" placeholder="07XX XXX XXX"></label>
        <button class="btn green" type="submit">Pay with M-Pesa</button>
      </form>
    <?php endif; ?>
    <?php if (waafi_ready()): ?>
      <p><strong>EVC Plus / Zaad / Sahal (Somalia):</strong> enter your number and approve the payment on your phone<?php try { [$wc, $wa] = waafi_quote($invDue, doc_cur($doc)); echo $wc !== doc_cur($doc) ? ' (' . e($wc) . ' ' . e(number_format($wa, 2)) . ')' : ''; } catch (Throwable $e) {} ?>.</p>
      <form class="pay-row" method="post" action="pay.php">
        <?= csrf_field() ?><input type="hidden" name="t" value="<?= e($doc['token']) ?>"><input type="hidden" name="method" value="evc">
        <label>EVC Plus number<input name="phone" inputmode="tel" required maxlength="20" placeholder="061 XXX XXXX"></label>
        <button class="btn green" type="submit">Pay with EVC Plus</button>
      </form>
    <?php endif; ?>
    <p class="pay-msg" role="status"></p>
    <?php if (setting('mpesa_till')): ?>
      <p class="till"><?= mpesa_ready() ? 'Or pay' : 'Pay' ?> by <strong>M-Pesa Buy Goods, Till <?= e(setting('mpesa_till')) ?></strong>. Use <strong><?= e($doc['number']) ?></strong> as the reference.</p>
    <?php endif; ?>
    <?php if (setting('evc_merchant') && !waafi_ready()): ?>
      <p class="till">In Somalia, pay by <strong>EVC Plus to merchant <?= e(setting('evc_merchant')) ?></strong> and send the confirmation to WhatsApp <?= e(setting('phone_somalia')) ?>.</p>
    <?php endif; ?>
    <?php if (!mpesa_ready() && !waafi_ready() && !setting('mpesa_till') && !setting('evc_merchant')): ?>
      <p>Online payment is being set up. See <strong>How to pay</strong> on the invoice below, WhatsApp us on <?= e(wa_number()) ?> or call <?= e(setting('phone_kenya')) ?> (Kenya).</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php
if ($receipt) {
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE document_id = ? AND (paid_on < ? OR (paid_on = ? AND id <= ?))');
    $st->execute([$doc['id'], $receipt['paid_on'], $receipt['paid_on'], $receipt['id']]);
    render_receipt($receipt, $doc, (float)$st->fetchColumn());
} else {
    $opt = [];
    if ($doc['type'] === 'invoice') {
        $opt['paid'] = doc_paid($pdo, (int)$doc['id']);
        $st = $pdo->prepare('SELECT * FROM payments WHERE document_id = ? ORDER BY paid_on, id');
        $st->execute([$doc['id']]);
        $opt['payments'] = $st->fetchAll();
        if ($doc['source_id'] && ($src = doc_load($pdo, (int)$doc['source_id']))) $opt['source_number'] = $src['number'];
    }
    render_document($doc, $opt);
}
?>
<script src="assets/js/qrcode.js"></script>
<script src="assets/js/print.js"></script>
</body>
</html>
