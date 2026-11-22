<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$pdo = db_ready() ? db() : null;
$one = null; $products = []; $cats = [];
$cat = mb_substr((string)($_GET['c'] ?? ''), 0, 120);
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
if ($pdo) {
    if (isset($_GET['id'])) {
        $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND visible = 1');
        $st->execute([(int)$_GET['id']]);
        $one = $st->fetch() ?: null;
        if (!$one) http_response_code(404);
    } else {
        $sql = 'SELECT * FROM products WHERE visible = 1';
        $args = [];
        if ($cat !== '') { $sql .= ' AND category = ?'; $args[] = $cat; }
        if ($q !== '') { $sql .= ' AND (name LIKE ? OR summary LIKE ?)'; array_push($args, "%$q%", "%$q%"); }
        $st = $pdo->prepare($sql . ' ORDER BY featured DESC, sort_order, id');
        $st->execute($args);
        $products = $st->fetchAll();
        $cats = $pdo->query("SELECT DISTINCT category FROM products WHERE visible = 1 AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
    }
}
page_head($one['name'] ?? 'Online Shop', $one['summary'] ?? 'Order solar panels, inverters, batteries, water heaters, EV chargers and floodlights online and pay with M-Pesa.');
site_header();
$cur = setting('currency', 'KES');
?>
<main>
<?php if ($one): ?>
  <section class="section">
    <div class="container detail">
      <div class="detail-img"><img src="<?= e(media_url($one['image'])) ?>" alt=""></div>
      <div>
        <a href="shop.php" class="back dark">← Back to shop</a>
        <span class="cat"><?= e($one['category']) ?></span>
        <h1 class="h-prod"><?= e($one['name']) ?></h1>
        <p class="price big"><?= e($cur) ?> <?= number_format((float)$one['price']) ?><?php if ($one['old_price'] && (float)$one['old_price'] > (float)$one['price']): ?> <s><?= number_format((float)$one['old_price']) ?></s><?php endif; ?></p>
        <p class="stock <?= (int)$one['stock'] > 0 ? 'in' : 'out' ?>"><?= (int)$one['stock'] > 0 ? 'In stock' : 'Out of stock: contact us for lead time' ?><?= $one['sku'] ? ' · SKU ' . e($one['sku']) : '' ?></p>
        <div class="prose"><?= rich_text((string)($one['description'] ?: $one['summary'])) ?></div>
        <div class="qty-row">
          <?php if ((int)$one['stock'] > 0): ?>
          <label class="sr" for="qty">Quantity</label><input type="number" min="1" max="<?= (int)$one['stock'] ?>" value="1" id="qty">
          <button class="btn btn-primary" type="button" data-add="<?= (int)$one['id'] ?>" data-name="<?= e($one['name']) ?>" data-price="<?= e((string)(float)$one['price']) ?>" data-img="<?= e(media_url($one['image'])) ?>" data-qty-from="#qty">Add to cart</button>
          <?php endif; ?>
          <a class="btn btn-outline" href="./#quote">Need installation? Get a quote</a>
        </div>
      </div>
    </div>
  </section>
<?php elseif (isset($_GET['id'])): ?>
  <section class="section"><div class="container narrow center"><h1>Product not found</h1><p><a class="btn btn-primary" href="shop.php">Back to the shop</a></p></div></section>
<?php else: ?>
  <section class="page-hero"><div class="container"><h1>Online Shop</h1><p>Genuine, warranty-backed solar and clean-energy equipment. Order online and pay with M-Pesa.</p></div></section>
  <section class="section">
    <div class="container">
      <form class="shop-filter" method="get">
        <label class="sr" for="sq">Search</label><input id="sq" name="q" value="<?= e($q) ?>" placeholder="Search products…">
        <label class="sr" for="sc">Category</label>
        <select id="sc" name="c" data-autosubmit><option value="">All categories</option><?php foreach ($cats as $c): ?><option <?= $c === $cat ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline btn-sm">Search</button>
      </form>
      <?php if (!$products): ?><p class="empty">No products found.</p><?php endif; ?>
      <div class="grid shop-grid"><?php foreach ($products as $p) product_card($p); ?></div>
    </div>
  </section>
<?php endif; ?>
</main>
<?php site_footer(); ?>
