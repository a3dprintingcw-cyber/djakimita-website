<?php
// Staff backend (djakimita.com/admin): shared helpers and page layout.
// Every admin page starts with:  require __DIR__ . '/../../app/bootstrap.php'; $me = admin_page();

// ---------------------------------------------------------------------------
// Settings (site_settings table). Falls back to config.local.php when not set.
// ---------------------------------------------------------------------------
function setting(string $key, $default = null)
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (db()->query('SELECT k, v FROM site_settings') as $r) {
                $all[$r['k']] = $r['v'];
            }
        } catch (Throwable $e) {
            // table not there yet
        }
    }
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO site_settings (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)')
        ->execute(['k' => $key, 'v' => $value]);
}

// ---------------------------------------------------------------------------
// Staff accounts and activity log
// ---------------------------------------------------------------------------
function staff_accounts(): array
{
    try {
        return db()->query('SELECT id, username, name, active, created_at, last_login FROM admin_users ORDER BY username')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function staff_password_ok(string $pass): ?string
{
    if (mb_strlen($pass) < 10) {
        return 'Use at least 10 characters.';
    }
    if (in_array(strtolower($pass), ['djakimita123', 'password123', '1234567890', 'qwertyuiop'], true)) {
        return 'That password is too easy to guess.';
    }
    return null;
}

function admin_log(string $action, string $detail = ''): void
{
    try {
        db()->prepare('INSERT INTO admin_log (username, action, detail, created_at) VALUES (:u, :a, :d, NOW())')
            ->execute(['u' => (string) admin_user(), 'a' => $action, 'd' => mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) {
    }
}

// ---------------------------------------------------------------------------
// Page helpers
// ---------------------------------------------------------------------------
/** Start an admin page: no caching, no indexing, must be logged in. Returns the username. */
function admin_page(): string
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    return require_admin();
}

/** For POST handlers: CSRF check, then run $fn. Redirects back to $back with a message. */
function admin_post(callable $fn, string $back): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (!csrf_check()) {
        flash('Session expired, please try again.', 'bad');
    } else {
        try {
            $fn();
        } catch (Throwable $e) {
            error_log('Admin action failed: ' . $e->getMessage());
            flash('Something went wrong: ' . $e->getMessage(), 'bad');
        }
    }
    header('Location: ' . $back);
    exit;
}

function flash(string $msg, string $kind = 'ok'): void
{
    $_SESSION['admin_flash'] = [$msg, $kind];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function admin_money(float $amount, string $currency): string
{
    $currency = strtolower($currency ?: 'eur');
    return money($amount, $currency);
}

function admin_date(?string $d, bool $time = false): string
{
    if (!$d || str_starts_with($d, '0000')) {
        return '–';
    }
    return date($time ? 'd-m-Y H:i' : 'd-m-Y', strtotime($d));
}

function card_name(int $package): string
{
    static $names = [1 => 'Kids', 2 => 'Teen', 3 => 'Adult', 4 => 'Family', 5 => 'Senior', 6 => 'eSIM', 7 => 'Duo', 8 => 'Couple', 9 => 'Couple'];
    return $names[$package] ?? ('Package ' . $package);
}

function pager(int $total, int $page, int $per, array $query): string
{
    $pages = (int) ceil($total / $per);
    if ($pages <= 1) {
        return '';
    }
    $out = '<nav class="ad-pager">';
    if ($page > 1) {
        $out .= '<a href="?' . e(http_build_query($query + ['p' => $page - 1])) . '">‹ Previous</a>';
    }
    $out .= '<span>Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        $out .= '<a href="?' . e(http_build_query($query + ['p' => $page + 1])) . '">Next ›</a>';
    }
    return $out . '</nav>';
}

/** Store an uploaded photo as a JPEG (max $maxSide px). Returns [bytes, mime] or throws. */
function admin_image_upload(array $file, int $maxSide = 1600): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Try a smaller photo (max 16 MB).');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Only JPG, PNG or WebP photos.');
    }
    $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
    if (!$src) {
        throw new RuntimeException('Could not read that photo.');
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
        $src = $o === 3 ? imagerotate($src, 180, 0) : ($o === 6 ? imagerotate($src, -90, 0) : ($o === 8 ? imagerotate($src, 90, 0) : $src));
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($dst, null, 85);
    return [(string) ob_get_clean(), 'image/jpeg'];
}

/** Remove cached resized copies of a partner photo so the website shows the new one. */
function clear_image_cache(string $prefix, int $id): void
{
    foreach (['s', 'm', 'l'] as $s) {
        @unlink(__DIR__ . '/cache/img/' . $prefix . $id . '-' . $s . '.jpg');
    }
}

