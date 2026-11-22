<?php
/* Customer order page: summary, M-Pesa payment and status. */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/doc-templates.php';
require_once __DIR__ . '/includes/mpesa.php';
require_once __DIR__ . '/includes/waafi.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$o = null;
$tok = (string)($_GET['t'] ?? '');
if (db_ready() && preg_match('/^[a-f0-9]{48}$/', $tok)) {
    $st = db()->prepare('SELECT * FROM orders WHERE token = ?');
    $st->execute([$tok]);
    $o = $st->fetch() ?: null;
}
if (!$o) http_response_code(404);
$cur = setting('currency', 'KES');
$labels = ['new' => 'Received', 'confirmed' => 'Confirmed', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
page_head($o ? 'Order ' . $o['number'] : 'Order not found');
site_header();
?>
<main>
<section class="section"><div class="container narrow">
<?php if (!$o): ?>
  <h1>Order not found</h1><p>Check the link, or WhatsApp us on <?= e(wa_number()) ?>.</p>
<?php else: $items = json_decode($o['items'], true) ?: []; $due = max(round((float)$o['total'] - (float)$o['paid'], 2), 0); ?>
  <?php if (isset($_GET['new'])): ?><p class="form-note ok" data-clear-cart>Thank you, <?= e(strtok($o['name'], ' ')) ?>! Your order <strong><?= e($o['number']) ?></strong> has been placed.</p><?php endif; ?>
  <?php if (isset($_GET['paid'])): ?><p class="form-note ok">Payment received. Thank you! We will call you to arrange delivery.</p><?php endif; ?>
  <h1>Order <?= e($o['number']) ?></h1>
  <p class="muted">Placed <?= e(date('j M Y, H:i', strtotime($o['created_at']))) ?> · <span class="pill"><?= e($labels[$o['status']] ?? $o['status']) ?></span> · <span class="pill <?= $o['pay_status'] === 'paid' ? 'ok' : '' ?>"><?= $o['pay_status'] === 'paid' ? 'Paid' : ($o['pay_status'] === 'partly' ? 'Part paid' : 'Awaiting payment') ?></span></p>
  <div class="table-wrap"><table class="tbl">
    <thead><tr><th>Item</th><th>Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead>
    <tbody><?php foreach ($items as $i): ?><tr><td><?= e($i['name']) ?></td><td><?= (int)$i['qty'] ?></td><td class="r"><?= money((float)$i['price']) ?></td><td class="r"><?= money((float)$i['total']) ?></td></tr><?php endforeach; ?></tbody>
    <tfoot><tr><th colspan="3" class="r">Total (<?= e($cur) ?>)</th><th class="r"><?= money((float)$o['total']) ?></th></tr>
      <?php if ((float)$o['paid'] > 0): ?><tr><td colspan="3" class="r">Paid<?= $o['mpesa_ref'] ? ' (M-Pesa ' . e($o['mpesa_ref']) . ')' : '' ?></td><td class="r"><?= money((float)$o['paid']) ?></td></tr><?php endif; ?></tfoot>
  </table></div>

  <?php if ($due > 0 && $o['status'] !== 'cancelled'): ?>
  <section class="paybox" data-stk>
    <div class="apops-pay-head"><h2>Pay <?= e($cur) ?> <?= money($due) ?></h2><span class="apops-badge"><img src="assets/img/apops-mark.png" alt="" width="20" height="21"> Processed by <strong>APOPS Pay</strong></span></div>
    <p class="apops-note">Payments go straight to <?= e(setting('company_name')) ?>'s own M-Pesa, EVC Plus or bank account. APOPS Pay only processes and records them; it never holds your money.</p>
    <?php if (mpesa_ready()): ?>
      <p><strong>M-Pesa:</strong> you will get a prompt on your phone. Enter your M-Pesa PIN to complete the payment.</p>
      <form class="qty-row" method="post" action="pay.php">
        <?= csrf_field() ?><input type="hidden" name="o" value="<?= e($o['token']) ?>">
        <label class="grow">Safaricom number<input name="phone" type="tel" required maxlength="20" value="<?= str_starts_with(preg_replace('/\D/', '', $o['phone']), '252') ? '' : e($o['phone']) ?>"></label>
        <button class="btn btn-primary" type="submit">Pay with M-Pesa</button>
      </form>
    <?php endif; ?>
    <?php if (waafi_ready()): ?>
      <p><strong>EVC Plus / Zaad / Sahal:</strong> approve the payment on your phone.</p>
      <form class="qty-row" method="post" action="pay.php">
        <?= csrf_field() ?><input type="hidden" name="o" value="<?= e($o['token']) ?>"><input type="hidden" name="method" value="evc">
        <label class="grow">EVC Plus number<input name="phone" type="tel" required maxlength="20" value="<?= str_starts_with(preg_replace('/\D/', '', $o['phone']), '252') ? e($o['phone']) : '' ?>" placeholder="061 XXX XXXX"></label>
        <button class="btn btn-primary" type="submit">Pay with EVC Plus</button>
      </form>
    <?php endif; ?>
    <p class="pay-msg" role="status"></p>
    <?php if (setting('mpesa_till')): ?>
      <p class="till"><?= mpesa_ready() ? 'Or pay' : 'Pay' ?> by <strong>M-Pesa Buy Goods, Till <?= e(setting('mpesa_till')) ?></strong>, reference <strong><?= e($o['number']) ?></strong>.</p>
    <?php endif; ?>
    <?php if (setting('evc_merchant') && !waafi_ready()): ?>
      <p class="till">Somalia: <strong>EVC Plus merchant <?= e(setting('evc_merchant')) ?></strong>, reference <strong><?= e($o['number']) ?></strong>.</p>
    <?php endif; ?>
    <?php if (!mpesa_ready() && !waafi_ready() && !setting('mpesa_till') && !setting('evc_merchant')): ?>
      <p class="muted">Online payment is being activated. Our team will call you on <?= e($o['phone']) ?> with payment details, or WhatsApp <a href="<?= e(wa_link(wa_number(), 'Payment for order ' . $o['number'])) ?>"><?= e(setting('phone_kenya')) ?></a>.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <p class="muted small">Keep this page's link to track your order.</p>
<?php endif; ?>
</div></section>
</main>
<script src="assets/js/print.js"></script>
<?php site_footer(); ?>
