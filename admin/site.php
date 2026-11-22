<?php
/* Website editor: hero slides (images or video), product & solution cards, services and projects. */
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/schema.php';
require_once __DIR__ . '/../includes/admin-ui.php';
require_once __DIR__ . '/../includes/layout.php';
header('X-Robots-Tag: noindex, nofollow');
require_admin();
require_perm('website');

$pdo = db();
pp_migrate($pdo);
$sections = ['home' => 'Home page sections', 'slides' => 'Hero slides', 'solution' => 'Products & solutions', 'service' => 'Services', 'project' => 'Projects', 'library' => 'Library (files & videos)'];
$sec = isset($sections[$_GET['s'] ?? '']) ? $_GET['s'] : 'home';
$back = 'site.php?s=' . $sec;
$slug = fn(string $t) => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($t)), '-') ?: 'item';
$f = fn(string $k, int $max = 400) => ($v = mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max)) === '' ? null : $v;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) { flash('The form expired. Try again.', 'err'); redirect($back); }
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    try {
        if ($sec === 'home') {
            $hide = array_values(array_diff(array_keys(home_sections()), (array)($_POST['show'] ?? [])));
            setting_save('home_hidden', implode(',', $hide));
            foreach (['home_stats' => 1000, 'about_title' => 160, 'about_text' => 3000, 'mission' => 800, 'vision' => 800, 'tagline' => 160] as $k => $max) {
                if (array_key_exists($k, $_POST)) setting_save($k, mb_substr(trim((string)$_POST[$k]), 0, $max));
            }
            audit($pdo, 'home_page_saved', $hide ? 'hidden: ' . implode(', ', $hide) : 'all sections shown');
            flash('Home page saved and published.');
        } elseif ($sec === 'library') {
            if ($action === 'upload') {
                $n = 0;
                $files = $_FILES['files'] ?? null;
                foreach ((array)($files['name'] ?? []) as $i => $name) {
                    if (($files['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
                    $_FILES['one'] = ['name' => $name, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                    $path = handle_upload('one', true, 2400);
                    $kind = preg_match('/\.mp4$/i', $path) ? 'video' : (preg_match('/\.pdf$/i', $path) ? 'file' : 'image');
                    $title = $f('title', 200) ?: pathinfo((string)$name, PATHINFO_FILENAME);
                    $pdo->prepare('INSERT INTO library (kind, title, path, mime, size, in_gallery, sort_order, uploaded_by, created_at) VALUES (?,?,?,?,?,?,100,?,?)')
                        ->execute([$kind, mb_substr($title, 0, 200), $path, $kind === 'video' ? 'video/mp4' : ($kind === 'file' ? 'application/pdf' : 'image/jpeg'),
                                   (int)@filesize(__DIR__ . '/../' . $path), $kind === 'image' && !empty($_POST['in_gallery']) ? 1 : 0, $_SESSION['admin_id'], now()]);
                    $n++;
                }
                if (!$n) throw new RuntimeException('Choose at least one file.');
                flash("$n file(s) added to the library.");
            } elseif ($action === 'edit') {
                $pdo->prepare('UPDATE library SET title = ?, in_gallery = ?, sort_order = ? WHERE id = ?')
                    ->execute([$f('title', 200), !empty($_POST['in_gallery']) ? 1 : 0, (int)($_POST['sort_order'] ?? 100), $id]);
                flash('Saved.');
            } elseif ($action === 'slide') {
                $st = $pdo->prepare("SELECT * FROM library WHERE id = ? AND kind IN ('image','video')"); $st->execute([$id]);
                if ($l = $st->fetch()) {
                    $pdo->prepare('INSERT INTO slides (heading, subheading, btn_text, btn_link, sort_order, visible, file, media, created_at) VALUES (?,?,?,?,?,1,?,?,?)')
                        ->execute([$l['title'], null, 'Get a Free Quote', '#quote', 100, $l['path'], $l['kind'], now()]);
                    flash('Added as a hero slide. Edit its headline under Hero slides.');
                }
            } elseif ($action === 'delete') {
                $st = $pdo->prepare('SELECT path FROM library WHERE id = ?'); $st->execute([$id]);
                $path = (string)$st->fetchColumn();
                $used = $pdo->prepare('SELECT (SELECT COUNT(*) FROM slides WHERE file = ?) + (SELECT COUNT(*) FROM cards WHERE image = ?) + (SELECT COUNT(*) FROM products WHERE image = ?)');
                $used->execute([$path, $path, $path]);
                if ((int)$used->fetchColumn()) throw new RuntimeException('This file is used on the website (slide, card or product). Change those first.');
                $pdo->prepare('DELETE FROM library WHERE id = ?')->execute([$id]);
                if (str_starts_with($path, PP_UPLOAD_URL . '/') && is_file(__DIR__ . '/../' . $path)) @unlink(__DIR__ . '/../' . $path);
                flash('File deleted.');
            }
        } elseif ($sec === 'slides') {
            if ($action === 'save') {
                $file = handle_upload('upload', true, 2400) ?? $f('library_file', 500) ?? $f('file_url', 500);
                if ($file && preg_match('/\.pdf$/i', $file)) throw new RuntimeException('A slide needs a picture or an MP4 video.');
                $media = $file && preg_match('/\.(mp4|webm)(\?|$)/i', $file) ? 'video' : 'image';
                $vals = [$f('heading', 160), $f('subheading', 300), $f('btn_text', 60), $f('btn_link', 255), (int)($_POST['sort_order'] ?? 100), !empty($_POST['visible']) ? 1 : 0];
                if ($id) {
                    $pdo->prepare('UPDATE slides SET heading=?, subheading=?, btn_text=?, btn_link=?, sort_order=?, visible=? WHERE id=?')->execute([...$vals, $id]);
                    if ($file) $pdo->prepare('UPDATE slides SET file=?, media=? WHERE id=?')->execute([$file, $media, $id]);
                } else {
                    if (!$file) throw new RuntimeException('Choose a picture or MP4 video for the new slide.');
                    $pdo->prepare('INSERT INTO slides (heading, subheading, btn_text, btn_link, sort_order, visible, file, media, created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([...$vals, $file, $media, now()]);
                }
                flash('Slide saved.');
            } elseif ($action === 'toggle') {
                $pdo->prepare('UPDATE slides SET visible = 1 - visible WHERE id = ?')->execute([$id]);
            } elseif ($action === 'delete') {
                $pdo->prepare('DELETE FROM slides WHERE id = ?')->execute([$id]);
                flash('Slide removed.');
            }
        } else {
            if ($action === 'save') {
                $title = $f('title', 160);
                if (!$title) throw new RuntimeException('Give it a title.');
                $img = handle_upload('upload', false, 1600) ?? $f('library_file', 500) ?? $f('image_url', 500);
                $vals = [$title, $f('summary', 400), $f('body', 8000), $f('tag', 60), $f('meta', 160), !empty($_POST['featured']) ? 1 : 0, !empty($_POST['visible']) ? 1 : 0, (int)($_POST['sort_order'] ?? 100)];
                if ($id) {
                    $pdo->prepare('UPDATE cards SET title=?, summary=?, body=?, tag=?, meta=?, featured=?, visible=?, sort_order=? WHERE id=? AND kind=?')->execute([...$vals, $id, $sec]);
                    if ($img) $pdo->prepare('UPDATE cards SET image=? WHERE id=?')->execute([$img, $id]);
                } else {
                    $s = $slug($title);
                    $chk = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE slug = ?');
                    $chk->execute([$s]);
                    if ((int)$chk->fetchColumn()) $s .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                    $pdo->prepare('INSERT INTO cards (title, summary, body, tag, meta, featured, visible, sort_order, kind, slug, image, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([...$vals, $sec, $s, $img, now()]);
                }
                if ($sec === 'solution') {
                    $vid = $f('video_file', 500) ?? $f('video_url', 500);
                    if ($vid && !preg_match('#^(https://|uploads/|assets/)#', $vid)) throw new RuntimeException('The video link must start with https://');
                    $cid = $id ?: (int)$pdo->lastInsertId();
                    if (!empty($_POST['video_remove'])) $pdo->prepare('UPDATE cards SET video = NULL WHERE id = ?')->execute([$cid]);
                    elseif ($vid) $pdo->prepare('UPDATE cards SET video = ? WHERE id = ?')->execute([$vid, $cid]);
                }
                flash($sections[$sec] . ': saved.');
            } elseif ($action === 'toggle' && in_array($_POST['field'] ?? '', ['featured', 'visible'], true)) {
                $col = $_POST['field'];
                $pdo->prepare("UPDATE cards SET $col = 1 - $col WHERE id = ?")->execute([$id]);
            } elseif ($action === 'delete') {
                $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]);
                flash('Removed.');
            }
        }
    } catch (Throwable $e) {
        flash(e($e->getMessage()), 'err');
    }
    redirect($back);
}

$library = $pdo->query('SELECT * FROM library ORDER BY id DESC')->fetchAll();
$rows = $sec === 'slides'
    ? $pdo->query('SELECT * FROM slides ORDER BY sort_order, id')->fetchAll()
    : (in_array($sec, ['home', 'library'], true) ? [] : (function () use ($pdo, $sec) { $st = $pdo->prepare('SELECT * FROM cards WHERE kind = ? ORDER BY featured DESC, sort_order, id'); $st->execute([$sec]); return $st->fetchAll(); })());
$fl = flash();
admin_head('Web management');
admin_bar('site', admin_new_leads($pdo), admin_new_orders($pdo));

function media_thumb(array $r, string $col): void
{
    $u = media_url($r[$col] ?? '', '../');
    if (($r['media'] ?? '') === 'video') echo '<video src="' . e($u) . '#t=1" muted playsinline preload="metadata"></video>';
    else echo '<img src="' . e($u) . '" alt="" loading="lazy">';
}

/** Pick a file already in the library instead of uploading again. */
function library_pick(array $lib, bool $video): void
{
    $opts = array_filter($lib, fn($l) => $l['kind'] === 'image' || ($video && $l['kind'] === 'video'));
    if (!$opts) return; ?>
  <label>…or choose from the library<select name="library_file"><option value="">(none)</option>
    <?php foreach ($opts as $l): ?><option value="<?= e($l['path']) ?>"><?= e(($l['kind'] === 'video' ? '🎬 ' : '') . ($l['title'] ?: basename($l['path']))) ?></option><?php endforeach; ?>
  </select></label>
<?php }

function item_form(string $sec, array $r = []): void
{
    global $library;
    $isSlide = $sec === 'slides';
    $v = fn(string $k) => e((string)($r[$k] ?? ''));
    ?>
  <form method="post" enctype="multipart/form-data" class="item-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0) ?>">
    <?php if ($isSlide): ?>
      <div class="grid2">
        <label>Headline<input name="heading" maxlength="160" value="<?= $v('heading') ?>"></label>
        <label>Button text<input name="btn_text" maxlength="60" value="<?= $v('btn_text') ?>" placeholder="Get a Free Quote"></label>
        <label>Sub-headline<input name="subheading" maxlength="300" value="<?= $v('subheading') ?>"></label>
        <label>Button link<input name="btn_link" maxlength="255" value="<?= $v('btn_link') ?>" placeholder="#quote, shop.php, solution.php?s=cold-storage"></label>
        <label>Picture or MP4 video<?= $r ? ' (leave empty to keep)' : '' ?><input type="file" name="upload" accept="image/jpeg,image/png,image/webp,video/mp4"></label>
        <?php library_pick($library, true); ?>
        <label>…or paste a link to one<input name="file_url" maxlength="500" placeholder="https://…"></label>
      </div>
    <?php else: ?>
      <div class="grid2">
        <label>Title<input name="title" maxlength="160" required value="<?= $v('title') ?>"></label>
        <label><?= $sec === 'project' ? 'Category (e.g. Commercial solar)' : 'Badge (e.g. New, Offer)' ?><input name="tag" maxlength="60" value="<?= $v('tag') ?>"></label>
        <label class="span-all">Short summary (shown on the card)<textarea name="summary" rows="2" maxlength="400"><?= $v('summary') ?></textarea></label>
        <?php if ($sec === 'solution'): ?>
          <label>Top menu label (featured items appear in the menu)<input name="meta" maxlength="160" value="<?= $v('meta') ?>" placeholder="Solar Hybrid"></label>
          <label class="span-all">Full description (detail page). A line starting with "## " is a heading; lines starting with "-" become a ticked list.<textarea name="body" rows="12" maxlength="8000"><?= $v('body') ?></textarea></label>
          <label>Demo video on the detail page (MP4 from the library)<select name="video_file"><option value="">(keep current<?= !empty($r['video']) ? ': ' . e(basename((string)$r['video'])) : ': none' ?>)</option>
            <?php foreach (array_filter($library, fn($l) => $l['kind'] === 'video') as $l): ?><option value="<?= e($l['path']) ?>"><?= e($l['title'] ?: basename($l['path'])) ?></option><?php endforeach; ?></select></label>
          <label>…or paste a video link (MP4)<input name="video_url" maxlength="500" placeholder="https://…"></label>
          <?php if (!empty($r['video'])): ?><label class="check"><input type="checkbox" name="video_remove" value="1"> Remove the video</label><?php endif; ?>
        <?php elseif ($sec === 'project'): ?>
          <label>Location · size<input name="meta" maxlength="160" value="<?= $v('meta') ?>" placeholder="Juja, Nairobi · 120 kWp"></label>
        <?php endif; ?>
        <label>Picture<?= $r ? ' (leave empty to keep)' : '' ?><input type="file" name="upload" accept="image/jpeg,image/png,image/webp"></label>
        <?php library_pick($library, false); ?>
        <label>…or paste an image link<input name="image_url" maxlength="500" placeholder="https://…"></label>
      </div>
      <?php if ($sec === 'solution'): ?><label class="check"><input type="checkbox" name="featured" value="1" <?= !empty($r['featured']) ? 'checked' : '' ?>> Featured (shown first, highlighted)</label><?php endif; ?>
    <?php endif; ?>
    <div class="row-inline">
      <label class="narrow">Order<input type="number" name="sort_order" value="<?= (int)($r['sort_order'] ?? 100) ?>"></label>
      <label class="check"><input type="checkbox" name="visible" value="1" <?= ($r['visible'] ?? 1) ? 'checked' : '' ?>> Show on the website</label>
      <button class="btn small"><?= $r ? 'Save changes' : 'Add' ?></button>
    </div>
  </form>
<?php }
?>
<main class="wrap">
  <?php admin_flash($fl); ?>
  <div class="head"><h1>Web management</h1><a class="btn ghost" href="../" target="_blank" rel="noopener">View the website</a></div>
  <nav class="filters" aria-label="Website sections">
    <?php foreach ($sections as $k => $label): ?><a href="?s=<?= $k ?>" <?= $sec === $k ? 'aria-current="true"' : '' ?>><?= $label ?></a><?php endforeach; ?>
  </nav>
  <p class="muted"><?= [
      'home'     => 'Choose which parts of the home page are published. Untick a section to hide it; nothing is deleted. Each card, slide and product can also be hidden on its own tab.',
      'library'  => 'Keep the photos, videos and PDF files used on the website here. Use any picture or video as a hero slide, and tick "Gallery" to show photos in the home page gallery.',
      'slides'   => 'The big rotating banner at the top of the home page. Use pictures or short MP4 videos (under a minute, muted). Lower order numbers show first.',
      'solution' => 'Cards in "Our Specializations". Featured cards also appear in the top menu. Feature a card to put it first, untick "Show" to hide it without deleting, or add new ones.',
      'service'  => 'Cards in "Our Services": design, installation and maintenance.',
      'project'  => 'Featured installations shown on the home page.',
  ][$sec] ?></p>

<?php if ($sec === 'home'): $S = settings(); ?>
  <form method="post" class="panel">
    <?= csrf_field() ?><input type="hidden" name="action" value="home">
    <h2>Sections on the home page</h2>
    <div class="perm-grid">
      <?php foreach (home_sections() as $k => $label): ?><label class="check"><input type="checkbox" name="show[]" value="<?= $k ?>" <?= home_on($k) ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?>
    </div>
    <h2>About us text</h2>
    <label>Tagline<input name="tagline" maxlength="160" value="<?= e($S['tagline']) ?>"></label>
    <label>About heading<input name="about_title" maxlength="160" value="<?= e($S['about_title']) ?>"></label>
    <label>About text<textarea name="about_text" rows="4"><?= e($S['about_text']) ?></textarea></label>
    <div class="grid2">
      <label>Mission<textarea name="mission" rows="4"><?= e($S['mission']) ?></textarea></label>
      <label>Vision<textarea name="vision" rows="4"><?= e($S['vision']) ?></textarea></label>
    </div>
    <h2>Highlight figures</h2>
    <label>One per line: <b>figure | small unit | label</b>. Up to 6. A line with a blank or 0 figure is not shown; empty the box to hide them all.<textarea name="home_stats" rows="5" placeholder="875 | kWp | Largest rooftop solar system delivered"><?= e($S['home_stats']) ?></textarea></label>
    <div class="save-bar"><button class="btn">Save and publish</button></div>
  </form>
<?php elseif ($sec === 'library'): ?>
  <form method="post" enctype="multipart/form-data" class="panel">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <h2>Upload to the library</h2>
    <div class="grid2">
      <label>Photos (JPG, PNG, WebP), MP4 videos or PDF files<input type="file" name="files[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,application/pdf"></label>
      <label>Title (optional, used for all files in this upload)<input name="title" maxlength="200"></label>
    </div>
    <label class="check"><input type="checkbox" name="in_gallery" value="1"> Show photos in the home page gallery</label>
    <button class="btn">Upload</button>
    <p class="muted small">Photos are resized and cleaned of hidden data automatically. Videos up to <?= (int)cfg('max_video_mb', 60) ?> MB, PDFs up to 20 MB.</p>
  </form>
  <div class="lib-grid">
  <?php foreach ($library as $l): $u = media_url($l['path'], '../'); ?>
    <article class="lib-item">
      <div class="lib-thumb"><?php if ($l['kind'] === 'video'): ?><video src="<?= e($u) ?>#t=1" muted playsinline preload="metadata"></video><?php elseif ($l['kind'] === 'file'): ?><span class="lib-pdf">PDF</span><?php else: ?><img src="<?= e($u) ?>" alt="" loading="lazy"><?php endif; ?></div>
      <p class="small"><strong><?= e($l['title'] ?: basename($l['path'])) ?></strong><br><span class="muted"><?= e(ucfirst($l['kind'])) ?> · <?= number_format($l['size'] / 1024) ?> KB<?= $l['in_gallery'] ? ' · in gallery' : '' ?></span></p>
      <p class="small"><a href="<?= e($u) ?>" target="_blank" rel="noopener">Open</a> · Link: <code class="lib-path"><?= e(site_url($l['path'])) ?></code></p>
      <?php if ($l['kind'] !== 'file'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="slide"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="link">Use as hero slide</button></form><?php endif; ?>
      <details class="edit"><summary>Edit</summary>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <label>Title<input name="title" maxlength="200" value="<?= e((string)$l['title']) ?>"></label>
          <?php if ($l['kind'] === 'image'): ?><label class="check"><input type="checkbox" name="in_gallery" value="1" <?= $l['in_gallery'] ? 'checked' : '' ?>> Gallery</label><?php endif; ?>
          <label class="narrow">Order<input type="number" name="sort_order" value="<?= (int)$l['sort_order'] ?>"></label>
          <button class="btn small">Save</button>
        </form>
        <form method="post" data-confirm="Delete this file permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="link danger">Delete</button></form>
      </details>
    </article>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <details class="panel add-new" <?= !$rows ? 'open' : '' ?>><summary><strong>+ Add <?= $sec === 'slides' ? 'a slide' : 'new' ?></strong></summary><?php item_form($sec); ?></details>

  <div class="ed-list">
  <?php foreach ($rows as $r): $file = $sec === 'slides' ? 'file' : 'image'; ?>
    <article class="item <?= ($r['visible'] ?? 1) ? '' : 'hidden-item' ?>">
      <div class="item-thumb"><?php media_thumb($r, $file); ?><?php if (str_starts_with((string)$r[$file], 'http')): ?><span class="tag">From old site</span><?php endif; ?></div>
      <div class="item-main">
        <h2><?= e($sec === 'slides' ? ($r['heading'] ?: '(no headline)') : $r['title']) ?>
          <?php if (!empty($r['featured'])): ?><span class="st st-accepted">Featured</span><?php endif; ?>
          <?php if (!($r['visible'] ?? 1)): ?><span class="st st-cancelled">Hidden</span><?php endif; ?></h2>
        <p class="muted"><?= e($sec === 'slides' ? ucfirst($r['media']) . ' · order ' . $r['sort_order'] : ($r['summary'] ?? '')) ?></p>
        <div class="item-actions">
          <?php if ($sec === 'solution'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="featured"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link"><?= $r['featured'] ? 'Unfeature' : 'Feature' ?></button></form>
          <?php endif; ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="field" value="visible"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link"><?= $r['visible'] ? 'Hide' : 'Show' ?></button></form>
          <?php if ($sec === 'solution'): ?><a class="link" href="../solution.php?s=<?= e($r['slug']) ?>" target="_blank" rel="noopener">View page</a><?php endif; ?>
          <form method="post" data-confirm="Delete this permanently? (Hide it instead if you may want it back.)"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="link danger">Delete</button></form>
        </div>
        <details class="edit"><summary>Edit</summary><?php item_form($sec, $r); ?></details>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</main>
<script src="../assets/js/admin.js"></script>
</body>
</html>
