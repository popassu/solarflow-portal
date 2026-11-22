<?php
/* Shared header and footer for public pages. */
require_once __DIR__ . '/focus.php';

function icon(string $name): string
{
    static $p = [
        'wa'    => '<path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.1c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.5-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2.1 1-2.4c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .5l-.3.5-.4.4c-.1.2-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.3 2.4 1.5.3.1.5.1.6-.1l.9-1c.2-.3.4-.2.6-.1l2 1c.3.1.5.2.5.3.1.1.1.6-.1 1.2z" fill="currentColor" stroke="none"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'mail'  => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
        'pin'   => '<path d="M12 22s7-6 7-12a7 7 0 0 0-14 0c0 6 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
        'cart'  => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.5 12h11.5l2-8H6"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'menu'  => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'sales' => '<path d="M3 21l3.5-1 11-11-2.5-2.5-11 11z"/><path d="M14 5l2.5 2.5M15 3l6 6"/>',
        'installation' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/>',
        'maintenance'  => '<path d="M12 2 4 5v6c0 5 3.4 9.3 8 11 4.6-1.7 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>',
        'sun'   => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? $p['sun']) . '</svg>';
}

function page_head(string $title, string $desc = ''): void
{
    $full = $title === brand() ? $title : $title . ' | ' . brand();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($full) ?></title>
<meta name="description" content="<?= e($desc ?: setting('tagline')) ?>">
<meta name="theme-color" content="#1a1a2e">
<link rel="icon" href="favicon.ico" sizes="any"><link rel="icon" href="assets/img/favicon-32.png" type="image/png" sizes="32x32"><link rel="apple-touch-icon" href="assets/img/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&display=swap">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php }

function site_header(): void
{
    $links = ['./#about' => 'About'];
    try {
        if (db_ready()) foreach (db()->query("SELECT slug, title, meta FROM cards WHERE kind = 'solution' AND visible = 1 AND featured = 1 ORDER BY sort_order, id LIMIT 4") as $c)
            $links['solution.php?s=' . rawurlencode($c['slug'])] = e($c['meta'] ?: $c['title']);
    } catch (Throwable $e) { error_log('nav: ' . $e->getMessage()); }
    if (count($links) === 1) foreach (focus_cards() as $c) $links['solution.php?s=' . $c['slug']] = e($c['meta']);
    $links += ['./#projects' => 'Projects', 'shop.php' => 'Shop', './#contact' => 'Contact'];
    ?>
<header class="nav">
  <div class="container nav-in">
    <a class="brand" href="./"><img src="assets/img/icon-192.png" alt="" width="44" height="44"><span><?= e(brand()) ?></span></a>
    <button class="menu-btn" aria-expanded="false" aria-controls="nav" aria-label="Menu"><?= icon('menu') ?></button>
    <nav id="nav" class="links">
      <?php foreach ($links as $href => $label): ?><a href="<?= $href ?>"><?= $label ?></a><?php endforeach; ?>
      <a href="cart.php" class="cart-link" aria-label="Cart"><?= icon('cart') ?><span class="cart-count" data-cart-count hidden>0</span></a>
      <a href="./#quote" class="btn btn-primary btn-sm">Get a Quote</a>
    </nav>
  </div>
</header>
<?php }

function site_footer(): void
{
    $sol = [];
    if (db_ready()) {
        try { $sol = db()->query("SELECT title, slug FROM cards WHERE kind='solution' AND visible=1 ORDER BY featured DESC, sort_order, id LIMIT 9")->fetchAll(); } catch (Throwable $e) {}
    }
    if (!$sol) $sol = focus_cards();
    ?>
<footer class="foot" id="contact">
  <div class="container foot-grid">
    <div>
      <a class="brand light" href="./"><img src="assets/img/admin-icon-192.png" alt="" width="44" height="44"><span><?= e(brand()) ?></span></a>
      <p class="muted-light"><?= e(setting('tagline')) ?>. Solar hybrid power, solar cooling and structural works for homes, businesses and institutions across Kenya, Somalia and East Africa.</p>
      <a class="btn btn-primary btn-sm" href="./#quote">Get a Free Quote</a>
    </div>
    <div>
      <h4>Contact</h4>
      <ul class="contact-list">
        <li><?= icon('wa') ?><a href="<?= e(wa_link(wa_number(), 'Hello Sunbeam')) ?>" target="_blank" rel="noopener"><span>WhatsApp</span><?= e(wa_number()) ?></a></li>
        <li><?= icon('phone') ?><a href="<?= e(tel_link(setting('phone_kenya'))) ?>"><span>Call (Kenya)</span><?= e(setting('phone_kenya')) ?></a></li>
        <li><?= icon('mail') ?><a href="mailto:<?= e(setting('email')) ?>"><span>Email</span><?= e(setting('email')) ?></a></li>
        <li><?= icon('pin') ?><span><span>Office</span><?= e(setting('address')) ?></span></li>
      </ul>
    </div>
    <div>
      <h4>Our specializations</h4>
      <ul class="plain">
        <?php foreach ($sol as $s): ?><li><a href="solution.php?s=<?= e($s['slug']) ?>"><?= e($s['title']) ?></a></li><?php endforeach; ?>
      </ul>
    </div>
    <div>
      <h4>Quick links</h4>
      <ul class="plain">
        <li><a href="shop.php">Online shop</a></li>
        <li><a href="./#services">Design, build &amp; maintain</a></li>
        <li><a href="verify">Verify an invoice</a></li>
        <li><a href="admin/">Staff portal</a></li>
      </ul>
    </div>
  </div>
  <div class="container foot-bottom">© <?= date('Y') ?> <?= e(setting('company_name')) ?>. All rights reserved.</div>
</footer>
<a class="wa-float" href="<?= e(wa_link(wa_number(), 'Hello Sunbeam, I would like to enquire about ')) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><?= icon('wa') ?></a>
<script src="assets/js/main.js"></script>
</body>
</html>
<?php }

