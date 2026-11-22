<?php
/*
 * Health check: database, install, content, folders and email.
 * Open it signed in (owner or Settings permission), or, when sign-in is impossible
 * because the database is down, with the cron key: admin/health.php?k=YOUR_CRON_KEY
 */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/mailer.php';
header('X-Robots-Tag: noindex, nofollow');

$key = (string)cfg('cron_key', '');
$byKey = $key !== '' && !str_starts_with($key, 'CHANGE_ME') && hash_equals($key, (string)($_GET['k'] ?? ''));
if (!$byKey) { require_admin(); require_perm('settings'); }

$rows = [];   // [status ok|warn|bad, check, detail]
$add = function (string $st, string $what, string $detail = '') use (&$rows) { $rows[] = [$st, $what, $detail]; };

// PHP
$add(version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'bad', 'PHP version', PHP_VERSION . ' (8.1 or newer needed)');
foreach (['pdo_mysql', 'gd', 'curl', 'mbstring', 'openssl'] as $ext) $add(extension_loaded($ext) ? 'ok' : 'bad', "PHP extension $ext", extension_loaded($ext) ? 'enabled' : 'turn it on in hPanel → Advanced → PHP Configuration → PHP extensions');

// Config (never shows the values)
$db = cfg('db');
$local = is_file(PP_ROOT . '/includes/config.local.php');
$add($local ? 'ok' : 'warn', 'includes/config.local.php', $local ? 'present: passwords survive new uploads' : 'missing: passwords in config.php are wiped every time a new zip is uploaded');
$unset = fn($v) => $v === '' || $v === null || str_starts_with((string)$v, 'CHANGE_ME');
if ($db['driver'] !== 'sqlite') $add($unset($db['pass']) ? 'bad' : 'ok', 'Database password', $unset($db['pass']) ? 'not set' : 'set');
$add($unset(cfg('app_key')) ? 'bad' : 'ok', 'app_key', $unset(cfg('app_key')) ? 'not set (needed for authenticator 2FA)' : 'set');
$add($unset(cfg('cron_key')) ? 'warn' : 'ok', 'cron_key', $unset(cfg('cron_key')) ? 'not set (scheduled invoices and this key link will not work)' : 'set');
$smtp = cfg('smtp', []);
if (!empty($smtp['enabled'])) $add($unset($smtp['pass'] ?? '') ? 'bad' : 'ok', 'Email (SMTP) password', $unset($smtp['pass'] ?? '') ? 'not set: website emails will fail' : 'set');
else $add('warn', 'Email (SMTP)', 'off: PHP mail() is used, which often fails on Hostinger');

// Install + database
$add(db_ready() ? 'ok' : 'bad', 'Installed', db_ready() ? 'data/installed.lock present' : 'not installed: open /install.php');
$pdo = null;
try {
    $pdo = db();
    $pdo->query('SELECT 1');
    $add('ok', 'Database connection', ($db['driver'] === 'sqlite' ? 'SQLite' : 'MySQL ' . $db['name'] . ' as ' . $db['user']) . ': connected');
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $hint = str_contains($msg, 'Access denied') ? ' → the database user or password is wrong'
          : (str_contains($msg, 'Unknown database') ? ' → the database name is wrong or not created in hPanel' : '');
    $add('bad', 'Database connection', preg_replace('/\(using password: \w+\)/', '', $msg) . $hint);
}
if ($pdo) {
    $need = ['admins', 'settings', 'leads', 'cards', 'slides', 'products', 'documents', 'payments', 'work_orders', 'payouts', 'library'];
    $missing = array_values(array_filter($need, fn($t) => !pp_columns($pdo, $t)));
    $add($missing ? 'bad' : 'ok', 'Tables', $missing ? 'missing: ' . implode(', ', $missing) . ' → run /install.php (or sign in to the portal once)' : 'all present');
    if (!$missing) {
        $n = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
        $sol = $n("SELECT COUNT(*) FROM cards WHERE kind='solution' AND visible=1");
        $add($sol ? 'ok' : 'warn', 'Specializations shown', $sol ? "$sol" : 'none in the database: the site shows the built-in three until content is added');
        $svc = $n("SELECT COUNT(*) FROM cards WHERE kind='service' AND visible=1");
        $add($svc ? 'ok' : 'warn', 'Services shown', $svc ? "$svc" : 'none in the database: the built-in three are shown');
        $add('ok', 'Hero slides shown', (string)$n('SELECT COUNT(*) FROM slides WHERE visible=1'));
        $add('ok', 'Products on sale', (string)$n('SELECT COUNT(*) FROM products WHERE visible=1'));
        $add($n('SELECT COUNT(*) FROM admins') ? 'ok' : 'bad', 'Staff accounts', (string)$n('SELECT COUNT(*) FROM admins'));
        $add('ok', 'Quote requests saved', (string)$n('SELECT COUNT(*) FROM leads'));
        $remote = $n("SELECT COUNT(*) FROM cards WHERE visible=1 AND image LIKE 'https://sunbeaminnovation.africa/_next/%'") + $n("SELECT COUNT(*) FROM slides WHERE visible=1 AND file LIKE 'https://sunbeaminnovation.africa/_next/%'");
        $add($remote ? 'warn' : 'ok', 'Pictures linked to the old site', $remote ? "$remote: they break once the old site is gone. Replace them in Web management, or use Settings → Copy them to this server while the old site still answers" : 'none');
    }
}

