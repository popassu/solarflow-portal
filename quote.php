<?php
/* Receives the quote form, stores the lead and emails the office. */
require __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('./#quote');

function back(string $status, array $old = []): never
{
    $_SESSION['quote_status'] = $status;
    $_SESSION['quote_old'] = $old;
    redirect('./#quote');
}

$f = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
$old = [
    'name'     => $f('name', 120),
    'phone'    => $f('phone', 40),
    'email'    => $f('email', 160),
    'location' => $f('location', 160),
    'service'  => $f('service', 120),
    'message'  => $f('message', 3000),
];

// Bots: hidden field filled, or form sent faster than a person could type
$started = (int)($_SESSION['quote_started'] ?? 0);
if (($_POST['website'] ?? '') !== '' || ($started && time() - $started < 3)) {
    back('sent'); // pretend success, store nothing
}
if (!csrf_ok()) back('expired', $old);

if ($old['name'] === '' || !preg_match('/^[+0-9 ()-]{9,20}$/', $old['phone'])) back('invalid', $old);
if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) back('invalid', $old);
// Save the lead. If the database is unavailable, the request is still emailed to the office so nothing is lost.
$saved = false;
if (!db_ready()) {
    error_log('quote: the site is not installed yet (data/installed.lock is missing). Run install.php.');
} else {
    try {
        $pdo = db();
        // Max 5 requests per hour from one address
        $st = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE ip = ? AND created_at > ?');
        $st->execute([client_ip(), date('Y-m-d H:i:s', time() - 3600)]);
        if ((int)$st->fetchColumn() >= 5) back('limit', $old);

        $pdo->prepare('INSERT INTO leads (name, phone, email, location, service, message, ip, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$old['name'], $old['phone'], $old['email'] ?: null, $old['location'] ?: null,
                       $old['service'] ?: null, $old['message'] ?: null, client_ip(), now()]);
        $saved = true;
    } catch (Throwable $ex) {
        error_log('quote insert failed (check the database name, user and password in includes/config.local.php): ' . $ex->getMessage());
    }
}

// Email the office. A mail failure never blocks the request once it is saved.
require_once __DIR__ . '/includes/mailer.php';
$body = "A new quote request came in from the website.\n\n"
      . "Name:     {$old['name']}\nPhone:    {$old['phone']}\nEmail:    {$old['email']}\n"
      . "Location: {$old['location']}\nService:  {$old['service']}\n\nMessage:\n{$old['message']}\n\n"
      . 'Received ' . now() . " (Nairobi time)\n"
      . ($saved ? 'Open in the admin portal: ' . site_url('admin/') . "\n" : "NOTE: this request could NOT be saved in the portal (database problem). Reply to the customer from this email.\n");
$mailed = pp_mail(setting('notify_email'), 'New quote request: ' . ($old['service'] ?: 'General') . ' — ' . $old['name'], $body, $old['email'] ?: null, $err);
if (!$mailed) error_log('quote notify mail: ' . $err);
if (!$saved && !$mailed) back('error', $old);

// Acknowledge the customer
if ($old['email'] !== '' && cfg('auto_reply', true)) {
    $first = strtok($old['name'], ' ');
    $ack = "Hello {$first},\n\nThank you for contacting " . setting('company_name') . ". We have received your request"
         . ($old['service'] ? " for {$old['service']}" : '') . " and will contact you on {$old['phone']} shortly.\n\n"
         . 'If it is urgent, WhatsApp us on ' . wa_number() . ' or call ' . setting('phone_kenya') . " (Kenya).\n\n" . setting('company_name') . "\n"
         . setting('email') . "\n" . cfg('site_url') . "\n";
    if (!pp_mail($old['email'], 'We received your request — ' . setting('company_name'), $ack, setting('email'), $err)) {
        error_log('quote ack mail: ' . $err);
    }
}

unset($_SESSION['quote_started']);
back('sent');
