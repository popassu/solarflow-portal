<?php
/* APOPS Pay (receivables): what customers owe and what has come in.
 * Send a payment request (email with the pay link), push an M-Pesa or EVC Plus prompt to the customer's phone,
 * or record a payment received another way; every payment issues a (interim) receipt automatically. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/mpesa.php';
require_once __DIR__ . '/../includes/waafi.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('receivables');
$pdo = db();
pp_migrate($pdo);
maybe_run_schedules($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect('receivables.php'); }
    $d = doc_load($pdo, (int)($_POST['id'] ?? 0));
    if (!$d || $d['type'] !== 'invoice') { flash('Invoice not found.', 'err'); redirect('receivables.php'); }
    [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    $due = round($total - doc_paid($pdo, (int)$d['id']), 2);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'remind') {
            if (!$d['client_email']) throw new RuntimeException('This invoice has no client email. Use WhatsApp or a phone prompt.');
            $ok = doc_email($pdo, $d, 'This is a reminder: ' . doc_cur($d) . ' ' . money($due) . ' is still due on this invoice. You can pay online from the link below.', $err);
            flash($ok ? 'Payment request emailed to ' . $d['client_email'] . '.' : 'Email failed: ' . e((string)$err), $ok ? 'ok' : 'err');
        } elseif ($action === 'mpesa') {
            if ($due <= 0) throw new RuntimeException('Nothing is due.');
            if (doc_cur($d) !== 'KES') throw new RuntimeException('M-Pesa takes KES only.');
            $r = mpesa_stk($pdo, (string)($_POST['phone'] ?? ''), $due, $d['number'], 'Invoice', ['document_id' => (int)$d['id']]);
            audit($pdo, 'stk_sent', "{$d['number']} " . money($due));
            flash('M-Pesa prompt sent. When the customer enters their PIN the payment and receipt are recorded automatically.');
        } elseif ($action === 'evc') {
            if ($due <= 0) throw new RuntimeException('Nothing is due.');
            $r = waafi_purchase((string)($_POST['phone'] ?? ''), $due, $d['number'], 'Invoice ' . $d['number'], doc_cur($d));
            if (!$r['ok']) throw new RuntimeException($r['message']);
            record_payment($pdo, (int)$d['id'], $due, 'evc', $r['tx'] ?: null, date('Y-m-d'), "EVC Plus online ({$r['charged'][0]} " . number_format($r['charged'][1], 2) . " from {$r['msisdn']})", true, $m);
            flash('EVC Plus payment approved. ' . $m);
        }
    } catch (Throwable $e) {
        flash(e($e->getMessage()), 'err');
    }
    redirect('receivables.php');
}

$rows = [];
$totals = ['due' => [], 'overdue' => [], 'month' => []];
foreach ($pdo->query("SELECT * FROM documents WHERE type = 'invoice' AND status IN ('draft','sent','viewed','partly','overdue') ORDER BY doc_date, id")->fetchAll() as $r) {
    $r['items'] = json_decode((string)$r['items'], true) ?: [];
    [, , , $t] = doc_totals($r['items'], doc_rate($r), (float)$r['discount']);
    $r['total'] = $t;
    $r['bal'] = round($t - doc_paid($pdo, (int)$r['id']), 2);
    if ($r['bal'] <= 0) continue;
    $r['late'] = doc_is_overdue($r);
    $c = doc_cur($r);
    if ($r['status'] !== 'draft') $totals['due'][$c] = ($totals['due'][$c] ?? 0) + $r['bal'];
    if ($r['late']) $totals['overdue'][$c] = ($totals['overdue'][$c] ?? 0) + $r['bal'];
    $rows[] = $r;
}
$recent = $pdo->query('SELECT p.*, d.number, d.client_name, d.client_company, d.currency FROM payments p JOIN documents d ON d.id = p.document_id ORDER BY p.id DESC LIMIT 25')->fetchAll();
foreach ($recent as $p) if (str_starts_with((string)$p['paid_on'], date('Y-m'))) $totals['month'][$p['currency'] ?: setting('currency', 'KES')] = ($totals['month'][$p['currency'] ?: setting('currency', 'KES')] ?? 0) + (float)$p['amount'];
$quotes = $pdo->query("SELECT * FROM documents WHERE type IN ('quote','proposal') AND status IN ('sent','viewed','accepted') ORDER BY id DESC LIMIT 20")->fetchAll();
$fmt = fn(array $by) => $by ? implode(' + ', array_map(fn($c, $v) => $c . ' ' . money($v), array_keys($by), $by)) : '0.00';
$f = flash();
admin_head('APOPS Pay');
admin_bar('receivables', admin_new_leads($pdo), admin_new_orders($pdo));
$statusNames = doc_statuses('invoice');
?>
<main class="wrap">
<?php admin_flash($f); ?>
  <div class="head"><h1 class="apops-h"><img src="../assets/img/apops-mark.png" alt="" width="34" height="36"> APOPS Pay <small class="muted">· Receivables</small></h1><?php if (can('docs')): ?><a class="btn" href="documents.php?new=invoice">New invoice</a><?php endif; ?></div>
  <p class="muted small">Customers pay straight into <?= e(setting('company_name')) ?>'s own till, EVC Plus merchant or bank. APOPS Pay processes and records each payment and issues the receipt; it never holds funds.</p>
  <div class="stats">
    <div class="stat"><b><?= e($fmt($totals['due'])) ?></b><span>Owed on sent invoices</span></div>
    <div class="stat"><b class="due-txt"><?= e($fmt($totals['overdue'])) ?></b><span>Overdue</span></div>
    <div class="stat"><b><?= e($fmt($totals['month'])) ?></b><span>Received this month</span></div>
    <div class="stat"><b><?= mpesa_ready() ? 'On' : 'Off' ?> / <?= waafi_ready() ? 'On' : 'Off' ?></b><span>M-Pesa / EVC Plus online</span></div>
  </div>

  <section class="panel">
    <h2>Unpaid invoices</h2>
    <?php if (!$rows): ?><p class="empty">Nothing is owed. 🎉</p><?php else: ?>
    <div class="table-wrap"><table class="doc-list">
      <thead><tr><th>Invoice</th><th>Client</th><th>Status</th><th class="r">Balance</th><th>Collect</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $phone = (string)$r['client_phone']; $so = str_starts_with(preg_replace('/\D/', '', $phone), '252'); ?>
        <tr>
          <td><a href="documents.php?id=<?= (int)$r['id'] ?>"><?= e($r['number']) ?></a><br><small class="muted"><?= e(date('j M Y', strtotime($r['doc_date']))) ?> · due <?= e(date('j M', strtotime($r['doc_date']) + 86400 * (int)$r['due_days'])) ?></small></td>
          <td><?= e($r['client_company'] ?: $r['client_name'] ?: '—') ?><br><small class="muted"><?= e($phone) ?></small></td>
          <td><span class="st st-<?= $r['late'] ? 'overdue' : e($r['status']) ?>"><?= $r['late'] && $r['status'] !== 'overdue' ? 'Overdue · ' . e($statusNames[$r['status']] ?? '') : e($statusNames[$r['status']] ?? $r['status']) ?></span><?= $r['viewed_at'] ? '<br><small class="muted">opened ' . e(date('j M', strtotime($r['viewed_at']))) . '</small>' : '' ?></td>
          <td class="r"><strong><?= e(doc_cur($r)) ?> <?= money($r['bal']) ?></strong><br><small class="muted">of <?= money($r['total']) ?></small></td>
          <td>
            <?php if ($r['status'] === 'draft'): ?><a class="link" href="documents.php?id=<?= (int)$r['id'] ?>">Draft: send it first</a><?php else: ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php if ($r['client_email']): ?><button class="link" name="action" value="remind">Email payment request</button><?php endif; ?>
            </form>
            <?php if (mpesa_ready() && doc_cur($r) === 'KES'): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="mpesa">
              <input name="phone" value="<?= $so ? '' : e($phone) ?>" placeholder="07XX…" aria-label="M-Pesa phone" required><button class="btn small">M-Pesa prompt</button></form>
            <?php endif; ?>
            <?php if (waafi_ready()): ?>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="evc">
              <input name="phone" value="<?= $so ? e($phone) : '' ?>" placeholder="061…" aria-label="EVC Plus phone" required><button class="btn small ghost">EVC Plus prompt</button></form>
            <?php endif; ?>
            <a class="link" href="documents.php?id=<?= (int)$r['id'] ?>#payments">Record cash / bank</a>
            <?php if ($phone): ?> · <a class="link" href="https://wa.me/<?= e(wa_digits($phone)) ?>?text=<?= e(rawurlencode('Hello, a balance of ' . doc_cur($r) . ' ' . money($r['bal']) . ' is due on invoice ' . $r['number'] . '. Pay here: ' . doc_public_url($r))) ?>" target="_blank" rel="noopener">WhatsApp reminder</a><?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>

  <div class="grid2">
  <section class="panel">
    <h2>Latest receipts <a class="small" href="receipts.php">All receipts &amp; new receipt</a></h2>
    <?php if (!$recent): ?><p class="empty">No payments yet.</p><?php else: ?>
    <div class="table-wrap"><table class="doc-list"><thead><tr><th>Receipt</th><th>Invoice</th><th>Method</th><th class="r">Amount</th></tr></thead><tbody>
    <?php foreach ($recent as $p): ?>
      <tr><td><a href="print.php?receipt=<?= (int)$p['id'] ?>" target="_blank"><?= e($p['receipt_no']) ?></a><br><small class="muted"><?= e(date('j M', strtotime($p['paid_on']))) ?></small></td>
        <td><a href="documents.php?id=<?= (int)$p['document_id'] ?>"><?= e($p['number']) ?></a><br><small class="muted"><?= e($p['client_company'] ?: $p['client_name']) ?></small></td>
        <td><?= e(pay_methods()[$p['method']] ?? $p['method']) ?><br><small class="muted"><?= e((string)$p['reference']) ?></small></td>
        <td class="r"><?= e($p['currency'] ?: setting('currency', 'KES')) ?> <?= money((float)$p['amount']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>Quotes waiting on the client</h2>
    <?php if (!$quotes): ?><p class="empty">None.</p><?php else: ?>
    <ul class="plain">
    <?php foreach ($quotes as $q): [, , , $qt] = doc_totals(json_decode((string)$q['items'], true) ?: [], doc_rate($q), (float)$q['discount']); ?>
      <li><a href="documents.php?id=<?= (int)$q['id'] ?>"><?= e($q['number']) ?></a> · <?= e($q['client_company'] ?: $q['client_name'] ?: '—') ?> · <?= e(doc_cur($q)) ?> <?= money($qt) ?> · <span class="st st-<?= e($q['status']) ?>"><?= e(doc_statuses($q['type'])[$q['status']] ?? $q['status']) ?></span><?= $q['status'] === 'accepted' ? ' → convert to invoice' : '' ?></li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  </div>
</main>
<script src="../assets/js/admin.js"></script>
</body>
</html>