// ---------------------------------------------------------------------------
// Layout
// ---------------------------------------------------------------------------
const ADMIN_MENU = [
    'index' => ['Overview', 'home'],
    'orders' => ['Orders', 'card'],
    'customers' => ['Customers', 'user'],
    'checks' => ['Card checks', 'check'],
    'partners' => ['Partners', 'pin'],
    'categories' => ['Categories', 'grid'],
    'prices' => ['Prices', 'tag'],
    'app-home' => ['App home', 'phone'],
    'settings' => ['Settings', 'gear'],
];

function admin_head(string $title, string $active): void
{
    $flash = $_SESSION['admin_flash'] ?? null;
    unset($_SESSION['admin_flash']);
    $waiting = 0;
    try {
        $waiting = (int) db()->query('SELECT COUNT(*) FROM holder_verifications WHERE status = 0')->fetchColumn()
            + (int) db()->query('SELECT COUNT(*) FROM partner_applications WHERE status = 0')->fetchColumn();
    } catch (Throwable $e) {
    }
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= e($title) ?> · Dj'aki mi ta! admin</title>
  <link rel="icon" href="/assets/img/logo-mark.png">
  <link rel="stylesheet" href="/assets/css/site.css?v=<?= (int) (@filemtime(__DIR__ . '/../public_html/assets/css/site.css') ?: @filemtime(__DIR__ . '/../public/assets/css/site.css')) ?>">
  <style><?= admin_css() ?></style>
</head>
<body class="admin ad">
<div class="ad-shell">
  <aside class="ad-side" id="ad-side">
    <a class="ad-brand" href="/admin/"><img src="/assets/img/logo-mark.png" alt="" width="36" height="36"><span>Dj'aki mi ta!<small>Admin</small></span></a>
    <nav class="ad-menu">
      <?php foreach (ADMIN_MENU as $key => [$label, $ic]): ?>
        <a href="/admin/<?= $key === 'index' ? '' : $key . '.php' ?>" class="<?= $key === $active ? 'on' : '' ?>"><?= e($label) ?><?= $key === 'checks' && $waiting ? ' <span class="ad-badge">' . $waiting . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="ad-side-foot">
      <a href="/" target="_blank" rel="noopener">View website ↗</a>
      <a href="/Dashboard/" target="_blank" rel="noopener">Old dashboard ↗</a>
      <a href="https://my.mollie.com/dashboard" target="_blank" rel="noopener">Mollie ↗</a>
      <form method="post" action="/admin/"><?= csrf_field() ?><button name="action" value="logout" class="ad-linkbtn">Log out <?= e((string) admin_user()) ?></button></form>
    </div>
  </aside>
  <main class="ad-main">
    <button class="ad-menu-btn" type="button" onclick="document.getElementById('ad-side').classList.toggle('open')">☰ Menu</button>
    <?php if ($flash): ?><p class="ad-flash <?= e($flash[1]) ?>"><?= e($flash[0]) ?></p><?php endif; ?>
    <?php
}

function admin_foot(): void
{
    ?>
  </main>
</div>
<script>
function adLink(sel) {
  var f = sel.closest('form'), u = f.querySelector('.lk-url'), c = f.querySelector('.lk-co');
  u.hidden = sel.value !== 'url'; c.hidden = sel.value !== 'company';
  u.querySelector('[data-v]').disabled = u.hidden; c.querySelector('[data-v]').disabled = c.hidden;
}
document.querySelectorAll('form[data-confirm]').forEach(function (f) {
  f.addEventListener('submit', function (ev) { if (!confirm(f.dataset.confirm)) { ev.preventDefault(); } });
});
</script>
</body>
</html>
    <?php
}

