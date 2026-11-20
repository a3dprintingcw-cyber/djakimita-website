<?php
// Staff backend: launch date, staff accounts, own password, activity log.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

admin_post(function () use ($db, $me) {
    switch ($_POST['action'] ?? '') {
        case 'launch':
            if (!empty($_POST['no_countdown'])) {
                set_setting('launch_at', '');
                admin_log('countdown_off');
                flash('Countdown switched off: the full website is open now.');
                break;
            }
            $d = (string) $_POST['launch_at'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $d)) {
                throw new RuntimeException('Pick a date and time.');
            }
            $v = str_replace('T', ' ', $d) . ':00';
            set_setting('launch_at', $v);
            admin_log('launch_date', $v);
            flash(strtotime($v) > time() ? 'Launch set to ' . admin_date($v, true) . '. The countdown runs until then.' : 'That moment has passed, so the full website is open.');
            break;
        case 'my_password':
            $st = $db->prepare('SELECT pass_hash FROM admin_users WHERE username = :u');
            $st->execute(['u' => $me]);
            $hash = $st->fetchColumn();
            $current = (string) $_POST['current'];
            $cfg = (array) config('admin_users', []);
            $okNow = password_verify($current, $hash ?: (string) ($cfg[$me] ?? ''));
            if (!$okNow) {
                throw new RuntimeException('Your current password is not right.');
            }
            $new = (string) $_POST['new'];
            if ($err = staff_password_ok($new)) {
                throw new RuntimeException($err);
            }
            if ($new !== (string) $_POST['again']) {
                throw new RuntimeException('The two new passwords are not the same.');
            }
            $h = password_hash($new, PASSWORD_DEFAULT);
            if ($hash) {
                $db->prepare('UPDATE admin_users SET pass_hash = :h WHERE username = :u')->execute(['h' => $h, 'u' => $me]);
            } else {
                // First account in the database: from now on staff logins come from here, not from the config file.
                $db->prepare('INSERT INTO admin_users (username, name, pass_hash, active, created_at) VALUES (:u, :n, :h, 1, NOW())')
                    ->execute(['u' => $me, 'n' => $me, 'h' => $h]);
            }
            admin_log('own_password_changed');
            flash('Your password is changed.');
            break;
        case 'staff_add':
            $u = strtolower(trim((string) $_POST['username']));
            if (!preg_match('/^[a-z0-9._-]{3,40}$/', $u)) {
                throw new RuntimeException('Username: 3 to 40 letters or numbers, no spaces.');
            }
            if ($err = staff_password_ok((string) $_POST['password'])) {
                throw new RuntimeException($err);
            }
            if ((int) $db->query('SELECT COUNT(*) FROM admin_users')->fetchColumn() === 0) {
                throw new RuntimeException('First change your own password above, so your own login moves over too.');
            }
            $db->prepare('INSERT INTO admin_users (username, name, pass_hash, active, created_at) VALUES (:u, :n, :h, 1, NOW())')
                ->execute(['u' => $u, 'n' => mb_substr(trim((string) $_POST['name']), 0, 100), 'h' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT)]);
            admin_log('staff_added', $u);
            flash('Staff account ' . $u . ' added. Give them the password in person, not by email.');
            break;
        case 'staff_toggle':
            $u = (string) $_POST['username'];
            if ($u === $me) {
                throw new RuntimeException('You cannot switch off your own account.');
            }
            $db->prepare('UPDATE admin_users SET active = 1 - active WHERE username = :u')->execute(['u' => $u]);
            admin_log('staff_toggled', $u);
            flash('Updated ' . $u . '.');
            break;
        case 'staff_password':
            $u = (string) $_POST['username'];
            if ($err = staff_password_ok((string) $_POST['password'])) {
                throw new RuntimeException($err);
            }
            $db->prepare('UPDATE admin_users SET pass_hash = :h WHERE username = :u')->execute(['h' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT), 'u' => $u]);
            admin_log('staff_password_reset', $u);
            flash('New password set for ' . $u . '.');
            break;
    }
}, '/admin/settings.php');

$launch = (string) setting('launch_at', (string) config('launch_at', ''));
$staff = staff_accounts();
$inDb = (bool) $staff;
$log = [];
try {
    $log = $db->query('SELECT * FROM admin_log ORDER BY id DESC LIMIT 60')->fetchAll();
} catch (Throwable $e) {
}

admin_head('Settings', 'settings');
?>
<h1>Settings</h1>

