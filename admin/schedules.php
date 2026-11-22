<?php
/* Scheduled (recurring) billing: monthly invoices that stop on an end date. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/billing.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('schedules');

$pdo = db();
pp_migrate($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect('schedules.php'); }
    $id = (int)($_POST['id'] ?? 0);
    switch ($_POST['action'] ?? '') {
        case 'update':
            $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['end_date'] ?? '')) ? $_POST['end_date'] : null;
            $next = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['next_run'] ?? '')) ? $_POST['next_run'] : date('Y-m-d');
            $pdo->prepare('UPDATE schedules SET every_months = ?, next_run = ?, end_date = ?, auto_email = ?, active = ? WHERE id = ?')
                ->execute([max(1, min(12, (int)$_POST['every_months'])), $next, $end, !empty($_POST['auto_email']) ? 1 : 0, !empty($_POST['active']) ? 1 : 0, $id]);
            flash('Schedule updated.');
            break;
        case 'run':
            $made = run_schedules($pdo);
            flash($made ? 'Created: ' . implode(', ', $made) . '.' : 'Nothing is due today.');
            break;
        case 'delete':
            $pdo->prepare('DELETE FROM schedules WHERE id = ?')->execute([$id]);
            flash('Schedule deleted. Invoices already created are kept.');
            break;
    }
    redirect('schedules.php');
}

$rows = $pdo->query('SELECT s.*, d.number tpl_number, d.title, d.client_name, d.client_company, d.client_email, d.items, d.vat, d.discount
                     FROM schedules s LEFT JOIN documents d ON d.id = s.template_id ORDER BY s.active DESC, s.next_run')->fetchAll();
$f = flash();
admin_head('Scheduled billing');
admin_bar('schedules', admin_new_leads($pdo), admin_new_orders($pdo));
?>
<main class="wrap">
  <?php admin_flash($f); ?>
  <div class="head">
    <h1>Scheduled billing</h1>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run"><button class="btn ghost">Run due invoices now</button></form>
  </div>
  <p class="muted">To set one up, open any invoice in <a href="documents.php?type=invoice">Quotes &amp; invoices</a> and use <strong>Bill this monthly</strong>. On each date a new numbered invoice is created from it and emailed with the M-Pesa payment link, until the end date. Checks run hourly (cron job) and whenever the admin is opened. Last check: <?= e(setting('cron_last', 'never')) ?>.</p>

  <?php if (!$rows): ?>
    <p class="empty">No billing schedules yet.</p>
  <?php else: foreach ($rows as $r):
      [, , , $t] = doc_totals(json_decode((string)$r['items'], true) ?: [], doc_rate($r), (float)$r['discount']);
      $made = $pdo->prepare('SELECT id, number, doc_date, status FROM documents WHERE schedule_id = ? ORDER BY id DESC LIMIT 12');
      $made->execute([$r['id']]); $made = $made->fetchAll(); ?>
    <section class="panel">
      <div class="head">
        <h2><?= e($r['client_company'] ?: $r['client_name'] ?: 'Client') ?> — <?= e($r['title'] ?: 'Invoice') ?></h2>
        <span class="st <?= $r['active'] ? 'st-sent' : 'st-cancelled' ?>"><?= $r['active'] ? 'Active' : 'Stopped' ?></span>
      </div>
      <p class="muted"><?= e(setting('currency', 'KES')) ?> <?= money($t) ?> every <?= (int)$r['every_months'] === 1 ? 'month' : (int)$r['every_months'] . ' months' ?> · template <a href="documents.php?id=<?= (int)$r['template_id'] ?>"><?= e($r['tpl_number'] ?? 'deleted') ?></a> · <?= (int)$r['runs'] ?> sent so far<?= $r['client_email'] ? ' · to ' . e($r['client_email']) : ' · no client email' ?></p>
      <form method="post" class="grid3">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <label>Next invoice<input type="date" name="next_run" value="<?= e($r['next_run']) ?>"></label>
        <label>End date<input type="date" name="end_date" value="<?= e((string)$r['end_date']) ?>"></label>
        <label>Every<select name="every_months"><?php foreach ([1 => 'Month', 3 => '3 months', 6 => '6 months', 12 => 'Year'] as $k => $v): ?><option value="<?= $k ?>" <?= (int)$r['every_months'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
        <label class="check"><input type="checkbox" name="auto_email" value="1" <?= $r['auto_email'] ? 'checked' : '' ?>> Email automatically</label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $r['active'] ? 'checked' : '' ?>> Active</label>
        <div><button class="btn small">Save</button></div>
      </form>
      <?php if ($made): ?>
        <p class="small">Created: <?php foreach ($made as $i => $m): ?><?= $i ? ', ' : '' ?><a href="documents.php?id=<?= (int)$m['id'] ?>"><?= e($m['number']) ?></a> (<?= date('M Y', strtotime($m['doc_date'])) ?>, <?= e(doc_statuses('invoice')[$m['status']] ?? $m['status']) ?>)<?php endforeach; ?></p>
      <?php endif; ?>
      <form method="post" data-confirm="Delete this schedule? Invoices already created are kept."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link danger">Delete schedule</button></form>
    </section>
  <?php endforeach; endif; ?>
</main>
<script src="../assets/js/admin.js"></script>
</body>
</html>
