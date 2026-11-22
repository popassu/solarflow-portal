<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/doc-templates.php';
require_once __DIR__ . '/../includes/billing.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('docs');

$pdo = db();
pp_migrate($pdo);
$types = doc_types();

/* ---------- create a new draft ---------- */
if (isset($_GET['new'], $types[$_GET['new']])) {
    $type = $_GET['new'];
    $lead = null;
    if (!empty($_GET['lead'])) {
        $st = $pdo->prepare('SELECT * FROM leads WHERE id = ?');
        $st->execute([(int)$_GET['lead']]);
        $lead = $st->fetch() ?: null;
    }
    $title = $lead && $lead['service'] ? $lead['service'] : '';
    $sections = $type === 'proposal'
        ? array_map(fn($s) => ['heading' => $s[0], 'body' => $s[1]], doc_proposal_sections())
        : [];
    $items = [['desc' => $title ? 'Supply and install — ' . $title : '', 'qty' => 1, 'unit' => 'lot', 'price' => 0]];
    $pdo->prepare('INSERT INTO documents (type, number, lead_id, title, client_name, client_phone, client_email, site, items, sections, vat, discount, notes, terms, valid_days, due_days, status, token, doc_date, created_at, updated_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$type, doc_next_number($pdo, $type), $lead['id'] ?? null, $title ?: null, $lead['name'] ?? null, $lead['phone'] ?? null,
                   $lead['email'] ?? null, $lead['location'] ?? null, json_encode($items), json_encode($sections), 1, 0,
                   $lead && $lead['message'] ? "Client's request: " . $lead['message'] : null, doc_default_terms($type), 30, 14, 'draft',
                   bin2hex(random_bytes(24)), date('Y-m-d'), now(), now()]);
    redirect('documents.php?id=' . $pdo->lastInsertId());
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $back = 'documents.php?id=' . $id;
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect($back); }
    $d = $id ? doc_load($pdo, $id) : null;
    if (!$d) { flash('That document no longer exists.', 'err'); redirect('documents.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $f = fn(string $k, int $max = 255) => ($v = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max)) === '' ? null : $v;
        $items = [];
        $in = $_POST['items'] ?? [];
        foreach (($in['desc'] ?? []) as $i => $desc) {
            $desc = mb_substr(trim((string)$desc), 0, 500);
            if ($desc === '') continue;
            $items[] = [
                'desc'  => $desc,
                'qty'   => round((float)str_replace(',', '', (string)($in['qty'][$i] ?? 1)), 2),
                'unit'  => mb_substr(trim((string)($in['unit'][$i] ?? '')), 0, 20),
                'price' => round((float)str_replace(',', '', (string)($in['price'][$i] ?? 0)), 2),
            ];
        }
        $sections = [];
        foreach (($_POST['sec']['heading'] ?? []) as $i => $h) {
            $h = mb_substr(trim((string)$h), 0, 160);
            $b = trim((string)($_POST['sec']['body'][$i] ?? ''));
            if ($h === '' && $b === '') continue;
            $sections[] = ['heading' => $h, 'body' => $b];
        }
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['doc_date'] ?? '')) ? $_POST['doc_date'] : date('Y-m-d');
        // Tax: "none" or one of the rates set up in Settings (copied onto the document so later rate changes don't alter it)
        $taxRow = null;
        foreach (tax_rates($pdo, false) as $tr) if ((string)$tr['id'] === (string)($_POST['tax'] ?? '')) $taxRow = $tr;
        if ($taxRow && (float)$taxRow['rate'] <= 0) $taxRow = null;
        $cur = isset(currencies()[$_POST['currency'] ?? '']) ? $_POST['currency'] : setting('currency', 'KES');
        $pdo->prepare('UPDATE documents SET tax_rate=?, tax_label=?, currency=? WHERE id=?')
            ->execute([$taxRow ? (float)$taxRow['rate'] : null, $taxRow ? trim($taxRow['name'] . ($taxRow['country'] ? ' (' . $taxRow['country'] . ')' : '')) : null, $cur, $id]);
        $pdo->prepare('UPDATE documents SET title=?, client_name=?, client_company=?, client_phone=?, client_email=?, client_address=?, site=?, items=?, sections=?,
                       vat=?, discount=?, notes=?, terms=?, valid_days=?, due_days=?, doc_date=?, updated_at=? WHERE id=?')
            ->execute([$f('title', 200), $f('client_name', 120), $f('client_company', 160), $f('client_phone', 40), $f('client_email', 160),
                       $f('client_address'), $f('site', 160), json_encode($items), json_encode($sections), $taxRow ? 1 : 0,
                       max(0, round((float)str_replace(',', '', (string)($_POST['discount'] ?? 0)), 2)), $f('notes', 5000), $f('terms', 5000),
                       max(1, (int)($_POST['valid_days'] ?? 30)), max(0, (int)($_POST['due_days'] ?? 14)), $date, now(), $id]);
        flash('Saved.');
        redirect(($_POST['then'] ?? '') === 'preview' ? 'print.php?id=' . $id : $back);
    }

    if ($action === 'status') {
        $s = $_POST['status'] ?? '';
        if (isset(doc_statuses($d['type'])[$s])) {
            $pdo->prepare('UPDATE documents SET status=?, updated_at=? WHERE id=?')->execute([$s, now(), $id]);
            flash('Status set to ' . doc_statuses($d['type'])[$s] . '.');
        }
        redirect($back);
    }

    if ($action === 'convert' && $d['type'] !== 'invoice') {
        $pdo->prepare('INSERT INTO documents (type, number, lead_id, source_id, title, client_name, client_company, client_phone, client_email, client_address, site, items, sections, vat, discount, notes, terms, valid_days, due_days, status, token, doc_date, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(['invoice', doc_next_number($pdo, 'invoice'), $d['lead_id'], $d['id'], $d['title'], $d['client_name'], $d['client_company'],
                       $d['client_phone'], $d['client_email'], $d['client_address'], $d['site'], json_encode($d['items']), '[]', $d['vat'], $d['discount'],
                       null, doc_default_terms('invoice'), 30, 14, 'draft', bin2hex(random_bytes(24)), date('Y-m-d'), now(), now()]);
        $newId = $pdo->lastInsertId();
        doc_copy_tax($pdo, (int)$newId, $d);
        $pdo->prepare("UPDATE documents SET status='invoiced', updated_at=? WHERE id=?")->execute([now(), $id]);
        flash('Invoice created from ' . $d['number'] . '. Check the details, then email it to the client.');
        redirect('documents.php?id=' . $newId);
    }

    if ($action === 'duplicate') {
        $pdo->prepare('INSERT INTO documents (type, number, lead_id, title, client_name, client_company, client_phone, client_email, client_address, site, items, sections, vat, discount, notes, terms, valid_days, due_days, status, token, doc_date, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$d['type'], doc_next_number($pdo, $d['type']), $d['lead_id'], $d['title'], $d['client_name'], $d['client_company'], $d['client_phone'],
                       $d['client_email'], $d['client_address'], $d['site'], json_encode($d['items']), json_encode($d['sections']), $d['vat'], $d['discount'],
                       $d['notes'], $d['terms'], $d['valid_days'], $d['due_days'], 'draft', bin2hex(random_bytes(24)), date('Y-m-d'), now(), now()]);
        $newId = (int)$pdo->lastInsertId();
        doc_copy_tax($pdo, $newId, $d);
        flash('Copy created.');
        redirect('documents.php?id=' . $newId);
    }

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM payments WHERE document_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
        flash($d['number'] . ' deleted.');
        redirect('documents.php');
    }

    if ($action === 'email') {
        if (!$d['client_email']) { flash('Add the client email address first, then save.', 'err'); redirect($back); }
        if (doc_email($pdo, $d, (string)($_POST['message'] ?? ''), $err)) flash($types[$d['type']] . " emailed to {$d['client_email']}. If it doesn't arrive, ask the client to check spam, and see Health check → Send a test email.");
        else flash('Email not sent: ' . e((string)$err), 'err');
        redirect($back);
    }

    if ($action === 'payment' && $d['type'] === 'invoice') {
        $amount = round((float)str_replace(',', '', (string)($_POST['amount'] ?? 0)), 2);
        $method = isset(pay_methods()[$_POST['method'] ?? '']) ? $_POST['method'] : 'mpesa';
        $paidOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['paid_on'] ?? '')) ? $_POST['paid_on'] : date('Y-m-d');
        if ($amount <= 0) { flash('Enter the amount received.', 'err'); redirect($back); }
        $ok = record_payment($pdo, $id, $amount, $method, mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 80), $paidOn,
            mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255), !empty($_POST['email_receipt']), $msg);
        flash((string)$msg, $ok ? 'ok' : 'err');
        redirect($back . '#payments');
    }

    if ($action === 'schedule' && $d['type'] === 'invoice') {
        $every = max(1, min(12, (int)($_POST['every_months'] ?? 1)));
        $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['start'] ?? '')) ? $_POST['start'] : date('Y-m-d');
        $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['end_date'] ?? '')) ? $_POST['end_date'] : null;
        if ($end && $end < $start) { flash('The end date must be after the first invoice date.', 'err'); redirect($back); }
        $pdo->prepare('INSERT INTO schedules (template_id, every_months, next_run, end_date, auto_email, active, runs, created_at) VALUES (?,?,?,?,?,1,0,?)')
            ->execute([$id, $every, $start, $end, !empty($_POST['auto_email']) ? 1 : 0, now()]);
        $made = run_schedules($pdo);
        flash('Billing scheduled every ' . ($every === 1 ? 'month' : "$every months") . ' from ' . date('j M Y', strtotime($start)) . ($end ? ' until ' . date('j M Y', strtotime($end)) : ' with no end date') . '.'
            . ($made ? ' Created now: ' . implode(', ', $made) . '.' : ''));
        redirect('schedules.php');
    }

    if ($action === 'payment_delete') {
        if (!can('settings')) { flash('Only the owner can remove a payment.', 'err'); redirect($back . '#payments'); }
        $st = $pdo->prepare('SELECT receipt_no, amount, reference FROM payments WHERE id = ? AND document_id = ?');
        $st->execute([(int)$_POST['pid'], $id]);
        if ($gone = $st->fetch()) audit($pdo, 'payment_removed', "{$gone['receipt_no']} {$d['number']} " . money((float)$gone['amount']) . ' ' . $gone['reference']);
        $pdo->prepare('DELETE FROM payments WHERE id = ? AND document_id = ?')->execute([(int)$_POST['pid'], $id]);
        [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
        $paid = doc_paid($pdo, $id);
        $pdo->prepare('UPDATE documents SET status=?, updated_at=? WHERE id=?')
            ->execute([$paid <= 0 ? 'sent' : ($paid + 0.005 >= $total ? 'paid' : 'partly'), now(), $id]);
        flash('Payment removed and its receipt cancelled.');
        redirect($back . '#payments');
    }
    redirect($back);
}

