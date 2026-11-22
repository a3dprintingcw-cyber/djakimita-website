<?php
// Staff backend: launch date, staff accounts, own password, activity log.
require __DIR__ . '/../../app/bootstrap.php';
$me = admin_page();
$db = db();

admin_post(function () use ($db, $me) {
    switch ($_POST['action'] ?? '') {
        case 'notify':
            $list = array_filter(array_map('trim', explode(',', (string) $_POST['notify_email'])));
            foreach ($list as $m) {
                if (!filter_var($m, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('"' . $m . '" is not a valid email address.');
                }
            }
            set_setting('notify_email', implode(', ', $list));
            foreach (['notify_orders', 'notify_esim', 'notify_checks', 'notify_contact', 'notify_daily', 'birthday_on'] as $k) {
                set_setting($k, empty($_POST[$k]) ? '0' : '1');
            }
            set_setting('birthday_text', mb_substr(trim((string) $_POST['birthday_text']), 0, 1000));
            admin_log('notify_settings', implode(', ', $list));
            flash('Saved.');
            break;
        case 'company':
            set_setting('company_name', mb_substr(trim((string) $_POST['company_name']), 0, 150));
            set_setting('company_kvk', mb_substr(trim((string) $_POST['company_kvk']), 0, 40));
            set_setting('company_address', mb_substr(trim((string) $_POST['company_address']), 0, 250));
            admin_log('company_details');
            flash('Company details saved. They show at the bottom of every page and in the terms.');
            break;
        case 'jingle':
            set_setting('jingle_on', empty($_POST['jingle_on']) ? '0' : '1');
            if (!empty($_FILES['jingle']['name'])) {
                $f = $_FILES['jingle'];
                if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 3 * 1024 * 1024) {
                    throw new RuntimeException('Upload failed. The sound file can be at most 3 MB.');
                }
                $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                $ext = ['audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'video/mp4' => 'm4a', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav'][$mime] ?? null;
                if (!$ext) {
                    throw new RuntimeException('Use an MP3, M4A, OGG or WAV file.');
                }
                $dir = public_dir() . '/assets/media';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                foreach (['mp3', 'm4a', 'ogg', 'wav'] as $old) {
                    @unlink($dir . '/jingle.' . $old);
                }
                if (!move_uploaded_file($f['tmp_name'], $dir . '/jingle.' . $ext)) {
                    throw new RuntimeException('Could not save the file.');
                }
                @chmod($dir . '/jingle.' . $ext, 0644);
            }
            if (!empty($_POST['jingle_remove'])) {
                foreach (['mp3', 'm4a', 'ogg', 'wav'] as $old) {
                    @unlink(public_dir() . '/assets/media/jingle.' . $old);
                }
                set_setting('jingle_on', '0');
            }
            admin_log('jingle', setting('jingle_on', '0') === '1' ? 'on' : 'off');
            flash('Saved.');
            break;
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
            $role = ($_POST['role'] ?? '') === 'inspector' ? 'inspector' : 'admin';
            $db->prepare('INSERT INTO admin_users (username, name, role, pass_hash, active, created_at) VALUES (:u, :n, :r, :h, 1, NOW())')
                ->execute(['u' => $u, 'n' => mb_substr(trim((string) $_POST['name']), 0, 100), 'r' => $role, 'h' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT)]);
            admin_log('staff_added', $u . ' (' . $role . ')');
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
        case 'staff_role':
            $u = (string) $_POST['username'];
            if ($u === $me) {
                throw new RuntimeException('You cannot change your own role.');
            }
            $role = ($_POST['role'] ?? '') === 'inspector' ? 'inspector' : 'admin';
            $db->prepare('UPDATE admin_users SET role = :r WHERE username = :u')->execute(['r' => $role, 'u' => $u]);
            admin_log('staff_role', $u . ' → ' . $role);
            flash($u . ' is now: ' . STAFF_ROLES[$role] . '.');
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

<?php $ci = company_info(); ?>
<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">Emails to the team</h2>
  <div class="full"><label>Send to (more addresses: separate with a comma)</label><input type="text" name="notify_email" value="<?= e(implode(', ', staff_emails())) ?>"></div>
  <label class="check full"><input type="checkbox" name="notify_orders" value="1"<?= setting('notify_orders', '1') === '1' ? ' checked' : '' ?>> <span>Email me when an account is bought</span></label>
  <label class="check full"><input type="checkbox" name="notify_esim" value="1"<?= setting('notify_esim', '1') === '1' ? ' checked' : '' ?>> <span>Email me when an eSIM is bought</span></label>
  <label class="check full"><input type="checkbox" name="notify_checks" value="1"<?= setting('notify_checks', '1') === '1' ? ' checked' : '' ?>> <span>Email me when someone sends photos for the account check</span></label>
  <label class="check full"><input type="checkbox" name="notify_contact" value="1"<?= setting('notify_contact', '1') === '1' ? ' checked' : '' ?>> <span>Email me when someone uses "Send us a message" on the website</span></label>
  <label class="check full"><input type="checkbox" name="notify_daily" value="1"<?= setting('notify_daily', '1') === '1' ? ' checked' : '' ?>> <span>Every morning: summary of yesterday (sales, which partners were visited, app views)</span></label>
  <h2 class="full">Birthday message</h2>
  <label class="check full"><input type="checkbox" name="birthday_on" value="1"<?= setting('birthday_on', '1') === '1' ? ' checked' : '' ?>> <span>Send account holders an email on their birthday (only people with an active account)</span></label>
  <div class="full"><label>Message (leave empty for the standard text)</label><textarea name="birthday_text" rows="3" placeholder="Today is your day! The whole Dj'aki mi ta! team wishes you a happy birthday."><?= e((string) setting('birthday_text', '')) ?></textarea></div>
  <div class="full ad-actions"><button class="btn btn-sm" name="action" value="notify">Save</button></div>
</form>

<form method="post" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">Company details</h2>
  <p class="full ad-muted">Shown at the bottom of every page and in the terms and privacy pages.</p>
  <div><label>Legal company name</label><input type="text" name="company_name" value="<?= e($ci['name']) ?>"></div>
  <div><label>Chamber of Commerce (KvK) number</label><input type="text" name="company_kvk" value="<?= e($ci['kvk']) ?>"></div>
  <div class="full"><label>Address</label><input type="text" name="company_address" value="<?= e($ci['address']) ?>"></div>
  <div class="full ad-actions"><button class="btn btn-sm" name="action" value="company">Save</button></div>
</form>

<form method="post" enctype="multipart/form-data" class="ad-box ad-form"><?= csrf_field() ?>
  <h2 class="full" style="margin-top:0">Jingle</h2>
  <p class="full ad-muted">Plays once when someone opens the website. Phones and browsers only allow sound after the visitor taps or clicks once, so it starts at their first tap.</p>
  <?php if ($jf = jingle_file()): ?><div class="full"><audio controls src="<?= e($jf) ?>?v=<?= (int) @filemtime(public_dir() . $jf) ?>"></audio></div><?php endif; ?>
  <div><label>Sound file (MP3, max 3 MB)</label><input type="file" name="jingle" accept="audio/*"></div>
  <label class="check full"><input type="checkbox" name="jingle_on" value="1"<?= setting('jingle_on', '0') === '1' ? ' checked' : '' ?>> <span>Play the jingle on the website</span></label>
  <?php if ($jf): ?><label class="check full"><input type="checkbox" name="jingle_remove" value="1"> <span>Remove the jingle</span></label><?php endif; ?>
  <div class="full ad-actions"><button class="btn btn-sm" name="action" value="jingle">Save</button></div>
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
      <thead><tr><th>Username</th><th>Name</th><th>Can see</th><th>Last login</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($staff as $s): ?>
        <tr><td><strong><?= e($s['username']) ?></strong></td><td><?= e($s['name']) ?></td>
          <td><?php if ($s['username'] !== $me): ?><form method="post" class="ad-inline"><?= csrf_field() ?><input type="hidden" name="username" value="<?= e($s['username']) ?>"><input type="hidden" name="action" value="staff_role">
            <select name="role" onchange="this.form.submit()"><?php foreach (STAFF_ROLES as $rk => $rl): ?><option value="<?= $rk ?>"<?= ($s['role'] ?? 'admin') === $rk ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></form>
            <?php else: ?><?= e(STAFF_ROLES[$s['role'] ?? 'admin'] ?? 'Admin') ?><?php endif; ?></td>
          <td><?= e(admin_date($s['last_login'], true)) ?></td>
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
        <div><label>Can see</label><select name="role"><?php foreach (STAFF_ROLES as $rk => $rl): ?><option value="<?= $rk ?>"<?= $rk === 'inspector' ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></div>
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
