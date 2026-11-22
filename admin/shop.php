<?php
/* Online shop: products and customer orders. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/billing.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('shop');

$pdo = db();
pp_migrate($pdo);
$tab = ($_GET['tab'] ?? '') === 'products' ? 'products' : 'orders';
$orderStatuses = ['new' => 'New', 'confirmed' => 'Confirmed', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];
$f = fn(string $k, int $max = 400) => ($v = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max)) === '' ? null : $v;
$num = fn(string $k) => round((float)str_replace(',', '', (string)($_POST[$k] ?? 0)), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = $_POST['back'] ?? 'shop.php';
    if (!preg_match('/^shop\.php(\?[a-z0-9=&]*)?$/', $back)) $back = 'shop.php';
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect($back); }
    $id = (int)($_POST['id'] ?? 0);
    try {
        switch ($_POST['action'] ?? '') {
            case 'order_reply':
                $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?'); $st->execute([$id]); $o = $st->fetch();
                $subj = $f('subject', 200); $msg = trim((string)($_POST['body'] ?? ''));
                if (!$o || !$o['email']) throw new RuntimeException('This order has no email address. Call or WhatsApp instead.');
                if (!$subj || $msg === '') throw new RuntimeException('Add a subject and a message.');
                $ok = pp_mail($o['email'], $subj, $msg, setting('email'), $err);
                $pdo->prepare('INSERT INTO replies (lead_id, order_id, to_email, subject, body, ok, error, sent_by, created_at) VALUES (0,?,?,?,?,?,?,?,?)')
                    ->execute([$id, $o['email'], $subj, $msg, $ok ? 1 : 0, $ok ? null : mb_substr((string)$err, 0, 255), $_SESSION['admin_id'], now()]);
                if (!$ok) throw new RuntimeException('Email not sent: ' . $err);
                flash('Email sent to ' . $o['email'] . '.');
                break;
            case 'product_save':
                if (!$f('name', 160)) throw new RuntimeException('Give the product a name.');
                $img = handle_upload('upload', false, 1400) ?? $f('image_url', 500);
                $vals = [$f('name', 160), $f('category', 120), $f('sku', 60), $num('price'), $num('old_price') ?: null, $f('summary', 400), $f('description', 8000),
                         (int)($_POST['stock'] ?? 0), !empty($_POST['featured']) ? 1 : 0, !empty($_POST['visible']) ? 1 : 0, (int)($_POST['sort_order'] ?? 100)];
                if ($id) {
                    $pdo->prepare('UPDATE products SET name=?, category=?, sku=?, price=?, old_price=?, summary=?, description=?, stock=?, featured=?, visible=?, sort_order=? WHERE id=?')->execute([...$vals, $id]);
                    if ($img) $pdo->prepare('UPDATE products SET image=? WHERE id=?')->execute([$img, $id]);
                } else {
                    $pdo->prepare('INSERT INTO products (name, category, sku, price, old_price, summary, description, stock, featured, visible, sort_order, image, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$vals, $img, now()]);
                }
                flash('Product saved.');
                break;
            case 'product_toggle':
                if (in_array($_POST['field'] ?? '', ['featured', 'visible'], true)) {
                    $c = $_POST['field'];
                    $pdo->prepare("UPDATE products SET $c = 1 - $c WHERE id = ?")->execute([$id]);
                }
                break;
            case 'product_delete':
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
                flash('Product deleted.');
                break;
            case 'order_status':
                if (isset($orderStatuses[$_POST['status'] ?? ''])) {
                    $pdo->prepare('UPDATE orders SET status = ?, updated_at = ? WHERE id = ?')->execute([$_POST['status'], now(), $id]);
                    flash('Order updated.');
                }
                break;
            case 'order_paid':
                $amt = $num('amount');
                if ($amt <= 0) throw new RuntimeException('Enter the amount received.');
                order_mark_paid($pdo, $id, $amt, $f('reference', 40));
                flash('Payment recorded.');
                break;
            case 'order_invoice':
                $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
                $st->execute([$id]);
                $o = $st->fetch();
                $items = array_map(fn($i) => ['desc' => $i['name'], 'qty' => $i['qty'], 'unit' => 'no.', 'price' => $i['price']], json_decode($o['items'], true) ?: []);
                // Shop prices already include VAT, so the invoice is created without adding VAT again
                $pdo->prepare('INSERT INTO documents (type, number, title, client_name, client_phone, client_email, client_address, items, sections, vat, discount, notes, terms, valid_days, due_days, status, token, doc_date, created_at, updated_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute(['invoice', doc_next_number($pdo, 'invoice'), 'Online order ' . $o['number'], $o['name'], $o['phone'], $o['email'], $o['address'], json_encode($items), '[]', 0, 0,
                               'Prices include VAT.', doc_default_terms('invoice'), 30, 7, 'draft', bin2hex(random_bytes(24)), date('Y-m-d'), now(), now()]);
                $newId = (int)$pdo->lastInsertId();
                if ((float)$o['paid'] > 0) record_payment($pdo, $newId, (float)$o['paid'], 'mpesa', $o['mpesa_ref'], date('Y-m-d'), 'Paid for online order ' . $o['number'], true);
                flash('Invoice created from order ' . $o['number'] . '.');
                redirect('documents.php?id=' . $newId);
        }
    } catch (Throwable $e) {
        flash(e($e->getMessage()), 'err');
    }
    redirect($back);
}

$order = null;
if (isset($_GET['order'])) {
    $st = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([(int)$_GET['order']]);
    $order = $st->fetch() ?: null;
}
$cats = $pdo->query("SELECT title FROM cards WHERE kind = 'solution' ORDER BY sort_order")->fetchAll(PDO::FETCH_COLUMN);
$cur = setting('currency', 'KES');
$fl = flash();
admin_head('Shop & orders');
admin_bar('shop', admin_new_leads($pdo), admin_new_orders($pdo));

function product_form(array $cats, array $p = []): void
{
    $v = fn(string $k) => e((string)($p[$k] ?? ''));
    ?>
  <form method="post" enctype="multipart/form-data" class="item-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="product_save"><input type="hidden" name="id" value="<?= (int)($p['id'] ?? 0) ?>"><input type="hidden" name="back" value="shop.php?tab=products">
    <div class="grid2">
      <label>Name<input name="name" required maxlength="160" value="<?= $v('name') ?>"></label>
      <label>Category<select name="category"><option value="">—</option><?php foreach (array_merge($cats, ['Accessories']) as $c): ?><option <?= ($p['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></label>
      <label>Price (KES, incl. VAT)<input name="price" inputmode="decimal" required value="<?= $v('price') ?>"></label>
      <label>Was price (optional, shows "Offer")<input name="old_price" inputmode="decimal" value="<?= $v('old_price') ?>"></label>
      <label>Stock (0 = out of stock)<input type="number" name="stock" min="0" value="<?= (int)($p['stock'] ?? 10) ?>"></label>
      <label>SKU<input name="sku" maxlength="60" value="<?= $v('sku') ?>"></label>
      <label class="span-all">Short summary<textarea name="summary" rows="2" maxlength="400"><?= $v('summary') ?></textarea></label>
      <label class="span-all">Description. Lines starting with "-" become a ticked list.<textarea name="description" rows="5" maxlength="8000"><?= $v('description') ?></textarea></label>
      <label>Picture<?= $p ? ' (leave empty to keep)' : '' ?><input type="file" name="upload" accept="image/jpeg,image/png,image/webp"></label>
      <label>…or paste an image link<input name="image_url" maxlength="500"></label>
    </div>
    <div class="row-inline">
      <label class="narrow">Order<input type="number" name="sort_order" value="<?= (int)($p['sort_order'] ?? 100) ?>"></label>
      <label class="check"><input type="checkbox" name="featured" value="1" <?= !empty($p['featured']) ? 'checked' : '' ?>> Show on home page</label>
      <label class="check"><input type="checkbox" name="visible" value="1" <?= ($p['visible'] ?? 1) ? 'checked' : '' ?>> In the shop</label>
      <button class="btn small"><?= $p ? 'Save changes' : 'Add product' ?></button>
    </div>
  </form>
<?php }
?>
<main class="wrap">
  <?php admin_flash($fl); ?>
<?php if ($order): $items = json_decode($order['items'], true) ?: []; $due = max((float)$order['total'] - (float)$order['paid'], 0); ?>
  <p class="crumb"><a href="shop.php">← All orders</a></p>
  <div class="head"><h1>Order <?= e($order['number']) ?></h1><span class="st st-<?= $order['pay_status'] === 'paid' ? 'paid' : ($order['pay_status'] === 'partly' ? 'partly' : 'sent') ?>"><?= $order['pay_status'] === 'paid' ? 'Paid' : ($order['pay_status'] === 'partly' ? 'Part paid' : 'Unpaid') ?></span></div>
  <div class="doc-grid">
    <section class="panel">
      <h2>Customer</h2>
      <p><strong><?= e($order['name']) ?></strong><br><a href="tel:<?= e(preg_replace('/[^+0-9]/', '', $order['phone'])) ?>"><?= e($order['phone']) ?></a> · <a href="<?= e(wa_link($order['phone'], 'Hello ' . strtok($order['name'], ' ') . ', about your Sunbeam order ' . $order['number'])) ?>" target="_blank" rel="noopener">WhatsApp</a>
        <?php if ($order['email']): ?><br><a href="mailto:<?= e($order['email']) ?>"><?= e($order['email']) ?></a><?php endif; ?>
        <?php if ($order['address']): ?><br><?= nl2br(e($order['address'])) ?><?php endif; ?></p>
      <?php if ($order['notes']): ?><p class="msg"><?= nl2br(e($order['notes'])) ?></p><?php endif; ?>
      <div class="table-wrap"><table class="doc-list"><thead><tr><th>Item</th><th>Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead><tbody>
        <?php foreach ($items as $i): ?><tr><td><?= e($i['name']) ?></td><td><?= (int)$i['qty'] ?></td><td class="r"><?= money((float)$i['price']) ?></td><td class="r"><?= money((float)$i['total']) ?></td></tr><?php endforeach; ?>
        <tr><td colspan="3" class="r"><strong>Total (<?= e($cur) ?>)</strong></td><td class="r"><strong><?= money((float)$order['total']) ?></strong></td></tr>
        <tr><td colspan="3" class="r">Paid<?= $order['mpesa_ref'] ? ' · ref ' . e($order['mpesa_ref']) : '' ?></td><td class="r"><?= money((float)$order['paid']) ?></td></tr>
      </tbody></table></div>
      <h2>Messages</h2>
      <?php $rs = $pdo->prepare('SELECT * FROM replies WHERE order_id = ? ORDER BY id'); $rs->execute([$order['id']]);
        foreach ($rs->fetchAll() as $r): ?>
        <div class="reply <?= $r['ok'] ? '' : 'failed' ?>"><p class="reply-meta"><?= $r['ok'] ? 'Emailed' : 'Failed' ?> <?= e(date('j M, H:i', strtotime($r['created_at']))) ?> — <strong><?= e($r['subject']) ?></strong></p><p><?= nl2br(e($r['body'])) ?></p></div>
      <?php endforeach; ?>
      <?php if ($order['email']): ?>
      <details class="reply-box"><summary>Reply by email</summary>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="order_reply"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="back" value="shop.php?order=<?= (int)$order['id'] ?>">
          <label>Subject<input name="subject" required maxlength="200" value="Your order <?= e($order['number']) ?> — Sunbeam Innovation"></label>
          <label>Message<textarea name="body" rows="6" required><?= e("Hello " . strtok($order['name'], ' ') . ",\n\n\n\n" . company_signoff()) ?></textarea></label>
          <button class="btn small">Send email</button> <span class="muted small">Sent from <?= e(cfg('mail_from')) ?>; replies come back to that inbox.</span>
        </form>
      </details>
      <?php endif; ?>
      <p class="muted small">Placed <?= e(date('j M Y, H:i', strtotime($order['created_at']))) ?> · <a href="../order.php?t=<?= e($order['token']) ?>" target="_blank" rel="noopener">Customer's order page</a></p>
    </section>
    <aside class="doc-side">
      <section class="panel"><h2>Status</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="order_status"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="back" value="shop.php?order=<?= (int)$order['id'] ?>">
          <select name="status" data-autosubmit aria-label="Order status"><?php foreach ($orderStatuses as $k => $v): ?><option value="<?= $k ?>" <?= $order['status'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
        </form>
      </section>
      <?php if ($due > 0): ?>
      <section class="panel"><h2>Record a payment</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="order_paid"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="back" value="shop.php?order=<?= (int)$order['id'] ?>">
          <label>Amount (<?= e($cur) ?>)<input name="amount" inputmode="decimal" value="<?= e((string)$due) ?>"></label>
          <label>M-Pesa / bank reference<input name="reference" maxlength="40"></label>
          <button class="btn wide">Mark as paid</button>
        </form>
      </section>
      <?php endif; ?>
      <section class="panel"><h2>Invoice</h2>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="order_invoice"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><button class="btn ghost wide">Create invoice from this order</button></form>
        <p class="muted small">Makes a numbered invoice (with QR code) and a receipt for anything already paid.</p>
      </section>
    </aside>
  </div>

<?php else: ?>
  <div class="head"><h1>Shop &amp; orders</h1><a class="btn ghost" href="../shop.php" target="_blank" rel="noopener">View the shop</a></div>
  <nav class="filters"><a href="shop.php" <?= $tab === 'orders' ? 'aria-current="true"' : '' ?>>Orders</a><a href="shop.php?tab=products" <?= $tab === 'products' ? 'aria-current="true"' : '' ?>>Products</a></nav>

  <?php if ($tab === 'orders'): $orders = $pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 300')->fetchAll(); ?>
    <?php if (!$orders): ?><p class="empty">No orders yet. Orders from the online shop appear here and are emailed to <?= e(setting('notify_email')) ?>.</p><?php else: ?>
    <div class="table-wrap"><table class="doc-list">
      <thead><tr><th>Order</th><th>Customer</th><th class="r">Total (<?= e($cur) ?>)</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead>
      <tbody><?php foreach ($orders as $o): ?>
        <tr><td><a href="?order=<?= (int)$o['id'] ?>"><strong><?= e($o['number']) ?></strong></a></td><td><?= e($o['name']) ?><br><small class="muted"><?= e($o['phone']) ?></small></td>
          <td class="r"><?= money((float)$o['total']) ?></td>
          <td><span class="st st-<?= $o['pay_status'] === 'paid' ? 'paid' : ($o['pay_status'] === 'partly' ? 'partly' : 'draft') ?>"><?= $o['pay_status'] === 'paid' ? 'Paid' : ($o['pay_status'] === 'partly' ? 'Part paid' : 'Unpaid') ?></span></td>
          <td><?= e($orderStatuses[$o['status']] ?? $o['status']) ?></td><td><?= e(date('j M Y', strtotime($o['created_at']))) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>

  <?php else: $products = $pdo->query('SELECT * FROM products ORDER BY sort_order, id')->fetchAll(); ?>
    <details class="panel add-new" <?= !$products ? 'open' : '' ?>><summary><strong>+ Add a product</strong></summary><?php product_form($cats); ?></details>
    <div class="ed-list">
    <?php foreach ($products as $p): ?>
      <article class="item <?= $p['visible'] ? '' : 'hidden-item' ?>">
        <div class="item-thumb"><img src="<?= e(media_url($p['image'], '../')) ?>" alt="" loading="lazy"></div>
        <div class="item-main">
          <h2><?= e($p['name']) ?> <?php if ($p['featured']): ?><span class="st st-accepted">Home page</span><?php endif; ?><?php if (!$p['visible']): ?><span class="st st-cancelled">Hidden</span><?php endif; ?></h2>
          <p class="muted"><?= e($cur) ?> <?= money((float)$p['price']) ?> · <?= e($p['category'] ?: 'No category') ?> · stock <?= (int)$p['stock'] ?></p>
          <div class="item-actions">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="product_toggle"><input type="hidden" name="field" value="featured"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="back" value="shop.php?tab=products"><button class="link"><?= $p['featured'] ? 'Remove from home page' : 'Show on home page' ?></button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="product_toggle"><input type="hidden" name="field" value="visible"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="back" value="shop.php?tab=products"><button class="link"><?= $p['visible'] ? 'Hide' : 'Show' ?></button></form>
            <form method="post" data-confirm="Delete this product?"><?= csrf_field() ?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="back" value="shop.php?tab=products"><button class="link danger">Delete</button></form>
          </div>
          <details class="edit"><summary>Edit</summary><?php product_form($cats, $p); ?></details>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</main>
<script src="../assets/js/admin.js"></script>
</body>
</html>
