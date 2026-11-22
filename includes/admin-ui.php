<?php
/** Top navigation bar shared by admin pages. */
function admin_bar(string $active, int $newLeads = 0, int $newOrders = 0): void
{
    $tabs = [
        'leads'       => ['dashboard.php?tab=leads', 'Inbox', $newLeads, 'leads'],
        'docs'        => ['documents.php', 'Quotes & invoices', 0, 'docs'],
        'workorders'  => ['workorders.php', 'Work orders', admin_my_jobs(), 'workorders|wo_assignee'],
        'receivables' => ['receivables.php', 'APOPS Pay', 0, 'receivables'],
        'receipts'    => ['receipts.php', 'Receipts', 0, 'receivables|docs'],
        'payables'    => ['payables.php', 'APOPS Send', admin_to_approve(), 'payables|payables_ok'],
        'schedules'   => ['schedules.php', 'Scheduled billing', 0, 'schedules'],
        'shop'        => ['shop.php', 'Shop & orders', $newOrders, 'shop'],
        'site'        => ['site.php', 'Web management', 0, 'website'],
        'users'       => ['users.php', 'Staff', 0, 'settings'],
        'settings'    => ['dashboard.php?tab=settings', 'Settings', 0, 'settings'],
    ];
    $tabs = array_filter($tabs, function ($t) { foreach (explode('|', $t[3]) as $p) if (can($p)) return true; return false; });
    $me = current_user();
    ?>
<header class="bar no-print">
  <a href="dashboard.php" class="bar-brand"><img src="../assets/img/icon-192.png" alt=""> Sunbeam portal</a>
  <nav class="tabs" aria-label="Admin sections">
    <?php foreach ($tabs as $key => [$href, $label, $n]): ?>
      <a href="<?= $href ?>" <?= $active === $key ? 'aria-current="page"' : '' ?>><?= $label ?><?php if ($n): ?> <span class="pill"><?= (int)$n ?></span><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="bar-end">
    <a href="account.php" <?= $active === 'account' ? 'aria-current="page"' : '' ?>><?= e(user_label($me)) ?> · <?= e(role_name((string)$me['role'])) ?></a>
    <?php if (can('settings')): ?><a href="health.php">Health check</a><?php endif; ?>
    <a href="../" target="_blank" rel="noopener">View site</a>
    <form method="post" action="logout.php"><?= csrf_field() ?><button class="link">Sign out</button></form>
  </div>
</header>
<?php
}

function admin_new_leads(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Open jobs assigned to the signed-in technician. */
function admin_my_jobs(): int
{
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM work_orders WHERE assigned_to = ? AND status IN ('assigned','open')");
        $st->execute([(int)($_SESSION['admin_id'] ?? 0)]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Payouts waiting for approval (for approvers). */
function admin_to_approve(): int
{
    if (!can('payables_ok')) return 0;
    try {
        return (int)db()->query("SELECT COUNT(*) FROM payouts WHERE status = 'submitted'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function admin_new_orders(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Common admin page head. */
function admin_head(string $title): void
{ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> — Sunbeam admin</title>
<link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" href="../assets/img/admin-favicon-32.png" type="image/png" sizes="32x32"><link rel="apple-touch-icon" href="../assets/img/admin-apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<?php }

function admin_flash(?array $f): void
{
    if ($f) echo '<p class="note ' . e($f['type']) . '">' . ($f['type'] === 'ok' ? e($f['msg']) : $f['msg']) . '</p>';
}
