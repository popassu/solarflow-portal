<?php
/* Receipts: every payment received, interim (part payment) and final, in one place.
 * Same flow as APOPS Pay: an invoice is paid in full or in part -> one receipt per payment, numbered SB-R-YYYY-NNNN,
 * "Interim receipt" while a balance remains, "Final receipt" when the invoice is cleared; the invoice status moves to
 * Part paid / Paid and the receipt (PDF) is emailed to the client. M-Pesa and EVC Plus payments do this automatically.
 * Here staff can: list / search receipts, download the PDF, email it (again), and record a new receipt
 * (a) against an open invoice, or (b) for a walk-in sale with no invoice yet (a paid invoice is created behind it). */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/billing.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('receivables', 'docs');
$pdo = db();
pp_migrate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = (string)($_POST['back'] ?? '');
    $back = preg_match('#^(documents|receipts)\.php(\?[A-Za-z0-9=&_%-]*)?(\#[a-z]+)?$#', $back) ? $back : 'receipts.php';
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect($back); }
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'email') {
            $to = trim((string)($_POST['to'] ?? '')) ?: null;
            $ok = receipt_email($pdo, (int)($_POST['pid'] ?? 0), $err, $to);
            flash($ok ? 'Receipt emailed' . ($to ? " to $to" : '') . ' with the PDF attached.' : 'Email failed: ' . e((string)$err), $ok ? 'ok' : 'err');
            redirect($back);
        }

        if ($action === 'new') {
            $amount = round((float)str_replace(',', '', (string)($_POST['amount'] ?? 0)), 2);
            $method = isset(pay_methods()[$_POST['method'] ?? '']) ? $_POST['method'] : 'cash';
            $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['paid_on'] ?? '')) ? $_POST['paid_on'] : date('Y-m-d');
            $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 80);
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            $send = !empty($_POST['email_receipt']);
            if ($amount <= 0) throw new RuntimeException('Enter the amount received.');

            if (($_POST['mode'] ?? 'invoice') === 'sale') {
                // Walk-in / direct sale: create the invoice behind the receipt so the books stay complete
                if (!can('docs')) throw new RuntimeException('Creating a sale invoice needs the Quotes & invoices permission.');
                $name = mb_substr(trim((string)($_POST['client_name'] ?? '')), 0, 120);
                $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
                if ($name === '' || $desc === '') throw new RuntimeException('Enter the customer name and what was sold.');
                $email = trim((string)($_POST['client_email'] ?? ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That email address is not valid.');
                $cur = isset(currencies()[$_POST['currency'] ?? '']) ? $_POST['currency'] : setting('currency', 'KES');
                $items = [['desc' => $desc, 'qty' => 1, 'unit' => 'lot', 'price' => $amount]];
                $pdo->prepare('INSERT INTO documents (type, number, title, client_name, client_company, client_phone, client_email, items, sections, vat, discount, notes, terms, valid_days, due_days, status, token, doc_date, created_at, updated_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(['invoice', doc_next_number($pdo, 'invoice'), $desc, $name, mb_substr(trim((string)($_POST['client_company'] ?? '')), 0, 120) ?: null,
                               mb_substr(trim((string)($_POST['client_phone'] ?? '')), 0, 30) ?: null, $email ?: null, json_encode($items), json_encode([]), 0, 0,
                               'Direct sale, paid on the spot.', doc_default_terms('invoice'), 30, 0, 'sent', bin2hex(random_bytes(24)), $paidOn, now(), now()]);
                $docId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE documents SET currency = ? WHERE id = ?')->execute([$cur, $docId]);
                audit($pdo, 'sale_invoice', "invoice #$docId for direct sale receipt");
            } else {
                $docId = (int)($_POST['document_id'] ?? 0);
                $d = doc_load($pdo, $docId);
                if (!$d || $d['type'] !== 'invoice') throw new RuntimeException('Choose the invoice that was paid.');
            }
            $pid = record_payment($pdo, $docId, $amount, $method, $ref, $paidOn, $note, $send, $msg);
            if (!$pid) {
                if (($_POST['mode'] ?? '') === 'sale') $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$docId]);
                throw new RuntimeException((string)$msg);
            }
            flash((string)$msg);
            redirect('receipts.php?show=' . $pid);
        }
    } catch (Throwable $e) {
        flash(e($e->getMessage()), 'err');
    }
    redirect($back);
}

/* ---------- list ---------- */
$q = trim((string)($_GET['q'] ?? ''));
$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : '';
$kindF = in_array($_GET['kind'] ?? '', ['interim', 'final'], true) ? $_GET['kind'] : '';

