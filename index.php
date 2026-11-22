<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/focus.php';

$slides = $solutions = $services = $projects = $products = $gallery = [];
if (db_ready()) {
    $pdo = db();
    try {
        $slides = $pdo->query('SELECT * FROM slides WHERE visible = 1 ORDER BY sort_order, id')->fetchAll();
        $cards = $pdo->query('SELECT * FROM cards WHERE visible = 1 ORDER BY featured DESC, sort_order, id')->fetchAll();
        foreach ($cards as $c) {
            if ($c['kind'] === 'solution') $solutions[] = $c;
            elseif ($c['kind'] === 'service') $services[] = $c;
            elseif ($c['kind'] === 'project') $projects[] = $c;
        }
        $products = $pdo->query('SELECT * FROM products WHERE visible = 1 AND featured = 1 ORDER BY sort_order, id LIMIT 4')->fetchAll();
        if (home_on('gallery')) $gallery = $pdo->query("SELECT * FROM library WHERE in_gallery = 1 AND kind = 'image' ORDER BY sort_order, id DESC LIMIT 12")->fetchAll();
    } catch (Throwable $e) {
        error_log('home: ' . $e->getMessage());
    }
}
// Never show an empty section: fall back to the built-in content
if (!$solutions) $solutions = focus_cards();
if (!$services) $services = focus_service_cards();
if (!$slides) $slides = focus_slides();

$_SESSION['quote_started'] = time();
$qStatus = $_SESSION['quote_status'] ?? '';
$old = $_SESSION['quote_old'] ?? [];
unset($_SESSION['quote_status'], $_SESSION['quote_old']);
$qMessages = [
    'sent'    => ['ok',  'Thank you! Your request is in. Our team will contact you shortly.'],
    'invalid' => ['err', 'Please add your name and a phone number we can reach you on (for example 0712 345 678).'],
    'expired' => ['err', 'The form timed out. Check your details below and send it again.'],
    'limit'   => ['err', 'We have already received several requests from you this hour. WhatsApp ' . wa_number() . ' or call ' . setting('phone_kenya') . ' if it is urgent.'],
    'error'   => ['err', 'The request could not be sent. WhatsApp us on ' . wa_number() . ' or call ' . setting('phone_kenya') . ' and we will help straight away.'],
];
$o = fn(string $k) => e($old[$k] ?? '');
$preset = (string)($_GET['for'] ?? '');

page_head(brand(), "Solar hybrid systems, solar cold storage & AC, and structural works (rooftop canopies, carports, decorative metal) across Kenya, Somalia and East Africa.");
site_header();
?>
<main>
<?php if (home_on('hero')): ?>
<section class="hero" data-carousel aria-label="Highlights">
  <?php foreach ($slides as $i => $sl): ?>
  <div class="slide<?= $i === 0 ? ' active' : '' ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
    <?php if ($sl['file'] === ''): ?>
      <div class="slide-art"></div>
    <?php elseif ($sl['media'] === 'video'): ?>
      <video src="<?= e(media_url($sl['file'])) ?>" muted loop playsinline preload="<?= $i === 0 ? 'auto' : 'none' ?>" <?= $i === 0 ? 'autoplay' : '' ?>></video>
    <?php else: ?>
      <img src="<?= e(media_url($sl['file'])) ?>" alt="" <?= $i ? 'loading="lazy"' : 'fetchpriority="high"' ?>>
    <?php endif; ?>
    <div class="slide-shade"></div>
    <div class="container slide-text">
      <?php if ($sl['heading']): ?><<?= $i === 0 ? 'h1' : 'h2' ?> class="hero-title"><?= e($sl['heading']) ?></<?= $i === 0 ? 'h1' : 'h2' ?>><?php endif; ?>
      <?php if ($sl['subheading']): ?><p><?= e($sl['subheading']) ?></p><?php endif; ?>
      <div class="hero-ctas">
        <?php if ($sl['btn_text']): ?><a class="btn btn-primary btn-lg" href="<?= e($sl['btn_link'] ?: '#quote') ?>"><?= e($sl['btn_text']) ?></a><?php endif; ?>
        <a class="btn btn-ghost btn-lg" href="#solutions">Our specializations</a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (count($slides) > 1): ?>
  <button class="hero-arrow prev" type="button" aria-label="Previous slide">‹</button>
  <button class="hero-arrow next" type="button" aria-label="Next slide">›</button>
  <div class="dots"><?php foreach ($slides as $i => $sl): ?><button type="button" aria-label="Slide <?= $i + 1 ?>" class="<?= $i === 0 ? 'on' : '' ?>"></button><?php endforeach; ?></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if (home_on('about')): ?>
