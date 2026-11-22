<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/focus.php';

$slug = (string)($_GET['s'] ?? '');
$sol = null; $others = $products = $projects = $photos = [];
if (db_ready() && preg_match('/^[a-z0-9-]{1,160}$/', $slug)) {
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT * FROM cards WHERE kind = 'solution' AND slug = ? AND visible = 1");
        $st->execute([$slug]);
        $sol = $st->fetch() ?: null;
        if ($sol) {
            $st = $pdo->prepare("SELECT * FROM cards WHERE kind = 'solution' AND visible = 1 AND id <> ? ORDER BY featured DESC, sort_order LIMIT 4");
            $st->execute([$sol['id']]);
            $others = $st->fetchAll();
            $st = $pdo->prepare('SELECT * FROM products WHERE visible = 1 AND category = ? ORDER BY featured DESC, sort_order LIMIT 8');
            $st->execute([$sol['title']]);
            $products = $st->fetchAll();
            // Solar hybrid is where the delivered projects and site photos belong
            if ($slug === 'solar-hybrid') {
                $projects = $pdo->query("SELECT * FROM cards WHERE kind = 'project' AND visible = 1 ORDER BY sort_order, id")->fetchAll();
                $photos = $pdo->query("SELECT * FROM library WHERE in_gallery = 1 AND kind = 'image' ORDER BY sort_order, id DESC LIMIT 8")->fetchAll();
            }
        }
    } catch (Throwable $e) {
        error_log('solution: ' . $e->getMessage());
    }
}
// Built-in page if the database is empty or unreachable
if (!$sol) foreach (focus_cards() as $c) if ($c['slug'] === $slug) { $sol = $c; $others = array_values(array_filter(focus_cards(), fn($x) => $x['slug'] !== $slug)); }
if (!$sol) { http_response_code(404); }
page_head($sol['title'] ?? 'Not found', $sol['summary'] ?? '');
site_header();
$waText = 'Hello Sunbeam, I am interested in ' . ($sol['title'] ?? '');
?>
<main>
<?php if (!$sol): ?>
  <section class="section"><div class="container narrow center"><h1>Page not found</h1><p><a class="btn btn-primary" href="./#solutions">See what we do</a></p></div></section>
<?php else: ?>
  <section class="sol-hero">
    <img src="<?= e(media_url($sol['image'])) ?>" alt="" fetchpriority="high">
    <div class="slide-shade"></div>
    <div class="container sol-hero-in">
      <a href="./#solutions" class="back">← Our specializations</a>
      <?php if ($sol['tag']): ?><span class="eyebrow light"><?= e($sol['tag']) ?></span><?php endif; ?>
      <h1><?= e($sol['title']) ?></h1>
      <p><?= e($sol['summary']) ?></p>
      <div class="hero-ctas">
        <a class="btn btn-primary btn-lg" href="./?for=<?= e($sol['slug']) ?>#quote">Get a free quote</a>
        <a class="btn btn-ghost btn-lg" href="<?= e(wa_link(wa_number(), $waText)) ?>" target="_blank" rel="noopener">WhatsApp us</a>
      </div>
    </div>
  </section>

  <section class="section">
    <div class="container sol-layout">
      <article class="prose sol-body"><?= rich_text((string)($sol['body'] ?: $sol['summary'])) ?></article>
      <aside class="sol-aside">
        <div class="sol-box">
          <h3>Start your project</h3>
          <ol class="steps">
            <li><b>Site visit</b><span>We measure, listen and check your site.</span></li>
            <li><b>Design &amp; quote</b><span>A clear design with a fixed, itemised price.</span></li>
            <li><b>Build</b><span>Our own team installs and commissions.</span></li>
            <li><b>Support</b><span>Maintenance and fast call-outs for years.</span></li>
          </ol>
          <a class="btn btn-primary btn-block" href="./?for=<?= e($sol['slug']) ?>#quote">Request a site visit</a>
          <p class="muted small">WhatsApp: <a href="<?= e(wa_link(wa_number(), $waText)) ?>" target="_blank" rel="noopener"><?= e(wa_number()) ?></a><br>
            Call (Kenya): <a href="<?= e(tel_link(setting('phone_kenya'))) ?>"><?= e(setting('phone_kenya')) ?></a></p>
        </div>
      </aside>
    </div>
  </section>

  <?php if (!empty($sol['video'])): ?>
  <section class="section alt">
    <div class="container narrow"><div class="sec-head"><span class="eyebrow">See it in action</span><h2><?= e($sol['title']) ?></h2></div>
      <video class="sol-video" src="<?= e(media_url($sol['video'])) ?>" controls playsinline preload="metadata"></video>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($projects): ?>
  <section class="section dark">
    <div class="container">
      <div class="sec-head"><span class="eyebrow">Delivered by Sunbeam</span><h2>Solar hybrid projects</h2></div>
      <div class="grid projects">
        <?php foreach ($projects as $p): ?>
        <article class="project">
          <img src="<?= e(media_url($p['image'])) ?>" alt="" loading="lazy">
          <div class="project-body">
            <?php if ($p['tag']): ?><span class="tag"><?= e($p['tag']) ?></span><?php endif; ?>
            <h3><?= e($p['title']) ?></h3>
            <p><?= e($p['summary']) ?></p>
            <?php if ($p['meta']): ?><p class="meta"><?= e($p['meta']) ?></p><?php endif; ?>
          </div>
        </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($photos): ?>
  <section class="section">
    <div class="container"><div class="sec-head"><span class="eyebrow">On site</span><h2>From our installations</h2></div>
      <div class="gallery"><?php foreach ($photos as $g): ?><figure><img src="<?= e(media_url($g['path'])) ?>" alt="<?= e($g['title']) ?>" loading="lazy"></figure><?php endforeach; ?></div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($products): ?>
  <section class="section alt">
    <div class="container"><div class="sec-head"><span class="eyebrow">Products</span><h2>Our <?= e($sol['title']) ?> range</h2></div>
      <div class="grid shop-grid"><?php foreach ($products as $p) product_card($p); ?></div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($others): ?>
  <section class="section">
    <div class="container"><div class="sec-head"><span class="eyebrow">Also from Sunbeam</span><h2>Our other specializations</h2></div>
      <div class="grid cards small"><?php foreach ($others as $x): ?>
        <a class="card sol" href="solution.php?s=<?= e($x['slug']) ?>"><div class="card-img"><img src="<?= e(media_url($x['image'])) ?>" alt="" loading="lazy"></div><div class="card-body"><h3><?= e($x['title']) ?></h3><p><?= e($x['summary']) ?></p><span class="more">Learn more <?= icon('arrow') ?></span></div></a>
      <?php endforeach; ?></div>
    </div>
  </section>
  <?php endif; ?>
<?php endif; ?>
</main>
<?php site_footer(); ?>