$f = flash();
$doc = isset($_GET['id']) ? doc_load($pdo, (int)$_GET['id']) : null;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $doc ? e($doc['number']) . ' — ' : '' ?>Quotes &amp; invoices — Sunbeam admin</title>
<link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" href="../assets/img/admin-favicon-32.png" type="image/png" sizes="32x32"><link rel="apple-touch-icon" href="../assets/img/admin-apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<?php admin_bar('docs', admin_new_leads($pdo), admin_new_orders($pdo)); ?>
<main class="wrap">
<?php admin_flash($f); ?>

<?php if (!$doc): /* ================= LIST ================= */
    $ft = isset($types[$_GET['type'] ?? '']) ? $_GET['type'] : '';
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT * FROM documents WHERE 1=1';
    $args = [];
    if ($ft) { $sql .= ' AND type = ?'; $args[] = $ft; }
    if ($q !== '') { $sql .= ' AND (number LIKE ? OR client_name LIKE ? OR client_company LIKE ? OR title LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
    $st = $pdo->prepare($sql . ' ORDER BY id DESC LIMIT 300');
    $st->execute($args);
    $docs = $st->fetchAll();
    $counts = $pdo->query('SELECT type, COUNT(*) FROM documents GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);
    $outstanding = 0.0;
    foreach ($pdo->query("SELECT * FROM documents WHERE type='invoice' AND status IN ('sent','viewed','partly','overdue')") as $inv) {
        [, , , $t] = doc_totals(json_decode((string)$inv['items'], true) ?: [], doc_rate($inv), (float)$inv['discount']);
        $outstanding += max($t - doc_paid($pdo, (int)$inv['id']), 0);
    }
?>
  <div class="head">
    <h1>Quotes, proposals &amp; invoices</h1>
    <div class="actions-row">
      <a class="btn" href="?new=quote">New quotation</a>
      <a class="btn ghost" href="?new=proposal">New proposal</a>
      <a class="btn ghost" href="?new=invoice">New invoice</a>
    </div>
  </div>
  <?php if ($outstanding > 0): ?><p class="note warn">Outstanding on unpaid invoices: <strong>KES <?= money($outstanding) ?></strong></p><?php endif; ?>
  <div class="list-tools">
    <nav class="filters" aria-label="Filter by type">
      <a href="documents.php" <?= !$ft ? 'aria-current="true"' : '' ?>>All (<?= array_sum($counts) ?>)</a>
      <?php foreach ($types as $k => $label): ?><a href="?type=<?= $k ?>" <?= $ft === $k ? 'aria-current="true"' : '' ?>><?= $label ?>s (<?= (int)($counts[$k] ?? 0) ?>)</a><?php endforeach; ?>
    </nav>
    <form class="search" method="get"><?php if ($ft): ?><input type="hidden" name="type" value="<?= e($ft) ?>"><?php endif; ?>
      <label class="sr" for="q">Search</label><input id="q" name="q" value="<?= e($q) ?>" placeholder="Search number, client or title"></form>
  </div>
  <?php if (!$docs): ?>
    <p class="empty">No documents<?= $q || $ft ? ' match this filter' : ' yet' ?>. Start a quotation above, or open a quote request and choose <strong>Create quote</strong> to fill in the client's details automatically.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="doc-list">
    <thead><tr><th>Number</th><th>Client</th><th>Title</th><th class="r">Total (KES)</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $row): [, , , $t] = doc_totals(json_decode((string)$row['items'], true) ?: [], doc_rate($row), (float)$row['discount']); ?>
      <tr>
        <td><a href="?id=<?= (int)$row['id'] ?>"><strong><?= e($row['number']) ?></strong></a><br><small class="muted"><?= e($types[$row['type']]) ?></small></td>
        <td><?= e($row['client_company'] ?: $row['client_name'] ?: '—') ?></td>
        <td><?= e($row['title'] ?: '—') ?></td>
        <td class="r"><?= money($t) ?><?php if ($row['type'] === 'invoice' && in_array($row['status'], ['partly', 'sent', 'viewed', 'overdue'], true)): ?><br><small class="due-txt">Due <?= money(max($t - doc_paid($pdo, (int)$row['id']), 0)) ?></small><?php endif; ?></td>
        <td><span class="st st-<?= e($row['status']) ?>"><?= e(doc_statuses($row['type'])[$row['status']] ?? $row['status']) ?></span></td>
        <td><?= date('j M Y', strtotime($row['doc_date'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

<?php else: /* ================= EDITOR ================= */
    $isInv = $doc['type'] === 'invoice';
    $payments = [];
    if ($isInv) {
        $st = $pdo->prepare('SELECT * FROM payments WHERE document_id = ? ORDER BY paid_on, id');
        $st->execute([$doc['id']]);
        $payments = $st->fetchAll();
    }
    [$sub, $disc, $tax, $total] = doc_totals($doc['items'], doc_rate($doc), (float)$doc['discount']);
    $paid = $isInv ? doc_paid($pdo, (int)$doc['id']) : 0;
    $source = $doc['source_id'] ? doc_load($pdo, (int)$doc['source_id']) : null;
    $children = $pdo->prepare("SELECT id, number FROM documents WHERE source_id = ?");
    $children->execute([$doc['id']]);
    $children = $children->fetchAll();
?>
  <p class="crumb"><a href="documents.php">← All documents</a></p>
  <div class="head">
    <h1><?= e($types[$doc['type']]) ?> <?= e($doc['number']) ?></h1>
    <span class="st st-<?= e($doc['status']) ?>"><?= e(doc_statuses($doc['type'])[$doc['status']] ?? $doc['status']) ?></span>
  </div>
  <?php if ($source): ?><p class="muted">Created from <a href="?id=<?= (int)$source['id'] ?>"><?= e($source['number']) ?></a>.</p><?php endif; ?>
  <?php foreach ($children as $c): ?><p class="muted">Invoiced as <a href="?id=<?= (int)$c['id'] ?>"><?= e($c['number']) ?></a>.</p><?php endforeach; ?>
  <?php if ($doc['accepted_at']): ?><p class="note ok">Accepted online by <?= e($doc['accepted_name']) ?> on <?= date('j M Y, H:i', strtotime($doc['accepted_at'])) ?>.</p><?php endif; ?>

  <div class="doc-grid">
  <form method="post" class="doc-form" id="docForm">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">

    <section class="panel">
      <h2>Client</h2>
      <div class="grid2">
        <label>Contact name<input name="client_name" maxlength="120" value="<?= e($doc['client_name']) ?>"></label>
        <label>Company (optional)<input name="client_company" maxlength="160" value="<?= e($doc['client_company']) ?>"></label>
        <label>Phone<input name="client_phone" maxlength="40" value="<?= e($doc['client_phone']) ?>"></label>
        <label>Email<input type="email" name="client_email" maxlength="160" value="<?= e($doc['client_email']) ?>"></label>
        <label>Postal / physical address<input name="client_address" maxlength="255" value="<?= e($doc['client_address']) ?>"></label>
        <label>Site / project location<input name="site" maxlength="160" value="<?= e($doc['site']) ?>"></label>
      </div>
    </section>

    <section class="panel">
      <h2>Details</h2>
      <div class="grid3">
        <label class="span2">Title<input name="title" maxlength="200" value="<?= e($doc['title']) ?>" placeholder="e.g. Booster pump and tank installation"></label>
        <label>Date<input type="date" name="doc_date" value="<?= e($doc['doc_date']) ?>"></label>
        <?php if ($isInv): ?>
          <label>Payment due in (days)<input type="number" name="due_days" min="0" value="<?= (int)$doc['due_days'] ?>"></label>
          <input type="hidden" name="valid_days" value="<?= (int)$doc['valid_days'] ?>">
        <?php else: ?>
          <label>Valid for (days)<input type="number" name="valid_days" min="1" value="<?= (int)$doc['valid_days'] ?>"></label>
          <input type="hidden" name="due_days" value="<?= (int)$doc['due_days'] ?>">
        <?php endif; ?>
      </div>
    </section>

    <?php if ($doc['type'] === 'proposal'): ?>
    <section class="panel">
      <h2>Proposal sections</h2>
      <p class="muted">Edit the ready-written text. <code>{client}</code>, <code>{company}</code>, <code>{site}</code> and <code>{title}</code> fill in automatically. A section containing only <code>{pricing}</code> shows the priced items below. Lines starting with "-" become bullet points.</p>
      <div id="sections">
        <?php foreach ($doc['sections'] as $sec): ?>
        <div class="sec">
          <div class="sec-head">
            <input name="sec[heading][]" value="<?= e($sec['heading']) ?>" aria-label="Section heading">
            <button type="button" class="icon" data-move="-1" aria-label="Move up">↑</button>
            <button type="button" class="icon" data-move="1" aria-label="Move down">↓</button>
            <button type="button" class="icon danger" data-remove aria-label="Remove section">×</button>
          </div>
          <textarea name="sec[body][]" rows="5" aria-label="Section text"><?= e($sec['body']) ?></textarea>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn small ghost" id="addSection">Add section</button>
    </section>
    <?php endif; ?>

    <section class="panel">
      <h2><?= $doc['type'] === 'proposal' ? 'Pricing schedule' : 'Items' ?></h2>
      <div class="table-wrap">
      <table class="item-edit">
        <thead><tr><th>Description</th><th>Qty</th><th>Unit</th><th>Unit price</th><th class="r">Amount</th><th></th></tr></thead>
        <tbody id="items">
          <?php foreach ($doc['items'] ?: [['desc' => '', 'qty' => 1, 'unit' => '', 'price' => 0]] as $it): ?>
          <tr>
            <td><input name="items[desc][]" list="catalogue" value="<?= e($it['desc']) ?>" aria-label="Description"></td>
            <td><input name="items[qty][]" inputmode="decimal" value="<?= e((string)(float)$it['qty']) ?>" class="num" aria-label="Quantity"></td>
            <td><input name="items[unit][]" value="<?= e($it['unit']) ?>" class="unit" aria-label="Unit"></td>
            <td><input name="items[price][]" inputmode="decimal" value="<?= e((string)(float)$it['price']) ?>" class="num" aria-label="Unit price"></td>
            <td class="r amt">0.00</td>
            <td><button type="button" class="icon danger" data-remove-row aria-label="Remove item">×</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <datalist id="catalogue">
        <?php foreach (doc_catalogue() as [$desc, $unit]): ?><option value="<?= e($desc) ?>" data-unit="<?= e($unit) ?>"></option><?php endforeach; ?>
      </datalist>
      <button type="button" class="btn small ghost" id="addItem">Add item</button>
      <div class="sum">
        <?php $curRate = doc_rate($doc); ?>
        <label class="inline">Tax<select name="tax" id="taxSel">
          <option value="none" data-rate="0">No tax</option>
          <?php $matched = false; foreach (tax_rates($pdo) as $tr): $sel = !$matched && $curRate > 0 && abs((float)$tr['rate'] - $curRate) < 0.001; $matched = $matched || $sel; ?>
            <option value="<?= (int)$tr['id'] ?>" data-rate="<?= e((string)(float)$tr['rate']) ?>" <?= $sel ? 'selected' : '' ?>><?= e($tr['name'] . ($tr['country'] ? ' (' . $tr['country'] . ')' : '') . ' ' . (float)$tr['rate'] . '%') ?></option>
          <?php endforeach; ?>
        </select></label>
        <label class="inline">Currency<select name="currency"><?php foreach (currencies() as $k => $v): ?><option value="<?= $k ?>" <?= doc_cur($doc) === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label class="inline">Discount<input name="discount" inputmode="decimal" class="num" value="<?= e((string)(float)$doc['discount']) ?>"></label>
        <table class="sum-table">
          <tr><th>Subtotal</th><td id="tSub"><?= money($sub) ?></td></tr>
          <tr><th>Discount</th><td id="tDisc"><?= money($disc) ?></td></tr>
          <tr><th>Tax</th><td id="tVat"><?= money($tax) ?></td></tr>
          <tr class="grand"><th>Total (<?= e(doc_cur($doc)) ?>)</th><td id="tTotal"><?= money($total) ?></td></tr>
        </table>
      </div>
    </section>

    <section class="panel">
      <h2>Notes and terms</h2>
      <label>Notes (shown on the document)<textarea name="notes" rows="3"><?= e($doc['notes']) ?></textarea></label>
      <label>Terms and conditions<textarea name="terms" rows="7"><?= e($doc['terms']) ?></textarea></label>
    </section>

    <div class="save-bar">
      <button class="btn" type="submit">Save</button>
      <button class="btn ghost" type="submit" name="then" value="preview">Save &amp; preview</button>
    </div>
  </form>

  <aside class="doc-side">
    <section class="panel">
      <h2>Total</h2>
      <p class="big-total"><?= e(doc_cur($doc)) ?> <?= money($total) ?></p>
      <?php if ($isInv): ?>
        <p>Paid: <strong><?= e(doc_cur($doc)) ?> <?= money($paid) ?></strong><br>Balance: <strong class="due-txt"><?= e(doc_cur($doc)) ?> <?= money(max($total - $paid, 0)) ?></strong></p>
      <?php endif; ?>
      <p><a class="btn wide" href="print.php?id=<?= (int)$doc['id'] ?>" target="_blank">Preview / print / PDF</a></p>
      <p><a class="link" href="<?= e(doc_public_url($doc)) ?>" target="_blank" rel="noopener">Open the client's <?= $isInv ? 'view &amp; pay' : 'view &amp; approve' ?> link</a></p>
      <p><a class="link" href="print.php?id=<?= (int)$doc['id'] ?>&amp;download=pdf">Download PDF</a> · <a class="link" href="print.php?id=<?= (int)$doc['id'] ?>&amp;download=html">HTML file</a></p>
      <p class="muted small">Security code: <strong><?= e(doc_security_code($doc)) ?></strong></p>
      <?php if (!empty($doc['mail_at'])): $mok = str_starts_with((string)$doc['mail_result'], 'ok'); ?>
        <p class="note <?= $mok ? 'ok' : 'err' ?> small">Last email to <?= e((string)$doc['mail_to']) ?>, <?= e(date('j M H:i', strtotime($doc['mail_at']))) ?>: <?= $mok ? 'accepted by the mail server. If the client\'s provider rejects it later, the bounce arrives in the ' . e(setting('email')) . ' inbox.' : e((string)$doc['mail_result']) ?></p>
      <?php endif; ?>
      <?php if ($doc['sent_at'] || $doc['viewed_at']): ?><p class="muted small"><?= $doc['sent_at'] ? 'Emailed ' . e(date('j M Y H:i', strtotime($doc['sent_at']))) : '' ?><?= $doc['viewed_at'] ? '<br>Opened by client ' . e(date('j M Y H:i', strtotime($doc['viewed_at']))) . ' (' . (int)$doc['view_count'] . '×)' : '' ?></p><?php endif; ?>
      <?php if (can('workorders') && $doc['type'] !== 'proposal'): ?><p><a class="link" href="workorders.php?new=1&amp;doc=<?= (int)$doc['id'] ?>">Create a work order for this job</a></p><?php endif; ?>
    </section>

    <section class="panel">
      <h2>Send to client</h2>
      <?php if ($doc['client_email']): ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <label>Extra message (optional)<textarea name="message" rows="3" placeholder="e.g. We can start on Monday."></textarea></label>
        <button class="btn wide">Email to <?= e($doc['client_email']) ?></button>
      </form>
      <p class="muted small">Sends a link where they can view, print<?= $isInv ? '' : ' and accept' ?> it. Save your changes first.</p>
      <?php else: ?><p class="muted">Add the client's email above and save to email it.</p><?php endif; ?>
      <?php if ($doc['client_phone']): $waNum = wa_digits((string)$doc['client_phone']);
        $waText = "Hello " . (strtok((string)$doc['client_name'], ' ') ?: '') . ", here is your " . strtolower($types[$doc['type']]) . " {$doc['number']} from " . setting('company_name') . ': ' . doc_public_url($doc); ?>
        <p><a class="btn wide wa" href="https://wa.me/<?= e($waNum) ?>?text=<?= e(rawurlencode($waText)) ?>" target="_blank" rel="noopener">Send link on WhatsApp</a></p>
      <?php endif; ?>
    </section>

    <?php if (!$isInv): ?>
    <section class="panel">
      <h2>Next step</h2>
      <form method="post" data-confirm="Create an invoice from <?= e($doc['number']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="convert"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <button class="btn wide">Convert to invoice</button>
      </form>
      <p class="muted small">Copies the client and all items into a new invoice and marks this <?= strtolower($types[$doc['type']]) ?> as invoiced.</p>
    </section>
    <?php else: ?>
    <section class="panel" id="payments">
      <h2>Record a payment</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <label>Amount (<?= e(doc_cur($doc)) ?>)<input name="amount" inputmode="decimal" required value="<?= e((string)max(round($total - $paid, 2), 0)) ?>"></label>
        <label>Method<select name="method"><?php foreach (pay_methods() as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></label>
        <label>Reference (M-Pesa code, cheque no.)<input name="reference" maxlength="80"></label>
        <label>Date received<input type="date" name="paid_on" value="<?= date('Y-m-d') ?>"></label>
        <label>Note (optional)<input name="note" maxlength="255"></label>
        <?php if ($doc['client_email']): ?><label class="check"><input type="checkbox" name="email_receipt" value="1" checked> Email the receipt to the client</label><?php endif; ?>
        <button class="btn wide">Record payment &amp; issue receipt</button>
      </form>
      <?php if ($payments): ?>
      <h3>Receipts <a class="small" href="receipts.php">All receipts</a></h3>
      <ul class="receipts">
        <?php foreach ($payments as $p): ?>
        <li>
          <a href="print.php?receipt=<?= (int)$p['id'] ?>" target="_blank"><strong><?= e($p['receipt_no']) ?></strong></a>
          <?= e(doc_cur($doc)) ?> <?= money((float)$p['amount']) ?> · <?= e(pay_methods()[$p['method']] ?? $p['method']) ?> · <?= date('j M Y', strtotime($p['paid_on'])) ?>
          · <a href="print.php?receipt=<?= (int)$p['id'] ?>&amp;download=pdf">PDF</a>
          <?php if (!empty($p['mail_result'])): ?> · <span class="muted"><?= str_starts_with($p['mail_result'], 'sent') ? 'emailed ' . e((string)$p['mail_to']) : 'email ' . e($p['mail_result']) ?></span><?php endif; ?>
          <?php if ($doc['client_email']): ?><form method="post" action="receipts.php"><?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><input type="hidden" name="back" value="documents.php?id=<?= (int)$doc['id'] ?>#payments"><button class="link">Email receipt</button></form><?php endif; ?>
          <?php if (can('settings')): ?><form method="post" data-confirm="Remove this payment and cancel receipt <?= e($p['receipt_no']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="payment_delete"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="pid" value="<?= (int)$p['id'] ?>"><button class="link danger">Remove</button></form><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

    <section class="panel">
      <h2>Bill this monthly</h2>
      <?php $sch = $pdo->prepare('SELECT * FROM schedules WHERE template_id = ? AND active = 1'); $sch->execute([$doc['id']]); $sch = $sch->fetch(); ?>
      <?php if ($doc['schedule_id']): ?>
        <p class="muted">This invoice was created by a <a href="schedules.php">billing schedule</a>.</p>
      <?php elseif ($sch): ?>
        <p>Repeats every <?= (int)$sch['every_months'] === 1 ? 'month' : (int)$sch['every_months'] . ' months' ?>. Next: <strong><?= date('j M Y', strtotime($sch['next_run'])) ?></strong><?= $sch['end_date'] ? ', ends ' . date('j M Y', strtotime($sch['end_date'])) : '' ?>. <a href="schedules.php">Manage</a></p>
      <?php else: ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="schedule"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <p class="muted small">Uses this invoice as the template. A new numbered invoice is created and emailed with its payment link on each date, until the end date.</p>
        <label>Repeat every<select name="every_months"><option value="1">Month</option><option value="3">3 months</option><option value="6">6 months</option><option value="12">Year</option></select></label>
        <label>First invoice on<input type="date" name="start" value="<?= e(date('Y-m-d', strtotime('first day of next month'))) ?>"></label>
        <label>End date (last invoice on or before)<input type="date" name="end_date"></label>
        <label class="check"><input type="checkbox" name="auto_email" value="1" checked> Email each invoice to the client automatically</label>
        <button class="btn wide">Schedule billing</button>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel">
      <h2>Status</h2>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <select name="status" data-autosubmit aria-label="Status"><?php foreach (doc_statuses($doc['type']) as $k => $v): ?><option value="<?= $k ?>" <?= $doc['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      </form>
      <div class="side-links">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><button class="link">Make a copy</button></form>
        <form method="post" data-confirm="Delete <?= e($doc['number']) ?><?= $payments ? ' and its receipts' : '' ?> permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><button class="link danger">Delete</button></form>
      </div>
    </section>
  </aside>
  </div>
<?php endif; ?>
</main>
<script src="../assets/js/admin.js"></script>
<script src="../assets/js/admin-docs.js"></script>
</body>
</html>
