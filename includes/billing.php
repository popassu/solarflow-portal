<?php
/* Shared billing logic: emailing documents, payments and receipts, shop orders, scheduled invoices. */
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/doc-templates.php';
require_once __DIR__ . '/doc-render.php';

function company_signoff(): string
{
    return "Regards,\n" . setting('company_name') . "\nCall (Kenya): " . setting('phone_kenya') . ' | WhatsApp: ' . setting('phone_somalia') . "\n" . setting('email') . "\n";
}

/** Email a quotation, proposal or invoice link to the client. Returns true on success. */
function doc_email(PDO $pdo, array $d, string $extra = '', ?string &$err = null): bool
{
    if (!$d['client_email']) { $err = 'The client has no email address.'; return false; }
    $types = doc_types();
    $label = $types[$d['type']];
    [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    $cur = doc_cur($d);
    $first = strtok((string)$d['client_name'], ' ') ?: 'Sir/Madam';
    $body = "Dear {$first},\n\n";
    if ($d['type'] === 'invoice') {
        $bal = max($total - doc_paid($pdo, (int)$d['id']), 0);
        $body .= "Please find our invoice {$d['number']}" . ($d['title'] ? " for {$d['title']}" : '') . ".\n\n"
               . "Amount due: $cur " . money($bal) . "\nDue date: " . date('j M Y', strtotime($d['doc_date']) + 86400 * $d['due_days']) . "\n\n"
               . "VIEW AND PAY ONLINE with APOPS Pay (M-Pesa, EVC Plus):\n" . doc_public_url($d) . "\nPayments go directly to " . setting('company_name') . "; APOPS Pay processes them and never holds funds.\n\n"
               . "Other ways to pay:\n" . setting('payment_details') . "\n"
               . implode('', array_map(fn($o) => "\n" . $o['name'] . ($o['details'] ? ": " . $o['details'] : '') . ($o['link'] ? "\n" . $o['link'] : '') . "\n", pay_options_for_docs()))
               . "\nReference: {$d['number']}\n\n";
    } else {
        $body .= 'Thank you for considering ' . setting('company_name') . '. Please find our ' . strtolower($label) . " {$d['number']}"
               . ($d['title'] ? " for {$d['title']}" : '') . ".\n\nTotal: $cur " . money($total) . "\nValid until: "
               . date('j M Y', strtotime($d['doc_date']) + 86400 * $d['valid_days']) . "\n\n"
               . "View, print and approve it online:\n" . doc_public_url($d) . "\n\n";
    }
    $extra = trim($extra);
    $body .= ($extra !== '' ? $extra . "\n\n" : '')
           . "Every " . setting('company_name') . " document has a QR code and security code. To confirm it is genuine, scan the code or visit " . site_url('verify') . "\n\n"
           . company_signoff();
    $subject = "$label {$d['number']} — " . setting('company_name');
    $to = trim((string)$d['client_email']);
    // The document goes as a PDF (never HTML: Gmail blocks HTML attachments with 552 5.7.0), with a View & pay link in the body
    $att = [];
    if (is_file(__DIR__ . '/doc-pdf.php')) {
        require_once __DIR__ . '/doc-pdf.php';
        if ($pdf = pdf_attachment(fn() => doc_pdf($pdo, $d), $d['number'])) $att[] = $pdf;
    }
    $html = doc_email_html($pdo, $d, $label, $total, $cur, $extra);
    $ok = pp_mail($to, $subject, $body, setting('email'), $err, $att, $html);
    $note = $att ? 'PDF attached' : 'link only (PDF could not be built, see the error log)';
    $pdo->prepare('UPDATE documents SET mail_at=?, mail_to=?, mail_result=? WHERE id=?')
        ->execute([now(), $to, $ok ? ('ok' . ($note ? ': ' . $note : '')) : mb_substr('failed: ' . (string)$err, 0, 500), $d['id']]);
    error_log("doc_email {$d['number']} to $to: " . ($ok ? 'sent' . ($note ? " ($note)" : '') : 'FAILED ' . $err));
    if ($ok) {
        $pdo->prepare("UPDATE documents SET sent_at=?, status=CASE WHEN status='draft' THEN 'sent' ELSE status END, updated_at=? WHERE id=?")->execute([now(), now(), $d['id']]);
        audit($pdo, 'document_sent', "{$d['number']} to {$d['client_email']}");
    }
    return $ok;
}

/** Branded HTML version of a document email with a View & pay button (no scripts, no attachments). */
function doc_email_html(PDO $pdo, array $d, string $label, float $total, string $cur, string $extra = ''): string
{
    $isInv = $d['type'] === 'invoice';
    $amount = $isInv ? max($total - doc_paid($pdo, (int)$d['id']), 0) : $total;
    $first = strtok((string)$d['client_name'], ' ') ?: 'Sir/Madam';
    $date = $isInv ? 'Due ' . date('j M Y', strtotime($d['doc_date']) + 86400 * $d['due_days']) : 'Valid until ' . date('j M Y', strtotime($d['doc_date']) + 86400 * $d['valid_days']);
    $btn = $isInv ? 'View &amp; pay invoice' : 'View ' . e(strtolower($label));
    $url = e(doc_public_url($d));
    $co = e(setting('company_name'));
    $extraHtml = trim($extra) !== '' ? '<p style="margin:0 0 16px">' . nl2br(e(trim($extra))) . '</p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden">'
        . '<tr><td style="background:#2C2F64;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold">' . $co . '</td></tr>'
        . '<tr><td style="padding:24px">'
        . '<p style="margin:0 0 12px">Dear ' . e($first) . ',</p>'
        . '<p style="margin:0 0 16px">Please find our ' . e(strtolower($label)) . ' <strong>' . e($d['number']) . '</strong>' . ($d['title'] ? ' for ' . e($d['title']) : '') . '.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:0 0 20px"><tr>'
        . '<td style="padding:14px 16px"><span style="color:#64748b;font-size:13px">' . ($isInv ? 'Amount due' : 'Total') . '</span><br><strong style="font-size:22px;color:#2C2F64">' . e($cur) . ' ' . money($amount) . '</strong></td>'
        . '<td style="padding:14px 16px;text-align:right;color:#64748b;font-size:13px">' . e($date) . '<br>Ref: ' . e($d['number']) . '</td></tr></table>'
        . $extraHtml
        . '<p style="margin:0 0 20px;text-align:center"><a href="' . $url . '" style="display:inline-block;background:#DF3128;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 26px;border-radius:8px">' . $btn . '</a></p>'
        . ($isInv ? '<p style="margin:0 0 12px;font-size:13px;color:#475569">Pay by M-Pesa or EVC Plus on that page. Payments go directly to ' . $co . '; APOPS Pay processes them and never holds funds. You can also print or save the ' . e(strtolower($label)) . ' as PDF there.</p>' : '<p style="margin:0 0 12px;font-size:13px;color:#475569">You can review, print or save it as PDF, and accept it online.</p>')
        . '<p style="margin:0;font-size:12px;color:#64748b">Every document has a security code. Confirm it is genuine at ' . e(site_url('verify')) . '</p>'
        . '</td></tr><tr><td style="background:#f8fafc;padding:14px 24px;font-size:12px;color:#64748b">'
        . $co . ' · Call (Kenya) ' . e(setting('phone_kenya')) . ' · WhatsApp ' . e(setting('phone_somalia')) . ' · ' . e(setting('email'))
        . '</td></tr></table></td></tr></table></body></html>';
}

/** Paid-to-date on the invoice as at this payment (this one included). */
function receipt_paid_to_date(PDO $pdo, array $p): float
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE document_id = ? AND (paid_on < ? OR (paid_on = ? AND id <= ?))');
    $st->execute([$p['document_id'], $p['paid_on'], $p['paid_on'], $p['id']]);
    return round((float)$st->fetchColumn(), 2);
}