function admin_css(): string
{
    return <<<CSS
.ad{background:#f4f6f2}
.ad-shell{display:grid;grid-template-columns:230px 1fr;min-height:100vh}
.ad-side{background:#0f3d14;color:#fff;padding:18px 14px;display:flex;flex-direction:column;gap:18px;position:sticky;top:0;height:100vh;overflow:auto}
.ad-brand{display:flex;gap:10px;align-items:center;color:#fff;text-decoration:none;font-weight:800}
.ad-brand img{background:#fff;border-radius:50%;padding:3px}
.ad-brand small{display:block;font-weight:500;opacity:.7;font-size:.75rem}
.ad-menu{display:flex;flex-direction:column;gap:2px}
.ad-menu a{color:#e8f1e6;text-decoration:none;padding:9px 12px;border-radius:10px;font-weight:600;font-size:.95rem}
.ad-menu a:hover{background:rgba(255,255,255,.08)}
.ad-menu a.on{background:#fff;color:#0f3d14}
.ad-badge{background:#e8b84a;color:#0f3d14;border-radius:999px;padding:1px 8px;font-size:.75rem;margin-left:4px}
.ad-side-foot{margin-top:auto;display:flex;flex-direction:column;gap:6px;font-size:.85rem}
.ad-side-foot a,.ad-linkbtn{color:#cfe2cc;text-decoration:none;background:none;border:0;padding:0;text-align:left;cursor:pointer;font:inherit}
.ad-main{padding:26px 30px 60px;min-width:0}
.ad-menu-btn{display:none}
.ad h1{font-size:1.7rem;margin:0 0 16px}
.ad h2{font-size:1.2rem;margin:26px 0 10px}
.ad-flash{padding:12px 16px;border-radius:12px;margin:0 0 16px;font-weight:600}
.ad-flash.ok{background:#e3f3df;color:#1c5a1d}
.ad-flash.bad{background:#fde7e4;color:#8a2216}
.ad-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:10px}
.ad-card{background:#fff;border:1px solid #e1e8dd;border-radius:14px;padding:14px 16px}
.ad-card b{display:block;font-size:1.6rem;font-family:var(--display)}
.ad-card span{color:#5b6a5d;font-size:.85rem}
.ad-box{background:#fff;border:1px solid #e1e8dd;border-radius:14px;padding:18px 20px;margin-bottom:16px}
.ad-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e1e8dd;border-radius:14px;overflow:hidden;font-size:.92rem}
.ad-table th{background:#eef3eb;text-align:left;padding:9px 12px;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#4b5a4d}
.ad-table td{padding:9px 12px;border-top:1px solid #edf1ea;vertical-align:top}
.ad-table tr:hover td{background:#fafcf8}
.ad-table a{font-weight:600}
.ad-wrap{overflow-x:auto}
.ad-filter{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin-bottom:14px}
.ad-filter input,.ad-filter select{padding:8px 10px;border:1px solid #cfd9ca;border-radius:10px;font:inherit;background:#fff}
.ad-form{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px 16px}
.ad-form .full{grid-column:1/-1}
.ad-form label,.ad-lbl{display:block;font-weight:700;font-size:.85rem;margin-bottom:4px}
.ad-form input[type=text],.ad-form input[type=email],.ad-form input[type=number],.ad-form input[type=date],.ad-form input[type=datetime-local],.ad-form input[type=password],.ad-form input[type=url],.ad-form select,.ad-form textarea,.ad-in{width:100%;padding:9px 11px;border:1px solid #cfd9ca;border-radius:10px;font:inherit;background:#fff;box-sizing:border-box}
.ad-form textarea{min-height:120px}
.ad-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;align-items:center}
.ad .btn-sm{padding:7px 14px;font-size:.88rem}
.ad-tag{display:inline-block;padding:2px 9px;border-radius:999px;font-size:.78rem;font-weight:700;background:#eef1ec;color:#4b5a4d}
.ad-tag.ok{background:#e3f3df;color:#1c5a1d}
.ad-tag.wait{background:#fff3d6;color:#7a5a00}
.ad-tag.bad{background:#fde7e4;color:#8a2216}
.ad-muted{color:#6b786d;font-size:.88rem}
.ad-photos{display:flex;flex-wrap:wrap;gap:10px}
.ad-photo{width:150px;background:#fff;border:1px solid #e1e8dd;border-radius:12px;overflow:hidden}
.ad-photo img{width:150px;height:110px;object-fit:cover;display:block}
.ad-photo form{display:flex;justify-content:space-between;padding:4px}
.ad-photo button{background:none;border:0;cursor:pointer;font-size:.8rem;padding:4px 6px;border-radius:6px}
.ad-photo button:hover{background:#eef3eb}
.ad-hours{display:grid;grid-template-columns:60px 1fr 1fr auto;gap:6px 10px;align-items:center;max-width:520px}
.ad-pager{display:flex;gap:14px;align-items:center;margin-top:12px}
.ad-two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.ad-inline{display:inline}
.ad dl{display:grid;grid-template-columns:150px 1fr;gap:6px 12px;margin:0}
.ad dt{color:#5b6a5d;font-size:.88rem}
.ad dd{margin:0;font-weight:600}
@media (max-width:860px){
 .ad-shell{grid-template-columns:1fr}
 .ad-side{position:fixed;inset:0 auto 0 0;width:240px;z-index:20;transform:translateX(-100%);transition:transform .2s}
 .ad-side.open{transform:none}
 .ad-menu-btn{display:inline-block;margin-bottom:12px;background:#0f3d14;color:#fff;border:0;border-radius:10px;padding:8px 14px;font-weight:700}
 .ad-main{padding:16px}
 .ad-two{grid-template-columns:1fr}
 .ad dl{grid-template-columns:1fr}
}
CSS;
}
