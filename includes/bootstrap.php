<?php
declare(strict_types=1);

$CFG = require __DIR__ . '/config.php';

if (!empty($CFG['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
date_default_timezone_set('Africa/Nairobi');

define('PP_ROOT', dirname(__DIR__));
define('PP_UPLOAD_DIR', PP_ROOT . '/uploads/site');
define('PP_UPLOAD_URL', 'uploads/site');
define('PP_INSTALL_LOCK', PP_ROOT . '/data/installed.lock');
// Keep our own error log in data/ (blocked from the web) so it is easy to find in File Manager
ini_set('log_errors', '1');
if (is_writable(PP_ROOT . '/data')) ini_set('error_log', PP_ROOT . '/data/php-errors.log');

/* ---------- session ---------- */
function pp_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('sb_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => pp_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/* ---------- helpers ---------- */
function cfg(string $key, $default = null)
{
    global $CFG;
    return $CFG[$key] ?? $default;
}

function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $t = $_POST['csrf'] ?? '';
    return is_string($t) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- database ---------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $d = cfg('db');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if ($d['driver'] === 'sqlite') {
        $pdo = new PDO('sqlite:' . $d['sqlite'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        if (str_starts_with((string)$d['pass'], 'CHANGE_ME')) throw new RuntimeException('The database password is not set. Put it in includes/config.local.php (see config.local.example.php).');
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $d['host'], $d['name']);
        $pdo = new PDO($dsn, $d['user'], $d['pass'], $opts);
    }
    return $pdo;
}

function db_ready(): bool
{
    return is_file(PP_INSTALL_LOCK);
}

/**
 * Validate an uploaded MP4 and move it into the gallery. Returns the stored file name.
 * Hosting cannot re-encode video, so compress clips on the phone or with HandBrake before uploading.
 */
function process_video(string $src): string
{
    $max = (int)cfg('max_video_mb', 60) * 1024 * 1024;
    if (!is_file($src) || filesize($src) > $max) {
        throw new RuntimeException('Each video must be under ' . cfg('max_video_mb', 60) . ' MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($src);
    $head = (string)file_get_contents($src, false, null, 4, 4);
    if (!in_array($mime, ['video/mp4', 'video/quicktime'], true) || $head !== 'ftyp') {
        throw new RuntimeException('Only MP4 videos are accepted.');
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.mp4';
    if (!move_uploaded_file($src, PP_UPLOAD_DIR . '/' . $name)) {
        throw new RuntimeException('The video could not be saved.');
    }
    return $name;
}

/* ---------- settings (editable in Admin → Settings) ---------- */
function setting_defaults(): array
{
    return [
        'company_name'   => 'Sunbeam Innovation Limited',   // legal name: documents, receipts, emails
        'brand_name'     => 'Sunbeam Innovation.africa',    // shown on the public website
        'tagline'        => "Empowering Africa's Solar Future",
        'email'          => 'info@sunbeaminnovation.africa',
        'notify_email'   => 'info@sunbeaminnovation.africa',
        'phone_kenya'    => '+254 727 548 111',   // Kenya: calls only
        'phone_somalia'  => '+252 61 057 5984',   // Somalia: the WhatsApp number
        'address'        => 'Parklands, Nairobi, Kenya',
        'kra_pin'        => '',
        'show_kra_pin'   => '0',   // off by default (client's choice)
        'payment_details'=> "",
        'vat_rate'       => '16',
        'currency'       => 'KES',
        'mpesa_env'      => 'sandbox',          // sandbox | production
        'mpesa_type'     => 'till',             // till (Buy Goods) | paybill
        'mpesa_till'     => '',                 // Till number customers pay to (Buy Goods)
        'mpesa_shortcode'=> '',                 // Business/Store shortcode used for STK push
        'mpesa_passkey'  => '',
        'mpesa_key'      => '',                 // Daraja consumer key
        'mpesa_secret'   => '',                 // Daraja consumer secret
        'callback_key'   => '',
        'doc_secret'     => '',
        'home_hidden' => '',
        'home_stats' => "875 | kWp | Largest rooftop solar system delivered\n3 | Core | Specializations: solar hybrid, cooling, structures\nEast Africa | | Regional coverage: Kenya, Somalia and beyond\nEnd-to-end | | Design, build and maintain under one team",
        'about_title' => 'Illuminating Smart Homes, Enriching Lives',
        'about_text' => "Sunbeam Innovation Limited is a regional provider of cutting-edge solar power and smart home systems, serving clients across Kenya, Somalia and East Africa. Founded on the principles of innovation and reliability, we deliver state-of-the-art solar, water heating, backup power, borehole and cold storage solutions that cut energy costs and elevate the modern home and business.",
        'mission' => 'To revolutionise everyday living through intuitive, intelligent and seamlessly integrated solar and smart home technology: simplifying life, enhancing security and providing unparalleled convenience for our customers.',
        'vision' => 'To redefine the future of connected living by pioneering innovative, intelligent and adaptable smart energy solutions, and to be the foremost authority in shaping smart living across East Africa and beyond.',
        'require_2fa' => '0', 'evc_merchant' => '', 'waafi_env' => 'sandbox', 'waafi_merchant_uid' => '', 'waafi_api_user_id' => '',
        'waafi_api_key' => '', 'waafi_currency' => 'USD', 'waafi_fx' => '', 'totp_seed' => '',
        'cron_last'      => '',
    ];
}

function settings(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $cache = setting_defaults();
    if (db_ready()) {
        try {
            foreach (db()->query('SELECT skey, svalue FROM settings') as $r) $cache[$r['skey']] = (string)$r['svalue'];
        } catch (Throwable $e) {
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $s = settings();
    return isset($s[$key]) && $s[$key] !== '' ? $s[$key] : $default;
}

function setting_save(string $key, string $value): void
{
    $pdo = db();
    $st = $pdo->prepare('UPDATE settings SET svalue = ? WHERE skey = ?');
    $st->execute([$value, $key]);
    if ($st->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE skey = ?');
        $chk->execute([$key]);
        if (!(int)$chk->fetchColumn()) $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)')->execute([$key, $value]);
    }
    settings(true);
}

/** Digits-only international number for wa.me links, e.g. "+254 712 345 678" -> 254712345678 */
function wa_digits(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    if (str_starts_with($d, '0')) $d = '254' . substr($d, 1);
    return $d;
}

/** tel: link for a phone number (Kenya line is for calls only). */
function tel_link(string $phone): string
{
    return 'tel:+' . wa_digits($phone);
}

/** WhatsApp goes to the Somalia number only. */
function wa_number(): string
{
    return setting('phone_somalia');
}

/** Name shown on the public website (the legal company name stays on documents). */
function brand(): string
{
    return setting('brand_name') ?: setting('company_name');
}

function wa_link(string $phone, string $text = ''): string
{
    return 'https://wa.me/' . wa_digits($phone) . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function site_url(string $path = ''): string
{
    return rtrim((string)cfg('site_url'), '/') . '/' . ltrim($path, '/');
}

/* ---------- admin ---------- */
function is_admin(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!is_admin()) redirect('index.php');
    // idle timeout: 30 minutes
    if (time() - ($_SESSION['admin_seen'] ?? 0) > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('You were signed out after 30 minutes of inactivity.', 'warn');
        redirect('index.php');
    }
    $_SESSION['admin_seen'] = time();
    $u = current_user();
    if (!$u || !(int)$u['active']) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('This account is switched off. Ask the owner.', 'err');
        redirect('index.php');
    }
    // Owner can require an authenticator app for everyone: until it is set up, only "My account" opens
    if (setting('require_2fa') === '1' && !(int)$u['totp_on'] && basename($_SERVER['SCRIPT_NAME']) !== 'account.php') {
        flash('Set up your authenticator app to continue. It is required for all staff.', 'warn');
        redirect('account.php');
    }
}

/* ---------- image handling ---------- */
/**
 * Validate and re-encode an uploaded image into uploads/site. Returns the stored file name.
 * Re-encoding with GD strips EXIF/GPS data and any payload hidden in the file.
 */
function process_image(string $src, int $maxSide = 1600): string
{
    $max = (int)cfg('max_upload_mb', 8) * 1024 * 1024;
    if (!is_file($src) || filesize($src) > $max) {
        throw new RuntimeException('Each photo must be under ' . cfg('max_upload_mb', 8) . ' MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($src);
    $info = @getimagesize($src);
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Only JPG, PNG or WebP photos are accepted.');
    }
    [$w, $h] = $info;
    if ($w < 100 || $h < 100 || $w * $h > 40_000_000) {
        throw new RuntimeException('That photo is too small or too large to use.');
    }
    $img = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($src),
        'image/png'  => imagecreatefrompng($src),
        'image/webp' => imagecreatefromwebp($src),
    };
    if (!$img) throw new RuntimeException('That photo could not be read.');
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $o = (int)($exif['Orientation'] ?? 1);
        if ($o === 3) $img = imagerotate($img, 180, 0);
        if ($o === 6) $img = imagerotate($img, -90, 0);
        if ($o === 8) $img = imagerotate($img, 90, 0);
    }
    if (!is_dir(PP_UPLOAD_DIR)) mkdir(PP_UPLOAD_DIR, 0755, true);
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.jpg';
    save_resized($img, PP_UPLOAD_DIR . '/' . $name, $maxSide, 82);
    imagedestroy($img);
    return $name;
}

/** Handle one uploaded file field (image or MP4). Returns stored URL path or null if nothing uploaded. */
function handle_upload(string $field, bool $allowVideo = false, int $maxSide = 1600): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        throw new RuntimeException('Upload failed. The file may be larger than the server allows.');
    }
    if ($allowVideo && preg_match('/\.(mp4|m4v|mov)$/i', $f['name'])) {
        return PP_UPLOAD_URL . '/' . process_video($f['tmp_name']);
    }
    if ($allowVideo && preg_match('/\.pdf$/i', $f['name'])) {
        if ($f['size'] > 20 * 1024 * 1024) throw new RuntimeException('PDF files must be under 20 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        if ($mime !== 'application/pdf' || file_get_contents($f['tmp_name'], false, null, 0, 5) !== '%PDF-') throw new RuntimeException('That is not a valid PDF.');
        if (!is_dir(PP_UPLOAD_DIR)) mkdir(PP_UPLOAD_DIR, 0755, true);
        $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.pdf';
        if (!move_uploaded_file($f['tmp_name'], PP_UPLOAD_DIR . '/' . $name)) throw new RuntimeException('The file could not be saved.');
        return PP_UPLOAD_URL . '/' . $name;
    }
    return PP_UPLOAD_URL . '/' . process_image($f['tmp_name'], $maxSide);
}

/** URL for a stored media path: uploaded files are relative, imported ones may be absolute. */
function media_url(?string $path, string $base = ''): string
{
    $path = (string)$path;
    if ($path === '') return $base . 'assets/img/placeholder.svg';
    return preg_match('#^https?://#', $path) ? $path : $base . ltrim($path, '/');
}

function save_resized(GdImage $img, string $dest, int $maxSide, int $quality): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = (int)round($w * $scale);
    $nh = (int)round($h * $scale);
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imageinterlace($out, true);
    imagejpeg($out, $dest, $quality);
    imagedestroy($out);
}

require_once __DIR__ . '/auth.php';