/** Turn admin-entered text into paragraphs and bullet lists (lines starting with "-"). */
function rich_text(string $text, string $pClass = ''): string
{
    $out = '';
    $pc = $pClass !== '' ? ' class="' . e($pClass) . '"' : '';
    foreach (preg_split("/\R{2,}/", trim($text)) as $block) {
        $lines = preg_split("/\R/", $block);
        if (preg_match('/^##\s+(.+)$/', $lines[0], $m)) {
            $out .= '<h2 class="rt-h">' . e(trim($m[1])) . '</h2>';
            array_shift($lines);
            if (!$lines) continue;
        }
        // Group consecutive "-" lines into a ticked list and other lines into a paragraph
        $runs = [];
        foreach ($lines as $l) {
            $isLi = (bool)preg_match('/^\s*-\s?/', $l);
            if ($runs && end($runs)[0] === $isLi) $runs[count($runs) - 1][1][] = $l; else $runs[] = [$isLi, [$l]];
        }
        foreach ($runs as [$isLi, $ls]) {
            $out .= $isLi
                ? '<ul class="ticks">' . implode('', array_map(fn($l) => '<li>' . icon('check') . e(preg_replace('/^\s*-\s?/', '', $l)) . '</li>', $ls)) . '</ul>'
                : '<p' . $pc . '>' . implode('<br>', array_map('e', $ls)) . '</p>';
        }
    }
    return $out;
}

function product_card(array $p): void
{ ?>
  <article class="card product">
    <a class="card-img" href="shop.php?id=<?= (int)$p['id'] ?>"><img src="<?= e(media_url($p['image'])) ?>" alt="" loading="lazy">
      <?php if ($p['old_price'] && (float)$p['old_price'] > (float)$p['price']): ?><span class="badge">Offer</span><?php endif; ?></a>
    <div class="card-body">
      <span class="cat"><?= e($p['category']) ?></span>
      <h3><a href="shop.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></h3>
      <div class="price-row">
        <span class="price"><?= e(setting('currency', 'KES')) ?> <?= number_format((float)$p['price']) ?>
          <?php if ($p['old_price'] && (float)$p['old_price'] > (float)$p['price']): ?><s><?= number_format((float)$p['old_price']) ?></s><?php endif; ?></span>
        <?php if ((int)$p['stock'] > 0): ?>
          <button class="btn btn-primary btn-sm" type="button" data-add="<?= (int)$p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= e((string)(float)$p['price']) ?>" data-img="<?= e(media_url($p['image'])) ?>">Add to cart</button>
        <?php else: ?><span class="muted small">Out of stock</span><?php endif; ?>
      </div>
    </div>
  </article>
<?php }

/** Front-page sections the webmaster can show or hide (Web management → Home page). */
/** Highlight figures: one per line, "figure | small unit | label". Blank or zero figures are never shown. */
function home_stats(): array
{
    $out = [];
    foreach (preg_split('/\R/', setting('home_stats')) as $line) {
        $p = array_map('trim', explode('|', $line));
        if (($p[0] ?? '') === '' || preg_match('/^0+([.,]0+)?$/', $p[0])) continue;
        $out[] = ['big' => $p[0], 'small' => $p[1] ?? '', 'label' => $p[2] ?? ''];
    }
    return array_slice($out, 0, 6);
}

function home_sections(): array
{
    return ['hero' => 'Hero slides (photos / videos)', 'about' => 'About us, mission and vision', 'stats' => 'Highlight figures (next to About us)', 'solutions' => 'Products & solutions',
            'services' => 'Services', 'projects' => 'Projects', 'gallery' => 'Photo gallery (from the library)', 'shop' => 'Shop: featured products',
            'why' => 'Why choose us', 'quote' => 'Get a quote form and contacts'];
}

function home_on(string $key): bool
{
    return !in_array($key, array_filter(explode(',', setting('home_hidden'))), true);
}
