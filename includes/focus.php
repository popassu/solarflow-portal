<?php
/* The three specializations Sunbeam is built around (November 2026 refocus). */

const FOCUS_HYBRID = "Sunbeam designs, installs and maintains solar hybrid systems that combine solar panels, lithium batteries, the grid and, where needed, a generator into one intelligent power system. Your building runs on sunshine by day, on stored energy at night, and switches over in milliseconds when the grid fails, so the lights, fridges, pumps and machines never stop.

## Who we power
- All-solar apartments: shared rooftop arrays and battery banks that power common areas, lifts, water pumps and individual units
- Solar houses: complete homes designed to run fully on solar, with the grid as a quiet backup
- Commercial and industrial buildings: offices, banks, factories, mills, hotels and hospitals
- Off-grid sites: farms, camps, schools, clinics and telecom sites far from reliable power

## Design
Every system starts with an engineering design, not a catalogue kit. We audit your energy use, study the roof or ground space and shading, and size the panels, inverters and batteries to your real load and the hours of backup you need.
- Load audit and energy-bill analysis
- Roof survey, structural check and shading study
- Single-line diagrams, cable sizing and protection design
- Clear proposal showing system size, savings and payback period

## Installation
Our technicians install to industry best practice with tier-1 equipment and a tidy, labelled finish.
- Monocrystalline solar panels on engineered aluminium or steel mounting
- Hybrid inverters with smart load control, peak shaving and generator integration
- Wall, floor or rack-mounted lithium (LiFePO4) battery banks that can grow with you
- Surge protection, earthing and safe isolation on both DC and AC sides
- Wi-Fi monitoring so you can see production, storage and usage on your phone

## Maintenance
A solar system is a long-term asset. We keep it at peak output for its full life.
- Scheduled servicing, panel cleaning and thermal inspection
- Battery health checks and firmware updates
- Remote monitoring with fault alerts
- Fast call-outs in Kenya and Somalia

## Proven projects
From an 875 kWp rooftop system for Lukenya Flour Mills and 120 kWp in Juja, to 12 kW hybrid homes in Lavington, Sunbeam has delivered solar that works day and night.";

const FOCUS_COLD = "Sunbeam supplies and installs solar-powered cooling: cold rooms that keep produce, fish, milk and medicine fresh without grid power, and solar air conditioning that cuts the cost of staying cool.

## Solar cold rooms
- Mini (2 to 3 tonnes) and standard 20-foot container cold rooms, chiller or freezer
- Run fully on solar with battery backup, so cooling continues through the night
- Portable, no civil works, with remote temperature alerts on your phone
- Buy or lease

## Solar air conditioning
- Hybrid AC/DC units that run straight from solar panels by day
- For homes, offices, shops and halls, and easy to add to a Sunbeam hybrid system

## Our range
We work with established cold storage manufacturers. Ask us for current models, capacities and lease options.";

const FOCUS_STRUCT = "Sunbeam Structural Works designs, fabricates and assembles steel structures for rooftops, parking and outdoor spaces, with decorative metal cut on our own CNC machine. In Somalia and across the region, the rooftop is where people eat, meet and relax. We turn it into a shaded, beautiful and usable space, and we can build solar into the structure so it powers the building too.

## Motorised louvred roofs
Our signature product: a rooftop canopy with motorised aluminium louvres that open when the sun is gentle and close against harsh sun, wind or rain, at the touch of a button or automatically with sun and rain sensors.
- Rotating louvres from fully open to fully sealed
- Remote, wall-switch or phone control, with optional automatic sun and rain sensors
- Built-in gutters and drainage, LED lighting and side screens
- Ideal for restaurant rooftops, hotel terraces and apartment roof lounges

## Rooftop structures
- Restaurant and café rooftops: full dining canopies, bar shades and lounge pergolas
- Apartment rooftops: residents' seating areas, shaded terraces, laundry and storage roofs
- Hotel and event terraces: large-span canopies for weddings, events and evening dining
- Retractable fabric roofs and fixed polycarbonate or sheet-metal shade roofs
- Rooftop solar frames: elevated steel structures that carry solar panels and give shade underneath

## Canopies and carports
- Solar carports for homes, offices, malls, hospitals and fleets, ready for EV charging
- Entrance, walkway, fuel-station and parking canopies
- Cantilever and tension canopies with clean, modern lines

## Decorative metal
CNC-cut steel and aluminium with patterns made for your building: geometric, Islamic, floral or your own logo.
- Façade cladding panels and privacy screens
- Gates, balustrades and rooftop railings
- Room dividers, signage and feature walls
- Decorative panels built into canopies and pergolas

