<?php
/*
 * One-time installer. Creates tables, the admin account and the starter website content.
 * DELETE THIS FILE after it reports success.
 */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/schema.php';

$done = false;
$error = '';

if (db_ready()) {
    $error = 'The site is already installed. Delete install.php from the server.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (!csrf_ok()) {
        $error = 'The form expired. Reload the page and try again.';
    } elseif (!preg_match('/^[A-Za-z0-9_.-]{3,60}$/', $user)) {
        $error = 'Username: 3–60 letters, numbers, dots, dashes or underscores.';
    } elseif (strlen($pass) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($pass !== $pass2) {
        $error = 'The two passwords do not match.';
    } else {
        try {
            $pdo = db();
            foreach (pp_schema(cfg('db')['driver']) as $sql) {
                $pdo->exec($sql);
            }
            pp_migrate($pdo);
            $pdo->prepare("INSERT INTO admins (username, full_name, role, password_hash, active, created_at) VALUES (?,?,'owner',?,1,?)")
                ->execute([$user, 'Owner', password_hash($pass, PASSWORD_DEFAULT), now()]);

            pp_seed($pdo);
            setting_save('callback_key', bin2hex(random_bytes(16)));
            setting_save('doc_secret', bin2hex(random_bytes(24)));

            if (!is_dir(dirname(PP_INSTALL_LOCK))) mkdir(dirname(PP_INSTALL_LOCK), 0750, true);
            file_put_contents(PP_INSTALL_LOCK, 'installed ' . now());
            $done = true;
        } catch (Throwable $ex) {
            $error = 'Installation failed: ' . $ex->getMessage()
                . ' — check the database settings in includes/config.php.';
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install — Sunbeam Innovation</title>
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="auth">
<main class="auth-card">
  <img src="assets/img/logo.png" alt="Sunbeam Innovation Limited" class="auth-logo">
  <?php if ($done): ?>
    <h1>Installed</h1>
    <p class="note ok">The database is ready and your admin account is created.</p>
    <p><strong>Now delete <code>install.php</code> from public_html</strong> in Hostinger File Manager.</p>
    <p><a class="btn" href="admin/">Go to the admin portal</a></p>
  <?php else: ?>
    <h1>Set up the site</h1>
    <p>Before you start, put the database name, user and password into <code>includes/config.php</code>.</p>
    <?php if ($error): ?><p class="note err"><?= e($error) ?></p><?php endif; ?>
    <?php if (!db_ready()): ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>Admin username<input name="username" required minlength="3" maxlength="60" value="<?= e($_POST['username'] ?? 'admin') ?>"></label>
      <label>Admin password (10+ characters)<input type="password" name="password" required minlength="10"></label>
      <label>Repeat password<input type="password" name="password2" required minlength="10"></label>
      <button class="btn" type="submit">Install</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
