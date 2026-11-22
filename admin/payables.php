<?php
/* APOPS Send (payables): money going out (salary, transport, lunch, suppliers...).
 * Flow (as in APOPS Send): request → approve (approver, never one's own request; authenticator code if the approver has one)
 * → pay from the company's M-Pesa / EVC / bank → record the confirmation code (it counts as paid only then).
 * The owner decides which categories exist and the most one request may be. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/doc-templates.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('payables', 'payables_ok');
$pdo = db();
pp_migrate($pdo);

$me = current_user();
$approver = can('payables_ok');
$owner = ($me['role'] ?? '') === 'owner';
$methods = ['mpesa' => 'M-Pesa', 'evc' => 'EVC Plus', 'bank' => 'Bank transfer', 'cash' => 'Cash'];
$statuses = ['submitted' => 'Waiting for approval', 'approved' => 'Approved: to be paid', 'paid' => 'Paid', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
$cats = $pdo->query('SELECT * FROM payout_categories ORDER BY active DESC, sort_order, name')->fetchAll();
$catById = array_column($cats, null, 'id');
$cur = setting('currency', 'KES');
$tab = $_GET['tab'] ?? 'list';

$load = function (int $id) use ($pdo, $approver, $me): ?array {
    $st = $pdo->prepare('SELECT * FROM payouts WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch() ?: null;
    if ($p && !$approver && (int)$p['requested_by'] !== (int)$me['id']) return null;
    return $p;
};
// Approving and paying need the authenticator code when the approver has one
$codeOk = function () use ($pdo, $me): bool {
    if (!(int)$me['totp_on']) return true;
    return user_totp_ok($pdo, current_user(), (string)($_POST['code'] ?? ''));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect('payables.php'); }
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $clean = fn(string $k, int $n) => ($v = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n)) === '' ? null : $v;

    if ($action === 'request') {
        $cat = $catById[(int)($_POST['category_id'] ?? 0)] ?? null;
        $amount = round((float)str_replace(',', '', (string)($_POST['amount'] ?? 0)), 2);
        $payee = $clean('payee_name', 160);
        $purpose = $clean('purpose', 255);
        $method = isset($methods[$_POST['method'] ?? '']) ? $_POST['method'] : 'mpesa';
        if (!$cat || !(int)$cat['active']) { flash('Choose what the money is for.', 'err'); redirect('payables.php?tab=new'); }
        if ($amount <= 0 || !$payee || !$purpose) { flash('Fill in who is paid, the amount and the reason.', 'err'); redirect('payables.php?tab=new'); }
        if ((float)$cat['max_amount'] > 0 && $amount > (float)$cat['max_amount']) {
            flash(e($cat['name']) . " requests are limited to $cur " . money((float)$cat['max_amount']) . '. Ask the owner if more is needed.', 'err');
            redirect('payables.php?tab=new');
        }
        $ref = 'SB-PO-' . date('Y') . '-' . str_pad((string)((int)$pdo->query("SELECT COUNT(*) FROM payouts WHERE ref LIKE 'SB-PO-" . date('Y') . "-%'")->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
        $wo = (int)($_POST['work_order_id'] ?? 0) ?: null;
        $pdo->prepare('INSERT INTO payouts (ref, category_id, payee_name, payee_phone, method, account, amount, purpose, work_order_id, status, requested_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$ref, $cat['id'], $payee, $clean('payee_phone', 40), $method, $clean('account', 120), $amount, $purpose, $wo, 'submitted', $me['id'], now(), now()]);
        audit($pdo, 'payout_requested', "$ref {$cat['name']} " . money($amount) . " to $payee");
        pp_mail(setting('notify_email'), "APOPS Send: payout to approve $ref — $cur " . money($amount),
            user_label($me) . " asked for $cur " . money($amount) . " ({$cat['name']}) to $payee.\nReason: $purpose\n\nApprove or reject: " . site_url('admin/payables.php?id=' . $pdo->lastInsertId()) . "\n", null, $e);
        flash("Request $ref sent for approval.");
        redirect('payables.php');
    }

    $p = $id ? $load($id) : null;
    if (in_array($action, ['approve', 'reject', 'paid', 'cancel'], true) && !$p) { flash('Payout not found.', 'err'); redirect('payables.php'); }

    if (in_array($action, ['approve', 'reject'], true) && $approver && $p['status'] === 'submitted') {
        if ((int)$p['requested_by'] === (int)$me['id'] && !$owner) { flash('You cannot approve your own request. Another approver must do it.', 'err'); redirect('payables.php?id=' . $id); }
        if (!$codeOk()) { flash('The authenticator code is not right.', 'err'); redirect('payables.php?id=' . $id); }
        $ok = $action === 'approve';
        $pdo->prepare('UPDATE payouts SET status = ?, approved_by = ?, approved_at = ?, note = ?, updated_at = ? WHERE id = ?')
            ->execute([$ok ? 'approved' : 'rejected', $me['id'], now(), $clean('note', 255), now(), $id]);
        audit($pdo, $ok ? 'payout_approved' : 'payout_rejected', $p['ref'] . ((int)$p['requested_by'] === (int)$me['id'] ? ' (owner approved own request)' : ''));
        flash($ok ? "Approved. Pay {$p['payee_name']} from the company account, then record the confirmation code." : 'Rejected.');
        redirect('payables.php?id=' . $id);
    }

    if ($action === 'paid' && $approver && $p['status'] === 'approved') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($_POST['paid_ref'] ?? '')));
        if ($p['method'] !== 'cash' && strlen($code) < 6) { flash('Enter the M-Pesa / EVC / bank confirmation code. A payout counts as paid only with it.', 'err'); redirect('payables.php?id=' . $id); }
        if ($code !== '') {
            $st = $pdo->prepare('SELECT ref FROM payouts WHERE paid_ref = ? AND id <> ?'); $st->execute([$code, $id]);
            if ($dup = $st->fetchColumn()) { flash("Code $code was already used on payout $dup.", 'err'); redirect('payables.php?id=' . $id); }
        }
        $pdo->prepare("UPDATE payouts SET status = 'paid', paid_by = ?, paid_at = ?, paid_ref = ?, updated_at = ? WHERE id = ?")
            ->execute([$me['id'], now(), $code ?: 'CASH', now(), $id]);
        audit($pdo, 'payout_paid', "{$p['ref']} " . money((float)$p['amount']) . " ref $code");
        flash('Recorded as paid.');
        redirect('payables.php?id=' . $id);
    }

    if ($action === 'cancel' && $p['status'] === 'submitted' && ((int)$p['requested_by'] === (int)$me['id'] || $approver)) {
        $pdo->prepare("UPDATE payouts SET status = 'cancelled', updated_at = ? WHERE id = ?")->execute([now(), $id]);
        audit($pdo, 'payout_cancelled', $p['ref']);
        flash('Request cancelled.');
        redirect('payables.php');
    }

    // Categories: only the owner (settings permission) defines what money may go out for
    if ($action === 'category' && can('settings')) {
        $name = $clean('name', 80);
        if (!$name) { flash('Give the category a name.', 'err'); redirect('payables.php?tab=categories'); }
        $max = max(0, round((float)str_replace(',', '', (string)($_POST['max_amount'] ?? 0)), 2));
        if ($id) $pdo->prepare('UPDATE payout_categories SET name = ?, max_amount = ?, active = ?, sort_order = ? WHERE id = ?')->execute([$name, $max, !empty($_POST['active']) ? 1 : 0, (int)($_POST['sort_order'] ?? 100), $id]);
        else $pdo->prepare('INSERT INTO payout_categories (name, max_amount, active, sort_order) VALUES (?,?,1,?)')->execute([$name, $max, (int)($_POST['sort_order'] ?? 100)]);
        audit($pdo, 'payout_category_saved', "$name max " . money($max));
        flash('Category saved.');
        redirect('payables.php?tab=categories');
    }
    flash('That action is not allowed for this payout.', 'err');
    redirect('payables.php' . ($id ? '?id=' . $id : ''));
}

$users = [];
foreach ($pdo->query('SELECT * FROM admins') as $u) $users[(int)$u['id']] = $u;
$view = isset($_GET['id']) ? $load((int)$_GET['id']) : null;
$f = flash();
admin_head('APOPS Send');
admin_bar('payables', admin_new_leads($pdo), admin_new_orders($pdo));
?>
<main class="wrap">
<?php admin_flash($f); ?>
  <div class="head"><h1 class="apops-h"><img src="../assets/img/apops-mark.png" alt="" width="34" height="36"> APOPS Send <small class="muted">· Payables</small></h1><a class="btn" href="?tab=new">New payout request</a></div>
  <p class="muted small">Payouts are sent from <?= e(setting('company_name')) ?>'s own M-Pesa, EVC Plus, bank or cash. APOPS Send runs the request, approval and record-keeping; it never holds funds.</p>
  <nav class="filters" aria-label="Payables">
    <a href="payables.php" <?= $tab === 'list' && !$view ? 'aria-current="true"' : '' ?>>Payouts</a>
    <a href="?tab=new" <?= $tab === 'new' ? 'aria-current="true"' : '' ?>>New request</a>
    <?php if (can('settings')): ?><a href="?tab=categories" <?= $tab === 'categories' ? 'aria-current="true"' : '' ?>>What can be paid (categories)</a><?php endif; ?>
  </nav>

<?php if ($view): $p = $view; $c = $catById[(int)$p['category_id']] ?? ['name' => '?']; ?>
  <section class="panel">
    <div class="head"><h2><?= e($p['ref']) ?> · <?= e($c['name']) ?></h2><span class="st st-<?= e($p['status']) ?>"><?= e($statuses[$p['status']]) ?></span></div>
    <p class="big-total"><?= e($cur) ?> <?= money((float)$p['amount']) ?></p>
    <p><strong>Pay to:</strong> <?= e($p['payee_name']) ?> <?= $p['payee_phone'] ? '· ' . e($p['payee_phone']) : '' ?> · <?= e($methods[$p['method']] ?? $p['method']) ?><?= $p['account'] ? ' · ' . e($p['account']) : '' ?></p>
    <p><strong>Reason:</strong> <?= e($p['purpose']) ?><?php if ($p['work_order_id']): ?> · <a href="workorders.php?id=<?= (int)$p['work_order_id'] ?>">work order</a><?php endif; ?></p>
    <p class="muted small">Requested by <?= e(user_label($users[(int)$p['requested_by']] ?? null)) ?> on <?= e(date('j M Y H:i', strtotime($p['created_at']))) ?>
      <?= $p['approved_by'] ? ' · ' . ($p['status'] === 'rejected' ? 'rejected' : 'approved') . ' by ' . e(user_label($users[(int)$p['approved_by']] ?? null)) . ' ' . e(date('j M H:i', strtotime($p['approved_at']))) : '' ?>
      <?= $p['paid_at'] ? ' · paid ' . e(date('j M H:i', strtotime($p['paid_at']))) . ', code ' . e((string)$p['paid_ref']) : '' ?></p>
    <?php if ($p['note']): ?><p class="msg"><?= e($p['note']) ?></p><?php endif; ?>

    <?php if ($approver && $p['status'] === 'submitted' && ((int)$p['requested_by'] !== (int)$me['id'] || $owner)): ?>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <label>Note (optional)<input name="note" maxlength="255"></label>
      <?php if ((int)$me['totp_on']): ?><label>Authenticator code<input name="code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code"></label><?php endif; ?>
      <button class="btn small" name="action" value="approve">Approve</button>
      <button class="btn small ghost" name="action" value="reject">Reject</button>
    </form>
    <?php if ((int)$p['requested_by'] === (int)$me['id']): ?><p class="muted small">This is your own request. As owner you may approve it; it is marked in the activity log.</p><?php endif; ?>
    <?php elseif ($p['status'] === 'submitted' && (int)$p['requested_by'] === (int)$me['id']): ?>
      <p class="muted">Waiting for an approver. You cannot approve your own request.</p>
    <?php endif; ?>

    <?php if ($approver && $p['status'] === 'approved'): ?>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="paid"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <p class="muted">Send <?= e($cur) ?> <?= money((float)$p['amount']) ?> to <?= e($p['payee_name']) ?> <?= $p['payee_phone'] ? '(' . e($p['payee_phone']) . ')' : '' ?> from the company <?= e($methods[$p['method']] ?? '') ?> account, then type the confirmation code here.</p>
      <label>Confirmation code<input name="paid_ref" maxlength="40" <?= $p['method'] === 'cash' ? '' : 'required' ?> placeholder="<?= $p['method'] === 'cash' ? 'optional for cash' : 'e.g. SKL1234XYZ' ?>"></label>
      <button class="btn small">Mark as paid</button>
    </form>
    <?php endif; ?>
    <?php if ($p['status'] === 'submitted'): ?>
    <form method="post" data-confirm="Cancel this request?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="link danger">Cancel request</button></form>
    <?php endif; ?>
  </section>

<?php elseif ($tab === 'new'): $wo = (int)($_GET['wo'] ?? 0); ?>
  <form method="post" class="panel">
    <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="work_order_id" value="<?= $wo ?: '' ?>">
    <h2>Request a payout</h2>
    <div class="grid3">
      <label>What is it for?<select name="category_id" required><?php foreach ($cats as $c): if (!$c['active']) continue; ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?><?= (float)$c['max_amount'] > 0 ? ' (max ' . money((float)$c['max_amount']) . ')' : '' ?></option><?php endforeach; ?></select></label>
      <label>Amount (<?= e($cur) ?>)<input name="amount" required inputmode="decimal"></label>
      <label>Pay by<select name="method"><?php foreach ($methods as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
      <label>Pay to (name)<input name="payee_name" required maxlength="160" value="<?= e(user_label($me)) ?>"></label>
      <label>Phone (M-Pesa / EVC)<input name="payee_phone" maxlength="40" value="<?= e((string)$me['phone']) ?>"></label>
      <label>Bank account / till (if any)<input name="account" maxlength="120"></label>
    </div>
    <label>Reason<input name="purpose" required maxlength="255" placeholder="e.g. Transport to Juja site for WO-0004"></label>
    <button class="btn">Send for approval</button>
  </form>

<?php elseif ($tab === 'categories' && can('settings')): ?>
  <section class="panel">
    <h2>What money can go out for</h2>
    <p class="muted">Staff can only request payouts in these categories. Set a limit per request (0 = no limit), or switch a category off.</p>
    <?php foreach ($cats as $c): ?>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="category"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <label>Name<input name="name" maxlength="80" value="<?= e($c['name']) ?>"></label>
      <label>Max per request<input name="max_amount" inputmode="decimal" value="<?= e((string)(float)$c['max_amount']) ?>"></label>
      <label>Order<input type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>"></label>
      <label class="check"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> In use</label>
      <button class="btn small ghost">Save</button>
    </form>
    <?php endforeach; ?>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="category">
      <label>New category<input name="name" maxlength="80" placeholder="e.g. Fuel" required></label>
      <label>Max per request<input name="max_amount" inputmode="decimal" value="0"></label>
      <button class="btn small">Add</button>
    </form>
  </section>

<?php else:
  $filter = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : '';
  $sql = 'SELECT * FROM payouts WHERE 1=1'; $args = [];
  if (!$approver) { $sql .= ' AND requested_by = ?'; $args[] = $me['id']; }
  if ($filter) { $sql .= ' AND status = ?'; $args[] = $filter; }
  $st = $pdo->prepare($sql . " ORDER BY CASE status WHEN 'submitted' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END, id DESC LIMIT 300");
  $st->execute($args);
  $rows = $st->fetchAll();
  $month = date('Y-m');
  $tot = []; foreach ($rows as $r) if ($r['status'] === 'paid' && str_starts_with((string)$r['paid_at'], $month)) $tot[$r['category_id']] = ($tot[$r['category_id']] ?? 0) + (float)$r['amount']; ?>
  <?php if ($approver): ?>
  <div class="stats">
    <div class="stat"><b><?= count(array_filter($rows, fn($r) => $r['status'] === 'submitted')) ?></b><span>Waiting for approval</span></div>
    <div class="stat"><b><?= count(array_filter($rows, fn($r) => $r['status'] === 'approved')) ?></b><span>Approved, to be paid</span></div>
    <div class="stat"><b><?= e($cur) ?> <?= money(array_sum($tot)) ?></b><span>Paid out this month</span></div>
    <?php foreach ($tot as $cid => $amt): ?><div class="stat"><b><?= money($amt) ?></b><span><?= e($catById[$cid]['name'] ?? '?') ?> this month</span></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <nav class="filters"><a href="payables.php" <?= !$filter ? 'aria-current="true"' : '' ?>>All</a><?php foreach ($statuses as $k => $v): ?><a href="?status=<?= $k ?>" <?= $filter === $k ? 'aria-current="true"' : '' ?>><?= e($v) ?></a><?php endforeach; ?></nav>
  <?php if (!$rows): ?><p class="empty">No payouts yet.</p><?php else: ?>
  <div class="table-wrap"><table class="doc-list">
    <thead><tr><th>Ref</th><th>For</th><th>Pay to</th><th>Requested by</th><th class="r">Amount</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="?id=<?= (int)$r['id'] ?>"><?= e($r['ref']) ?></a><br><small class="muted"><?= e(date('j M', strtotime($r['created_at']))) ?></small></td>
        <td><?= e($catById[$r['category_id']]['name'] ?? '?') ?><br><small class="muted"><?= e($r['purpose']) ?></small></td>
        <td><?= e($r['payee_name']) ?><br><small class="muted"><?= e($methods[$r['method']] ?? '') ?> <?= e((string)$r['payee_phone']) ?></small></td>
        <td><?= e(user_label($users[(int)$r['requested_by']] ?? null)) ?></td>
        <td class="r"><?= money((float)$r['amount']) ?></td>
        <td><span class="st st-<?= e($r['status']) ?>"><?= e($statuses[$r['status']] ?? $r['status']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
<?php endif; ?>
</main>
<script src="../assets/js/admin.js"></script>
</body>
</html>
