<?php
// Partner login (same username and password as the old partner dashboard).
require __DIR__ . '/../app/bootstrap.php';

if (pp_user()) {
    header('Location: /partner/');
    exit;
}
$err = '';
$name = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 80);
    if (!csrf_check()) {
        $err = 'The page was open too long. Please try again.';
    } else {
        $r = pp_login($name, (string) ($_POST['password'] ?? ''));
        if (is_int($r)) {
            header('Location: /partner/');
            exit;
        }
        $err = $r;
    }
}
pp_head('Log in', null);
?>
<div class="pp-box" style="max-width:440px;margin:40px auto">
  <h1 style="font-size:1.6rem;margin:0 0 4px">Partner login</h1>
  <p class="pp-muted" style="margin:0 0 14px">For businesses that work with Dj'aki mi ta!. Use the username and password you got from the team.</p>
  <?php if ($err): ?><div class="pp-err" role="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="pp-form" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <label for="u">Username</label>
    <input type="text" id="u" name="username" value="<?= e($name) ?>" autocomplete="username" autocapitalize="none" required>
    <label for="p">Password</label>
    <input type="password" id="p" name="password" autocomplete="current-password" required>
    <button class="btn btn-lg" style="margin-top:16px;width:100%">Log in</button>
  </form>
  <p class="pp-muted" style="margin:16px 0 0;font-size:.9rem">Forgot your password? Ask the Dj'aki mi ta! team at contact@djakimita.com.</p>
</div>
<?php pp_foot();
