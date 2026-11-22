<?php
// Staff backend: login and overview.
require __DIR__ . '/../../app/bootstrap.php';
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $msg = 'Session expired, please try again.';
    } elseif (($_POST['action'] ?? '') === 'login') {
        $u = trim((string) ($_POST['user'] ?? ''));
        if (admin_login($u, (string) ($_POST['pass'] ?? ''))) {
            admin_log('login');
            header('Location: /admin/');
            exit;
        }
        $msg = 'Wrong username or password. After 5 wrong tries, logging in is blocked for 30 minutes.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        unset($_SESSION['admin']);
        session_regenerate_id(true);
        header('Location: /admin/');
        exit;
    }
}

if (!admin_user()) {
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Staff login · Dj'aki mi ta!</title>
  <link rel="icon" href="/assets/img/logo-mark.png">
  <link rel="stylesheet" href="/assets/css/site.css">
  <style>
    body { background: #0f3d14; min-height: 100vh; display: grid; place-items: center; margin: 0; }
    .lg { background: #fff; border-radius: 20px; padding: 30px 28px; width: min(380px, 92vw); box-shadow: 0 20px 60px rgba(0,0,0,.3); }
    .lg img { display: block; margin: 0 auto 10px; }
    .lg h1 { text-align: center; font-size: 1.4rem; margin: 0 0 18px; }
    .lg .err { background: #fde7e4; color: #8a2216; padding: 10px 12px; border-radius: 10px; font-size: .9rem; }
  </style>
</head>
<body>
  <form method="post" class="lg">
    <img src="/assets/img/logo-mark.png" alt="" width="64" height="64">
    <h1>Dj'aki mi ta! admin</h1>
    <?php if ($msg): ?><p class="err"><?= e($msg) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="login">
    <div class="field"><label for="u">Username</label><input id="u" name="user" autocomplete="username" required autofocus></div>
    <div class="field"><label for="p">Password</label><input id="p" name="pass" type="password" autocomplete="current-password" required></div>
    <button class="btn btn-block">Log in</button>
  </form>
</body>
</html>
    <?php
    exit;
}

$me = admin_page();
$db = db();

$paid = $db->query("SELECT LOWER(currency) cur, COUNT(DISTINCT token) orders FROM sale_receits WHERE status = 1 AND dia_orario >= CURDATE() - INTERVAL 30 DAY GROUP BY LOWER(currency)")->fetchAll();
$today = (int) $db->query('SELECT COUNT(DISTINCT token) FROM sale_receits WHERE status = 1 AND DATE(dia_orario) = CURDATE()')->fetchColumn();
$week = (int) $db->query('SELECT COUNT(DISTINCT token) FROM sale_receits WHERE status = 1 AND dia_orario >= CURDATE() - INTERVAL 7 DAY')->fetchColumn();
$activeCards = (int) $db->query('SELECT COUNT(*) FROM clients_package WHERE expire >= CURDATE()')->fetchColumn();
$customers = (int) $db->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$partners = (int) $db->query('SELECT COUNT(*) FROM voucher_company WHERE active = 1 AND country = 1')->fetchColumn();
$checks = (int) $db->query('SELECT COUNT(*) FROM holder_verifications WHERE status = 0')->fetchColumn();
$apps = (int) $db->query('SELECT COUNT(*) FROM partner_applications WHERE status = 0')->fetchColumn();
$unpaid = (int) $db->query('SELECT COUNT(DISTINCT token) FROM sale_receits WHERE status = 0 AND dia_orario >= NOW() - INTERVAL 2 DAY')->fetchColumn();

// Money in the last 30 days, per currency (from the card prices of paid orders)
$money = $db->query("SELECT LOWER(s.currency) cur,
        SUM(CASE WHEN LOWER(s.currency) = 'xcg' THEN COALESCE(l.xcg, 0) WHEN LOWER(s.currency) = 'usd' THEN COALESCE(l.usd, p.dollars, 0) ELSE COALESCE(l.eur, p.eur, 0) END) total
    FROM sale_receits s
    LEFT JOIN sale_lengths sl ON sl.sale_id = s.id
    LEFT JOIN card_lengths l ON l.id = sl.length_id
    LEFT JOIN website_product_prices p ON p.product_id = s.package AND sl.sale_id IS NULL
    WHERE s.status = 1 AND s.dia_orario >= CURDATE() - INTERVAL 30 DAY
    GROUP BY LOWER(s.currency)")->fetchAll();

$recent = $db->query("SELECT s.token, MIN(s.id) id, MIN(s.email) email, MIN(s.currency) currency, MAX(s.status) status, MIN(s.dia_orario) d,
        GROUP_CONCAT(s.package ORDER BY s.id) packages
    FROM sale_receits s WHERE s.token IS NOT NULL AND s.token <> ''
    GROUP BY s.token ORDER BY MIN(s.id) DESC LIMIT 10")->fetchAll();

$launch = (string) setting('launch_at', (string) config('launch_at', ''));

admin_head('Overview', 'index');
?>
<h1>Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e($me) ?></h1>
<?php if (before_launch()): ?>
  <p class="ad-actions"><a class="btn btn-sm" href="/?preview=1" target="_blank" rel="noopener">See the website as it will look on launch day ↗</a>
    <span class="ad-muted">Only you see it (while logged in here). Visitors keep seeing the countdown.</span></p>
<?php endif; ?>

<?php if ($checks || $apps): ?>
  <p class="ad-flash ok"><a href="/admin/checks.php"><?= $checks ?> account check<?= $checks === 1 ? '' : 's' ?> and <?= $apps ?> partner application<?= $apps === 1 ? '' : 's' ?> waiting →</a></p>
<?php endif; ?>

<div class="ad-cards">
  <a class="ad-card" href="/admin/orders.php?status=paid&amp;from=<?= date('Y-m-d') ?>"><b><?= $today ?></b><span>Paid orders today</span></a>
  <a class="ad-card" href="/admin/orders.php?status=paid&amp;from=<?= date('Y-m-d', strtotime('-7 days')) ?>"><b><?= $week ?></b><span>Paid orders, last 7 days</span></a>
  <?php foreach ($money as $m): ?>
    <a class="ad-card" href="/admin/orders.php?status=paid&amp;from=<?= date('Y-m-d', strtotime('-30 days')) ?>"><b><?= e(admin_money((float) $m['total'], $m['cur'] ?: 'eur')) ?></b><span>Sales last 30 days<?= $m['cur'] ? '' : ' (old orders)' ?></span></a>
  <?php endforeach; ?>
  <a class="ad-card" href="/admin/customers.php?only=active"><b><?= $activeCards ?></b><span>Active accounts</span></a>
  <a class="ad-card" href="/admin/customers.php"><b><?= $customers ?></b><span>Customer accounts</span></a>
  <a class="ad-card" href="/admin/partners.php?country=1&amp;show=on"><b><?= $partners ?></b><span>Active partners (Curaçao)</span></a>
  <a class="ad-card" href="/admin/orders.php?status=open&amp;from=<?= date('Y-m-d', strtotime('-2 days')) ?>"><b><?= $unpaid ?></b><span>Started but not paid (48 h)</span></a>
</div>

<?php if ($launch && strtotime($launch) > time()): ?>
  <p class="ad-muted">The website shows the countdown until <strong><?= e(admin_date($launch, true)) ?></strong>. Change it under <a href="/admin/settings.php">Settings</a>.</p>
<?php endif; ?>

<h2>Latest orders</h2>
<div class="ad-wrap">
<table class="ad-table">
  <thead><tr><th>Order</th><th>Date</th><th>Email</th><th>Accounts</th><th>Paid with</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $o): ?>
    <tr>
      <td><a href="/admin/orders.php?id=<?= (int) $o['id'] ?>">#<?= (int) $o['id'] ?></a></td>
      <td><?= e(admin_date($o['d'], true)) ?></td>
      <td><?= e($o['email']) ?></td>
      <td><?= e(implode(', ', array_map(fn($p) => card_name((int) $p), explode(',', (string) $o['packages'])))) ?></td>
      <td><?= e(str_starts_with((string) $o['token'], 'sentoo:') ? 'Sentoo' : (str_starts_with((string) $o['token'], 'tr_') ? 'Mollie' : 'Stripe (old)')) ?> · <?= e(strtoupper($o['currency'] ?: 'eur')) ?></td>
      <td><?= (int) $o['status'] === 1 ? '<span class="ad-tag ok">Paid</span>' : '<span class="ad-tag wait">Not paid</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p><a href="/admin/orders.php">All orders →</a></p>
<?php admin_foot();
