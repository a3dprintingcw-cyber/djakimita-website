<?php
// Partner portal (/partner/): partners log in with the same username and password as the old
// dashboard (users + users_properties). They can check an account and register a visit, see their
// numbers, and edit their own description, hours, address, contact details and photos.
// They cannot change discounts, switch their page off or delete it: that stays with the team.

const PP_ACCOUNT_PREFIX = '765459'; // printed in front of the account number in the app

function pp_user(): ?array
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $uid = (int) ($_SESSION['pp_uid'] ?? 0);
    if (!$uid) {
        $uid = pp_remembered();
        if (!$uid) {
            return null;
        }
        session_regenerate_id(true);
        $_SESSION['pp_uid'] = $uid;
    }
    $st = db()->prepare('SELECT u.id, u.name, u.active, p.voucher_company_id AS company_id, TRIM(v.name) AS company, v.active AS company_active
                         FROM users u JOIN users_properties p ON p.user_id = u.id JOIN voucher_company v ON v.id = p.voucher_company_id
                         WHERE u.id = :u LIMIT 1');
    $st->execute(['u' => $uid]);
    $u = $st->fetch();
    if (!$u || !(int) $u['active']) {
        unset($_SESSION['pp_uid']);
        return null;
    }
    return $u;
}

function pp_require(): array
{
    $u = pp_user();
    if (!$u) {
        // Opened from a customer's QR code (or a bookmark) while logged out: come back here after logging in
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if (str_starts_with($uri, '/partner/') && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $_SESSION['pp_next'] = $uri;
        }
        header('Location: /partner-login.php');
        exit;
    }
    return $u;
}

/** "Stay logged in" for 30 days on the partner's own phone: a signed cookie, void after a password change. */
function pp_remember_sig(int $uid, int $until, string $hash): string
{
    $secret = (string) (config('app_secret') ?: config('db_pass')) . '|pp-remember';
    return hash_hmac('sha256', $uid . '.' . $until . '.' . substr($hash, -20), $secret);
}

