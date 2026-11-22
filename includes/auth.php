<?php
/* Staff accounts, roles and permissions, the sign-in picture check (CAPTCHA) and authenticator codes (TOTP). */

/** What a staff member can be allowed to do. */
function perm_list(): array
{
    return [
        'leads'        => 'Quote requests from the website',
        'docs'         => 'Quotes, proposals and invoices',
        'schedules'    => 'Scheduled (monthly) billing',
        'receivables'  => 'Receivables: collect payments (M-Pesa / EVC Plus)',
        'payables'     => 'Payables: request payouts',
        'payables_ok'  => 'Payables: approve and mark payouts paid',
        'workorders'   => 'Work orders: create and assign',
        'wo_assignee'  => 'Can be assigned work orders',
        'shop'         => 'Shop: orders and products',
        'website'      => 'Website editor (slides and cards)',
        'settings'     => 'Settings and staff users',
    ];
}

function role_list(): array
{
    return ['owner' => 'Owner', 'secretary' => 'Secretary', 'technician' => 'Technician', 'shop' => 'Shop attendant', 'webmaster' => 'Webmaster'];
}

/** Default permissions for each role (the owner always has every permission). */
function role_perms(string $role): array
{
    return match ($role) {
        'owner'      => array_keys(perm_list()),
        'secretary'  => ['leads', 'docs', 'schedules', 'receivables', 'payables', 'workorders', 'shop'],
        'technician' => ['wo_assignee', 'payables'],
        'shop'       => ['shop', 'receivables', 'payables'],
        'webmaster'  => ['website'],
        default      => [],
    };
}

/** The signed-in staff member (row from admins) or null. */
function current_user(): ?array
{
    static $u = false;
    if ($u !== false && ($u === null || (int)$u['id'] === (int)($_SESSION['admin_id'] ?? 0))) return $u;
    $u = null;
    if (empty($_SESSION['admin_id'])) return null;
    $st = db()->prepare('SELECT * FROM admins WHERE id = ?');
    $st->execute([(int)$_SESSION['admin_id']]);
    $u = $st->fetch() ?: null;
    return $u;
}

function user_perms(array $u): array
{
    if (($u['role'] ?? '') === 'owner') return array_keys(perm_list());
    $p = json_decode((string)($u['perms'] ?? ''), true);
    return is_array($p) ? $p : role_perms((string)($u['role'] ?? ''));
}

function can(string $perm): bool
{
    $u = current_user();
    return $u && in_array($perm, user_perms($u), true);
}

function require_perm(string ...$any): void
{
    foreach ($any as $p) if (can($p)) return;
    http_response_code(403);
    flash('Your account does not have access to that page. Ask the owner if you need it.', 'err');
    redirect('home.php');
}

/** First admin page a user may open. */
function user_home(): string
{
    foreach ([['leads', 'dashboard.php?tab=leads'], ['docs', 'documents.php'], ['workorders', 'workorders.php'], ['wo_assignee', 'workorders.php'],
              ['receivables', 'receivables.php'], ['shop', 'shop.php'], ['website', 'site.php'], ['payables', 'payables.php']] as [$p, $href]) {
        if (can($p)) return $href;
    }
    return 'account.php';
}

function role_name(string $r): string
{
    return role_list()[$r] ?? ucfirst($r);
}

/** Users who can take a given permission (e.g. technicians for work orders). */
function users_with(PDO $pdo, string $perm): array
{
    $out = [];
    foreach ($pdo->query('SELECT * FROM admins WHERE active = 1 ORDER BY full_name, username') as $u) {
        if (in_array($perm, user_perms($u), true)) $out[(int)$u['id']] = $u;
    }
    return $out;
}

function user_label(?array $u): string
{
    if (!$u) return '—';
    return trim((string)($u['full_name'] ?? '')) ?: (string)$u['username'];
}

/* ---------- CAPTCHA (picture with letters, drawn on this server) ---------- */
function captcha_new(): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 5; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    $_SESSION['captcha'] = ['code' => $code, 'at' => time(), 'tries' => 0];
    return $code;
}

function captcha_ok(string $answer): bool
{
    $c = $_SESSION['captcha'] ?? null;
    unset($_SESSION['captcha']);             // one attempt per picture
    if (!$c || time() - $c['at'] > 600) return false;
    return hash_equals($c['code'], strtoupper(preg_replace('/\s+/', '', $answer)));
}

/* ---------- Authenticator app codes (RFC 6238, 30 s, 6 digits, SHA-1) ---------- */
function b32_encode(string $bin): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $ch) $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $a[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function b32_decode(string $s): string
{
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = preg_replace('/[^A-Z2-7]/', '', strtoupper($s));
    $bits = '';
    foreach (str_split($s) as $ch) $bits .= str_pad(decbin(strpos($a, $ch)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_code(string $secret, int $step): string
{
    $h = hash_hmac('sha1', pack('N2', 0, $step), b32_decode($secret), true);
    $o = ord($h[19]) & 15;
    $n = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
    return str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

/** Checks a code (±30 s). Returns the matched time step, or 0. Steps at or before $lastStep are refused (no replay). */
function totp_check(string $secret, string $code, int $lastStep = 0): int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6 || $secret === '') return 0;
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $s = $now + $i;
        if ($s > $lastStep && hash_equals(totp_code($secret, $s), $code)) return $s;
    }
    return 0;
}

/** Verifies a user's authenticator code and stores the step so the code cannot be used again. */
function user_totp_ok(PDO $pdo, array $u, string $code): bool
{
    $secret = totp_secret_open((string)$u['totp_secret']);
    $step = totp_check($secret, $code, (int)$u['totp_last']);
    if (!$step) return false;
    $pdo->prepare('UPDATE admins SET totp_last = ? WHERE id = ?')->execute([$step, $u['id']]);
    return true;
}

function totp_uri(string $secret, string $account): string
{
    $issuer = setting('company_name', 'Sunbeam Innovation');
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}

/* Secrets are stored encrypted with a key kept in the settings table + config, never in plain text. */
function totp_key(): string
{
    // app_key lives in includes/config.php (outside the database); set it once and never change it
    return hash('sha256', 'totp|' . (string)cfg('app_key', '') . '|' . setting('totp_seed'), true);
}

function totp_secret_seal(string $secret): string
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($secret, 'aes-256-gcm', totp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function totp_secret_open(string $sealed): string
{
    $raw = base64_decode($sealed, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', totp_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? '' : $pt;
}

/** Simple audit trail for money and staff actions. */
function audit(PDO $pdo, string $what, string $detail = ''): void
{
    try {
        $u = current_user();
        $pdo->prepare('INSERT INTO audit_log (user_id, username, action, detail, ip, created_at) VALUES (?,?,?,?,?,?)')
            ->execute([$u['id'] ?? null, $u['username'] ?? 'system', mb_substr($what, 0, 60), mb_substr($detail, 0, 500), client_ip(), now()]);
    } catch (Throwable $e) {
    }
}