## From design to assembly
- Site measurement and structural design for local wind loads
- 3D drawings so you see the finished structure before any steel is cut
- Precision CNC cutting, welding, galvanising and powder-coat finish in your colour
- Delivery and on-site assembly by our own team, with lighting, fans and solar installed in the same project

## Serving Somalia and Kenya
We are building the structural works team for Somalia's growing apartment, restaurant and hospitality market, and for clients across Kenya.";

const FOCUS_ABOUT = "Sunbeam Innovation Limited is a regional engineering company serving clients across Kenya, Somalia and East Africa. We focus on three specializations: solar hybrid systems for apartments, homes and businesses; solar cold storage and air conditioning; and structural works such as motorised rooftop canopies, solar carports and CNC-cut decorative metal. We design, build and maintain every project ourselves, so our clients get one accountable partner from the first drawing to years of reliable service.";

/** The three specializations, also used as a fallback when the database has none. */
function focus_list(): array
{
    return [
        ['slug' => 'solar-hybrid', 'title' => 'Solar Hybrid Systems', 'meta' => 'Solar Hybrid', 'summary' => 'Design, installation and maintenance of solar panels and lithium batteries for all-solar apartments, solar houses and businesses.', 'body' => FOCUS_HYBRID, 'image' => 'assets/img/gallery/project-04.jpg', 'tag' => 'Core', 'sort_order' => 10],
        ['slug' => 'cold-storage', 'title' => 'Solar Cold Storage & AC', 'meta' => 'Cold Storage & AC', 'summary' => 'Off-grid solar cold rooms and solar air conditioning that keep produce, fish, milk and medicine fresh with no power bills.', 'body' => FOCUS_COLD, 'image' => 'assets/img/site/cold-room.jpg', 'tag' => 'Specialist', 'sort_order' => 20],
        ['slug' => 'structural-works', 'title' => 'Structural Works', 'meta' => 'Structural Works', 'summary' => 'Motorised louvred roofs, rooftop canopies for restaurants and apartments, solar carports and CNC-cut decorative metal, built by our structural team.', 'body' => FOCUS_STRUCT, 'image' => 'assets/img/focus/structural.svg', 'tag' => 'New', 'sort_order' => 30],
    ];
}

/** Built-in specialization cards (shape of a cards row) for when the database is empty or unreachable. */
function focus_cards(): array
{
    return array_map(fn($c) => $c + ['id' => 0, 'kind' => 'solution', 'featured' => 1, 'visible' => 1, 'video' => null], focus_list());
}

/** Services, written around the people who deliver them. */
function focus_services(): array
{
    return [
        ['slug' => 'sales', 'title' => 'Design & Engineering', 'summary' => 'Qualified electrical and structural engineers audit your site, size every solar and cooling system, and produce 3D structural designs for rooftops, canopies and carports before anything is bought or cut.', 'image' => 'assets/img/gallery/project-09.jpg'],
        ['slug' => 'installation', 'title' => 'Installation & Fabrication', 'summary' => 'Certified installers and technicians fit panels, batteries and cold rooms, while our dedicated structural team CNC-cuts, welds, finishes and assembles every structure on site.', 'image' => 'assets/img/gallery/project-01.jpg'],
        ['slug' => 'maintenance', 'title' => 'Maintenance & Support', 'summary' => 'Trained service technicians handle scheduled servicing, remote monitoring and fast call-outs across Kenya, Somalia and East Africa, keeping every system performing for years.', 'image' => 'assets/img/gallery/project-13.jpg'],
    ];
}

function focus_service_cards(): array
{
    return array_map(fn($c) => $c + ['id' => 0, 'kind' => 'service', 'body' => null, 'tag' => null, 'meta' => null, 'featured' => 0, 'visible' => 1], focus_services());
}

/** Built-in hero slides (local pictures) for when the database has none. */
function focus_slides(): array
{
    return [
        ['media' => 'image', 'file' => 'assets/img/site/hero-solar.jpg', 'heading' => "Empowering Africa's Solar Future.", 'subheading' => 'Solar hybrid power, solar cold storage and structural works across Kenya, Somalia and East Africa.', 'btn_text' => 'Get a Free Quote', 'btn_link' => '#quote'],
        ['media' => 'image', 'file' => 'assets/img/gallery/project-04.jpg', 'heading' => 'Solar hybrid systems, designed and installed.', 'subheading' => 'Panels and lithium batteries for all-solar apartments, solar houses and businesses.', 'btn_text' => 'Explore Solar Hybrid', 'btn_link' => 'solution.php?s=solar-hybrid'],
        ['media' => 'image', 'file' => 'assets/img/focus/cold-storage.svg', 'heading' => 'Solar cold storage and AC.', 'subheading' => 'Off-grid cold rooms and solar air conditioning with zero power bills.', 'btn_text' => 'Explore Cold Storage', 'btn_link' => 'solution.php?s=cold-storage'],
        ['media' => 'image', 'file' => 'assets/img/focus/structural.svg', 'heading' => 'Rooftops built for living.', 'subheading' => 'Motorised louvred roofs, rooftop canopies, carports and CNC decorative metal.', 'btn_text' => 'Explore Structural Works', 'btn_link' => 'solution.php?s=structural-works'],
    ];
}