<section class="section" id="about">
  <div class="container about">
    <div>
      <span class="eyebrow">About us</span>
      <h2><?= e(setting('about_title')) ?></h2>
      <?= rich_text(setting('about_text'), 'lead') ?>
      <div class="mv">
        <?php if (setting('mission')): ?><div><h3>Our Mission</h3><p><?= e(setting('mission')) ?></p></div><?php endif; ?>
        <?php if (setting('vision')): ?><div><h3>Our Vision</h3><p><?= e(setting('vision')) ?></p></div><?php endif; ?>
      </div>
    </div>
    <?php if (home_on('stats') && ($stats = home_stats())): ?>
    <div class="about-stats">
      <?php foreach ($stats as $st): ?><div><b<?= mb_strlen($st['big']) > 5 ? ' class="word"' : '' ?>><?= e($st['big']) ?><?php if ($st['small'] !== ''): ?><small><?= e($st['small']) ?></small><?php endif; ?></b><span><?= e($st['label']) ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php if ($solutions && home_on('solutions')): ?>
<section class="section alt" id="solutions">
  <div class="container">
    <div class="sec-head"><span class="eyebrow">What we do</span><h2>Our Specializations</h2></div>
    <div class="grid cards fit">
      <?php foreach ($solutions as $s): ?>
      <a class="card sol<?= $s['featured'] ? ' featured' : '' ?>" href="solution.php?s=<?= e($s['slug']) ?>">
        <div class="card-img"><img src="<?= e(media_url($s['image'])) ?>" alt="" loading="lazy"><?php if ($s['tag']): ?><span class="badge"><?= e($s['tag']) ?></span><?php endif; ?></div>
        <div class="card-body">
          <h3><?= e($s['title']) ?></h3>
          <p><?= e($s['summary']) ?></p>
          <span class="more">Learn more <?= icon('arrow') ?></span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($services && home_on('services')): ?>
<section class="section" id="services">
  <div class="container">
    <div class="sec-head"><span class="eyebrow">Our services</span><h2>One team from design to support.</h2></div>
    <div class="grid services">
      <?php foreach ($services as $i => $s): ?>
      <article class="service">
        <div class="svc-img"><img src="<?= e(media_url($s['image'])) ?>" alt="" loading="lazy"><span class="svc-ic"><?= icon($s['slug']) ?></span></div>
        <div class="svc-body">
          <span class="svc-n"><?= str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) ?></span>
          <h3><?= e($s['title']) ?></h3>
          <p><?= e($s['summary']) ?></p>
          <a class="more" href="#quote" data-for="<?= e($s['title']) ?>">Talk to us <?= icon('arrow') ?></a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($projects && home_on('projects')): ?>
<section class="section dark" id="projects">
  <div class="container">
    <div class="sec-head scroller-head"><div><span class="eyebrow">Featured projects</span><h2>Our latest installations</h2></div>
      <div class="scroller-nav"><button type="button" class="sc-btn" data-sc-prev aria-label="Previous projects">‹</button><button type="button" class="sc-btn" data-sc-next aria-label="Next projects">›</button></div></div>
    <div class="projects-scroller" data-scroller tabindex="0" aria-label="Projects, scroll sideways">
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

<?php if ($products && home_on('shop')): ?>
<section class="section alt">
  <div class="container">
    <div class="sec-head row"><div><span class="eyebrow">Buy online</span><h2>From our shop</h2></div><a class="btn btn-outline" href="shop.php">View all products</a></div>
    <div class="grid shop-grid"><?php foreach ($products as $p) product_card($p); ?></div>
  </div>
</section>
<?php endif; ?>

