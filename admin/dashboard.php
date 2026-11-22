<?php
require __DIR__ . '/../includes/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/doc-templates.php';
require_once __DIR__ . '/../includes/mpesa.php';
require_once __DIR__ . '/../includes/waafi.php';
require_admin();

$pdo = db();
pp_migrate($pdo);
maybe_run_schedules($pdo);
$statuses = ['new' => 'New', 'contacted' => 'Contacted', 'quoted' => 'Quoted', 'won' => 'Job won', 'closed' => 'Closed'];
$tab = in_array($_GET['tab'] ?? '', ['leads', 'settings'], true) ? $_GET['tab'] : 'leads';
if ($tab === 'leads' && !can('leads') && !isset($_GET['tab'])) redirect(user_home());
require_perm($tab === 'settings' ? 'settings' : 'leads');

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quote-requests-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Name', 'Phone', 'Email', 'Location', 'Service', 'Message', 'Status']);
    foreach ($pdo->query('SELECT * FROM leads ORDER BY id DESC') as $r) {
        // neutralise spreadsheet formulas
        $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v,
            [$r['created_at'], $r['name'], $r['phone'], $r['email'], $r['location'], $r['service'], $r['message'], $r['status']]);
        fputcsv($out, $row);
    }
    exit;
}

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        flash('The form expired. Try again.', 'err');
        redirect('dashboard.php?tab=' . $tab);
    }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    switch ($action) {
        case 'lead_status':
            $s = $_POST['status'] ?? '';
            if (isset($statuses[$s])) {
                $pdo->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$s, $id]);
                flash('Status updated.');
            }
            redirect('dashboard.php?tab=leads');

        case 'lead_reply':
            $st = $pdo->prepare('SELECT * FROM leads WHERE id = ?');
            $st->execute([$id]);
            $lead = $st->fetch();
            $subj = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
            $msg = trim((string)($_POST['body'] ?? ''));
            if (!$lead || !$lead['email']) {
                flash('This request has no email address. Call or WhatsApp instead.', 'err');
            } elseif ($subj === '' || $msg === '') {
                flash('Add a subject and a message before sending.', 'err');
            } else {
                $ok = pp_mail($lead['email'], $subj, $msg, setting('email'), $err);
                $pdo->prepare('INSERT INTO replies (lead_id, to_email, subject, body, ok, error, created_at) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$id, $lead['email'], $subj, $msg, $ok ? 1 : 0, $ok ? null : mb_substr((string)$err, 0, 255), now()]);
                if ($ok) {
                    if ($lead['status'] === 'new') $pdo->prepare("UPDATE leads SET status = 'contacted' WHERE id = ?")->execute([$id]);
                    flash('Email sent to ' . $lead['email'] . '.');
                } else {
                    flash('Email not sent: ' . e((string)$err), 'err');
                }
            }
            redirect('dashboard.php?tab=leads#lead-' . $id);

        case 'test_mail':
            $ok = pp_mail(setting('notify_email'), 'Test email from the Sunbeam website',
                "This is a test sent through " . (cfg('smtp')['enabled'] ? cfg('smtp')['host'] . ':' . cfg('smtp')['port'] : 'PHP mail()') . ' at ' . now() . ".\nIf you can read this, email from the website works.", null, $err);
            flash($ok ? 'Test email sent to ' . setting('notify_email') . '. Check the inbox (and spam folder).' : 'Test failed: ' . e((string)$err), $ok ? 'ok' : 'err');
            redirect('dashboard.php?tab=settings');

        case 'lead_delete':
            $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
            flash('Request deleted.');
            redirect('dashboard.php?tab=leads');

        case 'settings':
            $keys = ['company_name', 'brand_name', 'tagline', 'email', 'notify_email', 'phone_kenya', 'phone_somalia', 'address', 'kra_pin', 'payment_details', 'currency',
                     'mpesa_env', 'mpesa_type', 'mpesa_till', 'mpesa_shortcode', 'mpesa_key',
                     'waafi_env', 'waafi_merchant_uid', 'waafi_api_user_id', 'waafi_currency', 'waafi_fx', 'evc_merchant'];
            foreach ($keys as $k) {
                if (array_key_exists($k, $_POST)) setting_save($k, mb_substr(trim((string)$_POST[$k]), 0, 2000));
            }
            // Secrets: a blank box keeps the saved value
            setting_save('require_2fa', !empty($_POST['require_2fa']) ? '1' : '0');
            setting_save('show_kra_pin', !empty($_POST['show_kra_pin']) ? '1' : '0');
            foreach (['mpesa_passkey', 'mpesa_secret', 'waafi_api_key'] as $k) {
                if (trim((string)($_POST[$k] ?? '')) !== '') setting_save($k, trim((string)$_POST[$k]));
            }
            audit($pdo, 'settings_saved');
            flash('Settings saved.');
            redirect('dashboard.php?tab=settings');

        case 'tax_save':
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
            $rate = round((float)str_replace(',', '.', (string)($_POST['rate'] ?? 0)), 3);
            $country = mb_substr(trim((string)($_POST['country'] ?? '')), 0, 40) ?: null;
            if ($name === '' || $rate < 0 || $rate > 100) { flash('Give the tax a name and a rate between 0 and 100.', 'err'); redirect('dashboard.php?tab=settings#taxes'); }
            if ($id) $pdo->prepare('UPDATE tax_rates SET name = ?, country = ?, rate = ?, active = ? WHERE id = ?')->execute([$name, $country, $rate, !empty($_POST['active']) ? 1 : 0, $id]);
            else $pdo->prepare('INSERT INTO tax_rates (name, country, rate, active) VALUES (?,?,?,1)')->execute([$name, $country, $rate]);
            audit($pdo, 'tax_rate_saved', "$name $country $rate%");
            flash('Tax rate saved. Existing documents keep the rate they were saved with.');
            redirect('dashboard.php?tab=settings#taxes');

        case 'payopt_save':
            $vals = [mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80), mb_substr(trim((string)($_POST['country'] ?? '')), 0, 40) ?: null,
                     mb_substr(trim((string)($_POST['details'] ?? '')), 0, 2000) ?: null, null,
                     !empty($_POST['on_docs']) ? 1 : 0, !empty($_POST['active']) ? 1 : 0, (int)($_POST['sort_order'] ?? 100)];
            $link = trim((string)($_POST['link'] ?? ''));
            if ($link !== '' && !preg_match('#^https://[^\s<>"]+$#', $link)) { flash('The payment link must start with https://', 'err'); redirect('dashboard.php?tab=settings#payopts'); }
            $vals[3] = $link ?: null;
            if ($vals[0] === '') { flash('Give the payment option a name.', 'err'); redirect('dashboard.php?tab=settings#payopts'); }
            if ($id) $pdo->prepare('UPDATE pay_options SET name=?, country=?, details=?, link=?, on_docs=?, active=?, sort_order=? WHERE id=?')->execute([...$vals, $id]);
            else $pdo->prepare('INSERT INTO pay_options (name, country, details, link, on_docs, active, sort_order) VALUES (?,?,?,?,?,?,?)')->execute($vals);
            audit($pdo, 'pay_option_saved', $vals[0]);
            flash('Payment option saved.');
            redirect('dashboard.php?tab=settings#payopts');

        case 'payopt_delete':
            $pdo->prepare('DELETE FROM pay_options WHERE id = ?')->execute([$id]);
            flash('Payment option removed.');
            redirect('dashboard.php?tab=settings#payopts');

        case 'localise':
            // Copy images/videos still loaded from the old website onto this server
            $done = 0; $fail = 0;
            $copy = function (string $url) use (&$done, &$fail): ?string {
                if (!preg_match('#^https://#', $url)) return null;
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_MAXFILESIZE => 80 * 1024 * 1024]);
                $data = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($data === false || $code !== 200) { $fail++; return null; }
                $tmp = tempnam(sys_get_temp_dir(), 'sb');
                file_put_contents($tmp, $data);
                try {
                    if (!is_dir(PP_UPLOAD_DIR)) mkdir(PP_UPLOAD_DIR, 0755, true);
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
                    if (str_starts_with($mime, 'video/')) {
                        $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.mp4';
                        rename($tmp, PP_UPLOAD_DIR . '/' . $name);
                    } else {
                        $name = process_image($tmp, 1920);
                    }
                    $done++;
                    return PP_UPLOAD_URL . '/' . $name;
                } catch (Throwable $e) {
                    $fail++;
                    return null;
                } finally {
                    if (is_file($tmp)) @unlink($tmp);
                }
            };
            foreach ([['slides', 'file'], ['cards', 'image'], ['products', 'image']] as [$table, $col]) {
                foreach ($pdo->query("SELECT id, $col FROM $table WHERE $col LIKE 'http%'")->fetchAll() as $r) {
                    if ($new = $copy($r[$col])) $pdo->prepare("UPDATE $table SET $col = ? WHERE id = ?")->execute([$new, $r['id']]);
                }
            }
            flash("$done files copied to this server." . ($fail ? " $fail could not be copied (upload those by hand)." : ''), $fail ? 'warn' : 'ok');
            redirect('dashboard.php?tab=settings');

    }
    redirect('dashboard.php');
}