// running paid-to-date per invoice, so each receipt knows whether it was interim or final
$all = $pdo->query('SELECT p.*, d.number, d.client_name, d.client_company, d.client_email, d.currency, d.items, d.vat, d.tax_rate, d.discount
                    FROM payments p JOIN documents d ON d.id = p.document_id ORDER BY p.document_id, p.paid_on, p.id')->fetchAll();
$run = []; $tot = []; $rows = [];
foreach ($all as $p) {
    $did = (int)$p['document_id'];
    if (!isset($tot[$did])) { [, , , $tot[$did]] = doc_totals(json_decode((string)$p['items'], true) ?: [], doc_rate($p), (float)$p['discount']); $run[$did] = 0.0; }
    $run[$did] += (float)$p['amount'];
    $p['total'] = $tot[$did];
    $p['bal_after'] = max(round($tot[$did] - $run[$did], 2), 0);
    $p['kind'] = $p['bal_after'] > 0.005 ? 'interim' : 'final';
    if ($month && !str_starts_with((string)$p['paid_on'], $month)) continue;
    if ($kindF && $p['kind'] !== $kindF) continue;
    if ($q !== '' && stripos($p['receipt_no'] . ' ' . $p['number'] . ' ' . $p['client_name'] . ' ' . $p['client_company'] . ' ' . $p['reference'], $q) === false) continue;
    $rows[] = $p;
}
usort($rows, fn($a, $b) => [$b['paid_on'], $b['id']] <=> [$a['paid_on'], $a['id']]);
$sum = [];
foreach ($rows as $p) { $c = $p['currency'] ?: setting('currency', 'KES'); $sum[$c] = ($sum[$c] ?? 0) + (float)$p['amount']; }
$shown = array_slice($rows, 0, 300);

// open invoices for "New receipt"
$open = [];
foreach ($pdo->query("SELECT * FROM documents WHERE type = 'invoice' AND status NOT IN ('paid','cancelled') ORDER BY id DESC LIMIT 300")->fetchAll() as $d) {
    [, , , $t] = doc_totals(json_decode((string)$d['items'], true) ?: [], doc_rate($d), (float)$d['discount']);
    $bal = round($t - doc_paid($pdo, (int)$d['id']), 2);
    if ($bal > 0.005) $open[] = ['id' => (int)$d['id'], 'label' => $d['number'] . ' · ' . ($d['client_company'] ?: $d['client_name'] ?: '—') . ' · balance ' . doc_cur($d) . ' ' . money($bal), 'bal' => $bal];
}
$pick = (int)($_GET['invoice'] ?? 0);
$showId = (int)($_GET['show'] ?? 0);

$f = flash();
admin_head('Receipts');
admin_bar('receipts', admin_new_leads($pdo), admin_new_orders($pdo));
?>
<main class="wrap">
<?php admin_flash($f); ?>
  <div class="head"><h1>Receipts</h1><a class="btn" href="#new">New receipt</a></div>
  <p class="muted small">One receipt per payment. While a balance remains it is an <strong>interim receipt</strong>; the payment that clears the invoice gets the <strong>final receipt</strong>. M-Pesa and EVC Plus payments are receipted and emailed automatically; record cash, bank and cheque payments here or on the invoice.</p>

  <form class="filters" method="get">
    <input name="q" value="<?= e($q) ?>" placeholder="Receipt, invoice, client or reference" aria-label="Search">
    <input type="month" name="month" value="<?= e($month) ?>" aria-label="Month">
    <select name="kind" aria-label="Type"><option value="">Interim &amp; final</option><option value="interim" <?= $kindF === 'interim' ? 'selected' : '' ?>>Interim only</option><option value="final" <?= $kindF === 'final' ? 'selected' : '' ?>>Final only</option></select>
    <button class="btn small">Filter</button><?php if ($q || $month || $kindF): ?> <a class="link" href="receipts.php">Clear</a><?php endif; ?>
  </form>

  <section class="panel">
    <h2><?= count($rows) ?> receipt<?= count($rows) === 1 ? '' : 's' ?><?php if ($sum): ?> <small class="muted">· <?= e(implode(' + ', array_map(fn($c, $v) => $c . ' ' . money($v), array_keys($sum), $sum))) ?></small><?php endif; ?></h2>
    <?php if (!$shown): ?><p class="empty">No receipts yet. They appear here as soon as an invoice is paid, in full or in part.</p><?php else: ?>
    <div class="table-wrap"><table class="doc-list rcpt-list">
      <thead><tr><th>Receipt</th><th>Invoice / client</th><th>Method</th><th class="r">Amount</th><th class="r">Balance after</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($shown as $p): $cur = $p['currency'] ?: setting('currency', 'KES'); ?>
        <tr<?= $p['id'] == $showId ? ' class="hl"' : '' ?>>
          <td><a href="print.php?receipt=<?= (int)$p['id'] ?>" target="_blank"><strong><?= e($p['receipt_no']) ?></strong></a><br>
            <span class="st st-<?= $p['kind'] === 'final' ? 'paid' : 'partly' ?>"><?= $p['kind'] === 'final' ? 'Final' : 'Interim' ?></span> <small class="muted"><?= e(date('j M Y', strtotime($p['paid_on']))) ?></small></td>
          <td><a href="documents.php?id=<?= (int)$p['document_id'] ?>#payments"><?= e($p['number']) ?></a><br><small class="muted"><?= e($p['client_company'] ?: $p['client_name'] ?: '—') ?></small></td>
          <td><?= e(pay_methods()[$p['method']] ?? $p['method']) ?><br><small class="muted"><?= e((string)$p['reference']) ?></small></td>
          <td class="r"><strong><?= e($cur) ?> <?= money((float)$p['amount']) ?></strong></td>
          <td class="r"><?= money($p['bal_after']) ?><br><small class="muted">of <?= money($p['total']) ?></small></td>
          <td class="acts">
            <a class="link" href="print.php?receipt=<?= (int)$p['id'] ?>" target="_blank">View</a> ·
            <a class="link" href="print.php?receipt=<?= (int)$p['id'] ?>&amp;download=pdf">PDF</a> ·
            <a class="link" href="../doc.php?r=<?= e($p['token']) ?>" target="_blank" title="The client's link">Client link</a>
            <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>">
              <input type="email" name="to" value="<?= e((string)$p['client_email']) ?>" placeholder="client@email" aria-label="Email to" required><button class="btn small ghost">Email</button></form>
            <?php if (!empty($p['mail_result'])): ?><small class="muted"><?= str_starts_with($p['mail_result'], 'sent') ? 'Emailed ' . e(date('j M H:i', strtotime((string)$p['mail_at']))) : e($p['mail_result']) ?></small><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($rows) > count($shown)): ?><p class="muted small">Showing the latest <?= count($shown) ?>. Filter by month to see older receipts.</p><?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="panel" id="new">
    <h2>New receipt</h2>
    <form method="post" class="rcpt-new"><?= csrf_field() ?><input type="hidden" name="action" value="new">
      <fieldset class="mode">
        <label class="check"><input type="radio" name="mode" value="invoice" <?= $open ? 'checked' : '' ?> data-mode> Payment on an invoice</label>
        <?php if (can('docs')): ?><label class="check"><input type="radio" name="mode" value="sale" <?= $open ? '' : 'checked' ?> data-mode> Direct sale (no invoice yet)</label><?php endif; ?>
      </fieldset>
      <div data-for="invoice">
        <?php if ($open): ?>
        <label>Invoice<select name="document_id" data-inv><?php foreach ($open as $o): ?><option value="<?= $o['id'] ?>" data-bal="<?= e((string)$o['bal']) ?>" <?= $pick === $o['id'] ? 'selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></label>
        <?php else: ?><p class="muted">No invoice has a balance right now.</p><?php endif; ?>
      </div>
      <div data-for="sale" class="grid2">
        <label>Customer name<input name="client_name" maxlength="120"></label>
        <label>Company (optional)<input name="client_company" maxlength="120"></label>
        <label>Phone<input name="client_phone" maxlength="30" inputmode="tel"></label>
        <label>Email (to send the receipt)<input type="email" name="client_email" maxlength="160"></label>
        <label>What was sold<input name="description" maxlength="255" placeholder="e.g. 2 × 550W panels"></label>
        <label>Currency<select name="currency"><?php foreach (currencies() as $k => $v): ?><option value="<?= $k ?>" <?= setting('currency', 'KES') === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="grid2">
        <label>Amount received<input name="amount" inputmode="decimal" required data-amt value="<?= e((string)($open[0]['bal'] ?? '')) ?>"></label>
        <label>Method<select name="method"><?php foreach (pay_methods() as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
        <label>Reference (M-Pesa code, bank ref, cheque no.)<input name="reference" maxlength="80"></label>
        <label>Date received<input type="date" name="paid_on" value="<?= date('Y-m-d') ?>"></label>
      </div>
      <label>Note (optional)<input name="note" maxlength="255"></label>
      <label class="check"><input type="checkbox" name="email_receipt" value="1" checked> Email the receipt (PDF) to the client</label>
      <button class="btn wide">Record payment &amp; issue receipt</button>
      <p class="muted small">Less than the balance gives an interim receipt; the full balance gives the final receipt and marks the invoice Paid.</p>
    </form>
  </section>
</main>
<script>
(function () {
  var f = document.querySelector('.rcpt-new'); if (!f) return;
  function sync() {
    var m = (f.querySelector('[data-mode]:checked') || {}).value || 'invoice';
    f.querySelectorAll('[data-for]').forEach(function (b) {
      var on = b.getAttribute('data-for') === m; b.hidden = !on;
      b.querySelectorAll('input,select').forEach(function (i) { i.disabled = !on; });
    });
    var n = f.querySelector('[name=client_name]'), s = f.querySelector('[name=description]');
    if (n) { n.required = m === 'sale'; s.required = m === 'sale'; }
  }
  f.querySelectorAll('[data-mode]').forEach(function (r) { r.addEventListener('change', sync); });
  var inv = f.querySelector('[data-inv]'), amt = f.querySelector('[data-amt]');
  if (inv) inv.addEventListener('change', function () { amt.value = inv.selectedOptions[0].getAttribute('data-bal'); });
  if (inv && inv.value) amt.value = inv.selectedOptions[0].getAttribute('data-bal');
  sync();
})();
</script>
<script src="../assets/js/admin.js"></script>
</body>
</html>
