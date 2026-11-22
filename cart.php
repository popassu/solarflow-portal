<?php
/* Cart (kept in the browser) and checkout. Prices are always re-read from the database. */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/includes/schema.php';
    require __DIR__ . '/includes/billing.php';
    $f = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $cart = json_decode((string)($_POST['cart'] ?? '[]'), true);
    if (!csrf_ok()) {
        $error = 'The page timed out. Please try again.';
    } elseif (($_POST['website'] ?? '') !== '') {
        redirect('./');
    } elseif ($f('name', 120) === '' || !preg_match('/^[+0-9 ()-]{9,20}$/', $f('phone', 40))) {
        $error = 'Please give your name and a phone number (for example 0712 345 678).';
    } elseif (!is_array($cart) || !$cart) {
        $error = 'Your cart is empty.';
    } elseif ($f('email', 160) !== '' && !filter_var($f('email', 160), FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address does not look right.';
    } else {
        $pdo = db();
        $_SESSION['orders'] = array_values(array_filter($_SESSION['orders'] ?? [], fn($t) => $t > time() - 3600));
        if (count($_SESSION['orders']) >= 5) {
            $error = 'You have placed several orders in the last hour. Please WhatsApp us on ' . wa_number() . '.';
        } else {
            $items = [];
            $total = 0.0;
            $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND visible = 1');
            foreach (array_slice($cart, 0, 40) as $c) {
                $st->execute([(int)($c['id'] ?? 0)]);
                $p = $st->fetch();
                if (!$p) continue;
                $qty = max(1, min(999, (int)($c['qty'] ?? 1)));
                if ((int)$p['stock'] > 0) $qty = min($qty, (int)$p['stock']);
                $line = round((float)$p['price'] * $qty, 2);
                $items[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'qty' => $qty, 'price' => (float)$p['price'], 'total' => $line];
                $total += $line;
            }
            if (!$items) {
                $error = 'The items in your cart are no longer available.';
            } else {
                $tok = bin2hex(random_bytes(24));
                $num = order_next_number($pdo);
                $pdo->prepare('INSERT INTO orders (number, token, name, phone, email, address, notes, items, total, paid, status, pay_status, ip, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?,?)')
                    ->execute([$num, $tok, $f('name', 120), $f('phone', 40), $f('email', 160) ?: null, $f('address', 400) ?: null, $f('notes', 2000) ?: null,
                               json_encode($items), round($total, 2), 'new', 'unpaid', client_ip(), now(), now()]);
                $oid = (int)$pdo->lastInsertId();
                $_SESSION['orders'][] = time();
                $cur = setting('currency', 'KES');
                $lines = implode("\n", array_map(fn($i) => "- {$i['qty']} x {$i['name']} @ $cur " . money($i['price']) . " = $cur " . money($i['total']), $items));
                pp_mail(setting('notify_email'), "New shop order $num — $cur " . money($total),
                    "New online order $num\n\n{$f('name', 120)}\n{$f('phone', 40)}\n{$f('email', 160)}\n{$f('address', 400)}\n\n$lines\n\nTotal: $cur " . money($total)
                    . "\n\nNotes: {$f('notes', 2000)}\n\n" . site_url('admin/shop.php?order=' . $oid) . "\n", $f('email', 160) ?: null, $e1);
                if ($f('email', 160) !== '') {
                    pp_mail($f('email', 160), "Your order $num — " . setting('company_name'),
                        "Dear " . (strtok($f('name', 120), ' ') ?: 'Customer') . ",\n\nThank you for your order.\n\n$lines\n\nTotal: $cur " . money($total)
                        . "\n\nPay and track your order here: " . site_url('order.php?t=' . $tok) . "\n\n" . company_signoff(), setting('email'), $e2);
                }
                redirect('order.php?t=' . $tok . '&new=1');
            }
        }
    }
}
page_head('Your cart');
site_header();
?>
<main>
<section class="page-hero"><div class="container"><h1>Your cart</h1></div></section>
<section class="section">
  <div class="container cart-layout" data-cart-page>
    <div>
      <?php if ($error): ?><p class="form-note err"><?= e($error) ?></p><?php endif; ?>
      <div class="table-wrap"><table class="tbl"><thead><tr><th>Product</th><th class="r">Price</th><th>Qty</th><th class="r">Total</th><th></th></tr></thead><tbody data-cart-rows></tbody></table></div>
      <p class="empty" data-cart-empty hidden>Your cart is empty. <a href="shop.php">Continue shopping</a></p>
      <p class="cart-total">Total: <strong data-cart-total>–</strong></p>
      <p class="muted small">Prices are confirmed when you place the order. Installation is quoted separately: <a href="./#quote">ask for a quote</a>.</p>
    </div>
    <form class="card-form" method="post" action="cart.php" data-checkout>
      <h2>Delivery &amp; payment</h2>
      <?= csrf_field() ?><input type="hidden" name="cart" data-cart-json>
      <div class="hp" aria-hidden="true"><label>Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
      <label>Full name<input name="name" required maxlength="120" autocomplete="name" value="<?= e((string)($_POST['name'] ?? '')) ?>"></label>
      <label>M-Pesa phone number<input name="phone" type="tel" required maxlength="40" autocomplete="tel" placeholder="07xx xxx xxx" value="<?= e((string)($_POST['phone'] ?? '')) ?>"></label>
      <label>Email <span class="opt">(for your receipt)</span><input name="email" type="email" maxlength="160" autocomplete="email" value="<?= e((string)($_POST['email'] ?? '')) ?>"></label>
      <label>Delivery address<textarea name="address" rows="2" maxlength="400"><?= e((string)($_POST['address'] ?? '')) ?></textarea></label>
      <label>Notes <span class="opt">(optional)</span><textarea name="notes" rows="2" maxlength="2000" placeholder="Need installation? Tell us here."><?= e((string)($_POST['notes'] ?? '')) ?></textarea></label>
      <button class="btn btn-primary btn-lg btn-block">Place order &amp; pay</button>
      <p class="form-small">You pay on the next page with M-Pesa.</p>
    </form>
  </div>
</section>
</main>
<?php site_footer(); ?>
