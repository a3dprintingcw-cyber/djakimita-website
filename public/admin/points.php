<?php
// Staff backend: points. How many points customers earn, the rewards they can get, and the codes.
require __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/points.php';
$me = admin_page();
$db = db();
const REWARD_ICONS = ['gift' => 'Gift', 'coffee' => 'Coffee', 'glass-cocktail' => 'Drink', 'food' => 'Food', 'ice-cream' => 'Ice cream',
                      'tshirt-crew' => 'T-shirt', 'ticket-percent' => 'Discount', 'calendar-plus' => 'Extra time', 'star' => 'Star'];

admin_post(function () use ($db) {
    $action = $_POST['action'] ?? '';
    $int = fn ($k, $min, $max) => max($min, min($max, (int) ($_POST[$k] ?? 0)));
    switch ($action) {
        case 'rules':
            $wasOn = pts_on($db);
            $on = !empty($_POST['points_on']);
            set_setting('points_on', $on ? '1' : '0');
            if ($on && !$wasOn && (string) setting('points_start', '') === '') {
                set_setting('points_start', date('Y-m-d'));
            }
            foreach (['pts_visit' => 1000, 'pts_visit_day_max' => 20, 'pts_purchase_month' => 1000, 'pts_invite' => 10000,
                      'pts_invited' => 10000, 'invite_hold_days' => 60, 'invite_year_max' => 100, 'pts_birthday' => 10000] as $k => $max) {
                set_setting($k, (string) $int($k, 0, $max));
            }
            admin_log('points_rules', $on ? 'on' : 'off');
            flash($on ? 'Saved. Points are on.' : 'Saved. Points are off: the app shows "Coming soon".');
            break;
        case 'shop':
            set_setting('pts_buy_on', empty($_POST['pts_buy_on']) ? '0' : '1');
            set_setting('pts_extra_scan', (string) $int('pts_extra_scan', 1, 100000));
            set_setting('extra_scan_count', (string) $int('extra_scan_count', 1, 50));
            $packs = [];
            foreach ((array) ($_POST['pack_points'] ?? []) as $i => $pp) {
                $pp = (int) $pp;
                if ($pp <= 0) {
                    continue;
                }
                $num = fn ($k) => max(0, round((float) str_replace(',', '.', (string) ($_POST[$k][$i] ?? 0)), 2));
                $packs[] = ['points' => $pp, 'xcg' => $num('pack_xcg'), 'usd' => $num('pack_usd'), 'eur' => $num('pack_eur')];
            }
            set_setting('points_packs', json_encode($packs));
            admin_log('points_shop_saved');
            flash('Saved.');
            break;
        case 'reward_add':
        case 'reward_save':
            $title = mb_substr(trim((string) $_POST['title']), 0, 120);
            $pts = $int('points', 0, 1000000);
            if ($title === '' || $pts < 1) {
                throw new RuntimeException('Fill in a name and how many points it costs.');
            }
            $partner = (int) ($_POST['partner_id'] ?? 0) ?: null;
            $stock = trim((string) ($_POST['stock'] ?? '')) === '' ? null : $int('stock', 0, 100000);
            $icon = array_key_exists((string) $_POST['icon'], REWARD_ICONS) ? (string) $_POST['icon'] : 'gift';
            $vals = ['t' => $title, 'd' => mb_substr(trim((string) $_POST['description']), 0, 400), 'p' => $partner, 'pt' => $pts,
                     'c' => (int) $_POST['country'] === 2 ? 2 : 1, 'i' => $icon, 'm' => $int('per_month', 0, 100), 's' => $stock];
            if ($action === 'reward_add') {
                $db->prepare('INSERT INTO rewards (title, description, partner_id, points, country, icon, per_month, stock, active, sort, created_at)
                              VALUES (:t, :d, :p, :pt, :c, :i, :m, :s, 1, 0, NOW())')->execute($vals);
                admin_log('reward_added', $title . ' · ' . $pts . ' points');
                flash('Reward added. It shows in the app right away.');
            } else {
                $vals['id'] = (int) $_POST['id'];
                $db->prepare('UPDATE rewards SET title = :t, description = :d, partner_id = :p, points = :pt, country = :c, icon = :i, per_month = :m, stock = :s WHERE id = :id')->execute($vals);
                admin_log('reward_saved', '#' . $vals['id']);
                flash('Saved.');
            }
            break;
        case 'reward_toggle':
            $db->prepare('UPDATE rewards SET active = 1 - active WHERE id = :i')->execute(['i' => (int) $_POST['id']]);
            admin_log('reward_toggled', '#' . (int) $_POST['id']);
            flash('Updated.');
            break;
        case 'claim_used':
            $db->prepare("UPDATE reward_claims SET used_at = NOW(), used_where = 'Dj''aki mi ta! office' WHERE id = :i AND used_at IS NULL AND cancelled_at IS NULL")
                ->execute(['i' => (int) $_POST['id']]);
            admin_log('reward_code_used', '#' . (int) $_POST['id']);
            flash('Marked as given.');
            break;
        case 'claim_cancel':
            $ok = pts_cancel_claim($db, (int) $_POST['id']);
            admin_log('reward_code_cancelled', '#' . (int) $_POST['id']);
            flash($ok ? 'Code cancelled. The customer got the points back.' : 'This code was already used or cancelled.');
            break;
    }
}, '/admin/points.php' . (isset($_GET['code']) ? '?code=' . urlencode((string) $_GET['code']) : ''));

$rules = pts_rules($db);
$on = pts_on($db);
$partners = $db->query('SELECT id, TRIM(name) AS name, country FROM voucher_company WHERE active = 1 ORDER BY name')->fetchAll();
$rewards = $db->query('SELECT r.*, TRIM(v.name) AS partner,
                              (SELECT COUNT(*) FROM reward_claims c WHERE c.reward_id = r.id AND c.cancelled_at IS NULL) AS claimed,
                              (SELECT COUNT(*) FROM reward_claims c WHERE c.reward_id = r.id AND c.used_at IS NOT NULL) AS used
                       FROM rewards r LEFT JOIN voucher_company v ON v.id = r.partner_id ORDER BY r.active DESC, r.country, r.points')->fetchAll();
$find = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['code'] ?? '')));
$claims = $db->prepare("SELECT c.*, r.title, TRIM(v.name) AS partner, TRIM(cl.username) AS first, TRIM(cl.lastName) AS last
                        FROM reward_claims c JOIN rewards r ON r.id = c.reward_id LEFT JOIN voucher_company v ON v.id = r.partner_id
                        JOIN clients cl ON cl.id = c.client_id " . ($find !== '' ? 'WHERE c.code = :code ' : '') . 'ORDER BY c.id DESC LIMIT 100');
$claims->execute($find !== '' ? ['code' => $find] : []);
$claims = $claims->fetchAll();
$tot = $db->query("SELECT COALESCE(SUM(CASE WHEN points > 0 AND kind NOT IN ('refund') THEN points END), 0) AS given,
                          COALESCE(-SUM(CASE WHEN kind = 'redeem' THEN points END), 0) AS spent FROM points_ledger")->fetch();
$top = $db->query("SELECT l.client_id, TRIM(c.username) AS first, TRIM(c.lastName) AS last, SUM(l.points) AS bal FROM points_ledger l
                   JOIN clients c ON c.id = l.client_id WHERE l.available_at <= NOW() GROUP BY l.client_id HAVING bal > 0 ORDER BY bal DESC LIMIT 10")->fetchAll();

$icons = REWARD_ICONS;
$rewardForm = function (?array $r) use ($partners, $icons) {
    $v = fn ($k, $d = '') => e((string) ($r[$k] ?? $d)); ?>
  <div class="full"><label>Reward (what the customer gets)</label><input type="text" name="title" required maxlength="120" value="<?= $v('title') ?>" placeholder="e.g. Free coffee, Dj'aki mi ta! t-shirt, 1 month extra"></div>
  <div class="full"><label>Short explanation (optional)</label><input type="text" name="description" maxlength="400" value="<?= $v('description') ?>" placeholder="e.g. Any size, at the counter"></div>
  <div><label>Where they get it</label><select name="partner_id"><option value="0">At Dj'aki mi ta! (staff mark it as given here)</option>
    <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) ($r['partner_id'] ?? 0) === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Costs (points)</label><input type="number" name="points" min="1" required value="<?= $v('points') ?>"></div>
  <div><label>App</label><select name="country"><option value="1">Dj'aki mi ta! (Curaçao)</option><option value="2"<?= (int) ($r['country'] ?? 1) === 2 ? ' selected' : '' ?>>Rib'e lugá (Netherlands)</option></select></div>
  <div><label>Max per customer per 30 days (0 = no limit)</label><input type="number" name="per_month" min="0" value="<?= $v('per_month', '1') ?>"></div>
  <div><label>How many available (empty = no limit)</label><input type="number" name="stock" min="0" value="<?= $v('stock') ?>"></div>
  <div><label>Icon</label><select name="icon"><?php foreach ($icons as $k => $l): ?><option value="<?= e($k) ?>"<?= ($r['icon'] ?? 'gift') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
<?php };

admin_head('Points', 'points');
?>
<h1>Points</h1>
<p class="ad-muted">Customers earn points and swap them for rewards in the app (tab Rewards). You decide how many points they earn and what each reward costs. As a guide: 1 point is worth about XCG 0.05 if a free coffee (about XCG 5) costs 100 points.</p>

<div class="ad-cards">
  <div class="ad-card"><span>Status</span><b><?= $on ? 'On' : 'Off' ?></b><?php if ($on): ?><span> since <?= e(date('d-m-Y', strtotime($rules['points_start']))) ?></span><?php endif; ?></div>
  <div class="ad-card"><span>Points given out</span><b><?= number_format((int) $tot['given'], 0, ',', '.') ?></b></div>
  <div class="ad-card"><span>Points spent on rewards</span><b><?= number_format((int) $tot['spent'], 0, ',', '.') ?></b></div>
</div>

<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">How customers earn points</h2>
  <label class="check full"><input type="checkbox" name="points_on" value="1"<?= $on ? ' checked' : '' ?>> <span><strong>Points are on.</strong> Only things from the day you switch it on count.</span></label>
  <div><label>Points per visit at a partner</label><input type="number" name="pts_visit" min="0" value="<?= $rules['pts_visit'] ?>"></div>
  <div><label>Max visits with points per day</label><input type="number" name="pts_visit_day_max" min="0" value="<?= $rules['pts_visit_day_max'] ?>"></div>
  <div><label>Points for buying an account, per month of the account</label><input type="number" name="pts_purchase_month" min="0" value="<?= $rules['pts_purchase_month'] ?>">
    <small class="ad-muted">With <?= $rules['pts_purchase_month'] ?>: 10 days = <?= (int) round($rules['pts_purchase_month'] / 2) ?>, 1 month = <?= $rules['pts_purchase_month'] ?>, 1 year = <?= $rules['pts_purchase_month'] * 12 ?> points. Free accounts get nothing.</small></div>
  <div><label>Points on their birthday</label><input type="number" name="pts_birthday" min="0" value="<?= $rules['pts_birthday'] ?>"></div>
  <div><label>Invite a friend: points for the customer who invites</label><input type="number" name="pts_invite" min="0" value="<?= $rules['pts_invite'] ?>"></div>
  <div><label>Invite a friend: points for the new friend</label><input type="number" name="pts_invited" min="0" value="<?= $rules['pts_invited'] ?>"></div>
  <div><label>Invite points arrive after (days, refund window)</label><input type="number" name="invite_hold_days" min="0" value="<?= $rules['invite_hold_days'] ?>"></div>
  <div><label>Max invites with points per customer per year</label><input type="number" name="invite_year_max" min="0" value="<?= $rules['invite_year_max'] ?>"></div>
  <div class="full ad-muted" style="font-size:14px">
    <strong>Protection against cheating:</strong> a visit only gives points once per partner per day, and only up to the daily max.
    Points for an account only when it is paid, and they are taken back if the order is refunded.
    Invites: the friend must be new (first account), not the same person, email or phone, and must have paid; points arrive after the waiting days.
    Birthday: once a year, only with an account bought at least 30 days before.
    Points can only be seen and spent on the customer's own logged-in phone, and only with a valid account. Every point is counted once.
  </div>
  <div class="full"><button class="btn btn-sm" name="action" value="rules">Save</button></div>
</form>

<?php $packs = pts_packs($db); while (count($packs) < 4) { $packs[] = ['points' => '', 'xcg' => '', 'usd' => '', 'eur' => '']; } ?>
<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">Buy points and extra visits</h2>
  <div><label>Extra visit at a partner costs (points)</label><input type="number" name="pts_extra_scan" min="1" value="<?= $rules['pts_extra_scan'] ?>"></div>
  <div><label>Extra visits you get for that</label><input type="number" name="extra_scan_count" min="1" value="<?= $rules['extra_scan_count'] ?>"></div>
  <p class="full ad-muted" style="margin-top:0">When a customer has used all visits at a partner, the app offers extra visits for points. They can only buy when they have none left there.</p>
  <label class="check full"><input type="checkbox" name="pts_buy_on" value="1"<?= $rules['pts_buy_on'] ? ' checked' : '' ?>> <span><strong>Customers can buy points with money</strong> in the app (Rewards → Shop). Paid with Sentoo (XCG) or Mollie (card), like accounts. Points are added once the payment is confirmed; bought points don't count for levels.</span></label>
  <div class="full ad-wrap"><table class="ad-table"><thead><tr><th>Points</th><th>Price XCG (Sentoo)</th><th>Price $ (Mollie, Curaçao without Sentoo)</th><th>Price € (Rib'e lugá)</th></tr></thead><tbody>
  <?php foreach ($packs as $pk): ?><tr>
    <td><input type="number" name="pack_points[]" min="0" value="<?= e((string) $pk['points']) ?>" style="max-width:110px"></td>
    <td><input type="text" name="pack_xcg[]" value="<?= e((string) $pk['xcg']) ?>" style="max-width:110px"></td>
    <td><input type="text" name="pack_usd[]" value="<?= e((string) $pk['usd']) ?>" style="max-width:110px"></td>
    <td><input type="text" name="pack_eur[]" value="<?= e((string) $pk['eur']) ?>" style="max-width:110px"></td></tr><?php endforeach; ?>
  </tbody></table><small class="ad-muted">Leave Points empty to remove a pack. Tip: keep points a bit more expensive than what the rewards are worth.</small></div>
  <div class="full"><button class="btn btn-sm" name="action" value="shop">Save</button></div>
</form>

<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">New reward</h2>
  <?php $rewardForm(null); ?>
  <div class="full"><button class="btn btn-sm" name="action" value="reward_add">Add reward</button></div>
</form>

<h2>Rewards</h2>
<?php if (!$rewards): ?><p class="ad-muted">No rewards yet. Add one above, for example a free coffee at a partner for 100 points, or a Dj'aki mi ta! t-shirt for 500 points.</p><?php endif; ?>
<?php foreach ($rewards as $r): ?>
  <details class="ad-box" style="padding:12px 16px">
    <summary style="cursor:pointer"><strong><?= e($r['title']) ?></strong> · <?= (int) $r['points'] ?> points · <?= e($r['partner'] ?: "at Dj'aki mi ta!") ?> · <?= (int) $r['country'] === 2 ? "Rib'e lugá" : "Dj'aki mi ta!" ?>
      · <?= (int) $r['claimed'] ?> taken, <?= (int) $r['used'] ?> given<?= $r['stock'] !== null ? ' · ' . (int) $r['stock'] . ' left' : '' ?> · <?= (int) $r['active'] ? '<span style="color:#1c5a1d;font-weight:600">On</span>' : '<span class="ad-muted">Off</span>' ?></summary>
    <form method="post" class="ad-form" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
      <?php $rewardForm($r); ?>
      <div class="full ad-actions"><button class="btn btn-sm" name="action" value="reward_save">Save</button>
        <button class="btn btn-sm btn-outline" name="action" value="reward_toggle"><?= (int) $r['active'] ? 'Switch off' : 'Switch on' ?></button></div>
    </form>
  </details>
<?php endforeach; ?>

<h2>Reward codes</h2>
<form method="get" class="ad-actions" style="margin-bottom:10px"><input type="text" name="code" value="<?= e($find) ?>" placeholder="Find a code" style="text-transform:uppercase;max-width:220px"><button class="btn btn-sm btn-outline">Find</button><?php if ($find !== ''): ?><a class="btn btn-sm btn-outline" href="/admin/points.php">All codes</a><?php endif; ?></form>
<p class="ad-muted">Partners check codes for their own rewards in their partner page. Rewards at Dj'aki mi ta! you mark as given here. A code works 30 days; unused codes give the points back by themselves.</p>
<div class="ad-wrap"><table class="ad-table"><thead><tr><th>Code</th><th>Reward</th><th>Customer</th><th>Taken</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($claims as $c): $stt = pts_claim_state($c); ?>
  <tr><td><strong><?= e($c['code']) ?></strong></td><td><?= e($c['title']) ?><br><small class="ad-muted"><?= e($c['partner'] ?: "at Dj'aki mi ta!") ?> · <?= (int) $c['points'] ?> points</small></td>
    <td><a href="/admin/customers.php?id=<?= (int) $c['client_id'] ?>"><?= e(trim($c['first'] . ' ' . $c['last'])) ?></a></td>
    <td><?= e(date('d-m-Y', strtotime($c['created_at']))) ?></td>
    <td><?= ['open' => 'Open until ' . date('d-m-Y', strtotime($c['expires_at'])), 'used' => 'Given ' . date('d-m-Y', strtotime((string) $c['used_at'])) . ($c['used_where'] ? ' at ' . $c['used_where'] : ''), 'cancelled' => 'Cancelled, points back', 'expired' => 'Not used, points back'][$stt] ?></td>
    <td><?php if ($stt === 'open'): ?><form method="post" class="ad-actions"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <button class="btn btn-sm" name="action" value="claim_used">Mark as given</button>
      <button class="btn btn-sm btn-outline" name="action" value="claim_cancel" onclick="return confirm('Cancel this code and give the points back?')">Cancel</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$claims): ?><tr><td colspan="6" class="ad-muted"><?= $find !== '' ? 'No code ' . e($find) . '.' : 'No codes yet.' ?></td></tr><?php endif; ?>
</tbody></table></div>

<?php $po = $db->query('SELECT o.*, TRIM(c.username) AS first, TRIM(c.lastName) AS last FROM points_orders o JOIN clients c ON c.id = o.client_id WHERE o.status = 1 ORDER BY o.id DESC LIMIT 30')->fetchAll(); if ($po): ?>
<h2>Points bought</h2>
<div class="ad-wrap"><table class="ad-table"><thead><tr><th>Date</th><th>Customer</th><th>Points</th><th>Paid</th></tr></thead><tbody>
<?php foreach ($po as $o): ?><tr><td><?= e(date('d-m-Y H:i', strtotime((string) $o['paid_at']))) ?></td><td><a href="/admin/customers.php?id=<?= (int) $o['client_id'] ?>"><?= e(trim($o['first'] . ' ' . $o['last'])) ?></a></td><td><?= (int) $o['points'] ?></td><td><?= e(strtoupper($o['currency']) . ' ' . number_format((float) $o['amount'], 2)) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<?php if ($top): ?>
<h2>Most points</h2>
<div class="ad-wrap"><table class="ad-table"><thead><tr><th>Customer</th><th>Points</th></tr></thead><tbody>
<?php foreach ($top as $t): ?><tr><td><a href="/admin/customers.php?id=<?= (int) $t['client_id'] ?>"><?= e(trim($t['first'] . ' ' . $t['last'])) ?></a></td><td><?= number_format((int) $t['bal'], 0, ',', '.') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php admin_foot();
