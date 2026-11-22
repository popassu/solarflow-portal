<?php
require_once __DIR__ . '/focus.php';
/** Creates any missing tables and columns. Safe to run on every admin page load. */
function pp_migrate(PDO $pdo): void
{
    foreach (pp_schema(cfg('db')['driver']) as $sql) $pdo->exec($sql);
    $add = [
        'documents' => ['schedule_id' => 'INT NULL', 'sent_at' => 'DATETIME NULL', 'viewed_at' => 'DATETIME NULL', 'view_count' => 'INT NOT NULL DEFAULT 0',
                        'tax_rate' => 'DECIMAL(6,3) NULL', 'tax_label' => 'VARCHAR(60) NULL', 'currency' => 'VARCHAR(3) NULL',
                        'mail_at' => 'DATETIME NULL', 'mail_to' => 'VARCHAR(160) NULL', 'mail_result' => 'VARCHAR(500) NULL'],
        'admins' => ['full_name' => 'VARCHAR(120) NULL', 'role' => "VARCHAR(20) NOT NULL DEFAULT 'secretary'", 'perms' => 'TEXT NULL',
                     'email' => 'VARCHAR(160) NULL', 'phone' => 'VARCHAR(40) NULL', 'active' => 'INT NOT NULL DEFAULT 1',
                     'totp_secret' => 'VARCHAR(255) NULL', 'totp_on' => 'INT NOT NULL DEFAULT 0', 'totp_last' => 'INT NOT NULL DEFAULT 0'],
        'payments' => ['recorded_by' => 'INT NULL', 'mail_at' => 'DATETIME NULL', 'mail_to' => 'VARCHAR(160) NULL', 'mail_result' => 'VARCHAR(255) NULL'],
        'replies' => ['order_id' => 'INT NULL', 'sent_by' => 'INT NULL'],
        'cards' => ['video' => 'VARCHAR(500) NULL'],
        'mpesa_requests' => ['channel' => "VARCHAR(10) NOT NULL DEFAULT 'mpesa'"],
    ];
    foreach ($add as $table => $cols) {
        $have = pp_columns($pdo, $table);
        foreach ($cols as $c => $def) if ($have && !in_array($c, $have, true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $c $def");
    }
    // The first account becomes the owner if there is none yet
    if (!(int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'owner'")->fetchColumn()) {
        $first = $pdo->query('SELECT MIN(id) FROM admins')->fetchColumn();
        if ($first) $pdo->prepare("UPDATE admins SET role = 'owner' WHERE id = ?")->execute([$first]);
    }
    if (!(int)$pdo->query('SELECT COUNT(*) FROM payout_categories')->fetchColumn()) {
        $c = $pdo->prepare('INSERT INTO payout_categories (name, max_amount, active, sort_order) VALUES (?,?,1,?)');
        foreach ([['Salary', 0], ['Transport', 5000], ['Lunch', 1000], ['Materials & suppliers', 0], ['Other', 10000]] as $i => [$n, $m]) $c->execute([$n, $m, ($i + 1) * 10]);
    }
    // Content from the 2026 company profile for sites installed before it arrived
    if ((int)$pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn()) {
        $pdo->prepare("UPDATE cards SET body = ?, summary = ? WHERE slug = 'cold-storage' AND body LIKE '%Placeholder text%'")
            ->execute([COLD_STORAGE_BODY, 'Solar-powered 20-foot cold rooms that run fully off-grid, with zero electricity bills.']);
        foreach (profile_cards() as $i => [$t, $slug, $sum, $body, $path]) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE slug = ?'); $st->execute([$slug]);
            if (!(int)$st->fetchColumn()) $pdo->prepare("INSERT INTO cards (kind, title, slug, summary, body, image, featured, visible, sort_order, created_at) VALUES ('solution',?,?,?,?,?,0,1,?,?)")
                ->execute([$t, $slug, $sum, $body, $path ?: null, 200 + $i * 10, now()]);
        }
    }
    if (!(int)$pdo->query('SELECT COUNT(*) FROM tax_rates')->fetchColumn()) {
        $pdo->prepare("INSERT INTO tax_rates (name, country, rate, active) VALUES ('VAT', 'Kenya', 16, 1)")->execute();
    }
    if (!(int)$pdo->query('SELECT COUNT(*) FROM pay_options')->fetchColumn()) {
        $o = $pdo->prepare('INSERT INTO pay_options (name, country, details, link, on_docs, active, sort_order) VALUES (?,?,?,?,1,?,?)');
        $o->execute(['M-Pesa (Buy Goods)', 'Kenya', 'Lipa na M-Pesa → Buy Goods → Till number: (add the till number here). Use the invoice number as the reference.', null, 1, 10]);
        $o->execute(['EVC Plus', 'Somalia', 'Send to the Sunbeam EVC Plus merchant number and share the confirmation by WhatsApp.', null, 1, 20]);
        $o->execute(['Bank transfer', 'All', 'Bank: (add bank name)\nAccount name: Sunbeam Innovation Limited\nAccount no.: (add)\nBranch: (add)', null, 0, 30]);
        $o->execute(['APOPS Pay', 'All', 'Pay online by card or mobile money through APOPS Pay.', null, 0, 40]);
    }
    // Photos shared by the client go into the library (and gallery) once
    if (!(int)$pdo->query('SELECT COUNT(*) FROM library')->fetchColumn()) {
        $l = $pdo->prepare("INSERT INTO library (kind, title, path, mime, size, in_gallery, sort_order, created_at) VALUES ('image', ?, ?, 'image/jpeg', ?, 1, ?, ?)");
        foreach (glob(__DIR__ . '/../assets/img/gallery/*.jpg') ?: [] as $i => $f) $l->execute(['Sunbeam installation', 'assets/img/gallery/' . basename($f), filesize($f), ($i + 1) * 10, now()]);
    }
    if (setting('company_name') === 'Sunbeam Innovation Ltd') setting_save('company_name', 'Sunbeam Innovation Limited');
    // Replace the old placeholder phone numbers with the client's real WhatsApp numbers
    if (str_contains(setting('phone_kenya'), '700 000 000')) setting_save('phone_kenya', '+254 727 548 111');
    if (str_contains(setting('phone_somalia'), '61 000 0000')) setting_save('phone_somalia', '+252 61 057 5984');
    if (setting('totp_seed') === '') setting_save('totp_seed', bin2hex(random_bytes(24)));
    // one-off secrets
    if (setting('callback_key') === '') setting_save('callback_key', bin2hex(random_bytes(16)));
    if (setting('doc_secret') === '') setting_save('doc_secret', bin2hex(random_bytes(24)));
    pp_refocus($pdo);
    pp_regional();
    pp_media_fix();
}

function pp_columns(PDO $pdo, string $table): array
{
    try {
        $st = $pdo->query("SELECT * FROM $table LIMIT 0");
        $cols = [];
        for ($i = 0; $i < $st->columnCount(); $i++) $cols[] = $st->getColumnMeta($i)['name'];
        return $cols;
    } catch (Throwable $e) {
        return [];
    }
}

/** Returns CREATE TABLE statements for the configured driver. */
function pp_schema(string $driver): array
{
    $id = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $tail = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        "CREATE TABLE IF NOT EXISTS admins (
            id $id, username VARCHAR(60) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL, last_login DATETIME NULL)$tail",

        "CREATE TABLE IF NOT EXISTS settings (skey VARCHAR(60) NOT NULL PRIMARY KEY, svalue TEXT NULL)$tail",

        "CREATE TABLE IF NOT EXISTS leads (
            id $id, name VARCHAR(120) NOT NULL, phone VARCHAR(40) NOT NULL, email VARCHAR(160) NULL,
            location VARCHAR(160) NULL, service VARCHAR(80) NULL, message TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new', ip VARCHAR(45) NULL, created_at DATETIME NOT NULL)$tail",

        "CREATE TABLE IF NOT EXISTS replies (
            id $id, lead_id INT NOT NULL, to_email VARCHAR(160) NOT NULL, subject VARCHAR(200) NOT NULL,
            body TEXT NOT NULL, ok INT NOT NULL DEFAULT 1, error VARCHAR(255) NULL, created_at DATETIME NOT NULL)$tail",

        // Homepage hero carousel: images or videos
        "CREATE TABLE IF NOT EXISTS slides (
            id $id, media VARCHAR(10) NOT NULL DEFAULT 'image', file VARCHAR(500) NOT NULL,
            heading VARCHAR(160) NULL, subheading VARCHAR(300) NULL, btn_text VARCHAR(60) NULL, btn_link VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 100, visible INT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL)$tail",

        // Product & solution cards, service cards and project cards
        "CREATE TABLE IF NOT EXISTS cards (
            id $id, kind VARCHAR(12) NOT NULL DEFAULT 'solution', title VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL,
            summary VARCHAR(400) NULL, body TEXT NULL, image VARCHAR(500) NULL, tag VARCHAR(60) NULL, meta VARCHAR(160) NULL,
            featured INT NOT NULL DEFAULT 0, visible INT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL)$tail",

        "CREATE TABLE IF NOT EXISTS products (
            id $id, name VARCHAR(160) NOT NULL, category VARCHAR(120) NULL, sku VARCHAR(60) NULL,
            price DECIMAL(12,2) NOT NULL DEFAULT 0, old_price DECIMAL(12,2) NULL, image VARCHAR(500) NULL,
            summary VARCHAR(400) NULL, description TEXT NULL, stock INT NOT NULL DEFAULT 0,
            featured INT NOT NULL DEFAULT 0, visible INT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NOT NULL)$tail",

        "CREATE TABLE IF NOT EXISTS orders (
            id $id, number VARCHAR(30) NOT NULL, token VARCHAR(64) NOT NULL, name VARCHAR(120) NOT NULL,
            phone VARCHAR(40) NOT NULL, email VARCHAR(160) NULL, address VARCHAR(400) NULL, notes TEXT NULL,
            items TEXT NOT NULL, total DECIMAL(12,2) NOT NULL, paid DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'new', pay_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
            mpesa_ref VARCHAR(40) NULL, ip VARCHAR(45) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$tail",

        "CREATE TABLE IF NOT EXISTS documents (
            id $id, type VARCHAR(12) NOT NULL DEFAULT 'quote', number VARCHAR(30) NOT NULL, lead_id INT NULL, source_id INT NULL,
            schedule_id INT NULL, title VARCHAR(200) NULL, client_name VARCHAR(120) NULL, client_company VARCHAR(160) NULL,
            client_phone VARCHAR(40) NULL, client_email VARCHAR(160) NULL, client_address VARCHAR(255) NULL, site VARCHAR(160) NULL,
            items TEXT NULL, sections TEXT NULL, vat INT NOT NULL DEFAULT 1, discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL, terms TEXT NULL, valid_days INT NOT NULL DEFAULT 30, due_days INT NOT NULL DEFAULT 14,
            status VARCHAR(12) NOT NULL DEFAULT 'draft', token VARCHAR(64) NOT NULL, doc_date DATE NOT NULL,
            accepted_name VARCHAR(120) NULL, accepted_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$tail",

        "CREATE TABLE IF NOT EXISTS payments (
            id $id, document_id INT NOT NULL, receipt_no VARCHAR(30) NOT NULL, amount DECIMAL(12,2) NOT NULL,
            method VARCHAR(20) NOT NULL, reference VARCHAR(80) NULL, paid_on DATE NOT NULL, note VARCHAR(255) NULL,
            token VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL)$tail",

        // M-Pesa STK push requests (one row per prompt sent to a phone)
        "CREATE TABLE IF NOT EXISTS mpesa_requests (
            id $id, document_id INT NULL, order_id INT NULL, phone VARCHAR(20) NOT NULL, amount DECIMAL(12,2) NOT NULL,
            checkout_id VARCHAR(80) NULL, merchant_id VARCHAR(80) NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending',
            result_code VARCHAR(10) NULL, result_desc VARCHAR(255) NULL, mpesa_ref VARCHAR(40) NULL, poll VARCHAR(48) NOT NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$tail",

        // Scheduled (recurring) invoices: copies a template invoice every N months until the end date
        "CREATE TABLE IF NOT EXISTS schedules (
            id $id, template_id INT NOT NULL, every_months INT NOT NULL DEFAULT 1, next_run DATE NOT NULL, end_date DATE NULL,
            auto_email INT NOT NULL DEFAULT 1, active INT NOT NULL DEFAULT 1, runs INT NOT NULL DEFAULT 0,
            last_run DATE NULL, created_at DATETIME NOT NULL)$tail",

        // Work orders: jobs given to technicians (installations, maintenance, site visits)
        "CREATE TABLE IF NOT EXISTS work_orders (
            id $id, number VARCHAR(30) NOT NULL, title VARCHAR(200) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'installation',
            client_name VARCHAR(160) NULL, client_phone VARCHAR(40) NULL, location VARCHAR(200) NULL, details TEXT NULL,
            document_id INT NULL, assigned_to INT NULL, priority VARCHAR(10) NOT NULL DEFAULT 'normal', due_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open', report TEXT NULL, created_by INT NULL, done_at DATETIME NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$tail",

        // Payables (Send): what money may go out, and each payout
        "CREATE TABLE IF NOT EXISTS payout_categories (
            id $id, name VARCHAR(80) NOT NULL, max_amount DECIMAL(12,2) NOT NULL DEFAULT 0, active INT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 100)$tail",
        "CREATE TABLE IF NOT EXISTS payouts (
            id $id, ref VARCHAR(30) NOT NULL, category_id INT NOT NULL, payee_name VARCHAR(160) NOT NULL, payee_phone VARCHAR(40) NULL,
            method VARCHAR(20) NOT NULL DEFAULT 'mpesa', account VARCHAR(120) NULL, amount DECIMAL(12,2) NOT NULL, purpose VARCHAR(255) NOT NULL,
            work_order_id INT NULL, status VARCHAR(12) NOT NULL DEFAULT 'submitted', requested_by INT NULL, approved_by INT NULL, approved_at DATETIME NULL,
            paid_by INT NULL, paid_at DATETIME NULL, paid_ref VARCHAR(60) NULL, note VARCHAR(255) NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)$tail",

        // Web management library: photos, videos and documents kept by the webmaster
        "CREATE TABLE IF NOT EXISTS library (
            id $id, kind VARCHAR(10) NOT NULL DEFAULT 'image', title VARCHAR(200) NULL, path VARCHAR(500) NOT NULL, mime VARCHAR(100) NULL,
            size INT NOT NULL DEFAULT 0, in_gallery INT NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 100, uploaded_by INT NULL, created_at DATETIME NOT NULL)$tail",

        // Tax rates per country (e.g. Kenya VAT 16%) and the ways customers can pay
        "CREATE TABLE IF NOT EXISTS tax_rates (
            id $id, name VARCHAR(60) NOT NULL, country VARCHAR(40) NULL, rate DECIMAL(6,3) NOT NULL DEFAULT 0, active INT NOT NULL DEFAULT 1)$tail",
        "CREATE TABLE IF NOT EXISTS pay_options (
            id $id, name VARCHAR(80) NOT NULL, country VARCHAR(40) NULL, details TEXT NULL, link VARCHAR(500) NULL,
            on_docs INT NOT NULL DEFAULT 1, active INT NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 100)$tail",

        "CREATE TABLE IF NOT EXISTS audit_log (
            id $id, user_id INT NULL, username VARCHAR(60) NULL, action VARCHAR(60) NOT NULL, detail VARCHAR(500) NULL,
            ip VARCHAR(45) NULL, created_at DATETIME NOT NULL)$tail",

        "CREATE TABLE IF NOT EXISTS login_attempts (id $id, ip VARCHAR(45) NOT NULL, attempted_at DATETIME NOT NULL)$tail",
    ];
}

