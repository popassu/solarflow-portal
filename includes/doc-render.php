<?php
/* Branded, watermarked A4 rendering for quotations, proposals, invoices and receipts. */

function doc_letterhead(): void
{ ?>
  <header class="lh">
    <img class="lh-logo" src="<?= e(doc_asset('assets/img/logo-hi.png')) ?>" alt="">
    <div class="lh-main">
      <div class="lh-name"><?= e(strtoupper(setting('company_name'))) ?></div>
      <div class="lh-tag">Solar Hybrid Systems | Solar Cold Storage &amp; AC | Structural Works</div>
      <div class="lh-contacts">
        <span><?= e(setting('address')) ?></span>
        <span>Call (Kenya): <?= e(setting('phone_kenya')) ?> · WhatsApp: <?= e(setting('phone_somalia')) ?></span>
        <span><?= e(setting('email')) ?> · <?= e(preg_replace('#^https?://#', '', (string)cfg('site_url'))) ?></span>
        <?php if (setting('show_kra_pin') === '1' && setting('kra_pin')): ?><span>KRA PIN: <?= e(setting('kra_pin')) ?></span><?php endif; ?>
      </div>
    </div>
  </header>
  <div class="lh-rule"><span></span><span></span></div>
<?php }

/** Company-name watermark tiled across the page (watermark.php), plus a faint logo in the middle. */
function doc_watermark(): void
{ ?>
  <div class="wm" aria-hidden="true"><img class="wm-text" src="<?= e(doc_asset('watermark.php')) ?>" alt=""><img class="wm-logo" src="<?= e(doc_asset('assets/img/logo.png')) ?>" alt=""></div>
<?php }

/** QR code (drawn by assets/js/qrcode.js) linking to the verification page, plus the security code. */
function doc_verify_block(array $d, string $url, string $code): void
{ ?>
  <section class="verify-box">
    <div class="qr" data-qr="<?= e($url) ?>" role="img" aria-label="QR code to verify this document"></div>
    <div>
      <h2>Verify this document</h2>
      <p>Scan the QR code, or visit <strong><?= e(preg_replace('#^https?://#', '', site_url('verify'))) ?></strong> and enter the number <strong><?= e($d['number'] ?? '') ?></strong> with security code:</p>
      <p class="code"><?= e($code) ?></p>
      <p class="small">If the details shown there do not match this page, do not pay. Call us on <?= e(setting('phone_kenya')) ?>.</p>
    </div>
  </section>
<?php }

