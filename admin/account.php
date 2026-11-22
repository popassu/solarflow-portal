<?php
/* My account: change password and set up the authenticator app. Open to every staff member. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
$pdo = db();
pp_migrate($pdo);
$u = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect('account.php'); }
    switch ($_POST['action'] ?? '') {
        case 'password':
            $new = (string)($_POST['new'] ?? '');
            if (!password_verify((string)($_POST['current'] ?? ''), (string)$u['password_hash'])) {
                flash('Your current password is not correct.', 'err');
            } elseif (strlen($new) < 10 || $new !== ($_POST['new2'] ?? '')) {
                flash('The new password must be 10+ characters and match in both boxes.', 'err');
            } else {
                $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
                session_regenerate_id(true);
                audit($pdo, 'password_changed');
                flash('Password changed.');
            }
            break;
        case 'totp_start':
            $_SESSION['totp_new'] = b32_encode(random_bytes(20));
            break;
        case 'totp_confirm':
            $secret = (string)($_SESSION['totp_new'] ?? '');
            $step = $secret ? totp_check($secret, (string)($_POST['code'] ?? '')) : 0;
            if (!$step) { flash('That code did not match. Check the time on your phone is automatic and try the newest code.', 'err'); break; }
            $pdo->prepare('UPDATE admins SET totp_secret = ?, totp_on = 1, totp_last = ? WHERE id = ?')->execute([totp_secret_seal($secret), $step, $u['id']]);
            unset($_SESSION['totp_new']);
            audit($pdo, '2fa_on');
            flash('Authenticator switched on. From now on, sign-in asks for a code from the app.');
            break;
        case 'totp_off':
            if (!user_totp_ok($pdo, $u, (string)($_POST['code'] ?? ''))) { flash('That code is not right, so the authenticator stays on.', 'err'); break; }
            if (setting('require_2fa') === '1') { flash('The owner requires an authenticator for all staff, so it cannot be switched off.', 'err'); break; }
            $pdo->prepare('UPDATE admins SET totp_secret = NULL, totp_on = 0, totp_last = 0 WHERE id = ?')->execute([$u['id']]);
            audit($pdo, '2fa_off');
            flash('Authenticator switched off.');
            break;
    }
    redirect('account.php');
}

$f = flash();
admin_head('My account');
admin_bar('account', 0, 0);
$newSecret = $_SESSION['totp_new'] ?? '';
?>
<main class="wrap">
  <?php admin_flash($f); ?>
  <div class="head"><h1>My account</h1></div>
  <p class="muted"><?= e(user_label($u)) ?> · <?= e(role_name((string)$u['role'])) ?> · signed in as <strong><?= e($u['username']) ?></strong></p>
  <div class="grid2">
    <section class="panel">
      <h2>Authenticator app</h2>
      <?php if ((int)$u['totp_on']): ?>
        <p><span class="st st-paid">On</span> Sign-in asks for a 6-digit code from your app.</p>
        <?php if (setting('require_2fa') !== '1'): ?>
        <form method="post" data-confirm="Switch the authenticator off?">
          <?= csrf_field() ?><input type="hidden" name="action" value="totp_off">
          <label>Current code to switch it off<input name="code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code"></label>
          <button class="btn ghost small">Switch off</button>
        </form>
        <?php endif; ?>
      <?php elseif ($newSecret): ?>
        <ol>
          <li>Install Google Authenticator, Microsoft Authenticator or Authy on your phone.</li>
          <li>In the app, tap <strong>+</strong> and scan this code:</li>
        </ol>
        <div class="qr totp-qr" data-qr="<?= e(totp_uri($newSecret, (string)$u['username'])) ?>"></div>
        <p class="small muted">Can't scan? Type this key: <code><?= e(trim(chunk_split($newSecret, 4, ' '))) ?></code></p>
        <form method="post">
          <?= csrf_field() ?><input type="hidden" name="action" value="totp_confirm">
          <label>3. Type the 6-digit code the app shows<input name="code" required inputmode="numeric" maxlength="7" autocomplete="one-time-code" autofocus></label>
          <button class="btn">Switch on</button>
        </form>
      <?php else: ?>
        <p>Add a second step to sign-in: after your password, you type a code from an app on your phone. Anyone who learns your password still cannot get in.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="totp_start"><button class="btn">Set up authenticator</button></form>
      <?php endif; ?>
    </section>
    <form class="panel" method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <h2>Change password</h2>
      <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
      <label>New password (10+ characters)<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
      <label>Repeat new password<input type="password" name="new2" required minlength="10" autocomplete="new-password"></label>
      <button class="btn">Change password</button>
    </form>
  </div>
</main>
<script src="../assets/js/qrcode.js"></script>
<script src="../assets/js/print.js"></script>
</body>
</html>