function pp_remember(int $uid): void
{
    $st = db()->prepare('SELECT password FROM users WHERE id = :u');
    $st->execute(['u' => $uid]);
    $until = time() + 30 * 86400;
    $val = $uid . '.' . $until . '.' . pp_remember_sig($uid, $until, (string) $st->fetchColumn());
    setcookie('djk_pp', $val, ['expires' => $until, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

function pp_forget(): void
{
    setcookie('djk_pp', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

function pp_remembered(): int
{
    $parts = explode('.', (string) ($_COOKIE['djk_pp'] ?? ''));
    if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || (int) $parts[1] < time()) {
        return 0;
    }
    $st = db()->prepare('SELECT password FROM users WHERE id = :u AND active = 1');
    $st->execute(['u' => (int) $parts[0]]);
    $hash = $st->fetchColumn();
    if ($hash === false || !hash_equals(pp_remember_sig((int) $parts[0], (int) $parts[1], (string) $hash), $parts[2])) {
        return 0;
    }
    return (int) $parts[0];
}

/** Login. Returns the user id, or an error text. Counts failed tries per IP (10 per 15 minutes). */
function pp_login(string $name, string $password)
{
    $db = db();
    $ip = hash('sha256', 'pp:' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $db->exec('CREATE TABLE IF NOT EXISTS bot_hits (h CHAR(64) NOT NULL, at DATETIME NOT NULL, KEY h (h, at)) ENGINE=InnoDB');
    $st = $db->prepare('SELECT COUNT(*) FROM bot_hits WHERE h = :h AND at > NOW() - INTERVAL 15 MINUTE');
    $st->execute(['h' => $ip]);
    if ((int) $st->fetchColumn() >= 10) {
        return 'Too many tries. Wait 15 minutes and try again.';
    }
    $st = $db->prepare('SELECT u.id, u.password, u.active FROM users u JOIN users_properties p ON p.user_id = u.id
                        WHERE LOWER(u.name) = LOWER(:n) LIMIT 1');
    $st->execute(['n' => trim($name)]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, (string) $u['password'])) {
        $db->prepare('INSERT INTO bot_hits (h, at) VALUES (:h, NOW())')->execute(['h' => $ip]);
        return 'Wrong username or password.';
    }
    if (!(int) $u['active']) {
        return 'This login is switched off. Contact the Dj\'aki mi ta! team.';
    }
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    session_regenerate_id(true);
    $_SESSION['pp_uid'] = (int) $u['id'];
    pp_remember((int) $u['id']);
    return (int) $u['id'];
}

/** Turn what was scanned or typed into the customer number (QR code, barcode or the account number shown in the app). */
function pp_account_id(string $raw): int
{
    $d = preg_replace('/\D/', '', $raw);
    if (strlen($d) > 6 && str_starts_with($d, PP_ACCOUNT_PREFIX)) {
        $d = substr($d, strlen(PP_ACCOUNT_PREFIX));
    }
    return $d === '' ? 0 : (int) $d;
}

/**
 * Everything the partner needs to see about a customer's account. The number in the customer's QR code,
 * barcode and "Account number" is their customer number (clients.id). Visits are counted per account
 * (clients_package.id), like the app does. With more than one account, the active one ending last is used.
 * Null when the customer does not exist.
 */
function pp_lookup(int $customerId, int $companyId): ?array
{
    if ($customerId <= 0) {
        return null;
    }
    $db = db();
    $st = $db->prepare('SELECT c.id AS customer, c.username AS first, c.lastName AS last, p.id, p.users_id, p.package_id, p.expire
                        FROM clients c LEFT JOIN clients_package p ON p.users_id = c.id
                        WHERE c.id = :id ORDER BY (p.expire >= CURDATE()) DESC, p.expire DESC LIMIT 1');
    $st->execute(['id' => $customerId]);
    $a = $st->fetch();
    if (!$a) {
        return null;
    }
    if (!$a['id']) {
        $a['id'] = 0;
        $a['package_id'] = 0;
        $a['expire'] = null;
    }
    $accountId = (int) $a['id'];
    $a['type'] = package_label((int) $a['package_id']) ?: 'Account';
    $a['valid'] = $a['expire'] && strtotime($a['expire'] . ' 23:59:59') >= time();

    // Visits: same numbers as the old dashboard and the app ("Partner visits" in the backend)
    $st = $db->prepare('SELECT v_amount_total FROM voucher_package_amount WHERE v_company_id = :c AND v_package_id = :p ORDER BY id DESC LIMIT 1');
    $st->execute(['c' => $companyId, 'p' => (int) $a['package_id']]);
    $total = $st->fetchColumn();
    $a['visits_total'] = $total === false ? null : (int) $total;
    $st = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM client_package_info WHERE users_package_id = :a AND company_id = :c');
    $st->execute(['a' => $accountId, 'c' => $companyId]);
    $a['visits_used'] = (int) $st->fetchColumn();
    $st = $db->prepare('SELECT MAX(date) FROM client_package_info WHERE users_package_id = :a AND company_id = :c');
    $st->execute(['a' => $accountId, 'c' => $companyId]);
    $a['last_visit'] = $st->fetchColumn() ?: null;

    // The discount for this account type at this partner
    $st = $db->prepare('SELECT was, after, the_percent FROM voucher_company_info_deals WHERE company_id = :c AND voucher_id = :v ORDER BY id LIMIT 1');
    $st->execute(['c' => $companyId, 'v' => (int) $a['package_id']]);
    $a['deal'] = $st->fetch() ?: null;
    $a['deal_text'] = partner_deal_text($companyId);

    // Holders, with the one-time account check (photo) when it was approved
    $holders = [];
    try {
        $st = $db->prepare('SELECT h.name, h.client_birthdate AS birth, s.user_id AS client_id
                            FROM sale_receits s JOIN client_package_customers h ON h.order_id = s.id
                            WHERE s.client_package_id = :a AND s.status IN (1, 2) ORDER BY h.id');
        $st->execute(['a' => $accountId]);
        foreach ($st->fetchAll() as $h) {
            $v = function_exists('find_verification') && $h['birth'] ? find_verification((int) $h['client_id'], (string) $h['name'], (string) $h['birth']) : null;
            $holders[] = [
                'name' => trim((string) $h['name']),
                'year' => $h['birth'] ? substr((string) $h['birth'], 0, 4) : '',
                'check' => $v ? (int) $v['status'] : null,
                'photo' => $v && (int) $v['status'] === VERIFY_APPROVED && $v['face_file'] ? (int) $v['id'] : 0,
            ];
        }
    } catch (Throwable $e) {
        error_log('pp holders: ' . $e->getMessage());
    }
    if (!$holders && trim($a['first'] . ' ' . $a['last']) !== '') {
        $holders[] = ['name' => trim($a['first'] . ' ' . $a['last']), 'year' => '', 'check' => null, 'photo' => 0];
    }
    $a['holders'] = $holders;
    return $a;
}

/** Why a visit cannot be registered right now, or '' when it can. */
function pp_block_reason(array $a): string
{
    if (!(int) $a['id']) {
        return 'This customer has no account yet. They can buy one on djakimita.com.';
    }
    if (!$a['valid']) {
        return 'This account ended on ' . date('d-m-Y', strtotime((string) $a['expire'])) . '. No discount.';
    }
    if ($a['visits_total'] === null || $a['visits_total'] <= 0) {
        return 'No visits are set for ' . $a['type'] . ' accounts at your business. Contact the Dj\'aki mi ta! team.';
    }
    if ($a['visits_used'] >= $a['visits_total']) {
        return 'All ' . $a['visits_total'] . ' visit' . ($a['visits_total'] === 1 ? '' : 's') . ' for this account are used at your business.';
    }
    return '';
}

function pp_register_visit(int $accountId, int $companyId): void
{
    db()->prepare('INSERT INTO client_package_info (users_package_id, company_id, amount, date) VALUES (:a, :c, 1, NOW())')
        ->execute(['a' => $accountId, 'c' => $companyId]);
}

function pp_stats(int $companyId): array
{
    $db = db();
    $q = fn (string $where) => (int) $db->query('SELECT COALESCE(SUM(amount), 0) FROM client_package_info WHERE company_id = ' . $companyId . ' AND ' . $where)->fetchColumn();
    return [
        'today' => $q('DATE(date) = CURDATE()'),
        'month' => $q("date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
        'all' => $q('1 = 1'),
        'views' => (function () use ($db, $companyId) {
            try {
                return (int) $db->query('SELECT COALESCE(SUM(view_count), 0) FROM client_view_history WHERE company_id = ' . $companyId . " AND date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
            } catch (Throwable $e) {
                return null;
            }
        })(),
    ];
}

/** Short name for partners: first name and the first letter of the last name ("Maria J."). */
function pp_short_name(string $first, string $last = ''): string
{
    $first = trim($first);
    $last = trim($last);
    if ($last === '' && str_contains($first, ' ')) {
        [$first, $last] = explode(' ', $first, 2);
    }
    return trim($first . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : ''));
}

/** Last visits at this partner, with who it was: name, account type, people on the account, photo. */
function pp_recent(int $companyId, int $limit = 15): array
{
    $db = db();
    $st = $db->prepare('SELECT i.date, i.users_package_id AS pkg, p.users_id AS account, p.package_id, c.username AS first, c.lastName AS last
                        FROM client_package_info i
                        LEFT JOIN clients_package p ON p.id = i.users_package_id
                        LEFT JOIN clients c ON c.id = p.users_id
                        WHERE i.company_id = :c ORDER BY i.date DESC LIMIT ' . (int) $limit);
    $st->execute(['c' => $companyId]);
    $rows = $st->fetchAll();
    $people = $db->prepare('SELECT h.name, h.client_birthdate AS birth, s.user_id AS client_id
                            FROM sale_receits s JOIN client_package_customers h ON h.order_id = s.id
                            WHERE s.client_package_id = :p AND s.status IN (1, 2) ORDER BY h.id');
    $cache = [];
    foreach ($rows as &$r) {
        $pkg = (int) $r['pkg'];
        if (!isset($cache[$pkg])) {
            $names = [];
            $photo = 0;
            $people->execute(['p' => $pkg]);
            foreach ($people->fetchAll() as $h) {
                $names[] = pp_short_name((string) $h['name']);
                if (!$photo && $h['birth'] && function_exists('find_verification')) {
                    $v = find_verification((int) $h['client_id'], (string) $h['name'], (string) $h['birth']);
                    if ($v && (int) $v['status'] === VERIFY_APPROVED && $v['face_file']) {
                        $photo = (int) $v['id'];
                    }
                }
            }
            $cache[$pkg] = ['names' => $names, 'photo' => $photo];
        }
        $r['who'] = pp_short_name((string) $r['first'], (string) $r['last']) ?: ($cache[$pkg]['names'][0] ?? 'Customer');
        $r['people'] = $cache[$pkg]['names'];
        $r['photo'] = $cache[$pkg]['photo'];
    }
    return $rows;
}

/** May this partner see this customer's check photo? Only when the customer visited them, or was just checked here. */
function pp_may_see(int $customerId, int $companyId): bool
{
    if (($_SESSION['pp_seen'][$customerId] ?? 0) > time() - 1800) {
        return true;
    }
    $st = db()->prepare('SELECT 1 FROM client_package_info i JOIN clients_package p ON p.id = i.users_package_id
                         WHERE p.users_id = :u AND i.company_id = :c LIMIT 1');
    $st->execute(['u' => $customerId, 'c' => $companyId]);
    return (bool) $st->fetchColumn();
}

/** Page shell for the partner portal: phone first, same colours and fonts as the website. */
function pp_head(string $title, ?array $u, string $active = ''): void
{
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?> · Dj'aki mi ta! partners</title>
<link rel="icon" href="/assets/img/logo-mark.png">
<link rel="stylesheet" href="/assets/css/site.css?v=<?= (int) @filemtime(public_dir() . '/assets/css/site.css') ?>">
<style>
body{background:#f4f6f2}
.pp-top{background:#fff;border-bottom:1px solid #e3e9df;position:sticky;top:0;z-index:20}
.pp-top .in{max-width:980px;margin:0 auto;padding:10px 16px;display:flex;align-items:center;gap:12px}
.pp-top img.w{height:34px;width:auto}
.pp-top .co{font-weight:700;color:#1d3b1f;font-size:.95rem;margin-left:auto;text-align:right;line-height:1.2}
.pp-top .co small{display:block;font-weight:500;color:#5d6b60}
.pp-nav{max-width:980px;margin:0 auto;padding:0 12px;display:flex;gap:6px;overflow-x:auto}
.pp-nav a{padding:10px 14px;border-radius:999px;color:#1d3b1f;text-decoration:none;font-weight:600;white-space:nowrap;font-size:.95rem}
.pp-nav a.on{background:#187c19;color:#fff}
.pp-main{max-width:980px;margin:0 auto;padding:16px}
.pp-box{background:#fff;border:1px solid #e3e9df;border-radius:18px;padding:18px;margin-bottom:16px}
.pp-box h2{margin:0 0 10px;font-size:1.15rem}
.pp-scan{display:flex;gap:8px;flex-wrap:wrap}
.pp-scan input{flex:1 1 220px;font-size:1.4rem;padding:14px 16px;border-radius:14px;border:2px solid #cfdccb;letter-spacing:.06em}
.pp-scan input:focus{outline:none;border-color:#187c19}
.pp-scan .btn{min-height:54px}
.pp-res{border-radius:18px;padding:18px;margin-bottom:16px;border:2px solid}
.pp-res.ok{background:#eaf6e6;border-color:#9fd193}
.pp-res.no{background:#fdeceb;border-color:#f0aaa4}
.pp-res .big{font-family:var(--display);font-size:1.6rem;line-height:1.15;margin:0 0 6px}
.pp-res .ok-t{color:#146b17}.pp-res .no-t{color:#a12a20}
.pp-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:12px 0}
.pp-meta div{background:#fff;border-radius:12px;padding:10px 12px}
.pp-meta b{display:block;font-size:1.1rem;color:#1d3b1f}
.pp-meta span{font-size:.82rem;color:#5d6b60}
.pp-holders{display:flex;flex-wrap:wrap;gap:10px;margin:8px 0 14px}
.pp-holder{display:flex;align-items:center;gap:10px;background:#fff;border-radius:14px;padding:8px 12px 8px 8px}
.pp-holder img,.pp-holder i{width:56px;height:56px;border-radius:12px;object-fit:cover;background:#eef2ee;display:grid;place-items:center;font-style:normal;color:#7a877c}
.pp-holder small{display:block;color:#5d6b60}
.pp-tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.75rem;font-weight:700;background:#eef2ee;color:#3b4a3d}
.pp-tag.ok{background:#d9f0d2;color:#146b17}.pp-tag.wait{background:#fff1c9;color:#6b5200}.pp-tag.no{background:#fbdcd9;color:#a12a20}
.pp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}
.pp-stats div{background:#fff;border:1px solid #e3e9df;border-radius:16px;padding:14px}
.pp-stats b{display:block;font-family:var(--display);font-size:1.8rem;color:#187c19}
.pp-stats span{color:#5d6b60;font-size:.9rem}
.pp-list{width:100%;border-collapse:collapse}
.pp-list td{padding:9px 6px;border-top:1px solid #eef2ee;font-size:.95rem}
.pp-form label{display:block;font-weight:600;margin:12px 0 6px}
.pp-form input[type=text],.pp-form input[type=email],.pp-form input[type=password],.pp-form input[type=url],.pp-form input[type=time],.pp-form textarea{width:100%;padding:12px 14px;border:1px solid #cfdccb;border-radius:12px;font:inherit;font-size:16px;background:#fff}
.pp-hours{display:grid;gap:8px}
.pp-hours .r{display:grid;grid-template-columns:52px 1fr 1fr auto;gap:8px;align-items:center}
.pp-hours .r input[type=time]{padding:8px 10px}
.pp-photos{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}
.pp-photos figure{margin:0;background:#fff;border:1px solid #e3e9df;border-radius:12px;overflow:hidden}
.pp-photos img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}
.pp-photos figcaption{display:flex;gap:6px;padding:6px;flex-wrap:wrap}
.pp-flash{background:#eaf6e6;border:1px solid #9fd193;border-radius:12px;padding:10px 14px;margin-bottom:14px;font-weight:600}
.pp-err{background:#fdeceb;border:1px solid #f0aaa4;border-radius:12px;padding:10px 14px;margin-bottom:14px;font-weight:600;color:#7d1d15}
.pp-muted{color:#5d6b60}
#pp-cam{width:100%;max-width:420px;border-radius:14px;overflow:hidden;margin-top:10px}
.pp-live-dot{position:fixed;right:12px;bottom:12px;background:#fff;border:1px solid #cfe3c9;border-radius:999px;padding:6px 12px 6px 10px;font-weight:700;font-size:.85rem;color:#187c19;display:flex;align-items:center;gap:6px;box-shadow:0 4px 14px rgba(0,0,0,.08);z-index:30}
.pp-live-dot i{width:9px;height:9px;border-radius:50%;background:#2fb52f;animation:pplive 1.6s infinite}
.pp-live-dot.off{color:#a12a20;border-color:#f0aaa4}.pp-live-dot.off i{background:#d9534f;animation:none}
@keyframes pplive{0%,100%{opacity:1}50%{opacity:.25}}
@media (prefers-reduced-motion: reduce){.pp-live-dot i{animation:none}}
.pp-pop{position:fixed;inset:0;background:rgba(10,30,12,.55);display:grid;place-items:center;padding:16px;z-index:50}
.pp-pop[hidden]{display:none}
.pp-pop-card{background:#fff;border-radius:22px;padding:20px;width:100%;max-width:440px;box-shadow:0 30px 80px rgba(0,0,0,.35);border:3px solid #9fd193}
.pp-pop-k{margin:0 0 10px;font-weight:800;color:#187c19;text-transform:uppercase;letter-spacing:.06em;font-size:.85rem}
.pp-pop-who{display:flex;gap:14px;align-items:center}
.pp-pop-who img,.pp-pop-who i{width:84px;height:84px;border-radius:16px;object-fit:cover;background:#dfeedd;color:#187c19;display:grid;place-items:center;font-style:normal;font-weight:800;font-size:2rem}
.pp-pop-who b{display:block;font-family:var(--display);font-size:1.6rem;line-height:1.1;color:#1d3b1f}
.pp-pop-who small{color:#5d6b60}
.pp-pop-deal{margin:14px 0 0;font-weight:800;font-size:1.25rem;color:#146b17}
.pp-visits{display:grid;gap:8px}
.pp-day{font-weight:700;color:#5d6b60;font-size:.85rem;text-transform:uppercase;letter-spacing:.04em;margin-top:8px}
.pp-visit{display:grid;grid-template-columns:52px 1fr auto auto;gap:12px;align-items:center;background:#f8faf6;border:1px solid #e3e9df;border-radius:14px;padding:8px 12px 8px 8px}
.pp-visit img,.pp-visit i{width:52px;height:52px;border-radius:12px;object-fit:cover;background:#dfeedd;color:#187c19;display:grid;place-items:center;font-style:normal;font-weight:800;font-size:1.3rem}
.pp-visit .who{display:grid;gap:2px;min-width:0}
.pp-visit .who span{font-size:.88rem;color:#3b4a3d;overflow:hidden;text-overflow:ellipsis}
.pp-visit time{font-weight:700;color:#1d3b1f}
@media(max-width:520px){.pp-visit{grid-template-columns:48px 1fr auto}.pp-visit .btn{display:none}}
</style>
</head>
<body>
<header class="pp-top"><div class="in">
  <img class="w" src="/assets/img/logo-word.png" alt="Dj'aki mi ta!">
  <?php if ($u): ?><div class="co"><?= e($u['company']) ?><small>Partner · <a href="/partner/logout.php">Log out</a></small></div><?php else: ?><div class="co">Partners</div><?php endif; ?>
</div>
<?php if ($u): ?>
<nav class="pp-nav">
  <?php foreach (['scan' => ['Check an account', '/partner/'], 'visits' => ['Visits', '/partner/visits.php'], 'profile' => ['My page', '/partner/profile.php'], 'password' => ['Password', '/partner/password.php']] as $k => [$l, $h]): ?>
    <a href="<?= $h ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
</header>
<main class="pp-main">
<?php
    if (!empty($_SESSION['pp_flash'])) {
        echo '<div class="pp-flash" role="status">' . e($_SESSION['pp_flash']) . '</div>';
        unset($_SESSION['pp_flash']);
    }
}

function pp_foot(bool $live = true): void
{
    if ($live && !empty($_SESSION['pp_uid'])) {
        // New scans pop up by themselves while this page is open (customers scan the partner's QR code in the app)
        ?>
<div class="pp-live-dot" id="pp-live" title="New scans show up here by themselves"><i></i> Live</div>
<div class="pp-pop" id="pp-pop" hidden role="alertdialog" aria-live="assertive" aria-labelledby="pp-pop-t">
  <div class="pp-pop-card">
    <p class="pp-pop-k" id="pp-pop-t">New scan</p>
    <div class="pp-pop-who"><span id="pp-pop-img"></span><div><b id="pp-pop-name"></b><small id="pp-pop-type"></small></div></div>
    <p class="pp-pop-deal" id="pp-pop-deal"></p>
    <div class="pp-meta" id="pp-pop-meta"></div>
    <p class="pp-muted" id="pp-pop-people" style="margin:0 0 12px"></p>
    <button class="btn btn-lg" type="button" id="pp-pop-ok" style="width:100%">OK, give the discount</button>
  </div>
</div>
<script>
{
  const pop = document.getElementById('pp-pop'), dot = document.getElementById('pp-live');
  const queue = []; let last = 0, busy = false, failures = 0;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  let ctx = null;
  const ding = () => { try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); [0, .18].forEach((t, i) => { const o = ctx.createOscillator(), g = ctx.createGain(); o.frequency.value = i ? 1046 : 784; g.gain.setValueAtTime(.0001, ctx.currentTime + t); g.gain.exponentialRampToValueAtTime(.35, ctx.currentTime + t + .02); g.gain.exponentialRampToValueAtTime(.0001, ctx.currentTime + t + .3); o.connect(g).connect(ctx.destination); o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + .32); }); } catch (e) {} try { navigator.vibrate && navigator.vibrate([200, 80, 200]); } catch (e) {} };
  document.addEventListener('pointerdown', () => { try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); ctx.resume(); } catch (e) {} }, { once: true });
  const show = () => {
    if (busy || !queue.length) return;
    busy = true; const v = queue.shift();
    document.getElementById('pp-pop-img').innerHTML = v.photo ? '<img src="' + esc(v.photo) + '" alt="">' : '<i>' + esc((v.who || '?').charAt(0)) + '</i>';
    document.getElementById('pp-pop-name').textContent = v.who;
    document.getElementById('pp-pop-type').textContent = v.type + ' account · ' + v.account + ' · ' + v.time;
    document.getElementById('pp-pop-deal').textContent = v.deal ? 'Discount: ' + v.deal : 'See your deal';
    document.getElementById('pp-pop-meta').innerHTML =
      '<div><b>' + (v.valid ? '✓ Valid' : '✕ Ended') + '</b><span>Until ' + esc(v.valid_until) + '</span></div>' +
      '<div><b>' + (v.total == null ? '–' : esc(v.left) + ' of ' + esc(v.total)) + '</b><span>Visits left here</span></div>';
    document.getElementById('pp-pop-people').textContent = v.people && v.people.length > 1 ? 'On this account: ' + v.people.join(', ') : '';
    pop.hidden = false; ding();
    document.getElementById('pp-pop-ok').focus();
  };
  document.getElementById('pp-pop-ok').addEventListener('click', () => { pop.hidden = true; busy = false; show(); });
  const poll = async () => {
    try {
      const r = await fetch('/partner/live.php?after=' + last, { cache: 'no-store', credentials: 'same-origin' });
      if (r.status === 401) { location.href = '/partner-login.php'; return; }
      const j = await r.json();
      if (last && j.visits && j.visits.length) { queue.push(...j.visits); show(); }
      last = j.last || last; failures = 0; dot.classList.remove('off');
    } catch (e) { failures++; if (failures > 2) dot.classList.add('off'); }
    setTimeout(poll, document.hidden ? 15000 : 4000);
  };
  poll();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
}
</script>
<?php
    }
    echo '</main></body></html>';
}

function pp_flash(string $msg): void
{
    $_SESSION['pp_flash'] = $msg;
}