/** "Interim receipt" while a balance remains after this payment, otherwise "Final receipt" (as in APOPS Pay). */
function receipt_kind(float $balanceAfter): string
{
    return $balanceAfter > 0.005 ? 'Interim receipt' : 'Final receipt';
}

/** Email one receipt to the client (or $to): HTML body with a View receipt button + the receipt as a PDF attachment. */
function receipt_email(PDO $pdo, int $pid, ?string &$err = null, ?string $to = null): bool
{
    $st = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
    $st->execute([$pid]);
    $p = $st->fetch();
    $d = $p ? doc_load($pdo, (int)$p['document_id']) : null;
    if (!$p || !$d) { $err = 'Receipt not found.'; return false; }
    $to = $to ?: (string)$d['client_email'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $err = 'No valid client email on invoice ' . $d['number'] . '.'; return false; }
    [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    $bal = max(round($total - receipt_paid_to_date($pdo, $p), 2), 0);
    $kind = receipt_kind($bal);
    $cur = doc_cur($d);
    $rno = $p['receipt_no'];
    $url = site_url('doc.php?r=' . $p['token']);
    $first = strtok((string)$d['client_name'], ' ') ?: 'Sir/Madam';
    $co = e(setting('company_name'));
    $method = pay_methods()[$p['method']] ?? $p['method'];
    $body = "Dear $first,\n\nThank you. We have received $cur " . money((float)$p['amount']) . " for invoice {$d['number']}" . ($p['reference'] ? " (ref. {$p['reference']})" : '')
          . ".\n\n$kind $rno: $url\n\nInvoice total: $cur " . money($total) . "\nBalance remaining: $cur " . money($bal)
          . "\n\nThe receipt is attached as a PDF.\n\nPayment processed by APOPS Pay and paid directly to " . setting('company_name') . ".\n\n" . company_signoff();
    $row = fn($k, $v) => '<tr><td style="padding:6px 0;color:#64748b;font-size:13px">' . $k . '</td><td style="padding:6px 0;text-align:right;font-size:14px">' . $v . '</td></tr>';
    $html = '<!doctype html><html><body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 0"><tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:10px;overflow:hidden">'
        . '<tr><td style="background:#2C2F64;color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold">' . $co . '</td></tr>'
        . '<tr><td style="padding:24px">'
        . '<p style="margin:0 0 12px">Dear ' . e($first) . ',</p>'
        . '<p style="margin:0 0 16px">Thank you. We have received your payment for invoice <strong>' . e($d['number']) . '</strong>. Your ' . e(strtolower($kind)) . ' is attached as a PDF.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin:0 0 20px"><tr><td style="padding:14px 16px">'
        . '<span style="color:#64748b;font-size:13px">' . e($kind) . ' ' . e($rno) . '</span><br><strong style="font-size:22px;color:#2C2F64">' . e($cur) . ' ' . money((float)$p['amount']) . '</strong>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;border-top:1px solid #e2e8f0">'
        . $row('Invoice', e($d['number'])) . $row('Paid on', date('j M Y', strtotime($p['paid_on']))) . $row('Method', e($method) . ($p['reference'] ? ' · ' . e($p['reference']) : ''))
        . $row('Invoice total', e($cur) . ' ' . money($total)) . $row('<strong>Balance remaining</strong>', '<strong>' . e($cur) . ' ' . money($bal) . '</strong>')
        . '</table></td></tr></table>'
        . '<p style="margin:0 0 20px;text-align:center"><a href="' . e($url) . '" style="display:inline-block;background:#DF3128;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 26px;border-radius:8px">View receipt</a></p>'
        . ($bal > 0.005 ? '<p style="margin:0 0 12px;font-size:13px;color:#475569">You can pay the balance from the invoice: <a href="' . e(doc_public_url($d)) . '">' . e(doc_public_url($d)) . '</a></p>' : '<p style="margin:0 0 12px;font-size:13px;color:#475569">This invoice is now fully paid. Thank you for your business.</p>')
        . '<p style="margin:0;font-size:12px;color:#64748b">Payment processed by APOPS Pay and paid directly to ' . $co . '; APOPS Pay never holds funds.</p>'
        . '</td></tr><tr><td style="background:#f8fafc;padding:14px 24px;font-size:12px;color:#64748b">'
        . $co . ' · Call (Kenya) ' . e(setting('phone_kenya')) . ' · WhatsApp ' . e(setting('phone_somalia')) . ' · ' . e(setting('email'))
        . '</td></tr></table></td></tr></table></body></html>';
    $att = [];
    if (is_file(__DIR__ . '/doc-pdf.php')) {
        require_once __DIR__ . '/doc-pdf.php';
        if ($pdf = pdf_attachment(fn() => receipt_pdf($pdo, $p, $d), $rno)) $att[] = $pdf;
    }
    $ok = pp_mail($to, "$kind $rno — " . setting('company_name'), $body, setting('email'), $err, $att, $html);
    try {
        $pdo->prepare('UPDATE payments SET mail_at = ?, mail_to = ?, mail_result = ? WHERE id = ?')
            ->execute([now(), $to, $ok ? 'sent' : mb_substr('failed: ' . $err, 0, 250), $pid]);
    } catch (Throwable $e) { /* columns added by pp_migrate */ }
    return $ok;
}

/** Record a payment against an invoice, issue a receipt, update the status, optionally email the receipt. */
function record_payment(PDO $pdo, int $docId, float $amount, string $method, ?string $reference, string $paidOn, ?string $note, bool $emailReceipt, ?string &$msg = null): ?int
{
    $d = doc_load($pdo, $docId);
    if (!$d || $amount <= 0) return null;
    $online = $note && str_contains($note, 'online');
    // One payment reference can be used once only (M-Pesa / EVC / bank codes)
    $reference = $reference ? strtoupper(trim($reference)) : null;
    if ($reference && in_array($method, ['mpesa', 'evc', 'bank'], true)) {
        $st = $pdo->prepare('SELECT receipt_no FROM payments WHERE UPPER(reference) = ? LIMIT 1');
        $st->execute([$reference]);
        if ($dup = $st->fetchColumn()) { $msg = "Reference $reference was already used on receipt $dup. Nothing was recorded."; return null; }
    }
    // Staff cannot record more than is owed (money that arrives online is always recorded)
    [, , , $tot0] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    $bal0 = round($tot0 - doc_paid($pdo, $docId), 2);
    if (!$online && $amount > $bal0 + 0.005) { $msg = 'That is more than the balance of ' . doc_cur($d) . ' ' . money(max($bal0, 0)) . '. Nothing was recorded.'; return null; }
    $rno = receipt_next_number($pdo);
    $tok = bin2hex(random_bytes(24));
    $pdo->prepare('INSERT INTO payments (document_id, receipt_no, amount, method, reference, paid_on, note, token, recorded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$docId, $rno, round($amount, 2), $method, $reference ?: null, $paidOn, $note ?: null, $tok, $online ? null : ($_SESSION['admin_id'] ?? null), now()]);
    $pid = (int)$pdo->lastInsertId(); // read before audit() inserts its own row
    if (!$online) audit($pdo, 'payment_recorded', "$rno {$d['number']} " . money($amount) . " $method " . ($reference ?? ''));
    [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    $paid = doc_paid($pdo, $docId);
    $pdo->prepare('UPDATE documents SET status=?, updated_at=? WHERE id=?')->execute([$paid + 0.005 >= $total ? 'paid' : 'partly', now(), $docId]);
    $cur = doc_cur($d);
    $msg = "Payment of $cur " . money($amount) . " recorded. Receipt $rno issued.";

    // Let the office know about online payments
    if ($online) {
        pp_mail(setting('notify_email', setting('email')), "APOPS Pay: paid {$d['number']} — $cur " . money($amount) . " ($reference)",
            ($method === 'evc' ? 'EVC Plus' : 'M-Pesa') . " payment received online.\n\nInvoice: {$d['number']}\nClient: " . ($d['client_company'] ?: $d['client_name']) . "\nAmount: $cur " . money($amount)
            . "\nRef: $reference\nBalance: $cur " . money(max($total - $paid, 0)) . "\n\n" . site_url('admin/documents.php?id=' . $docId) . "\n", null, $e2);
    }
    if ($emailReceipt && $d['client_email']) {
        $msg .= receipt_email($pdo, $pid, $err) ? " Receipt emailed to {$d['client_email']}." : ' Receipt email failed: ' . e((string)$err);
    }
    return $pid;
}

/** Mark a shop order (fully or partly) paid and notify office + customer. */
function order_mark_paid(PDO $pdo, int $orderId, float $amount, ?string $ref): void
{
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) return;
    $paid = round((float)$o['paid'] + $amount, 2);
    $pay = $paid + 0.005 >= (float)$o['total'] ? 'paid' : 'partly';
    $pdo->prepare("UPDATE orders SET paid=?, pay_status=?, mpesa_ref=?, status=CASE WHEN status='new' THEN 'confirmed' ELSE status END, updated_at=? WHERE id=?")
        ->execute([$paid, $pay, $ref, now(), $orderId]);
    $cur = setting('currency', 'KES');
    pp_mail(setting('notify_email', setting('email')), "Shop order paid: {$o['number']} — $cur " . money($amount),
        "Order {$o['number']} from {$o['name']} ({$o['phone']}) received $cur " . money($amount) . ($ref ? " (M-Pesa $ref)" : '') . ".\n\n" . site_url('admin/shop.php?order=' . $orderId) . "\n", null, $e);
    if ($o['email']) {
        pp_mail($o['email'], "Payment received — order {$o['number']}",
            "Dear " . (strtok($o['name'], ' ') ?: 'Customer') . ",\n\nThank you. We received $cur " . money($amount) . ($ref ? " (M-Pesa $ref)" : '')
            . " for order {$o['number']}. We will call you to arrange delivery or installation.\n\nTrack your order: " . site_url('order.php?t=' . $o['token']) . "\n\n" . company_signoff(), setting('email'), $e);
    }
}

/**
 * Create invoices for every active schedule that is due. Safe to run any number of times:
 * each run moves next_run forward, and schedules stop after their end date.
 * Returns the list of new invoice numbers.
 */
function run_schedules(PDO $pdo): array
{
    $today = date('Y-m-d');
    $made = [];
    $st = $pdo->prepare('SELECT * FROM schedules WHERE active = 1 AND next_run <= ?');
    $st->execute([$today]);
    foreach ($st->fetchAll() as $s) {
        $guard = 0;
        while ($s['next_run'] <= $today && $guard++ < 24) {
            if ($s['end_date'] && $s['next_run'] > $s['end_date']) break;
            $tpl = doc_load($pdo, (int)$s['template_id']);
            if (!$tpl) { $pdo->prepare('UPDATE schedules SET active = 0 WHERE id = ?')->execute([$s['id']]); break; }
            $period = date('F Y', strtotime($s['next_run']));
            $title = preg_replace('/\s+—\s+[A-Z][a-z]+ \d{4}$/', '', (string)$tpl['title']);
            $pdo->prepare('INSERT INTO documents (type, number, lead_id, source_id, schedule_id, title, client_name, client_company, client_phone, client_email, client_address, site, items, sections, vat, discount, notes, terms, valid_days, due_days, status, token, doc_date, created_at, updated_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(['invoice', doc_next_number($pdo, 'invoice'), $tpl['lead_id'], null, $s['id'], trim($title . ' — ' . $period, ' —'), $tpl['client_name'], $tpl['client_company'],
                           $tpl['client_phone'], $tpl['client_email'], $tpl['client_address'], $tpl['site'], json_encode($tpl['items']), '[]', $tpl['vat'], $tpl['discount'],
                           $tpl['notes'], $tpl['terms'], 30, $tpl['due_days'], 'draft', bin2hex(random_bytes(24)), $s['next_run'] > $today ? $today : $s['next_run'], now(), now()]);
            $newId = (int)$pdo->lastInsertId();
            doc_copy_tax($pdo, $newId, $tpl);
            $new = doc_load($pdo, $newId);
            $made[] = $new['number'];
            if ($s['auto_email'] && $new['client_email']) doc_email($pdo, $new, 'This is your scheduled invoice for ' . $period . '.', $err);

            $next = add_months($s['next_run'], max(1, (int)$s['every_months']));
            $active = (!$s['end_date'] || $next <= $s['end_date']) ? 1 : 0;
            $pdo->prepare('UPDATE schedules SET next_run = ?, last_run = ?, runs = runs + 1, active = ? WHERE id = ?')->execute([$next, $today, $active, $s['id']]);
            $s['next_run'] = $next;
            if (!$active) break;
        }
    }
    setting_save('cron_last', now());
    return $made;
}

/** Run schedules at most once an hour when someone opens the admin (backup for the cron job). */
/** Invoices past their due date (sent or viewed, nothing paid) become Overdue. Runs with the hourly job. */
function mark_overdue(PDO $pdo): int
{
    $n = 0;
    foreach ($pdo->query("SELECT id, doc_date, due_days FROM documents WHERE type='invoice' AND status IN ('sent','viewed')") as $r) {
        if (strtotime($r['doc_date']) + 86400 * ((int)$r['due_days'] + 1) <= time()) {
            $pdo->prepare("UPDATE documents SET status='overdue', updated_at=? WHERE id=?")->execute([now(), $r['id']]);
            $n++;
        }
    }
    return $n;
}

/** True when an unpaid or part-paid invoice is past its due date. */
function doc_is_overdue(array $d): bool
{
    return ($d['type'] ?? '') === 'invoice' && in_array($d['status'], ['sent', 'viewed', 'partly', 'overdue'], true)
        && strtotime($d['doc_date']) + 86400 * ((int)$d['due_days'] + 1) <= time();
}

function maybe_run_schedules(PDO $pdo): void
{
    $last = setting('cron_last');
    if ($last === '' || strtotime($last) < time() - 3600) {
        try { mark_overdue($pdo); run_schedules($pdo); } catch (Throwable $e) { error_log('schedules: ' . $e->getMessage()); }
    }
}

/** Add months without skipping short months (31 Jan + 1 month = 28/29 Feb). */
function add_months(string $date, int $n): string
{
    $d = new DateTime($date);
    $day = (int)$d->format('j');
    $d->modify('first day of this month')->modify("+$n month");
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($day, (int)$d->format('t')));
    return $d->format('Y-m-d');
}