// Folders
foreach (['data' => PP_ROOT . '/data', 'uploads/site' => PP_UPLOAD_DIR] as $label => $dir) {
    $ok = is_dir($dir) && is_writable($dir);
    $add($ok ? 'ok' : 'bad', "Folder $label writable", $ok ? 'yes' : 'no: set the folder permission to 755 in File Manager');
}

// Email server reachable (no login, nothing sent)
if (!empty($smtp['enabled'])) {
    $t = microtime(true);
    $fp = @fsockopen(($smtp['secure'] ?? '') === 'ssl' ? 'ssl://' . $smtp['host'] : $smtp['host'], (int)$smtp['port'], $errno, $errstr, 6);
    $add($fp ? 'ok' : 'bad', 'Email server reachable', $fp ? $smtp['host'] . ':' . $smtp['port'] . ' answered in ' . round((microtime(true) - $t) * 1000) . ' ms' : "$errstr ($errno)");
    if ($fp) fclose($fp);
}

$sent = null;
if (!$byKey && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $to = trim((string)($_POST['to'] ?? '')) ?: setting('notify_email');
    $ok = pp_mail($to, 'Sunbeam website test email', "This is a test from the portal health check.\nSent " . now() . "\n", setting('email'), $err);
    $sent = $ok ? 'ok' : ($err ?: 'unknown error');
    error_log("health test mail to $to: " . ($ok ? 'sent' : 'FAILED ' . $err));
}

$log = [];
$lf = PP_ROOT . '/data/php-errors.log';
if (is_file($lf)) { $all = file($lf, FILE_IGNORE_NEW_LINES) ?: []; $log = array_slice($all, -15); }

$bad = count(array_filter($rows, fn($r) => $r[0] === 'bad'));
admin_head('Health check');
if (!$byKey) admin_bar('settings');
?>
<main class="wrap">
  <h1>Health check</h1>
  <p class="<?= $bad ? 'note err' : 'note ok' ?>"><?= $bad ? "$bad problem" . ($bad > 1 ? 's' : '') . ' found. Fix the red rows first.' : 'Everything needed is in place.' ?></p>
  <div class="table-wrap"><table class="doc-list health">
    <thead><tr><th></th><th>Check</th><th>Result</th></tr></thead>
    <tbody><?php foreach ($rows as [$st, $what, $detail]): ?>
      <tr class="h-<?= $st ?>"><td><span class="h-dot"><?= $st === 'ok' ? '✓' : ($st === 'warn' ? '!' : '✕') ?></span></td><td><?= e($what) ?></td><td><?= e($detail) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>

  <?php if (!$byKey): ?>
  <form method="post" class="panel">
    <?= csrf_field() ?>
    <h2>Send a test email</h2>
    <p>Sends a short message using the website's email settings. Try an outside address too (for example a Gmail account): if mail to info@ arrives but outside mail does not, the domain's email records (SPF/DKIM) need fixing in hPanel.</p>
    <label>Send to<input type="email" name="to" maxlength="160" placeholder="<?= e(setting('notify_email')) ?>"></label>
    <?php if ($sent === 'ok'): ?><p class="note ok">Accepted by the mail server. Check the inbox and the spam folder.</p><?php elseif ($sent !== null): ?><p class="note err">Not sent: <?= e($sent) ?></p><?php endif; ?>
    <button class="btn">Send test email</button>
  </form>
  <?php endif; ?>

  <div class="panel">
    <h2>Latest errors</h2>
    <?php if ($log): ?><pre class="h-log"><?= e(implode("\n", $log)) ?></pre><?php else: ?><p class="muted">No errors logged.</p><?php endif; ?>
    <p class="muted small">Full log: File Manager → public_html/data/php-errors.log</p>
  </div>
</main>
</body>
</html>
