<?php
/*
 * Quotation and business proposal templates.
 * Edit the wording here; {client}, {company}, {site} and {title} are filled in automatically.
 */

const DOC_VAT_RATE = 16; // Kenya VAT %

function doc_types(): array
{
    return ['quote' => 'Quotation', 'proposal' => 'Business proposal', 'invoice' => 'Invoice'];
}

function doc_statuses(string $type = 'quote'): array
{
    if ($type === 'invoice') {
        return ['draft' => 'Draft', 'sent' => 'Sent', 'viewed' => 'Viewed by client', 'partly' => 'Partly paid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled'];
    }
    return ['draft' => 'Draft', 'sent' => 'Sent', 'viewed' => 'Viewed by client', 'accepted' => 'Accepted', 'declined' => 'Declined', 'invoiced' => 'Invoiced'];
}

function pay_methods(): array
{
    return ['mpesa' => 'M-Pesa', 'evc' => 'EVC Plus', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];
}

/** Total paid against an invoice. */
function doc_paid(PDO $pdo, int $docId): float
{
    $st = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM payments WHERE document_id = ?');
    $st->execute([$docId]);
    return round((float)$st->fetchColumn(), 2);
}

function receipt_next_number(PDO $pdo): string
{
    $prefix = 'SB-R-' . date('Y') . '-';
    $st = $pdo->prepare('SELECT receipt_no FROM payments WHERE receipt_no LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = (string)$st->fetchColumn();
    $n = $last ? (int)substr($last, strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/** Amount in words for receipts, e.g. 12500.50 -> "Twelve thousand five hundred shillings and fifty cents". */
function amount_words(float $n): string
{
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
             'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $chunk = function (int $x) use ($ones, $tens): string {
        $w = [];
        if ($x >= 100) { $w[] = $ones[intdiv($x, 100)] . ' hundred'; $x %= 100; if ($x) $w[] = 'and'; }
        if ($x >= 20) { $w[] = $tens[intdiv($x, 10)] . ($x % 10 ? '-' . $ones[$x % 10] : ''); }
        elseif ($x > 0) { $w[] = $ones[$x]; }
        return implode(' ', $w);
    };
    $whole = (int)floor($n);
    $cents = (int)round(($n - $whole) * 100);
    if ($whole === 0) $words = 'zero';
    else {
        $parts = [];
        foreach ([1_000_000_000 => 'billion', 1_000_000 => 'million', 1000 => 'thousand', 1 => ''] as $div => $name) {
            if ($whole >= $div) {
                $parts[] = trim($chunk(intdiv($whole, $div)) . ' ' . $name);
                $whole %= $div;
            }
        }
        $words = implode(' ', $parts);
    }
    $out = $words . ' shillings' . ($cents ? ' and ' . $chunk($cents) . ' cents' : '') . ' only';
    return ucfirst($out);
}

/** Common line items offered as suggestions when typing a description. */
function doc_catalogue(): array
{
    $out = [
        ['Site survey and energy assessment', 'lot'],
        ['Solar PV panel (550W mono)', 'no.'],
        ['Hybrid inverter', 'no.'],
        ['Lithium battery bank', 'no.'],
        ['Mounting structure and rails', 'set'],
        ['DC/AC cables, breakers and protection', 'lot'],
        ['Solar water heater (300 L pressurised)', 'no.'],
        ['Air-source heat pump', 'no.'],
        ['Solar air conditioner', 'no.'],
        ['Solar carport structure', 'bay'],
        ['EV charger (7 kW AC wallbox)', 'no.'],
        ['Solar floodlight', 'no.'],
        ['4G solar CCTV camera', 'no.'],
        ['Solar cold room', 'no.'],
        ['Installation labour', 'lot'],
        ['Transport and logistics', 'lot'],
        ['Testing and commissioning', 'lot'],
        ['Monthly maintenance / monitoring plan', 'month'],
        ['Preventive maintenance visit', 'visit'],
    ];
    if (db_ready()) {
        try {
            foreach (db()->query('SELECT name FROM products ORDER BY name') as $r) $out[] = [$r['name'], 'no.'];
        } catch (Throwable $e) {
        }
    }
    return $out;
}

function doc_default_terms(string $type): string
{
    if ($type === 'invoice') {
        return "1. Payment is due by the due date shown above.\n"
             . "2. Quote the invoice number as the payment reference.\n"
             . "3. Equipment remains the property of Sunbeam Innovation Limited until paid in full.\n"
             . "4. Workmanship is guaranteed for 12 months from handover. Manufacturer warranties apply to equipment.";
    }
    $t = "1. Prices are in Kenya Shillings (KES)" . " and include VAT at " . DOC_VAT_RATE . "% where shown.\n"
       . "2. This " . ($type === 'proposal' ? 'proposal' : 'quotation') . " is valid for the number of days stated above.\n"
       . "3. A deposit of 70% is required before equipment is ordered; the balance is due on commissioning and handover.\n"
       . "4. Payment by M-Pesa or bank transfer. M-Pesa and bank details are provided on the invoice.\n"
       . "5. Any work outside the listed scope will be quoted separately before it is done.\n"
       . "6. Workmanship is guaranteed for 12 months from handover. Manufacturer warranties apply to supplied equipment.\n"
       . "7. The client provides safe access to the roof/site and existing electrical points during the works.";
    return $t;
}

/** Proposal sections: [heading, body]. A section whose body is {pricing} shows the priced items. */
function doc_proposal_sections(): array
{
    return [
        ['Introduction',
         "Dear {client},\n\nThank you for the opportunity to propose {title} at {site}. This proposal sets out our understanding of your energy needs, the system we recommend, how we will deliver it, and the cost."],
        ['About Sunbeam Innovation Limited',
         "Sunbeam Innovation Limited is an Africa-focused provider of solar power systems and energy technologies, working in Kenya and Somalia. We design, supply, install and maintain solar electricity, hybrid backup, solar water heating, heat pumps, solar air conditioning, EV charging, solar carports, floodlights, CCTV and solar cold storage for homes, businesses and institutions.\n\nRecent projects include an 875 kWp rooftop system for Lukenya Flour Mills and a 38 kWp system for Cooperative Bank, Nanyuki Road."],
        ['Your energy requirements',
         "From our discussions and site survey, {company} requires:\n- \n- \n- "],
        ['Recommended system',
         "We recommend:\n- \n- \n\nExpected benefits: lower energy bills, backup during outages and a cleaner energy supply."],
        ['Scope of work',
         "We will:\n- Survey the site and confirm the load and roof/space\n- Supply all equipment in the pricing schedule\n- Install, wire and test the system\n- Commission and hand over with a walk-through and user guide"],
        ['Programme',
         "We estimate the works will take ___ working days from deposit and site access. A detailed programme will be shared at mobilisation."],
        ['Pricing', '{pricing}'],
        ['Warranty and aftercare',
         "Workmanship is guaranteed for 12 months from handover. Panels, inverters and batteries carry the manufacturers' warranties. Maintenance and monitoring plans are available monthly or annually."],
        ['Next steps',
         "To proceed, accept this proposal online using the link provided. We will then issue a deposit invoice and confirm the installation date.\n\nWe look forward to powering {company}."],
    ];
}

/* ---------- helpers ---------- */

function money(float $n): string
{
    return number_format($n, 2);
}

/** Returns [subtotal, discount, vat, total]. */
/** Tax rate (percent) that applies to a document: none, or the rate chosen for it (older documents: Kenya VAT). */
function doc_rate(array $d): float
{
    if (empty($d['vat'])) return 0.0;
    return isset($d['tax_rate']) && $d['tax_rate'] !== null && $d['tax_rate'] !== '' ? (float)$d['tax_rate'] : (float)DOC_VAT_RATE;
}

function doc_tax_label(array $d): string
{
    $r = doc_rate($d);
    return ($d['tax_label'] ?? '' ?: 'VAT') . ' ' . rtrim(rtrim(number_format($r, 2), '0'), '.') . '%';
}

/** Currency of a document (KES or USD). */
function doc_cur(array $d): string
{
    return ($d['currency'] ?? '') ?: setting('currency', 'KES');
}

/** Carries the tax rate and currency over to a copy (invoice from quote, duplicate, scheduled invoice). */
function doc_copy_tax(PDO $pdo, int $newId, array $src): void
{
    $pdo->prepare('UPDATE documents SET tax_rate = ?, tax_label = ?, currency = ? WHERE id = ?')
        ->execute([$src['tax_rate'] ?? null, $src['tax_label'] ?? null, $src['currency'] ?? null, $newId]);
}

/** Settings → payment details text, without lines that are still placeholders ("(to be added)", "(add …)"). */
function pay_details_text(): string
{
    $lines = preg_split('/\R/', (string)setting('payment_details'));
    $lines = array_filter($lines, fn($l) => !preg_match('/\((to be added|add\b)/i', $l));
    return trim(implode("\n", $lines));
}

/** Payment options switched on for documents (Settings → Payment options). */
function pay_options_for_docs(): array
{
    static $o = null;
    if ($o !== null) return $o;
    try {
        $o = db()->query('SELECT * FROM pay_options WHERE active = 1 AND on_docs = 1 ORDER BY sort_order, id')->fetchAll();
        // Show only options that are set up: fill in the M-Pesa till from Settings, hide any option still holding "(add …)" placeholders
        $till = trim((string)setting('mpesa_till'));
        foreach ($o as $k => $po) {
            $txt = (string)$po['details'];
            if ($till !== '') $txt = preg_replace('/\(add (the )?till number( here)?\)/i', $till, $txt);
            if (preg_match('/\(add\b/i', $txt)) { unset($o[$k]); continue; }
            $o[$k]['details'] = $txt;
        }
        $o = array_values($o);
    } catch (Throwable $e) {
        $o = [];
    }
    return $o;
}

function currencies(): array
{
    return ['KES' => 'KES (Kenya Shilling)', 'USD' => 'USD (US Dollar)'];
}

/** Tax rates the admin has set up, e.g. Kenya VAT 16%. */
function tax_rates(PDO $pdo, bool $activeOnly = true): array
{
    try {
        return $pdo->query('SELECT * FROM tax_rates' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY country, rate')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Returns [subtotal, discount, tax, total]. $rate: percent (true = Kenya VAT, false = none). */
function doc_totals(array $items, float|bool $rate, float $discount): array
{
    $sub = 0.0;
    foreach ($items as $it) {
        $sub += (float)($it['qty'] ?? 0) * (float)($it['price'] ?? 0);
    }
    $discount = min(max($discount, 0), $sub);
    $net = $sub - $discount;
    if (is_bool($rate)) $rate = $rate ? (float)DOC_VAT_RATE : 0.0;
    $tax = $rate > 0 ? round($net * $rate / 100, 2) : 0.0;
    return [round($sub, 2), round($discount, 2), $tax, round($net + $tax, 2)];
}

function doc_next_number(PDO $pdo, string $type): string
{
    $prefix = ['proposal' => 'SB-P-', 'invoice' => 'SB-INV-'][$type] ?? 'SB-Q-';
    $prefix .= date('Y') . '-';
    $st = $pdo->prepare('SELECT number FROM documents WHERE number LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = (string)$st->fetchColumn();
    $n = $last ? (int)substr($last, strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

function doc_fill(string $text, array $doc): string
{
    $client = trim((string)($doc['client_name'] ?? '')) ?: 'Sir/Madam';
    return strtr($text, [
        '{client}'  => $client,
        '{company}' => trim((string)($doc['client_company'] ?? '')) ?: $client,
        '{site}'    => trim((string)($doc['site'] ?? '')) ?: 'your premises',
        '{title}'   => trim((string)($doc['title'] ?? '')) ?: 'the works',
    ]);
}

function doc_load(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM documents WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) return null;
    $d['items'] = json_decode((string)$d['items'], true) ?: [];
    $d['sections'] = json_decode((string)$d['sections'], true) ?: [];
    return $d;
}

function doc_public_url(array $d): string
{
    return site_url('doc.php?t=' . $d['token']);
}

/** Public page that confirms a document is genuine (target of the QR code). */
function doc_verify_url(array $d): string
{
    return site_url('v/' . $d['token']);
}

/**
 * Short tamper-evidence code printed on every document. It is an HMAC of the number,
 * date and total, so a document whose amount has been edited will not match on verify.php.
 */
function doc_security_code(array $d): string
{
    [, , , $total] = doc_totals($d['items'], doc_rate($d), (float)$d['discount']);
    $raw = hash_hmac('sha256', $d['number'] . '|' . $d['doc_date'] . '|' . number_format($total, 2, '.', ''), setting('doc_secret', 'sunbeam'));
    return strtoupper(substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4));
}

function order_next_number(PDO $pdo): string
{
    $prefix = 'SB-ORD-' . date('Y') . '-';
    $st = $pdo->prepare('SELECT number FROM orders WHERE number LIKE ? ORDER BY id DESC LIMIT 1');
    $st->execute([$prefix . '%']);
    $last = (string)$st->fetchColumn();
    return $prefix . str_pad((string)(($last ? (int)substr($last, strlen($prefix)) : 0) + 1), 4, '0', STR_PAD_LEFT);
}
