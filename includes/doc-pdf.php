<?php
/*
 * PDF copies of quotes, proposals, invoices and receipts (Dompdf, bundled in includes/vendor; no Composer needed on the server).
 * Same content as the on-screen document: letterhead, watermark, totals, How to pay and the verification QR code.
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/doc-render.php';

use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Output\QROutputInterface;

/** QR code as a PNG data URI (GD) or SVG data URI when GD is missing. */
function pdf_qr(string $text): string
{
    $gd = extension_loaded('gd');
    $o = new QROptions([
        'outputType'   => $gd ? QROutputInterface::GDIMAGE_PNG : QROutputInterface::MARKUP_SVG,
        'scale'        => 6,
        'quietzoneSize' => 1,
        'outputBase64' => true,
    ]);
    return (new QRCode($o))->render($text);
}

/** Turn the screen HTML of a document into Dompdf-friendly HTML (no flex/grid, no scripts, inline images). */
function pdf_wrap(string $sheet, string $title): string
{
    // QR codes: draw them now (the screen version uses JavaScript)
    $sheet = preg_replace_callback('#<div class="qr" data-qr="([^"]*)"[^>]*></div>#', fn($m) => '<div class="qr"><img src="' . pdf_qr(html_entity_decode($m[1])) . '" alt=""></div>', $sheet);
    // Watermark: repeated rotated company name plus a faint logo (Dompdf cannot tile SVG patterns)
    $name = e(strtoupper(setting('company_name')));
    $rows = '';
    for ($i = 0; $i < 5; $i++) $rows .= '<div class="wmr" style="top:' . ($i * 62 + 10) . 'mm">' . str_repeat($name . str_repeat('&nbsp;', 24), 2) . '</div>';
    $logo = doc_asset('assets/img/logo.png');
    $sheet = preg_replace('#<div class="wm"[^>]*>.*?</div>#s', '<div class="wm">' . $rows . '<img class="wm-logo" src="' . e($logo) . '" alt=""></div>', $sheet, 1);

    $vars = ['--navy' => '#2C2F64', '--blue' => '#1A1A2E', '--red' => '#DF3128', '--water' => '#F5A524', '--ink' => '#1A2233', '--muted' => '#5B6B85', '--line' => '#D5DEEA'];
    $css = (string)file_get_contents(__DIR__ . '/../assets/css/doc.css');
    $css = preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css); // drop screen/print blocks
    $css = preg_replace_callback('/var\((--[a-z]+)\)/', fn($m) => $vars[$m[1]] ?? '#000', $css);
    $css .= <<<'CSS'