<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">Launch countdown</h2>
  <p class="full ad-muted">Until this moment, djakimita.com shows the countdown and only the partner list, checkout, login and legal pages are open. At this moment the full website opens by itself.</p>
  <div><label>Launch (Curaçao time)</label><input type="datetime-local" name="launch_at" value="<?= $launch ? e(date('Y-m-d\TH:i', strtotime($launch))) : '' ?>"></div>
  <label class="check full"><input type="checkbox" name="no_countdown" value="1"<?= $launch ? '' : ' checked' ?>> <span>No countdown: open the full website now</span></label>
  <div class="full ad-actions"><button class="btn btn-sm" name="action" value="launch" onclick="return document.querySelector('[name=no_countdown]').checked ? confirm('Open the full website right now?') : true">Save</button>
    <span class="ad-muted"><?= $launch && strtotime($launch) > time() ? 'Countdown is running.' : 'The full website is open.' ?></span></div>
</form>

<form method="post" class="ad-box ad-form" autocomplete="off"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">Your password</h2>
  <?php if (!$inDb): ?><p class="full ad-flash bad">You're still using the temporary login. Set a strong password now (at least 10 characters).</p><?php endif; ?>
  <div><label>Current password</label><input type="password" name="current" autocomplete="current-password" required></div>
  <div><label>New password (10+ characters)</label><input type="password" name="new" autocomplete="new-password" minlength="10" required></div>
  <div><label>New password again</label><input type="password" name="again" autocomplete="new-password" minlength="10" required></div>
  <div class="full ad-actions"><button class="btn btn-sm" name="action" value="my_password">Change my password</button></div>
</form>

<div class="ad-box">
  <h2 style="margin-top:0">Staff accounts</h2>
  <?php if ($staff): ?>
    <div class="ad-wrap"><table class="ad-table">
      <thead><tr><th>Username</th><th>Name</th><th>Last login</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($staff as $s): ?>
        <tr><td><strong><?= e($s['username']) ?></strong></td><td><?= e($s['name']) ?></td><td><?= e(admin_date($s['last_login'], true)) ?></td>
          <td><?= (int) $s['active'] ? '<span class="ad-tag ok">Active</span>' : '<span class="ad-tag">Off</span>' ?></td>
          <td><?php if ($s['username'] !== $me): ?>
            <form method="post" class="ad-inline"><?= csrf_field() ?><input type="hidden" name="username" value="<?= e($s['username']) ?>"><button class="btn btn-sm btn-outline" name="action" value="staff_toggle"><?= (int) $s['active'] ? 'Switch off' : 'Switch on' ?></button></form>
            <details class="ad-inline"><summary class="btn btn-sm btn-outline">New password</summary>
              <form method="post" class="ad-actions"><?= csrf_field() ?><input type="hidden" name="username" value="<?= e($s['username']) ?>">
                <input class="ad-in" type="text" name="password" minlength="10" placeholder="10+ characters" autocomplete="off" style="width:200px"><button class="btn btn-sm" name="action" value="staff_password">Set</button></form></details>
          <?php endif; ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php else: ?>
    <p class="ad-muted">Change your own password first. Then you can add staff here.</p>
  <?php endif; ?>
  <?php if ($inDb): ?>
    <details style="margin-top:14px"><summary class="btn btn-sm">+ Add staff</summary>
      <form method="post" class="ad-form" style="margin-top:12px" autocomplete="off"><?= csrf_field() ?>
        <div><label>Username</label><input type="text" name="username" required></div>
        <div><label>Name</label><input type="text" name="name"></div>
        <div><label>Password (10+ characters)</label><input type="text" name="password" minlength="10" autocomplete="off" required></div>
        <div class="full"><button class="btn btn-sm" name="action" value="staff_add">Add</button></div>
      </form></details>
  <?php endif; ?>
</div>

<div class="ad-box">
  <h2 style="margin-top:0">Who did what</h2>
  <div class="ad-wrap"><table class="ad-table">
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>Details</th></tr></thead>
    <tbody><?php foreach ($log as $l): ?>
      <tr><td><?= e(admin_date($l['created_at'], true)) ?></td><td><?= e($l['username']) ?></td><td><?= e(str_replace('_', ' ', $l['action'])) ?></td><td class="ad-muted"><?= e($l['detail']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$log): ?><tr><td colspan="4" class="ad-muted">Nothing yet.</td></tr><?php endif; ?></tbody>
  </table></div>
</div>
<?php admin_foot();
