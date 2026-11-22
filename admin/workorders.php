<?php
/* Work orders: installation, maintenance and site-visit jobs, assigned to technicians.
 * Managers (permission "workorders") create and assign; technicians see and update only their own jobs. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/doc-templates.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('workorders', 'wo_assignee');
$pdo = db();
pp_migrate($pdo);

$me = current_user();
$manager = can('workorders');
$kinds = ['installation' => 'Installation', 'maintenance' => 'Maintenance / service', 'survey' => 'Site survey', 'repair' => 'Repair', 'delivery' => 'Delivery'];
$statuses = ['open' => 'Open (not assigned)', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'done' => 'Done', 'cancelled' => 'Cancelled'];
$techs = users_with($pdo, 'wo_assignee');

$load = function (int $id) use ($pdo, $manager, $me): ?array {
    $st = $pdo->prepare('SELECT * FROM work_orders WHERE id = ?');
    $st->execute([$id]);
    $w = $st->fetch() ?: null;
    if ($w && !$manager && (int)$w['assigned_to'] !== (int)$me['id']) return null;   // technicians: own jobs only
    return $w;
};
$notify = function (array $w, int $techId) use ($pdo, $techs): void {
    $t = $techs[$techId] ?? null;
    if (!$t || !$t['email']) return;
    pp_mail($t['email'], "Work order {$w['number']}: {$w['title']}",
        "Hello " . (strtok(user_label($t), ' ') ?: '') . ",\n\nYou have been given work order {$w['number']}.\n\n"
        . "Job: {$w['title']}\nClient: " . ($w['client_name'] ?: '-') . ' ' . ($w['client_phone'] ?: '') . "\nLocation: " . ($w['location'] ?: '-')
        . "\nDue: " . ($w['due_date'] ?: 'not set') . "\n\n" . ($w['details'] ? $w['details'] . "\n\n" : '')
        . "Open it in the portal: " . site_url('admin/workorders.php?id=' . $w['id']) . "\n", null, $err);
};
$clean = fn(string $k, int $n) => ($v = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n)) === '' ? null : $v;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $back = $id ? 'workorders.php?id=' . $id : 'workorders.php';
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect($back); }
    $action = $_POST['action'] ?? '';

    if ($action === 'save' && $manager) {
        $title = $clean('title', 200);
        if (!$title) { flash('Give the job a title.', 'err'); redirect($back); }
        $assign = (int)($_POST['assigned_to'] ?? 0);
        if ($assign && !isset($techs[$assign])) $assign = 0;
        $docId = null;
        if ($num = $clean('doc_number', 30)) {
            $st = $pdo->prepare('SELECT id FROM documents WHERE UPPER(number) = ?'); $st->execute([strtoupper($num)]);
            $docId = (int)$st->fetchColumn() ?: null;
            if (!$docId) flash("No quote or invoice numbered $num was found, so it was not linked.", 'warn');
        }
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['due_date'] ?? '')) ? $_POST['due_date'] : null;
        $kind = isset($kinds[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'installation';
        $pri = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_POST['priority'] : 'normal';
        $vals = [$title, $kind, $clean('client_name', 160), $clean('client_phone', 40), $clean('location', 200), $clean('details', 5000), $docId, $assign ?: null, $pri, $due];
        if ($id) {
            $old = $load($id);
            $status = $old['status'];
            if ($assign && $status === 'open') $status = 'assigned';
            if (!$assign && $status === 'assigned') $status = 'open';
            if (isset($statuses[$_POST['status'] ?? '']) && $_POST['status'] !== $old['status']) $status = $_POST['status'];
            $pdo->prepare('UPDATE work_orders SET title=?, kind=?, client_name=?, client_phone=?, location=?, details=?, document_id=?, assigned_to=?, priority=?, due_date=?, status=?, updated_at=? WHERE id=?')
                ->execute([...$vals, $status, now(), $id]);
            if ($assign && (int)$old['assigned_to'] !== $assign) $notify($load($id), $assign);
            audit($pdo, 'work_order_saved', "{$old['number']} → " . user_label($techs[$assign] ?? null));
        } else {
            $num = 'SB-WO-' . date('Y') . '-' . str_pad((string)((int)$pdo->query("SELECT COUNT(*) FROM work_orders WHERE number LIKE 'SB-WO-" . date('Y') . "-%'")->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
            $pdo->prepare('INSERT INTO work_orders (title, kind, client_name, client_phone, location, details, document_id, assigned_to, priority, due_date, number, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([...$vals, $num, $assign ? 'assigned' : 'open', $me['id'], now(), now()]);
            $id = (int)$pdo->lastInsertId();
            if ($assign) $notify($load($id), $assign);
            audit($pdo, 'work_order_created', $num);
            $back = 'workorders.php?id=' . $id;
        }
        flash('Work order saved.' . ($assign && ($techs[$assign]['email'] ?? '') ? ' The technician was emailed.' : ''));
        redirect($back);
    }

    if ($action === 'progress') {
        $w = $load($id);
        if (!$w) { flash('That job is not assigned to you.', 'err'); redirect('workorders.php'); }
        $st = in_array($_POST['status'] ?? '', ['in_progress', 'done', 'assigned'], true) ? $_POST['status'] : $w['status'];
        $note = trim((string)($_POST['note'] ?? ''));
        $report = trim((string)$w['report']);
        if ($note !== '') $report = trim($report . "\n\n[" . date('j M Y H:i') . ' · ' . user_label($me) . "]\n" . mb_substr($note, 0, 3000));
        $pdo->prepare('UPDATE work_orders SET status = ?, report = ?, done_at = ?, updated_at = ? WHERE id = ?')
            ->execute([$st, $report ?: null, $st === 'done' ? ($w['done_at'] ?: now()) : null, now(), $id]);
        audit($pdo, 'work_order_update', "{$w['number']} → $st");
        if ($st === 'done' && $w['status'] !== 'done') {
            pp_mail(setting('notify_email'), "Job done: {$w['number']} {$w['title']}", user_label($me) . " marked work order {$w['number']} as done.\n\n" . ($note ?: '') . "\n\n" . site_url('admin/workorders.php?id=' . $id), null, $e);
        }
        flash('Update saved.');
        redirect($back);
    }
    redirect($back);
}

$f = flash();
admin_head('Work orders');
admin_bar('workorders', admin_new_leads($pdo), admin_new_orders($pdo));
$view = isset($_GET['id']) ? $load((int)$_GET['id']) : null;
$new = $manager && isset($_GET['new']);
$prefill = [];
if ($new && !empty($_GET['doc'])) {
    $d = doc_load($pdo, (int)$_GET['doc']);
    if ($d) $prefill = ['title' => ($d['title'] ?: 'Installation') , 'client_name' => $d['client_company'] ?: $d['client_name'], 'client_phone' => $d['client_phone'], 'location' => $d['site'], 'doc_number' => $d['number']];
}
?>
<main class="wrap">
<?php admin_flash($f); ?>
<?php if ($view || $new): $w = $view ?: array_merge(['id' => 0, 'title' => '', 'kind' => 'installation', 'client_name' => '', 'client_phone' => '', 'location' => '', 'details' => '', 'document_id' => null, 'assigned_to' => null, 'priority' => 'normal', 'due_date' => '', 'status' => 'open', 'report' => '', 'number' => 'New'], $prefill);
  $docNum = $w['doc_number'] ?? '';
  if (!$docNum && $w['document_id']) { $st = $pdo->prepare('SELECT number FROM documents WHERE id = ?'); $st->execute([$w['document_id']]); $docNum = (string)$st->fetchColumn(); } ?>
  <p class="crumb"><a href="workorders.php">← All work orders</a></p>
  <div class="head"><h1><?= e($w['number']) ?><?= $w['title'] ? ' · ' . e($w['title']) : '' ?></h1><?php if ($view): ?><span class="st st-<?= e($w['status']) ?>"><?= e($statuses[$w['status']]) ?></span><?php endif; ?></div>
  <?php if ($manager): ?>
  <form method="post" class="panel">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
    <div class="grid3">
      <label class="span2">Job title<input name="title" required maxlength="200" value="<?= e($w['title']) ?>" placeholder="Install 10 kW hybrid system"></label>
      <label>Type<select name="kind"><?php foreach ($kinds as $k => $v): ?><option value="<?= $k ?>" <?= $w['kind'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label>Client<input name="client_name" maxlength="160" value="<?= e((string)$w['client_name']) ?>"></label>
      <label>Client phone<input name="client_phone" maxlength="40" value="<?= e((string)$w['client_phone']) ?>"></label>
      <label>Location / site<input name="location" maxlength="200" value="<?= e((string)$w['location']) ?>"></label>
      <label>Assign to<select name="assigned_to"><option value="">— not yet —</option><?php foreach ($techs as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$w['assigned_to'] === (int)$t['id'] ? 'selected' : '' ?>><?= e(user_label($t) . ' (' . role_name($t['role']) . ')') ?></option><?php endforeach; ?></select></label>
      <label>Due date<input type="date" name="due_date" value="<?= e((string)$w['due_date']) ?>"></label>
      <label>Priority<select name="priority"><?php foreach (['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'] as $k => $v): ?><option value="<?= $k ?>" <?= $w['priority'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
      <label>Quote / invoice no. (optional)<input name="doc_number" maxlength="30" value="<?= e($docNum) ?>" placeholder="SB-INV-2026-0001"></label>
      <?php if ($view): ?><label>Status<select name="status"><?php foreach ($statuses as $k => $v): ?><option value="<?= $k ?>" <?= $w['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label><?php endif; ?>
    </div>
    <label>Instructions for the technician<textarea name="details" rows="4"><?= e((string)$w['details']) ?></textarea></label>
    <button class="btn"><?= $view ? 'Save' : 'Create work order' ?></button>
    <?php if (!$techs): ?><p class="muted small">No one can take jobs yet. Add a technician under Staff.</p><?php endif; ?>
  </form>
  <?php else: ?>
  <section class="panel">
    <p><strong>Client:</strong> <?= e((string)$w['client_name']) ?> <?php if ($w['client_phone']): ?>· <a href="tel:<?= e(preg_replace('/[^+0-9]/', '', $w['client_phone'])) ?>"><?= e($w['client_phone']) ?></a><?php endif; ?></p>
    <p><strong>Location:</strong> <?= e((string)$w['location']) ?> · <strong>Due:</strong> <?= e($w['due_date'] ?: 'not set') ?> · <strong>Priority:</strong> <span class="pri-<?= e($w['priority']) ?>"><?= e(ucfirst($w['priority'])) ?></span></p>
    <?php if ($w['details']): ?><p class="msg"><?= nl2br(e($w['details'])) ?></p><?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($view): ?>
  <section class="panel">
    <h2>Progress and report</h2>
    <?php if ($w['report']): ?><div class="thread"><?= nl2br(e($w['report'])) ?></div><?php else: ?><p class="muted">No updates yet.</p><?php endif; ?>
    <?php if ($manager || (int)$w['assigned_to'] === (int)$me['id']): ?>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="progress"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
      <label>Add an update (what was done, materials used, next steps)<textarea name="note" rows="3"></textarea></label>
      <div class="inline-form">
        <label>Status<select name="status"><option value="assigned" <?= $w['status'] === 'assigned' ? 'selected' : '' ?>>Not started</option><option value="in_progress" <?= $w['status'] === 'in_progress' ? 'selected' : '' ?>>In progress</option><option value="done" <?= $w['status'] === 'done' ? 'selected' : '' ?>>Done</option></select></label>
        <button class="btn small">Save update</button>
        <?php if (can('payables')): ?><a class="link" href="payables.php?tab=new&amp;wo=<?= (int)$w['id'] ?>">Request transport / lunch for this job</a><?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
<?php else:
  $filter = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : '';
  $sql = 'SELECT * FROM work_orders WHERE 1=1'; $args = [];
  if (!$manager) { $sql .= ' AND assigned_to = ?'; $args[] = $me['id']; }
  if ($filter) { $sql .= ' AND status = ?'; $args[] = $filter; } elseif (!isset($_GET['all'])) { $sql .= " AND status NOT IN ('done','cancelled')"; }
  $st = $pdo->prepare($sql . " ORDER BY CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END, COALESCE(due_date, '9999-12-31'), id DESC LIMIT 300");
  $st->execute($args);
  $rows = $st->fetchAll();
  $users = [];
  foreach ($pdo->query('SELECT * FROM admins') as $u) $users[(int)$u['id']] = $u; ?>
  <div class="head"><h1><?= $manager ? 'Work orders' : 'My jobs' ?></h1><?php if ($manager): ?><a class="btn" href="?new=1">New work order</a><?php endif; ?></div>
  <nav class="filters" aria-label="Filter">
    <a href="workorders.php" <?= !$filter && !isset($_GET['all']) ? 'aria-current="true"' : '' ?>>Active</a>
    <?php foreach ($statuses as $k => $v): ?><a href="?status=<?= $k ?>" <?= $filter === $k ? 'aria-current="true"' : '' ?>><?= e($v) ?></a><?php endforeach; ?>
    <a href="?all=1" <?= isset($_GET['all']) ? 'aria-current="true"' : '' ?>>All</a>
  </nav>
  <?php if (!$rows): ?><p class="empty">No work orders here.</p><?php else: ?>
  <div class="table-wrap"><table class="doc-list">
    <thead><tr><th>No.</th><th>Job</th><th>Client / location</th><th>Assigned to</th><th>Due</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $late = $r['due_date'] && $r['due_date'] < date('Y-m-d') && !in_array($r['status'], ['done', 'cancelled'], true); ?>
      <tr>
        <td><a href="?id=<?= (int)$r['id'] ?>"><?= e($r['number']) ?></a></td>
        <td><a href="?id=<?= (int)$r['id'] ?>"><?= e($r['title']) ?></a><br><small class="muted"><?= e($kinds[$r['kind']] ?? $r['kind']) ?><?= $r['priority'] === 'high' ? ' · <span class="pri-high">High priority</span>' : '' ?></small></td>
        <td><?= e((string)$r['client_name']) ?><br><small class="muted"><?= e((string)$r['location']) ?></small></td>
        <td><?= e(user_label($users[(int)$r['assigned_to']] ?? null)) ?></td>
        <td class="<?= $late ? 'due-txt' : '' ?>"><?= $r['due_date'] ? e(date('j M', strtotime($r['due_date']))) . ($late ? ' (late)' : '') : '—' ?></td>
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