function doc_asset(string $path): string
{
    // In a downloaded / emailed HTML copy every picture is embedded, so the file works offline
    if (!empty($GLOBALS['PP_DOC_INLINE'])) {
        if ($path === 'watermark.php') return 'data:image/svg+xml;base64,' . base64_encode(watermark_svg());
        $file = __DIR__ . '/../' . $path;
        if (is_file($file)) return 'data:' . (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode((string)file_get_contents($file));
    }
    return (defined('PP_DOC_BASE') ? PP_DOC_BASE : '') . $path;
}

function watermark_svg(): string
{
    $name = htmlspecialchars(strtoupper(setting('company_name', 'Sunbeam Innovation Limited')), ENT_XML1);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="794" height="1123" viewBox="0 0 794 1123"><defs>'
         . '<pattern id="p" width="420" height="150" patternUnits="userSpaceOnUse" patternTransform="rotate(-30)">'
         . '<text x="0" y="80" font-family="Arial, Helvetica, sans-serif" font-size="17" font-weight="700" letter-spacing="2" fill="#2C2F64" fill-opacity="0.07">' . $name . '</text>'
         . '</pattern></defs><rect width="794" height="1123" fill="url(#p)"/></svg>';
}

/** A complete, self-contained HTML copy of a quote / proposal / invoice: to download or attach to an email. */
/** $forEmail: no scripts or external links (mail filters reject HTML attachments with JavaScript). */
function doc_html_export(PDO $pdo, array $d, bool $forEmail = false): string
{
    $opt = [];
    if ($d['type'] === 'invoice') {
        $opt['paid'] = doc_paid($pdo, (int)$d['id']);
        $st = $pdo->prepare('SELECT * FROM payments WHERE document_id = ? ORDER BY paid_on, id');
        $st->execute([$d['id']]);
        $opt['payments'] = $st->fetchAll();
    }
    $GLOBALS['PP_DOC_INLINE'] = true;
    ob_start();
    render_document($d, $opt);
    $body = ob_get_clean();
    $GLOBALS['PP_DOC_INLINE'] = false;
    $css = (string)file_get_contents(__DIR__ . '/../assets/css/admin.css') . "\n" . (string)file_get_contents(__DIR__ . '/../assets/css/doc.css');
    $qr = (string)file_get_contents(__DIR__ . '/../assets/js/qrcode.js');
    $title = doc_types()[$d['type']] . ' ' . $d['number'] . ' — ' . setting('company_name');
    if ($forEmail) {
        // The QR code needs JavaScript, so the emailed copy shows a verify link in its place
        $body = preg_replace('#<div class="qr" data-qr="([^"]*)"[^>]*></div>#', '<div class="qr qr-link"><a href="$1">Verify online</a></div>', $body);
        return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . '<title>' . e($title) . '</title>'
            . "<style>$css\n.doc-online{max-width:210mm;margin:12px auto;font-family:Inter,system-ui,sans-serif}.qr-link{display:flex;align-items:center;justify-content:center;border:1px dashed #94a3b8;font:600 12px Inter,system-ui,sans-serif;text-align:center}</style></head><body class=\"doc-page\">"
            . '<p class="doc-online no-print">Online copy, payment and verification: <a href="' . e(doc_public_url($d)) . '">' . e(doc_public_url($d)) . '</a></p>'
            . $body . '</body></html>';
    }
    return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
        . '<title>' . e($title) . '</title>'
        . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=Poppins:wght@600;700;800&display=swap">'
        . "<style>$css\n.doc-online{max-width:210mm;margin:12px auto;font-family:Inter,system-ui,sans-serif}</style></head><body class=\"doc-page\">"
        . '<p class="doc-online no-print">Online copy, payment and verification: <a href="' . e(doc_public_url($d)) . '">' . e(doc_public_url($d)) . '</a></p>'
        . $body
        . "<script>$qr</script><script>document.querySelectorAll('[data-qr]').forEach(function(el){var q=qrcode(0,'M');q.addData(el.getAttribute('data-qr'));q.make();el.innerHTML=q.createSvgTag({cellSize:4,margin:0,scalable:true});});</script>"
        . '</body></html>';
}

function doc_stamp(string $type, string $status): ?array
{
    $map = [
        'draft' => ['DRAFT', 'grey'], 'accepted' => ['ACCEPTED', 'green'], 'declined' => ['DECLINED', 'grey'],
        'invoiced' => ['INVOICED', 'navy'], 'paid' => ['PAID', 'red'], 'partly' => ['PART PAID', 'amber'],
        'cancelled' => ['CANCELLED', 'grey'],
    ];
    return $map[$status] ?? null;
}

function doc_items_table(array $d, ?float $paid = null, bool $withVerify = false): void
{
    [$sub, $disc, $tax, $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    ?>
  <table class="items">
    <thead><tr><th class="n">#</th><th>Description</th><th class="r">Qty</th><th>Unit</th><th class="r">Unit price</th><th class="r">Amount (<?= e(doc_cur($d)) ?>)</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($d['items'] as $it): if (trim((string)($it['desc'] ?? '')) === '') continue; $i++; $amt = (float)$it['qty'] * (float)$it['price']; ?>
      <tr><td class="n"><?= $i ?></td><td><?= nl2br(e($it['desc'])) ?></td><td class="r"><?= e(rtrim(rtrim(number_format((float)$it['qty'], 2), '0'), '.')) ?></td><td><?= e($it['unit'] ?? '') ?></td><td class="r"><?= money((float)$it['price']) ?></td><td class="r"><?= money($amt) ?></td></tr>
    <?php endforeach; if (!$i): ?>
      <tr><td colspan="6" class="empty-row">No items yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php if ($withVerify): ?><table class="sum-row"><tr><td class="sum-left"><?php doc_verify_block($d, doc_verify_url($d), doc_security_code($d)); ?></td><td class="sum-right"><?php endif; ?>
  <table class="totals">
    <tr><th>Subtotal</th><td><?= money($sub) ?></td></tr>
    <?php if ($disc > 0): ?><tr><th>Discount</th><td>− <?= money($disc) ?></td></tr><?php endif; ?>
    <?php if (doc_rate($d) > 0): ?><tr><th><?= e(doc_tax_label($d)) ?></th><td><?= money($tax) ?></td></tr><?php endif; ?>
    <tr class="grand"><th>Total (<?= e(doc_cur($d)) ?>)</th><td><?= money($total) ?></td></tr>
    <?php if ($paid !== null): ?>
      <tr><th>Paid</th><td><?= money($paid) ?></td></tr>
      <tr class="grand due"><th>Balance due</th><td><?= money(max($total - $paid, 0)) ?></td></tr>
    <?php endif; ?>
  </table>
  <?php if ($withVerify): ?></td></tr></table><?php endif; ?>
<?php }

function render_document(array $d, array $opt = []): void
{
    $types = doc_types();
    $paid = $opt['paid'] ?? null;
    $stamp = doc_stamp($d['type'], $d['status']);
    $date = strtotime($d['doc_date']);
    ?>
<article class="sheet type-<?= e($d['type']) ?>">
  <?php doc_watermark(); ?>
  <?php doc_letterhead(); ?>

  <div class="doc-head">
    <div>
      <h1><?= e(strtoupper($types[$d['type']] ?? 'Document')) ?></h1>
      <?php if ($d['title']): ?><p class="doc-title"><?= e($d['title']) ?></p><?php endif; ?>
    </div>
    <?php if ($stamp): ?><div class="stamp <?= $stamp[1] ?>" aria-hidden="true"><?= $stamp[0] ?></div><?php endif; ?>
    <table class="meta">
      <tr><th><?= $d['type'] === 'invoice' ? 'Invoice no.' : 'Ref.' ?></th><td><?= e($d['number']) ?></td></tr>
      <tr><th>Date</th><td><?= date('j M Y', $date) ?></td></tr>
      <?php if ($d['type'] === 'invoice'): ?>
        <tr><th>Due date</th><td><?= date('j M Y', $date + 86400 * (int)$d['due_days']) ?></td></tr>
        <?php if (!empty($opt['source_number'])): ?><tr><th>Quotation</th><td><?= e($opt['source_number']) ?></td></tr><?php endif; ?>
      <?php else: ?>
        <tr><th>Valid until</th><td><?= date('j M Y', $date + 86400 * (int)$d['valid_days']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="parties">
    <div>
      <h2><?= $d['type'] === 'invoice' ? 'Bill to' : 'Prepared for' ?></h2>
      <p>
        <?php if ($d['client_company']): ?><strong><?= e($d['client_company']) ?></strong><br><?php endif; ?>
        <?= $d['client_company'] ? 'Attn: ' : '<strong>' ?><?= e($d['client_name']) ?><?= $d['client_company'] ? '' : '</strong>' ?><br>
        <?php if ($d['client_address']): ?><?= nl2br(e($d['client_address'])) ?><br><?php endif; ?>
        <?php if ($d['client_phone']): ?><?= e($d['client_phone']) ?><br><?php endif; ?>
        <?php if ($d['client_email']): ?><?= e($d['client_email']) ?><?php endif; ?>
      </p>
    </div>
    <?php if ($d['site']): ?>
    <div><h2>Site / project</h2><p><?= nl2br(e($d['site'])) ?></p></div>
    <?php endif; ?>
  </div>

  <?php if ($d['type'] === 'proposal'): ?>
    <?php $n = 0; foreach ($d['sections'] as $sec): if (trim((string)($sec['body'] ?? '')) === '' && trim((string)($sec['heading'] ?? '')) === '') continue; $n++; ?>
      <section class="psec">
        <h2><?= $n ?>. <?= e($sec['heading']) ?></h2>
        <?php if (trim($sec['body']) === '{pricing}'): doc_items_table($d); else: ?>
          <?= doc_paragraphs(doc_fill($sec['body'], $d)) ?>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <?php doc_items_table($d, $d['type'] === 'invoice' ? (float)$paid : null, true); ?>
  <?php endif; ?>

  <?php if ($d['notes']): ?><section class="block"><h2>Notes</h2><?= doc_paragraphs($d['notes']) ?></section><?php endif; ?>

  <?php if ($d['type'] === 'invoice'): ?>
    <?php $pos = pay_options_for_docs(); if ($pos || pay_details_text() !== ''): ?>
    <section class="block pay"><h2>How to pay <span class="pay-ref">Reference: <strong><?= e($d['number']) ?></strong></span></h2><?= doc_paragraphs(pay_details_text()) ?>
      <?php foreach ($pos as $po): ?>
        <div class="pay-opt"><strong><?= e($po['name']) ?></strong><?= $po['country'] && $po['country'] !== 'All' ? ' <span class="muted">(' . e($po['country']) . ')</span>' : '' ?><?php if ($po['details']): ?><br><?= nl2br(e($po['details'])) ?><?php endif; ?><?php if ($po['link']): ?><br><a href="<?= e($po['link']) ?>" rel="noopener"><?= e($po['link']) ?></a><?php endif; ?></div>
      <?php endforeach; ?></section>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($d['terms']): ?><section class="block terms"><h2>Terms and conditions</h2><?= doc_paragraphs($d['terms']) ?></section><?php endif; ?>

  <?php if (!empty($opt['payments'])): ?>
    <section class="block paid-list"><h2>Payments received</h2>
      <table class="items small"><thead><tr><th>Date</th><th>Receipt</th><th>Method</th><th>Reference</th><th class="r">Amount (<?= e(doc_cur($d)) ?>)</th><th class="r">Balance after</th></tr></thead><tbody>
      <?php [, , , $ptot] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']); $run = 0.0; ?>
      <?php foreach ($opt['payments'] as $p): $run += (float)$p['amount']; $after = max(round($ptot - $run, 2), 0); ?>
        <tr><td><?= date('j M Y', strtotime($p['paid_on'])) ?></td><td><a href="<?= e(site_url('doc.php?r=' . $p['token'])) ?>"><?= e($p['receipt_no']) ?></a><br><small class="muted"><?= $after > 0.005 ? 'Interim' : 'Final' ?></small></td><td><?= e(pay_methods()[$p['method']] ?? $p['method']) ?></td><td><?= e($p['reference']) ?></td><td class="r"><?= money((float)$p['amount']) ?></td><td class="r"><?= money($after) ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </section>
  <?php endif; ?>

  <?php if ($d['type'] === 'proposal') doc_verify_block($d, doc_verify_url($d), doc_security_code($d)); ?>

  <?php if ($d['type'] !== 'invoice'): ?>
  <div class="sign">
    <div><p class="line"></p><p>For <?= e(setting('company_name')) ?></p></div>
    <div>
      <?php if ($d['accepted_at']): ?>
        <p class="accepted">Accepted online by <?= e($d['accepted_name']) ?><br><?= date('j M Y, H:i', strtotime($d['accepted_at'])) ?></p>
      <?php else: ?>
        <p class="line"></p><p>Accepted by client — name, signature &amp; date</p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <footer class="lh-foot">Thank you for choosing <?= e(setting('company_name')) ?> · Call <?= e(setting('phone_kenya')) ?> · WhatsApp <?= e(setting('phone_somalia')) ?> · <?= e(setting('email')) ?></footer>
</article>
<?php }

function render_receipt(array $p, array $inv, float $paidToDate): void
{
    [, , , $total] = doc_totals($inv['items'], doc_rate($inv), (float)$inv['discount']);
    $balance = max($total - $paidToDate, 0);
    ?>
<article class="sheet type-receipt">
  <?php doc_watermark(); ?>
  <?php doc_letterhead(); ?>
  <div class="doc-head">
    <div><h1><?= $balance > 0.005 ? 'INTERIM RECEIPT' : 'OFFICIAL RECEIPT' ?></h1><p class="muted"><?= $balance > 0.005 ? 'Interim receipt: part payment. A balance remains on invoice ' . e($inv['number']) . '.' : 'Final receipt: invoice ' . e($inv['number']) . ' is fully paid.' ?></p></div>
    <div class="stamp red" aria-hidden="true"><?= $balance > 0.005 ? 'PART PAID' : 'PAID' ?></div>
    <table class="meta">
      <tr><th>Receipt no.</th><td><?= e($p['receipt_no']) ?></td></tr>
      <tr><th>Date</th><td><?= date('j M Y', strtotime($p['paid_on'])) ?></td></tr>
      <tr><th>Invoice</th><td><?= e($inv['number']) ?></td></tr>
    </table>
  </div>
  <dl class="rcpt">
    <div><dt>Received from</dt><dd><?= e($inv['client_company'] ?: $inv['client_name']) ?><?= $inv['client_company'] && $inv['client_name'] ? ' (' . e($inv['client_name']) . ')' : '' ?></dd></div>
    <div><dt>The sum of</dt><dd><?= e(amount_words((float)$p['amount'])) ?></dd></div>
    <div><dt>Being payment for</dt><dd><?= e($inv['title'] ?: 'Invoice ' . $inv['number']) ?></dd></div>
    <div><dt>Payment method</dt><dd><?= e(pay_methods()[$p['method']] ?? $p['method']) ?><?= $p['reference'] ? ' — ref. ' . e($p['reference']) : '' ?></dd></div>
    <?php if ($p['note']): ?><div><dt>Note</dt><dd><?= e($p['note']) ?></dd></div><?php endif; ?>
  </dl>
  <div class="rcpt-amount"><span><?= e(doc_cur($inv)) ?></span><?= money((float)$p['amount']) ?></div>
  <p class="apops-rcpt"><img src="<?= e(doc_asset('assets/img/apops-mark.png')) ?>" alt="" width="16" height="17"> Processed by APOPS Pay · paid directly to <?= e(setting('company_name')) ?></p>
  <table class="totals">
    <tr><th>Invoice total</th><td><?= money($total) ?></td></tr>
    <tr><th>Paid to date</th><td><?= money($paidToDate) ?></td></tr>
    <tr class="grand <?= $balance > 0 ? 'due' : '' ?>"><th>Balance</th><td><?= money($balance) ?></td></tr>
  </table>
  <?php doc_verify_block(['number' => $p['receipt_no']], site_url('v/r/' . $p['token']), receipt_security_code($p)); ?>
  <div class="sign"><div><p class="line"></p><p>Received by, for <?= e(setting('company_name')) ?></p></div><div></div></div>
  <footer class="lh-foot">Thank you for your payment · Call <?= e(setting('phone_kenya')) ?> · WhatsApp <?= e(setting('phone_somalia')) ?> · <?= e(setting('email')) ?></footer>
</article>
<?php }

/** Turn plain text into paragraphs and bullet lists (lines starting with "-"). */
function doc_paragraphs(string $text): string
{
    $out = '';
    foreach (preg_split("/\R{2,}/", trim($text)) as $block) {
        $lines = preg_split("/\R/", $block);
        if (count(array_filter($lines, fn($l) => preg_match('/^\s*-\s?/', $l))) === count($lines)) {
            $out .= '<ul>' . implode('', array_map(fn($l) => '<li>' . e(preg_replace('/^\s*-\s?/', '', $l)) . '</li>', $lines)) . '</ul>';
        } else {
            $out .= '<p>' . implode('<br>', array_map('e', $lines)) . '</p>';
        }
    }
    return $out;
}

function receipt_security_code(array $p): string
{
    $raw = hash_hmac('sha256', $p['receipt_no'] . '|' . $p['paid_on'] . '|' . number_format((float)$p['amount'], 2, '.', ''), setting('doc_secret', 'sunbeam'));
    return strtoupper(substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4));
}
