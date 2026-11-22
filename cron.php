<?php
/*
 * Scheduled invoices. Add an hourly cron job in hPanel → Advanced → Cron Jobs:
 *   php /home/USER/domains/DOMAIN/public_html/cron.php
 * or call https://DOMAIN/cron.php?key=CRON_KEY (key from includes/config.php).
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/billing.php';
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/plain; charset=utf-8');

$key = (string)cfg('cron_key');
if (PHP_SAPI !== 'cli' && (str_contains($key, 'CHANGE_ME') || !hash_equals($key, (string)($_GET['key'] ?? '')))) {
    http_response_code(403);
    exit("Forbidden\n");
}
if (!db_ready()) exit("Not installed\n");
$pdo = db();
pp_migrate($pdo);
mark_overdue($pdo);
$made = run_schedules($pdo);
// Prompts nobody answered within an hour are marked failed so the page stops waiting
$pdo->prepare("UPDATE mpesa_requests SET status='failed', result_desc='No response from M-Pesa', updated_at=? WHERE status='pending' AND created_at < ?")
    ->execute([now(), date('Y-m-d H:i:s', time() - 3600)]);
echo $made ? 'Created ' . implode(', ', $made) . "\n" : "Nothing due\n";
