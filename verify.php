<?php
/* Public authenticity check for quotations, invoices and receipts (the QR code target). */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/doc-templates.php';
require_once __DIR__ . '/includes/doc-render.php';
require_once __DIR__ . '/includes/layout.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

// Clean addresses: /verify, /v/<token> (document), /v/r/<token> (receipt). Old verify.php links move there for good.
$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_ends_with($uri, '/verify.php')) {
    $t = (string)($_GET['t'] ?? ''); $r = (string)($_GET['r'] ?? '');
    $to = preg_match('/^[a-f0-9]{48}$/', $r) ? 'v/r/' . $r : (preg_match('/^[a-f0-9]{48}$/', $t) ? 'v/' . $t : 'verify');
    header('Location: ' . site_url($to), true, 301);
    exit;
}
// Pages served from /v/... need a base so relative links (css, images) still work
$base = rtrim((string)parse_url(site_url(''), PHP_URL_PATH), '/') . '/';
ob_start(fn($html) => preg_replace('/<head>/', '<head><base href="' . htmlspecialchars($base) . '">', $html, 1));

$result = null;   // ['ok' => bool, ...]
$pdo = db_ready() ? db() : null;
$tok = (string)($_GET['t'] ?? $_GET['r'] ?? '');

if ($pdo && preg_match('/^[a-f0-9]{48}$/', $tok)) {
    if (isset($_GET['r'])) {
        $st = $pdo->prepare('SELECT * FROM payments WHERE token = ?');
        $st->execute([$tok]);
        if ($p = $st->fetch()) {
            $inv = doc_load($pdo, (int)$p['document_id']);
            $result = ['ok' => true, 'kind' => 'Receipt', 'number' => $p['receipt_no'], 'date' => $p['paid_on'], 'client' => $inv ? ($inv['client_company'] ?: $inv['client_name']) : '',
                       'amount' => (float)$p['amount'], 'status' => 'Payment received' . ($p['reference'] ? ' (ref. ' . $p['reference'] . ')' : ''), 'code' => receipt_security_code($p),
                       'for' => $inv ? 'Invoice ' . $inv['number'] : '', 'cur' => $inv ? doc_cur($inv) : null];
        } else $result = ['ok' => false];
    } else {
        $st = $pdo->prepare('SELECT id FROM documents WHERE token = ?');
        $st->execute([$tok]);
        $result = ($id = (int)$st->fetchColumn()) ? doc_result($pdo, doc_load($pdo, $id)) : ['ok' => false];
    }
} elseif ($pdo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['verify'] = array_values(array_filter($_SESSION['verify'] ?? [], fn($t) => $t > time() - 600));
    $_SESSION['verify'][] = time();
    $num = strtoupper(trim((string)($_POST['number'] ?? '')));
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')));
    if (!csrf_ok() || count($_SESSION['verify']) > 15) {
        $result = ['ok' => false, 'msg' => 'Too many checks. Please wait a few minutes.'];
    } else {
        $st = $pdo->prepare('SELECT id FROM documents WHERE UPPER(number) = ?');
        $st->execute([$num]);
        $d = ($id = (int)$st->fetchColumn()) ? doc_load($pdo, $id) : null;
        if ($d && hash_equals(str_replace('-', '', doc_security_code($d)), $code)) {
            $result = doc_result($pdo, $d);
        } else {
            $st = $pdo->prepare('SELECT * FROM payments WHERE UPPER(receipt_no) = ?');
            $st->execute([$num]);
            $p = $st->fetch();
            if ($p && hash_equals(str_replace('-', '', receipt_security_code($p)), $code)) {
                $result = ['ok' => true, 'kind' => 'Receipt', 'number' => $p['receipt_no'], 'date' => $p['paid_on'], 'client' => '', 'amount' => (float)$p['amount'],
                           'status' => 'Payment received', 'code' => receipt_security_code($p), 'for' => ''];
            } else {
                $result = ['ok' => false];
            }
        }
    }
}

function doc_result(PDO $pdo, array $d): array
{
    [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    $st = doc_statuses($d['type'])[$d['status']] ?? $d['status'];
    if ($d['type'] === 'invoice') $st .= ' · balance ' . doc_cur($d) . ' ' . money(max($total - doc_paid($pdo, (int)$d['id']), 0));
    return ['ok' => true, 'kind' => doc_types()[$d['type']], 'number' => $d['number'], 'date' => $d['doc_date'], 'client' => $d['client_company'] ?: $d['client_name'],
            'amount' => $total, 'status' => $st, 'code' => doc_security_code($d), 'for' => $d['title'] ?? '', 'cur' => doc_cur($d)];
}

page_head('Verify a document', 'Check that a Sunbeam Innovation quotation, invoice or receipt is genuine.');
site_header();
?>
<main>
<section class="page-hero"><div class="container"><h1>Verify a document</h1><p>Check that a quotation, invoice or receipt really comes from <?= e(setting('company_name')) ?>.</p></div></section>
<section class="section"><div class="container narrow">
  <?php if ($result && $result['ok']): ?>
    <div class="verify-result ok">
      <p class="verify-badge">✓ Genuine <?= e(setting('company_name')) ?> document</p>
      <dl class="verify-dl">
        <div><dt>Type</dt><dd><?= e($result['kind']) ?></dd></div>
        <div><dt>Number</dt><dd><?= e($result['number']) ?></dd></div>
        <div><dt>Date</dt><dd><?= e(date('j M Y', strtotime($result['date']))) ?></dd></div>
        <?php if ($result['client']): ?><div><dt>Issued to</dt><dd><?= e($result['client']) ?></dd></div><?php endif; ?>
        <?php if ($result['for']): ?><div><dt>For</dt><dd><?= e($result['for']) ?></dd></div><?php endif; ?>
        <div><dt>Amount</dt><dd><strong><?= e($result['cur'] ?? setting('currency', 'KES')) ?> <?= money($result['amount']) ?></strong></dd></div>
        <div><dt>Status</dt><dd><?= e($result['status']) ?></dd></div>
        <div><dt>Security code</dt><dd><code><?= e($result['code']) ?></code></dd></div>
      </dl>
      <p class="muted">Compare these details with your copy. If the amount, number or code differ, do not pay and call us on <?= e(setting('phone_kenya')) ?>.</p>
    </div>
  <?php elseif ($result): ?>
    <div class="verify-result bad">
      <p class="verify-badge">✗ Not verified</p>
      <p><?= e($result['msg'] ?? 'We could not find a document with those details. It may have been altered or not issued by us. Do not pay it. Call ' . setting('phone_kenya') . ' or email ' . setting('email') . '.') ?></p>
    </div>
  <?php endif; ?>

  <form class="card-form" method="post" action="verify">
    <?= csrf_field() ?>
    <h2>Check by number</h2>
    <p class="muted">Both are printed beside the QR code on every document.</p>
    <label>Document or receipt number<input name="number" required maxlength="30" placeholder="SB-INV-2026-0001" value="<?= e((string)($_POST['number'] ?? '')) ?>"></label>
    <label>Security code<input name="code" required maxlength="20" placeholder="XXXX-XXXX-XXXX" autocomplete="off"></label>
    <button class="btn btn-primary">Verify</button>
  </form>
</div></section>
</main>
<?php site_footer(); ?>