/** Applies the refocus once: three specializations shown, everything else hidden (never deleted). */
function pp_refocus(PDO $pdo): void
{
    if (setting('focus_v1') === '1' || !(int)$pdo->query('SELECT COUNT(*) FROM cards')->fetchColumn()) return;
    $now = now();
    $old = fn(string $p, int $w = 1200) => 'https://sunbeaminnovation.africa/_next/image?url=' . rawurlencode($p) . "&w=$w&q=75";
    $pdo->exec("UPDATE cards SET visible = 0, featured = 0 WHERE kind = 'solution'");
    foreach (focus_list() as ['slug' => $slug, 'title' => $title, 'meta' => $nav, 'summary' => $sum, 'body' => $body, 'image' => $image, 'tag' => $tag, 'sort_order' => $sort]) {
        $st = $pdo->prepare("SELECT id FROM cards WHERE kind = 'solution' AND slug = ?"); $st->execute([$slug]);
        if ($id = $st->fetchColumn()) {
            $pdo->prepare('UPDATE cards SET title=?, meta=?, summary=?, body=?, image=?, tag=?, featured=1, visible=1, sort_order=? WHERE id=?')->execute([$title, $nav, $sum, $body, $image, $tag, $sort, $id]);
        } else {
            $pdo->prepare("INSERT INTO cards (kind, title, slug, summary, body, image, tag, meta, featured, visible, sort_order, created_at) VALUES ('solution',?,?,?,?,?,?,?,1,1,?,?)")
                ->execute([$title, $slug, $sum, $body, $image, $tag, $nav, $sort, $now]);
        }
    }
    // Services: one design-install-maintain promise across all three specializations
    foreach (focus_services() as $v) $pdo->prepare("UPDATE cards SET title=?, summary=?, image=? WHERE kind='service' AND slug=?")->execute([$v['title'], $v['summary'], $v['image'], $v['slug']]);
    // Shop: only products that belong to the three specializations stay on sale
    $pdo->exec("UPDATE products SET category = 'Solar Hybrid Systems' WHERE category IN ('Solar Electricity', 'Hybrid Systems')");
    $pdo->exec("UPDATE products SET visible = 0 WHERE category NOT IN ('Solar Hybrid Systems', 'Solar Cold Storage & AC', 'Structural Works')");
    $p = $pdo->prepare('INSERT INTO products (name, category, sku, price, image, summary, description, stock, featured, visible, sort_order, created_at) VALUES (?,?,?,0,?,?,?,0,0,0,?,?)');
    foreach ([['Solar cold room (partner brand 1)', 'CS-01'], ['Solar cold room (partner brand 2)', 'CS-02']] as $i => [$n, $sku]) {
        $p->execute([$n, 'Solar Cold Storage & AC', $sku, 'assets/img/site/cold-room.jpg',
            'Draft slot: add the model, capacity, price and photo from the supplier, then tick Visible.', 'Draft slot for a cold storage partner product.', 100 + $i * 10, $now]);
    }
    // Hero: one slide per specialization, earlier slides hidden
    $pdo->exec('UPDATE slides SET visible = 0');
    $pdo->prepare("UPDATE slides SET visible = 1, sort_order = 5, heading = ?, subheading = ?, btn_text = 'Get a Free Quote', btn_link = '#quote' WHERE media = 'video' OR file = 'assets/img/site/hero-solar.jpg'")
        ->execute(["Empowering Africa's Solar Future.", 'Solar hybrid power, solar cold storage and structural works across Kenya, Somalia and East Africa.']);
    $s = $pdo->prepare('INSERT INTO slides (media, file, heading, subheading, btn_text, btn_link, sort_order, visible, created_at) VALUES (?,?,?,?,?,?,?,1,?)');
    $s->execute(['image', 'assets/img/gallery/project-04.jpg', 'Solar hybrid systems, designed and installed.', 'Panels and lithium batteries for all-solar apartments, solar houses and businesses: designed, installed and maintained by Sunbeam.', 'Explore Solar Hybrid', 'solution.php?s=solar-hybrid', 10, $now]);
    $s->execute(['image', 'assets/img/focus/cold-storage.svg', 'Solar cold storage and AC.', 'Off-grid cold rooms and solar air conditioning: fresh produce, fish and medicine with zero power bills.', 'Explore Cold Storage', 'solution.php?s=cold-storage', 20, $now]);
    $s->execute(['image', 'assets/img/focus/structural.svg', 'Rooftops built for living.', 'Motorised louvred roofs, rooftop canopies, solar carports and CNC decorative metal, designed and assembled by our team.', 'Explore Structural Works', 'solution.php?s=structural-works', 30, $now]);
    // About text that still lists the old product range
    if (str_contains(setting('about_text'), 'water heating, backup power, borehole')) {
        setting_save('about_text', FOCUS_ABOUT);
    }
    setting_save('focus_v1', '1');
}

