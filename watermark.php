<?php
/* Tiled company-name watermark used behind every quotation, invoice and receipt. */
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/doc-render.php';
header('Content-Type: image/svg+xml');
header('Cache-Control: public, max-age=86400');
echo watermark_svg();