@page{size:A4;margin:8mm 0 14mm}
body{margin:0;font-family:"DejaVu Sans",sans-serif;font-size:8.3pt;color:#1A2233;line-height:1.3}
.sheet{width:auto;min-height:0;margin:0;padding:0 12mm;box-shadow:none;overflow:visible;font-family:"DejaVu Sans",sans-serif;font-size:8.3pt}
.sheet p{margin:0 0 1.2mm}
.sheet h1,.sheet h2,.lh-name,.stamp,.rcpt-amount{font-family:"DejaVu Sans",sans-serif}
.wm{position:fixed;top:-8mm;left:0;right:0;bottom:-14mm;overflow:hidden;z-index:-1}
.wmr{position:absolute;left:-30mm;width:300mm;white-space:nowrap;font-size:12pt;font-weight:bold;color:#2C2F64;opacity:.035;transform:rotate(-30deg)}
.wm .wm-logo{position:absolute;top:105mm;left:60mm;width:90mm;opacity:.03}
.lh{display:block;overflow:hidden}
.lh-logo{float:left;height:17mm;width:auto;margin-right:4mm}
.lh-main{display:block;overflow:hidden;padding-top:1mm}
.lh-name{font-size:14pt;font-weight:bold;line-height:1.1}
.lh-tag{font-size:7pt;margin:1px 0 2px}
.lh-contacts{display:block;font-size:7pt}
.lh-contacts span{display:inline;margin-right:3mm}
.lh-rule{margin:2mm 0 2.5mm}
.doc-head{display:block;position:relative;min-height:17mm;margin-bottom:2.5mm}
.doc-head>div:first-child{width:95mm}
.type-receipt .doc-head>div:first-child{width:80mm}
.doc-head h1{font-size:16pt}
.doc-title{margin:0}
.doc-head .meta{position:absolute;right:0;top:0;font-size:8pt}
.doc-head .meta th,.doc-head .meta td{padding:.4mm 0 .4mm 3mm}
.stamp{position:absolute;right:58mm;top:2mm;font-size:13pt;font-weight:bold;transform:rotate(-10deg)}
.rcpt-amount span{margin-right:3mm}
.parties{display:block;overflow:hidden;padding:2mm 4mm;margin-bottom:3mm}
.parties>div{display:inline-block;width:48%;vertical-align:top}
.parties h2,.block h2,.verify-box h2{font-size:8pt;margin:0 0 1mm}
.parties p{margin:0;line-height:1.35}
.items{margin-bottom:2mm}
.items th{font-size:8pt;padding:1.6mm 2mm}
.items td{padding:1.1mm 2mm}
.sheet a{color:inherit;text-decoration:none}
.paid-list{page-break-inside:avoid}
.items.small th,.items.small td{font-size:7.4pt;padding:.8mm 1.6mm}
.totals{min-width:0}
.totals th,.totals td{padding:.7mm 0}
.totals th{padding-right:4mm}
.totals .grand th,.totals .grand td{font-size:10pt;padding-top:1.2mm}
.sum-row{width:100%;border-collapse:collapse;margin-bottom:3mm;page-break-inside:avoid}
.sum-row td{vertical-align:top;padding:0}
.sum-row .sum-left{padding-right:5mm}
.sum-row .sum-right{width:68mm}
.sum-row .totals{width:100%;margin:0}
.block{margin-bottom:2.5mm}
.terms{font-size:7.2pt;line-height:1.3}
.terms p,.terms li{margin:0 0 .6mm}
.terms ul,.terms ol{margin:0;padding-left:4mm}
.pay{padding:2mm 3mm;page-break-inside:avoid;columns:auto}
.pay h2 .pay-ref{float:right;font-size:8pt;text-transform:none;letter-spacing:0;font-weight:normal;color:#1A2233}
.pay .pay-opt{display:inline-block;width:48%;vertical-align:top;margin:0 1% 1.2mm 0;font-size:7.8pt}
.sign{display:block;overflow:hidden;margin-top:6mm}
.sign>div{display:inline-block;width:46%;vertical-align:top;margin-right:3%}
.sign .line{height:8mm}
.rcpt div{display:block;overflow:hidden;padding:1.6mm 0}
.rcpt dt{float:left;width:42mm}
.rcpt dd{margin-left:44mm}
.rcpt-amount{display:inline-block;font-size:16pt;font-weight:bold;margin-bottom:3mm}
.verify-box{display:block;overflow:hidden;background:#fff;padding:2mm 3mm;margin:0;page-break-inside:avoid}
.verify-box .qr{float:left;width:20mm;height:20mm;margin-right:3mm}
.verify-box .qr img{width:20mm;height:20mm}
.verify-box p{font-size:7.3pt;margin:0 0 .6mm}
.verify-box .code{font-family:"DejaVu Sans Mono","DejaVu Sans",monospace;font-size:9.5pt}
.verify-box .small{font-size:6.8pt}
.apops-rcpt{display:block;text-align:center}
.apops-rcpt img{width:12px;height:auto;vertical-align:middle}
.lh-foot{position:fixed !important;left:0;right:0;bottom:-11mm;padding:2mm 10mm;font-size:7pt}
CSS;
    return '<!doctype html><html><head><meta charset="utf-8"><title>' . e($title) . '</title><style>' . $css . '</style></head><body>' . $sheet . '</body></html>';
}

function pdf_render(string $html): string
{
    $o = new \Dompdf\Options();
    $o->set('isRemoteEnabled', false);
    $o->set('isPhpEnabled', false);
    $o->set('defaultFont', 'DejaVu Sans');
    $o->set('chroot', realpath(__DIR__ . '/..'));
    $tmp = __DIR__ . '/../data';
    if (is_writable($tmp)) $o->set('tempDir', $tmp);
    $pdf = new \Dompdf\Dompdf($o);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('A4');
    $pdf->render();
    return (string)$pdf->output();
}

/** PDF of a quote / proposal / invoice. */
function doc_pdf(PDO $pdo, array $d): string
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
    $sheet = (string)ob_get_clean();
    $GLOBALS['PP_DOC_INLINE'] = false;
    return pdf_render(pdf_wrap($sheet, doc_types()[$d['type']] . ' ' . $d['number']));
}

/** PDF of a payment receipt. */
function receipt_pdf(PDO $pdo, array $p, array $inv): string
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE document_id = ? AND (paid_on < ? OR (paid_on = ? AND id <= ?))');
    $st->execute([$inv['id'], $p['paid_on'], $p['paid_on'], $p['id']]);
    $GLOBALS['PP_DOC_INLINE'] = true;
    ob_start();
    render_receipt($p, $inv, (float)$st->fetchColumn());
    $sheet = (string)ob_get_clean();
    $GLOBALS['PP_DOC_INLINE'] = false;
    return pdf_render(pdf_wrap($sheet, 'Receipt ' . $p['receipt_no']));
}

/** Safe wrapper: returns [filename, mime, bytes] or null (and logs) when the PDF cannot be built. */
function pdf_attachment(callable $build, string $name): ?array
{
    try {
        @set_time_limit(60);
        $bytes = $build();
        return $bytes !== '' ? [$name . '.pdf', 'application/pdf', $bytes] : null;
    } catch (Throwable $e) {
        error_log("PDF $name: " . $e->getMessage());
        return null;
    }
}