/** Regional positioning (not "Kenyan-based"), applied once to text that still says so. */
function pp_regional(): void
{
    if (setting('regional_v2') === '1') return;
    $about = setting('about_text');
    if (str_contains($about, 'Kenyan-based')) setting_save('about_text', str_contains($about, 'three specializations') ? FOCUS_ABOUT : str_replace(['a Kenyan-based provider', 'serving clients in Kenya and Somalia'], ['a regional provider', 'serving clients across Kenya, Somalia and East Africa'], $about));
    $vision = setting('vision');
    if (str_contains($vision, 'across Kenya and beyond')) setting_save('vision', str_replace('across Kenya and beyond', 'across East Africa and beyond', $vision));
    db()->exec("UPDATE slides SET subheading = REPLACE(subheading, 'across Kenya and Somalia.', 'across Kenya, Somalia and East Africa.')");
    setting_save('regional_v2', '1');
}

/** Local pictures everywhere (the old website is gone) plus newly added client photos. Runs once per version. */
function pp_media_fix(): void
{
    if (setting('media_v4') === '1') return;
    $pdo = db();
    foreach (focus_list() as $c) $pdo->prepare("UPDATE cards SET image = ? WHERE kind = 'solution' AND slug = ? AND (image LIKE 'https://sunbeaminnovation.africa/%' OR image LIKE 'assets/img/focus/%' OR image LIKE '%solar-carport.jpg')")->execute([$c['image'], $c['slug']]);
    foreach (focus_services() as $v) $pdo->prepare("UPDATE cards SET summary = ?, image = CASE WHEN image LIKE 'https://sunbeaminnovation.africa/%' THEN ? ELSE image END WHERE kind = 'service' AND slug = ?")->execute([$v['summary'], $v['image'], $v['slug']]);
    $pdo->exec("UPDATE slides SET file = 'assets/img/focus/cold-storage.svg' WHERE btn_link = 'solution.php?s=cold-storage' AND file LIKE 'https://%'");
    $pdo->exec("UPDATE slides SET file = 'assets/img/focus/structural.svg' WHERE btn_link = 'solution.php?s=structural-works' AND (file LIKE 'https://%' OR file LIKE '%solar-carport.jpg')");
    $pdo->exec("UPDATE products SET image = 'assets/img/site/cold-room.jpg' WHERE sku IN ('CS-01','CS-02') AND (image LIKE 'https://%' OR image LIKE '%.svg')");
    pp_localise_media($pdo);
    setting_save('media_v4', '1');
}


/** Local picture for anything that still points at the old website (which is gone once the domain moved). */
function pp_localise_media(PDO $pdo): void
{
    $old = "LIKE 'https://sunbeaminnovation.africa/%'";
    $gal = fn(int $n) => sprintf('assets/img/gallery/project-%02d.jpg', $n);
    // Hero: the old video and pictures are replaced by local ones
    $pdo->exec("UPDATE slides SET visible = 0 WHERE file $old AND media = 'video'");
    $pdo->exec("UPDATE slides SET file = 'assets/img/site/hero-solar.jpg', media = 'image', visible = 1 WHERE media = 'video' AND visible = 0 AND btn_link = '#quote' AND sort_order = 5");
    $pdo->exec("UPDATE slides SET file = 'assets/img/site/hero-solar.jpg' WHERE file $old");
    // Projects, services and hidden solutions
    $i = 0;
    foreach ($pdo->query("SELECT id FROM cards WHERE image $old ORDER BY kind, sort_order, id")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('UPDATE cards SET image = ? WHERE id = ?')->execute([$gal([2, 5, 10, 6, 1, 3, 4, 9, 11, 12, 13][$i++ % 11]), $id]);
    }
    $pdo->exec("UPDATE products SET image = 'assets/img/site/battery-rack.jpg' WHERE image $old AND category = 'Solar Hybrid Systems' AND name LIKE '%Inverter%'");
    $pdo->exec("UPDATE products SET image = '" . $gal(3) . "' WHERE image $old");
}