const COLD_STORAGE_BODY = "Our solar-powered cold rooms are purpose-built 20-foot container units that operate entirely off-grid. They give farmers, agribusinesses, fish traders and horticulture exporters a secure, portable and cost-effective way to preserve produce, with zero electricity bills and flexible lease terms.

What we offer:
- Solar powered cold rooms: Mini (2 to 3 tonnes) and Standard
- Indoor cold rooms
- Cold rooms on lease

Features:
- 100% solar powered with lithium battery backup: no power bills and cooling right through the night
- Rapid cool-down: reaches target temperature within hours of switching on
- Fully portable: deploy on your farm or facility and relocate when you need to
- No civil works: place the unit, plug in, and it runs the same day
- Cuts post-harvest losses by up to 80%
- Built-in access control and security camera with instant alerts to your phone";

/** Extra product cards from the 2026 company profile. */
function profile_cards(): array
{
    return [
        ['Batteries & Energy Storage', 'batteries', 'Gel, AGM deep-cycle and wall or rack-mounted lithium battery modules.', "High-quality solar batteries designed for heavy-load discharge, with more than 15 years' design life in float service.\n\n- Solar gel batteries\n- Solar DC (AGM deep-cycle) batteries\n- Mounted lithium modules: wall, floor or server-rack mounted, easy to expand", 'assets/img/gallery/project-07.jpg', '', 0],
        ['Solar Backup Systems', 'solar-backup', 'Uninterrupted power for your home during blackouts.', "A backup system keeps your power ON during a blackout from stored energy, with a seamless switchover from your solar panels or generator.\n\n- Hybrid inverters with smart load control and peak shaving\n- IP66 protection, built-in surge protection\n- Quiet, fanless and compact", 'assets/img/gallery/project-12.jpg', '', 0],
        ['Borehole Drilling', 'borehole-drilling', 'From hydrogeological survey and permits to drilling, pump testing and installation.', "We handle the whole process:\n\n- Hydrogeological survey by a registered hydrogeologist\n- WRA authorisation and NEMA environmental assessment\n- Drilling, casing and installation\n- 24-hour pump test and water quality analysis to size the right (solar) pump", '', '', 0],
    ];
}