/* ---------- data ---------- */
$counts = $pdo->query("SELECT status, COUNT(*) c FROM leads GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$filter = isset($statuses[$_GET['status'] ?? '']) ? $_GET['status'] : '';
if ($filter) {
    $st = $pdo->prepare('SELECT * FROM leads WHERE status = ? ORDER BY id DESC LIMIT 300');
    $st->execute([$filter]);
} else {
    $st = $pdo->query('SELECT * FROM leads ORDER BY id DESC LIMIT 300');
}
$leads = $st->fetchAll();
$replies = [];
foreach ($pdo->query('SELECT * FROM replies ORDER BY id ASC') as $r) $replies[(int)$r['lead_id']][] = $r;
$f = flash();
?>
<?php admin_head($tab === 'settings' ? 'Settings' : 'Quote requests'); admin_bar($tab, (int)($counts['new'] ?? 0), admin_new_orders($pdo)); ?>

<main class="wrap">
<?php admin_flash($f); ?>

<?php if ($tab === 'leads'): ?>
  <div class="head">
    <h1>Quote requests</h1>
    <a class="btn ghost" href="?tab=leads&export=csv">Download CSV</a>
  </div>
  <nav class="filters" aria-label="Filter by status">
    <a href="?tab=leads" <?= !$filter ? 'aria-current="true"' : '' ?>>All (<?= array_sum($counts) ?>)</a>
    <?php foreach ($statuses as $k => $label): ?>
      <a href="?tab=leads&status=<?= $k ?>" <?= $filter === $k ? 'aria-current="true"' : '' ?>><?= $label ?> (<?= (int)($counts[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$leads): ?>
    <p class="empty">No quote requests<?= $filter ? ' with this status' : ' yet' ?>. New requests from the website form appear here and are emailed to <?= e(setting('notify_email')) ?>.</p>
  <?php else: ?>
  <div class="leads">
    <?php foreach ($leads as $l): ?>
    <article class="lead status-<?= e($l['status']) ?>" id="lead-<?= (int)$l['id'] ?>">
      <div class="lead-main">
        <h2><?= e($l['name']) ?> <small><?= e(date('j M Y, H:i', strtotime($l['created_at']))) ?></small></h2>
        <p class="lead-meta">
          <a href="tel:<?= e(preg_replace('/[^+0-9]/', '', $l['phone'])) ?>"><?= e($l['phone']) ?></a>
          <a href="<?= e(wa_link($l['phone'])) ?>" target="_blank" rel="noopener">WhatsApp</a>
          <?php if ($l['email']): ?><a href="mailto:<?= e($l['email']) ?>"><?= e($l['email']) ?></a><?php endif; ?>
        </p>
        <p><strong><?= e($l['service'] ?: 'General enquiry') ?></strong><?= $l['location'] ? ' in ' . e($l['location']) : '' ?></p>
        <?php if ($l['message']): ?><p class="msg"><?= nl2br(e($l['message'])) ?></p><?php endif; ?>
        <?php foreach ($replies[(int)$l['id']] ?? [] as $r): ?>
          <div class="reply <?= $r['ok'] ? '' : 'failed' ?>">
            <p class="reply-meta"><?= $r['ok'] ? 'Emailed' : 'Failed to send' ?> <?= e(date('j M, H:i', strtotime($r['created_at']))) ?> — <strong><?= e($r['subject']) ?></strong></p>
            <p><?= nl2br(e($r['body'])) ?></p>
            <?php if (!$r['ok']): ?><p class="reply-err"><?= e($r['error']) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($l['email']): ?>
        <details class="reply-box">
          <summary>Reply by email</summary>
          <form method="post">
            <?= csrf_field() ?><input type="hidden" name="action" value="lead_reply"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
            <label>To<input value="<?= e($l['email']) ?>" disabled></label>
            <label>Subject<input name="subject" required maxlength="200" value="Your <?= e(strtolower($l['service'] ?: 'solar')) ?> quote — Sunbeam Innovation"></label>
            <label>Message<textarea name="body" rows="7" required><?= e("Hello " . strtok($l['name'], ' ') . ",\n\nThank you for your request. \n\n" . company_signoff()) ?></textarea></label>
            <button class="btn small">Send email</button>
            <span class="muted">Sent from <?= e(cfg('mail_from')) ?>. Customer replies go to that inbox.</span>
          </form>
        </details>
        <?php endif; ?>
      </div>
      <div class="lead-actions">
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="lead_status"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <label class="sr" for="st<?= (int)$l['id'] ?>">Status</label>
          <select id="st<?= (int)$l['id'] ?>" name="status" data-autosubmit>
            <?php foreach ($statuses as $k => $label): ?><option value="<?= $k ?>" <?= $l['status'] === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
          </select>
          <noscript><button class="btn small">Save</button></noscript>
        </form>
        <a class="btn small" href="documents.php?new=quote&lead=<?= (int)$l['id'] ?>">Create quote</a>
        <a class="link" href="documents.php?new=proposal&lead=<?= (int)$l['id'] ?>">Create proposal</a>
        <form method="post" data-confirm="Delete this request permanently?">
          <?= csrf_field() ?><input type="hidden" name="action" value="lead_delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="link danger">Delete</button>
        </form>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<?php else: $S = settings(); $remote = 0;
  foreach ([['slides', 'file'], ['cards', 'image'], ['products', 'image']] as [$t, $c]) $remote += (int)$pdo->query("SELECT COUNT(*) FROM $t WHERE $c LIKE 'http%'")->fetchColumn(); ?>
  <div class="head"><h1>Settings</h1></div>
  <?php $todo = [];
    if (str_contains($S['phone_kenya'], '000 000')) $todo[] = 'Kenya call number';
    if (str_contains($S['phone_somalia'], '000 0000')) $todo[] = 'Somalia WhatsApp number';
    if ($S['mpesa_till'] === '') $todo[] = 'M-Pesa till number';
    if (!mpesa_ready()) $todo[] = 'Daraja API keys (switches on "Pay with M-Pesa")';
    if (!waafi_ready()) $todo[] = 'WaafiPay keys (switches on "Pay with EVC Plus")';
    if (str_contains((string)cfg('app_key'), 'CHANGE')) $todo[] = 'app_key in includes/config.php';
    if (str_contains((string)cfg('smtp')['pass'], 'CHANGE')) $todo[] = 'mailbox password in includes/config.php';
  if ($todo): ?><p class="note warn">Still to add: <?= e(implode(' · ', $todo)) ?>.</p><?php endif; ?>

  <form method="post" class="settings-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
    <section class="panel">
      <h2>Company and contacts</h2>
      <p class="muted">Shown on the website, on WhatsApp buttons and on every quotation, invoice and receipt.</p>
      <div class="grid2">
        <label>Legal company name (invoices, quotes, receipts)<input name="company_name" maxlength="120" value="<?= e($S['company_name']) ?>"></label>
        <label>Website name (header, footer, page titles)<input name="brand_name" maxlength="120" value="<?= e($S['brand_name']) ?>" placeholder="Sunbeam Innovation.africa"></label>
        <label>Tagline<input name="tagline" maxlength="160" value="<?= e($S['tagline']) ?>"></label>
        <label>Kenya phone (calls only)<input name="phone_kenya" maxlength="40" value="<?= e($S['phone_kenya']) ?>" placeholder="+254 7XX XXX XXX"></label>
        <label>WhatsApp number (Somalia, used for every WhatsApp button)<input name="phone_somalia" maxlength="40" value="<?= e($S['phone_somalia']) ?>" placeholder="+252 6X XXX XXXX"></label>
        <label>Email shown on the site<input type="email" name="email" maxlength="160" value="<?= e($S['email']) ?>"></label>
        <label>Send new requests and payment alerts to<input type="email" name="notify_email" maxlength="160" value="<?= e($S['notify_email']) ?>"></label>
        <label>Address<input name="address" maxlength="200" value="<?= e($S['address']) ?>"></label>
        <label>KRA PIN<input name="kra_pin" maxlength="30" value="<?= e($S['kra_pin']) ?>"></label>
        <label class="check"><input type="checkbox" name="show_kra_pin" value="1" <?= $S['show_kra_pin'] === '1' ? 'checked' : '' ?>> Print the KRA PIN on quotes, invoices and receipts</label>
      </div>
      <label>Extra payment note (optional, printed under "How to pay"; payment options are set further down)<textarea name="payment_details" rows="4"><?= e($S['payment_details']) ?></textarea></label>
      <input type="hidden" name="currency" value="<?= e($S['currency']) ?>">
    </section>

    <section class="panel" id="mpesa">
      <h2>M-Pesa</h2>
      <p class="muted">Enter the till number and you are done for manual payments. To let customers pay from the invoice or shop with a prompt on their phone (STK push), add the Daraja keys from <a href="https://developer.safaricom.co.ke" target="_blank" rel="noopener">developer.safaricom.co.ke</a> (Go Live → M-Pesa Express).</p>
      <p>Online payment: <strong><?= mpesa_ready() ? 'ON (' . e($S['mpesa_env']) . ')' : 'OFF until the keys below are filled in' ?></strong></p>
      <div class="grid2">
        <label>Till number (Buy Goods)<input name="mpesa_till" maxlength="20" inputmode="numeric" value="<?= e($S['mpesa_till']) ?>"></label>
        <label>Type<select name="mpesa_type"><option value="till" <?= $S['mpesa_type'] === 'till' ? 'selected' : '' ?>>Till (Buy Goods)</option><option value="paybill" <?= $S['mpesa_type'] === 'paybill' ? 'selected' : '' ?>>Paybill</option></select></label>
        <label>Business shortcode (store number / paybill)<input name="mpesa_shortcode" maxlength="20" value="<?= e($S['mpesa_shortcode']) ?>"></label>
        <label>Environment<select name="mpesa_env"><option value="sandbox" <?= $S['mpesa_env'] === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option><option value="production" <?= $S['mpesa_env'] === 'production' ? 'selected' : '' ?>>Production (live)</option></select></label>
        <label>Consumer key<input name="mpesa_key" maxlength="120" value="<?= e($S['mpesa_key']) ?>" autocomplete="off"></label>
        <label>Consumer secret <?= $S['mpesa_secret'] !== '' ? '<span class="muted">(saved, leave blank to keep)</span>' : '' ?><input type="password" name="mpesa_secret" maxlength="200" autocomplete="new-password"></label>
        <label>Passkey <?= $S['mpesa_passkey'] !== '' ? '<span class="muted">(saved, leave blank to keep)</span>' : '' ?><input type="password" name="mpesa_passkey" maxlength="200" autocomplete="new-password"></label>
      </div>
      <p class="muted small">Callback URL (filled in automatically): <code><?= e(preg_replace('/k=.*/', 'k=…', mpesa_callback_url())) ?></code></p>
    </section>
    <section class="panel" id="evc">
      <h2>EVC Plus (Somalia)</h2>
      <p class="muted">Customers in Somalia pay from the invoice or shop with EVC Plus, Zaad or Sahal: they approve on their phone. This uses a WaafiPay merchant account (Hormuud). Ask WaafiPay for the Merchant UID, API user ID and API key. Without keys, the EVC Plus merchant number below is shown for manual payment.</p>
      <p>Online EVC Plus: <strong><?= waafi_ready() ? 'ON (' . e($S['waafi_env']) . ')' : 'OFF until the keys below are filled in' ?></strong></p>
      <div class="grid2">
        <label>EVC Plus merchant number (shown to customers)<input name="evc_merchant" maxlength="30" value="<?= e($S['evc_merchant']) ?>" placeholder="e.g. 123456"></label>
        <label>Environment<select name="waafi_env"><option value="sandbox" <?= $S['waafi_env'] === 'sandbox' ? 'selected' : '' ?>>Sandbox (testing)</option><option value="live" <?= $S['waafi_env'] === 'live' ? 'selected' : '' ?>>Live</option></select></label>
        <label>Merchant UID<input name="waafi_merchant_uid" maxlength="60" value="<?= e($S['waafi_merchant_uid']) ?>" autocomplete="off"></label>
        <label>API user ID<input name="waafi_api_user_id" maxlength="60" value="<?= e($S['waafi_api_user_id']) ?>" autocomplete="off"></label>
        <label>API key <?= $S['waafi_api_key'] !== '' ? '<span class="muted">(saved, leave blank to keep)</span>' : '' ?><input type="password" name="waafi_api_key" maxlength="200" autocomplete="new-password"></label>
        <label>Charge in<select name="waafi_currency"><option value="USD" <?= $S['waafi_currency'] === 'USD' ? 'selected' : '' ?>>USD</option><option value="KES" <?= $S['waafi_currency'] === 'KES' ? 'selected' : '' ?>>Same as invoice (KES)</option></select></label>
        <label>Exchange rate: KES for 1 USD<input name="waafi_fx" maxlength="12" inputmode="decimal" value="<?= e($S['waafi_fx']) ?>" placeholder="129.50"></label>
      </div>
    </section>

    <section class="panel">
      <h2>Sign-in security</h2>
      <p class="muted">Every sign-in has a picture check. Staff can add an authenticator app (Google or Microsoft Authenticator, Authy) under their name at the top right.</p>
      <label class="check"><input type="checkbox" name="require_2fa" value="1" <?= $S['require_2fa'] === '1' ? 'checked' : '' ?>> Require an authenticator app for all staff</label>
    </section>
    <div class="save-bar"><button class="btn">Save settings</button></div>
  </form>

  <section class="panel" id="taxes">
    <h2>Tax rates</h2>
    <p class="muted">Pick one of these on each quote, proposal or invoice, or choose <strong>No tax</strong>. Add a rate for each country you bill in (for example Kenya VAT 16%, or the Somalia sales tax that applies to the client).</p>
    <?php foreach (tax_rates($pdo, false) as $tr): ?>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="tax_save"><input type="hidden" name="id" value="<?= (int)$tr['id'] ?>">
      <label>Name<input name="name" maxlength="60" value="<?= e($tr['name']) ?>" required></label>
      <label>Country<input name="country" maxlength="40" value="<?= e((string)$tr['country']) ?>"></label>
      <label>Rate %<input name="rate" inputmode="decimal" maxlength="7" value="<?= e((string)(float)$tr['rate']) ?>" required></label>
      <label class="check"><input type="checkbox" name="active" value="1" <?= $tr['active'] ? 'checked' : '' ?>> In use</label>
      <button class="btn small ghost">Save</button>
    </form>
    <?php endforeach; ?>
    <form method="post" class="inline-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="tax_save">
      <label>Name<input name="name" maxlength="60" placeholder="Sales tax" required></label>
      <label>Country<input name="country" maxlength="40" placeholder="Somalia"></label>
      <label>Rate %<input name="rate" inputmode="decimal" maxlength="7" placeholder="5" required></label>
      <button class="btn small">Add tax rate</button>
    </form>
  </section>

  <section class="panel" id="payopts">
    <h2>Payment options</h2>
    <p class="muted">These are printed under <strong>How to pay</strong> on invoices and in invoice emails when <em>Show on invoices</em> is ticked. Add bank accounts, an APOPS Pay link, EVC Plus or any other way you accept money. Online M-Pesa and EVC Plus buttons switch on by themselves once their keys are saved above.</p>
    <?php foreach ($pdo->query('SELECT * FROM pay_options ORDER BY sort_order, id')->fetchAll() as $po): ?>
    <form method="post" class="payopt">
      <?= csrf_field() ?><input type="hidden" name="action" value="payopt_save"><input type="hidden" name="id" value="<?= (int)$po['id'] ?>">
      <div class="grid3">
        <label>Name<input name="name" maxlength="80" value="<?= e($po['name']) ?>" required></label>
        <label>Country<input name="country" maxlength="40" value="<?= e((string)$po['country']) ?>" placeholder="Kenya / Somalia / All"></label>
        <label>Payment link (optional)<input name="link" maxlength="500" value="<?= e((string)$po['link']) ?>" placeholder="https://..."></label>
      </div>
      <label>Details shown to the client<textarea name="details" rows="2"><?= e((string)$po['details']) ?></textarea></label>
      <div class="inline-form">
        <label class="check"><input type="checkbox" name="active" value="1" <?= $po['active'] ? 'checked' : '' ?>> Active</label>
        <label class="check"><input type="checkbox" name="on_docs" value="1" <?= $po['on_docs'] ? 'checked' : '' ?>> Show on invoices</label>
        <label>Order<input name="sort_order" type="number" value="<?= (int)$po['sort_order'] ?>"></label>
        <button class="btn small ghost">Save</button>
      </div>
    </form>
    <form method="post" data-confirm="Remove this payment option?"><?= csrf_field() ?><input type="hidden" name="action" value="payopt_delete"><input type="hidden" name="id" value="<?= (int)$po['id'] ?>"><button class="link danger">Remove <?= e($po['name']) ?></button></form>
    <hr>
    <?php endforeach; ?>
    <details><summary class="btn small">Add a payment option</summary>
    <form method="post" class="payopt">
      <?= csrf_field() ?><input type="hidden" name="action" value="payopt_save">
      <div class="grid3">
        <label>Name<input name="name" maxlength="80" required placeholder="e.g. KCB Bank"></label>
        <label>Country<input name="country" maxlength="40" placeholder="Kenya / Somalia / All"></label>
        <label>Payment link (optional)<input name="link" maxlength="500" placeholder="https://..."></label>
      </div>
      <label>Details shown to the client<textarea name="details" rows="2"></textarea></label>
      <input type="hidden" name="active" value="1"><input type="hidden" name="sort_order" value="100">
      <label class="check"><input type="checkbox" name="on_docs" value="1" checked> Show on invoices</label>
      <button class="btn small">Add</button>
    </form></details>
  </section>

  <?php if ($remote): ?>
  <form class="panel" method="post" data-confirm="Copy <?= $remote ?> images/videos from the old website to this server?">
    <?= csrf_field() ?><input type="hidden" name="action" value="localise">
    <h2>Images from the old website</h2>
    <p class="muted"><?= $remote ?> pictures and videos are still loaded from the current sunbeaminnovation.africa site. Copy them here <strong>before</strong> the domain is switched to this new site.</p>
    <button class="btn ghost">Copy them to this server</button>
  </form>
  <?php endif; ?>

  <div class="grid2">
  <form class="panel" method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="test_mail">
    <h2>Email</h2>
    <p class="muted">Sending through <?= cfg('smtp')['enabled'] ? e(cfg('smtp')['host'] . ':' . cfg('smtp')['port'] . ' as ' . cfg('smtp')['user']) : 'PHP mail() (SMTP is off)' ?>. The mailbox password is set in <code>includes/config.php</code>.</p>
    <button class="btn ghost">Send a test email to <?= e(setting('notify_email')) ?></button>
    <p class="muted small">Scheduled invoices last checked: <?= e(setting('cron_last', 'never')) ?></p>
  </form>
  </div>
<?php endif; ?>
</main>
<script src="../assets/js/admin.js"></script>
</body>
</html>
