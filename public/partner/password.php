<?php
// Partner portal: change your own password.
require __DIR__ . '/../../app/bootstrap.php';
$u = pp_require();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $st = db()->prepare('SELECT password FROM users WHERE id = :id');
    $st->execute(['id' => (int) $u['id']]);
    $hash = (string) $st->fetchColumn();
    $new = (string) ($_POST['new'] ?? '');
    if (!csrf_check()) {
        $err = 'The page was open too long. Please try again.';
    } elseif (!password_verify((string) ($_POST['old'] ?? ''), $hash)) {
        $err = 'Your current password is not right.';
    } elseif (mb_strlen($new) < 8) {
        $err = 'The new password needs at least 8 characters.';
    } elseif ($new !== (string) ($_POST['new2'] ?? '')) {
        $err = 'The two new passwords are not the same.';
    } else {
        db()->prepare('UPDATE users SET password = :p WHERE id = :id')->execute(['p' => password_hash($new, PASSWORD_BCRYPT), 'id' => (int) $u['id']]);
        session_regenerate_id(true);
        pp_flash('Your password is changed. Use it next time you log in.');
        header('Location: /partner/password.php');
        exit;
    }
}
pp_head('Password', $u, 'password');
?>
<div class="pp-box" style="max-width:480px">
  <h2>Change your password</h2>
  <?php if ($err): ?><div class="pp-err" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="pp-form">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label for="o">Current password</label><input type="password" id="o" name="old" autocomplete="current-password" required>
    <label for="n">New password (at least 8 characters)</label><input type="password" id="n" name="new" autocomplete="new-password" minlength="8" required>
    <label for="n2">New password again</label><input type="password" id="n2" name="new2" autocomplete="new-password" minlength="8" required>
    <button class="btn" style="margin-top:16px">Save new password</button>
  </form>
</div>
<?php pp_foot();
