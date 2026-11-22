<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/focus.php';
http_response_code(404);
// Relative links must work from any missing address, e.g. /a/b/c
ob_start(fn($html) => preg_replace('/<head>/', '<head><base href="/">', $html, 1));
page_head('Page not found');
site_header();
?>
<main>
  <section class="section"><div class="container narrow center">
    <h1>Page not found</h1>
    <p>The page you are looking for has moved or does not exist.</p>
    <p class="hero-ctas center-ctas"><a class="btn btn-primary" href="/">Go to the home page</a> <a class="btn btn-outline" href="/admin/">Staff portal</a></p>
  </div></section>
</main>
<?php site_footer(); ?>
