<?php
/* Staff users: owner adds people, picks their role and ticks what each one can do. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/mailer.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('settings');
$pdo = db();
pp_migrate($pdo);

$owners = fn() => (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner' AND active = 1")->fetchColumn();
$clean = fn($k, $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect('users.php'); }
    $id = (int)($_POST['id'] ?? 0);
    $role = isset(role_list()[$_POST['role'] ?? '']) ? $_POST['role'] : 'secretary';
    $perms = array_values(array_intersect(array_keys(perm_list()), (array)($_POST['perms'] ?? [])));
    if ($role !== 'owner' && !$perms) $perms = role_perms($role);
    switch ($_POST['action'] ?? '') {
        case 'add':
            $user = strtolower($clean('username', 60));
            $pass = (string)($_POST['password'] ?? '');
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $user)) { flash('Username: 3+ letters or numbers, no spaces.', 'err'); break; }
            if (strlen($pass) < 10) { flash('Give a starting password of 10+ characters. They can change it under their name.', 'err'); break; }
            $st = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ?'); $st->execute([$user]);
            if ((int)$st->fetchColumn()) { flash('That username is taken.', 'err'); break; }
            $pdo->prepare('INSERT INTO admins (username, full_name, role, perms, email, phone, password_hash, active, created_at) VALUES (?,?,?,?,?,?,?,1,?)')
                ->execute([$user, $clean('full_name', 120), $role, json_encode($perms), $clean('email', 160) ?: null, $clean('phone', 40) ?: null, password_hash($pass, PASSWORD_DEFAULT), now()]);
            audit($pdo, 'user_added', "$user ($role)");
            flash("Account created for $user. Share the password with them in person; they should change it after signing in.");
            break;
        case 'update':
            $st = $pdo->prepare('SELECT * FROM admins WHERE id = ?'); $st->execute([$id]); $old = $st->fetch();
            if (!$old) break;
            $active = !empty($_POST['active']) ? 1 : 0;
            if ($old['role'] === 'owner' && ($role !== 'owner' || !$active) && $owners() <= 1) { flash('There must always be at least one active owner.', 'err'); break; }
            $pdo->prepare('UPDATE admins SET full_name = ?, role = ?, perms = ?, email = ?, phone = ?, active = ? WHERE id = ?')
                ->execute([$clean('full_name', 120), $role, json_encode($perms), $clean('email', 160) ?: null, $clean('phone', 40) ?: null, $active, $id]);
            if (strlen((string)($_POST['password'] ?? '')) >= 10) {
                $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash((string)$_POST['password'], PASSWORD_DEFAULT), $id]);
            }
            audit($pdo, 'user_updated', "{$old['username']} ($role" . ($active ? '' : ', switched off') . ')');
            flash('Saved.');
            break;
        case 'reset_2fa':
            $pdo->prepare('UPDATE admins SET totp_secret = NULL, totp_on = 0, totp_last = 0 WHERE id = ?')->execute([$id]);
            audit($pdo, '2fa_reset', "user #$id");
            flash('Authenticator removed. They can set up a new one under their name.');
            break;
    }
    redirect('users.php');
}

$users = $pdo->query('SELECT * FROM admins ORDER BY active DESC, role, username')->fetchAll();
$log = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 40')->fetchAll();
$f = flash();
admin_head('Staff');
admin_bar('users', admin_new_leads($pdo), admin_new_orders($pdo));

$permBoxes = function (string $role, array $have) { ?>
  <fieldset class="perm-grid" data-role-perms>
    <legend>What this person can do <span class="muted small">(owners can do everything)</span></legend>
    <?php foreach (perm_list() as $k => $label): ?>
      <label class="check"><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
    <?php endforeach; ?>
  </fieldset>
<?php };
?>
<main class="wrap" data-role-map="<?= e(json_encode(array_map('role_perms', array_combine(array_keys(role_list()), array_keys(role_list()))))) ?>">
  <?php admin_flash($f); ?>
  <div class="head"><h1>Staff</h1></div>
  <p class="muted">Each person signs in with their own username. The role sets the usual permissions; tick or untick boxes to adjust one person. Technicians see only the work orders given to them.</p>

  <details class="panel add-new">
    <summary class="btn">Add a staff member</summary>
    <form method="post" class="user-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="grid3">
        <label>Full name<input name="full_name" required maxlength="120"></label>
        <label>Username<input name="username" required maxlength="60" pattern="[A-Za-z0-9._\-]{3,60}" autocomplete="off"></label>
        <label>Role<select name="role" data-role><?php foreach (role_list() as $k => $v): ?><option value="<?= $k ?>" <?= $k === 'secretary' ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
        <label>Email (for work orders and alerts)<input type="email" name="email" maxlength="160"></label>
        <label>Phone / M-Pesa<input name="phone" maxlength="40"></label>
        <label>Starting password (10+)<input type="text" name="password" required minlength="10" autocomplete="off" value="<?= e(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 14)) ?>"></label>
      </div>
      <?php $permBoxes('secretary', role_perms('secretary')); ?>
      <button class="btn">Create account</button>
    </form>
  </details>

  <?php foreach ($users as $u): $have = user_perms($u); ?>
  <details class="panel">
    <summary class="user-sum">
      <strong><?= e(user_label($u)) ?></strong> <span class="muted">@<?= e($u['username']) ?></span>
      <span class="st <?= $u['role'] === 'owner' ? 'st-invoiced' : 'st-sent' ?>"><?= e(role_name((string)$u['role'])) ?></span>
      <?php if (!(int)$u['active']): ?><span class="st st-cancelled">Switched off</span><?php endif; ?>
      <span class="st <?= (int)$u['totp_on'] ? 'st-paid' : 'st-partly' ?>"><?= (int)$u['totp_on'] ? 'Authenticator on' : 'No authenticator' ?></span>
      <span class="muted small">Last sign-in: <?= $u['last_login'] ? e(date('j M Y H:i', strtotime($u['last_login']))) : 'never' ?></span>
    </summary>
    <form method="post" class="user-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
      <div class="grid3">
        <label>Full name<input name="full_name" maxlength="120" value="<?= e((string)$u['full_name']) ?>"></label>
        <label>Role<select name="role" data-role><?php foreach (role_list() as $k => $v): ?><option value="<?= $k ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></label>
        <label>Email<input type="email" name="email" maxlength="160" value="<?= e((string)$u['email']) ?>"></label>
        <label>Phone / M-Pesa<input name="phone" maxlength="40" value="<?= e((string)$u['phone']) ?>"></label>
        <label>New password <span class="muted small">(blank keeps it)</span><input type="password" name="password" minlength="10" autocomplete="new-password"></label>
        <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$u['active'] ? 'checked' : '' ?>> Account active</label>
      </div>
      <?php $permBoxes((string)$u['role'], $have); ?>
      <button class="btn small">Save</button>
    </form>
    <?php if ((int)$u['totp_on']): ?>
    <form method="post" data-confirm="Remove this person's authenticator (for a lost phone)?"><?= csrf_field() ?><input type="hidden" name="action" value="reset_2fa"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link danger">Reset authenticator (lost phone)</button></form>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>

  <section class="panel">
    <h2>Activity log</h2>
    <p class="muted small">Sign-ins, payments, payouts and staff changes (latest 40).</p>
    <div class="table-wrap"><table class="doc-list"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead><tbody>
    <?php foreach ($log as $l): ?>
      <tr><td><?= e(date('j M H:i', strtotime($l['created_at']))) ?></td><td><?= e((string)$l['username']) ?></td><td><?= e(str_replace('_', ' ', $l['action'])) ?></td><td class="small"><?= e((string)$l['detail']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </section>
</main>
<script src="../assets/js/admin.js"></script>
</body>
</html>
