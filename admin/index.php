<?php
/* Staff sign-in: username + password + picture check, then the authenticator code when it is switched on. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
header('X-Robots-Tag: noindex, nofollow');

if (!db_ready()) {
    exit('The site is not installed yet. Open /install.php first.');
}
$pdo = db();
pp_migrate($pdo);
if (is_admin()) redirect('home.php');

$error = '';
$step = !empty($_SESSION['pending_2fa']) && time() - (int)$_SESSION['pending_2fa']['at'] < 300 ? 2 : 1;
if ($step === 1) unset($_SESSION['pending_2fa']);

$fails = function () use ($pdo): int {
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([date('Y-m-d H:i:s', time() - 86400)]);
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?');
    $st->execute([client_ip(), date('Y-m-d H:i:s', time() - 900)]);
    return (int)$st->fetchColumn();
};
$fail = function () use ($pdo): void {
    $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([client_ip(), now()]);
};
$finish = function (array $row) use ($pdo): never {
    unset($_SESSION['pending_2fa']);
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$row['id'];
    $_SESSION['admin_seen'] = time();
    $pdo->prepare('UPDATE admins SET last_login = ? WHERE id = ?')->execute([now(), $row['id']]);
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
    audit($pdo, 'sign_in', (string)$row['username']);
    redirect('home.php');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($fails() >= 5) {
        $error = 'Too many failed sign-ins. Wait 15 minutes, then try again.';
    } elseif (!csrf_ok()) {
        $error = 'The form expired. Reload the page and try again.';
    } elseif (($_POST['step'] ?? '') === '2' && $step === 2) {
        $st = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND active = 1');
        $st->execute([(int)$_SESSION['pending_2fa']['id']]);
        $row = $st->fetch();
        if ($row && user_totp_ok($pdo, $row, (string)($_POST['code'] ?? ''))) $finish($row);
        $fail();
        $_SESSION['pending_2fa']['tries'] = ($_SESSION['pending_2fa']['tries'] ?? 0) + 1;
        if ($_SESSION['pending_2fa']['tries'] >= 5) { unset($_SESSION['pending_2fa']); $step = 1; }
        $error = 'That code is not right. Use the newest 6-digit code from your authenticator app.';
    } else {
        $captchaOk = captcha_ok((string)($_POST['captcha'] ?? ''));
        $st = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
        $st->execute([trim((string)($_POST['username'] ?? ''))]);
        $row = $st->fetch();
        $pass = (string)($_POST['password'] ?? '');
        // Always run a hash check so timing doesn't reveal valid usernames
        $hash = $row['password_hash'] ?? password_hash(random_bytes(12), PASSWORD_DEFAULT);
        $passOk = password_verify($pass, $hash) && $row;
        if (!$captchaOk) {
            $error = 'The letters from the picture did not match. Try the new picture.';
            $fail();
        } elseif (!$passOk) {
            $error = 'That username and password do not match.';
            $fail();
        } elseif (!(int)$row['active']) {
            $error = 'This account is switched off. Ask the owner.';
        } else {
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $row['id']]);
            }
            if ((int)$row['totp_on']) {
                session_regenerate_id(true);
                $_SESSION['pending_2fa'] = ['id' => (int)$row['id'], 'at' => time(), 'tries' => 0];
                $step = 2;
            } else {
                $finish($row);
            }
        }
    }
}
$f = flash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Sign in — Sunbeam portal</title>
<link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" href="../assets/img/admin-favicon-32.png" type="image/png" sizes="32x32"><link rel="apple-touch-icon" href="../assets/img/admin-apple-touch-icon.png">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="auth">
<main class="auth-card">
  <img src="../assets/img/logo.png" alt="Sunbeam Innovation Limited" class="auth-logo">
  <h1><?= $step === 2 ? 'Enter your code' : 'Staff sign in' ?></h1>
  <?php if ($f): ?><p class="note <?= e($f['type']) ?>"><?= e($f['msg']) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="note err"><?= e($error) ?></p><?php endif; ?>
  <?php if ($step === 2): ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="step" value="2">
    <p class="muted">Open your authenticator app (Google Authenticator, Microsoft Authenticator or Authy) and type the 6-digit code for Sunbeam.</p>
    <label>6-digit code<input name="code" required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" autofocus></label>
    <button class="btn" type="submit">Confirm</button>
  </form>
  <p class="muted"><a href="index.php?restart=1">Start again</a></p>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Username<input name="username" required autocomplete="username" autofocus value="<?= e((string)($_POST['username'] ?? '')) ?>"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <div class="captcha">
      <img src="captcha.php?<?= bin2hex(random_bytes(4)) ?>" width="180" height="56" alt="Picture with 5 letters and numbers">
      <label>Type the 5 characters in the picture<input name="captcha" required maxlength="8" autocomplete="off" autocapitalize="characters" spellcheck="false"></label>
    </div>
    <button class="btn" type="submit">Sign in</button>
  </form>
  <p class="muted"><a href="../">Back to the website</a></p>
  <?php endif; ?>
</main>
</body>
</html>