/** Starter content taken from the current sunbeaminnovation.africa site. Runs once at install. */
function pp_seed(PDO $pdo): void
{
    $now = now();
    // Every picture ships inside the zip: no links to the old website
    $pics = ['assets/img/gallery/project-02.jpg', 'assets/img/gallery/project-05.jpg', 'assets/img/gallery/project-10.jpg', 'assets/img/gallery/project-06.jpg', 'assets/img/gallery/project-01.jpg', 'assets/img/gallery/project-03.jpg', 'assets/img/gallery/project-09.jpg', 'assets/img/gallery/project-11.jpg', 'assets/img/site/power-room.jpg', 'assets/img/site/battery-rack.jpg', 'assets/img/site/cold-room.jpg', 'assets/img/gallery/project-12.jpg', 'assets/img/gallery/project-13.jpg'];
    $img = function (string $p, int $w = 0) use ($pics): string { static $i = 0; return $pics[$i++ % count($pics)]; };

    if (!(int)$pdo->query('SELECT COUNT(*) FROM slides')->fetchColumn()) {
        $s = $pdo->prepare('INSERT INTO slides (media, file, heading, subheading, btn_text, btn_link, sort_order, visible, created_at) VALUES (?,?,?,?,?,?,?,1,?)');
        $s->execute(['image', 'assets/img/site/hero-solar.jpg', "Empowering Africa's Solar Future.", 'Solar hybrid power, solar cold storage and structural works across Kenya, Somalia and East Africa.', 'Get a Free Quote', '#quote', 5, $now]);
    }

    if (!(int)$pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn()) {
        $c = $pdo->prepare('INSERT INTO cards (kind, title, slug, summary, body, image, tag, meta, featured, visible, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,?,1,?,?)');
        $sol = [
            ['Heat Pump', 'heat-pump', 'Energy-efficient hot water that uses a fraction of the electricity of a conventional heater.', "Air-source heat pumps pull heat from the surrounding air to heat water efficiently all year round, even on cloudy days.\n\nIdeal for homes, guesthouses, hotels, hospitals and apartments with high hot-water demand.\n\n- Up to 70% lower water-heating costs\n- Works day and night\n- Sized and installed by our engineers", '/images/products/heating-.jpeg', '', 0],
            ['Solar Water Heating', 'solar-water-heating', 'Reliable, energy-efficient hot water systems for homes, guesthouses, hotels and commercial properties.', "Pressurised and non-pressurised solar water heaters, sized for your household or building.\n\n- Evacuated-tube and flat-plate collectors\n- Electric backup for cloudy days\n- Compliant with Kenya's solar water heating regulations", '/images/products/heating-.jpeg', '', 1],
            ['Solar Air Conditioner', 'solar-air-conditioner', 'Smart cooling that reduces electricity demand while keeping homes and offices comfortable.', "Hybrid AC/DC air conditioners run directly from solar panels during the day and switch to grid or battery when needed.\n\n- Lower cooling bills\n- Quiet inverter technology\n- Home and office units", '/images/e0297e87-8824-44fe-a1db-2ea1dcc544a9.jpeg', '', 0],
            ['Hybrid Systems', 'hybrid-systems', 'Solar, battery and grid working together for power around the clock.', "Hybrid inverters with lithium batteries combine solar, grid and generator so your home or business never goes dark.\n\n- Automatic backup during outages\n- Remote monitoring\n- Scalable as your needs grow", '/images/e0297e87-8824-44fe-a1db-2ea1dcc544a9.jpeg', '', 1],
            ['Solar Electricity', 'solar-electricity', 'Complete solar power systems with panels, inverters and lithium batteries.', "Grid-tied, off-grid and hybrid photovoltaic systems engineered to your load, from homes to factories.\n\n- Tier-1 panels and inverters\n- Professional design and installation\n- Maintenance plans available", '/images/e8ce93d1-9744-4fcf-8a15-edc19fe55018.jpeg', '', 1],
            ['Solar Carport', 'solar-carport', 'Shade and protect vehicles while generating clean power.', "Steel-structure solar carports for homes, offices, malls and fleets, ready for EV charging.", '/images/be6413b2-e626-4c2a-8c44-2c04474d6576.jpeg', '', 0],
            ['EV Charger', 'ev-charger', 'Fast, reliable EV charging for homes, businesses and commercial fleets.', "AC wallbox and DC fast chargers, installed and integrated with solar so you drive on sunshine.", '/images/e7ec3113-ebf4-4a1e-a439-ee48e99f15b3.jpeg', '', 0],
            ['Solar Floodlights & CCTV', 'floodlights', 'Solar-powered outdoor lighting and security cameras for dependable coverage.', "All-in-one solar floodlights, street lights and 4G solar CCTV cameras. No trenching, no power bills.", '/images/5efed5bd-0ebc-44f2-b43c-3c455b8014a0.jpeg', '', 0],
            ['Cold Storage', 'cold-storage', 'Solar-powered 20-foot cold rooms that run fully off-grid, with zero electricity bills.', COLD_STORAGE_BODY, '/images/654c2505-bb0e-4284-b2ad-9463ae684dd2.jpeg', 'New', 1],
        ];
        $sol = array_merge($sol, profile_cards());
        foreach ($sol as $i => [$t, $slug, $sum, $body, $path, $tag, $feat]) {
            $c->execute(['solution', $t, $slug, $sum, $body, str_starts_with($path, 'assets/') ? $path : $img($path), $tag ?: null, null, $feat, 10 + $i * 10, $now]);
        }
        $svc = [
            ['Sales', 'sales', 'Genuine, warranty-backed solar panels, inverters, batteries, heat pumps and more. Buy from our team or order online.', '/images/solar-panels-green-energy-business-man-working-green-eco-friendly-office-business-creative-idea-planing-bio-economy-business-eco-friendly-professional-engineer-sustainable-electricityxa_1715-30032.avif'],
            ['Installation', 'installation', 'Site survey, system design and safe, professional installation by certified technicians using industry best practice.', '/images/01afcb4b-4211-40d2-a568-bbdf144f97fa.jpeg'],
            ['Maintenance', 'maintenance', 'Scheduled servicing, cleaning, repairs and monitoring plans that keep your system performing for years.', '/images/654c2505-bb0e-4284-b2ad-9463ae684dd2.jpeg'],
        ];
        foreach ($svc as $i => [$t, $slug, $sum, $path]) $c->execute(['service', $t, $slug, $sum, null, $img($path), null, null, 0, 10 + $i * 10, $now]);
        $proj = [
            ['875 kWp Rooftop Grid-Tied Solar System', 'Industrial solar', 'Lukenya, Kenya · 875 kWp / 800 kW AC', 'Delivered for Lukenya Flour Mills to cut grid consumption and operating energy costs.', '/images/lukenya-flour-mills/WhatsApp Image 2026-08-11 at 11.35.12.jpeg'],
            ['38 kWp Rooftop Grid-Tied Solar System', 'Commercial solar', 'Industrial Area, Nairobi · 38 kWp / 40 kW AC', 'Delivered for Cooperative Bank along Nanyuki Road.', '/images/cooperative-bank-nanyuki-road/WhatsApp Image 2026-08-11 at 11.36.37.jpeg'],
            ['120 kWp Rooftop Grid-Tied Solar System', 'Commercial solar', 'Juja, Nairobi · 120 kWp / 100 kW AC', 'Clean, reliable energy that reduces grid dependence and operating costs.', '/images/juja-rooftop-solar/WhatsApp Image 2026-08-11 at 11.39.08.jpeg'],
            ['12 kW Hybrid Solar System', 'Residential solar', 'Lavington, Nairobi · 12 kW hybrid', 'Solar, battery storage and grid for a 7-bedroom townhouse, day and night.', '/images/projects/lavington-townhouse/970469e8-5ad4-4a43-b1b7-023607108858.jpeg'],
        ];
        foreach ($proj as $i => [$t, $tag, $meta, $sum, $path]) $c->execute(['project', $t, 'project-' . ($i + 1), $sum, null, $img($path), $tag, $meta, 0, 10 + $i * 10, $now]);
    }

    if (!(int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn()) {
        $p = $pdo->prepare('INSERT INTO products (name, category, sku, price, old_price, image, summary, description, stock, featured, visible, sort_order, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)');
        $items = [
            ['550W Mono Solar Panel', 'Solar Electricity', 'PV-550', 18500, 21000, '/images/e8ce93d1-9744-4fcf-8a15-edc19fe55018.jpeg', 'High-efficiency monocrystalline panel with 25-year performance warranty.', 30, 1],
            ['5kW Hybrid Inverter + 5kWh Lithium Battery', 'Hybrid Systems', 'HYB-5K', 285000, null, '/images/e0297e87-8824-44fe-a1db-2ea1dcc544a9.jpeg', 'Complete backup kit with WiFi monitoring. Installation quoted separately.', 5, 1],
            ['300L Pressurised Solar Water Heater', 'Solar Water Heating', 'SWH-300', 145000, null, '/images/products/heating-.jpeg', 'Evacuated-tube pressurised system for a family home.', 8, 1],
            ['7kW Smart EV Wallbox Charger', 'EV Charger', 'EV-7K', 120000, null, '/images/e7ec3113-ebf4-4a1e-a439-ee48e99f15b3.jpeg', 'Type 2 home charger with app control.', 4, 0],
            ['200W Solar Floodlight', 'Solar Floodlights & CCTV', 'FL-200', 9500, 12000, '/images/5efed5bd-0ebc-44f2-b43c-3c455b8014a0.jpeg', 'All-in-one floodlight with remote and dusk-to-dawn sensor.', 40, 1],
            ['4G Solar CCTV Camera', 'Solar Floodlights & CCTV', 'CAM-4G', 24500, null, '/images/4G-solar-camera.jpg', 'Wire-free solar camera with 4G and night vision.', 12, 0],
        ];
        foreach ($items as $i => [$n, $cat, $sku, $price, $old, $path, $sum, $stock, $feat]) {
            $p->execute([$n, $cat, $sku, $price, $old, $img($path, 750), $sum, $sum, $stock, $feat, 10 + $i * 10, $now]);
        }
    }
    pp_refocus($pdo);
    pp_regional();
    pp_media_fix();
}