<?php if ($gallery): ?>
<section class="section" id="gallery">
  <div class="container">
    <div class="sec-head"><span class="eyebrow">Gallery</span><h2>Our work in pictures</h2></div>
    <div class="gallery">
      <?php foreach ($gallery as $g): ?><figure><img src="<?= e(media_url($g['path'])) ?>" alt="<?= e((string)$g['title']) ?>" loading="lazy"><?php if ($g['title']): ?><figcaption><?= e($g['title']) ?></figcaption><?php endif; ?></figure><?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>
<?php if (home_on('why')): ?>
<section class="section why">
  <div class="container">
    <div class="sec-head"><span class="eyebrow">Why Sunbeam</span><h2>Why choose us?</h2></div>
    <div class="grid why-grid">
      <div><h3>Qualified engineers</h3><p>Electrical and structural engineers design every system and structure to its load, site and local wind conditions.</p></div>
      <div><h3>Dedicated structural team</h3><p>Our own fabricators and fitters CNC-cut, weld, finish and assemble canopies, rooftops and carports, with no subcontracting.</p></div>
      <div><h3>Certified installers &amp; technicians</h3><p>Trained crews install, test and commission solar, battery and cooling systems, then stay on for maintenance and support.</p></div>
      <div><h3>Trusted across East Africa</h3><p>From an 875 kWp industrial rooftop to all-solar homes, clients in Kenya, Somalia and the region trust Sunbeam to deliver.</p></div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (home_on('quote')): ?>
<section class="quote-sec" id="quote">
  <div class="container quote-grid">
    <div class="quote-info">
      <span class="eyebrow light">Let's build together</span>
      <h2>Get a Free Quote</h2>
      <p>Tell us what you need and our engineers will prepare a tailored quotation. You can also reach us directly on WhatsApp.</p>
      <a class="wa-big" href="<?= e(wa_link(wa_number(), 'Hello Sunbeam, I would like a quote for ')) ?>" target="_blank" rel="noopener"><?= icon('wa') ?><span><strong>WhatsApp</strong><span><?= e(wa_number()) ?></span></span></a>
      <a class="wa-big call" href="<?= e(tel_link(setting('phone_kenya'))) ?>"><?= icon('phone') ?><span><strong>Call (Kenya)</strong><span><?= e(setting('phone_kenya')) ?></span></span></a>
      <p class="quote-mail"><?= icon('mail') ?> <a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a></p>
    </div>

    <form class="card-form" method="post" action="quote.php" novalidate>
      <?php if (isset($qMessages[$qStatus])): ?><p class="form-note <?= $qMessages[$qStatus][0] ?>" role="status"><?= e($qMessages[$qStatus][1]) ?></p><?php endif; ?>
      <?= csrf_field() ?>
      <div class="hp" aria-hidden="true"><label>Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="two">
        <label>Full name<input name="name" required maxlength="120" autocomplete="name" value="<?= $o('name') ?>"></label>
        <label>Phone / WhatsApp<input name="phone" type="tel" required maxlength="40" autocomplete="tel" placeholder="07xx xxx xxx" value="<?= $o('phone') ?>"></label>
        <label>Email <span class="opt">(optional)</span><input name="email" type="email" maxlength="160" autocomplete="email" value="<?= $o('email') ?>"></label>
        <label>Location<input name="location" maxlength="160" placeholder="Town / county / country" value="<?= $o('location') ?>"></label>
      </div>
      <label>I'm interested in
        <select name="service" data-service>
          <option value="">Choose a specialization or service</option>
          <?php foreach (array_merge($solutions, $services) as $s): $v = $s['title']; ?>
          <option <?= (($old['service'] ?? '') === $v || $preset === $s['slug']) ? 'selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
          <option>Something else</option>
        </select>
      </label>
      <label>Tell us more <span class="opt">(optional)</span><textarea name="message" rows="4" maxlength="3000" placeholder="Type of building or rooftop, monthly power bill, what you need cooled, sizes…"><?= $o('message') ?></textarea></label>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Request my quote</button>
      <p class="form-small">Your request goes straight to our team. We use your details only to reply.</p>
    </form>
  </div>
</section>
<?php endif; ?>
</main>
<?php site_footer(); ?>
