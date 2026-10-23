<?php
require __DIR__ . '/../app/bootstrap.php';

$next = safe_next((string) ($_GET['next'] ?? $_POST['next'] ?? '/account.php'));
if (current_client()) {
    header('Location: ' . $next);
    exit;
}
$email = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120);
    $pass = (string) ($_POST['pass'] ?? '');
    if (!csrf_check()) {
        $error = t('co.err.session');
    } elseif (login_blocked($email)) {
        $error = t('li.blocked');
    } elseif (client_login($email, $pass)) {
        header('Location: ' . $next);
        exit;
    } else {
        $error = t('li.err');
    }
}

page_header(t('li.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap auth-wrap">
    <div class="box auth-box">
      <h1 class="co-h1"><?= e(t('li.title')) ?></h1>
      <p class="muted"><?= e(t('li.lead')) ?></p>
      <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field"><label for="email"><?= e(t('li.email')) ?></label><input id="email" name="email" type="email" inputmode="email" autocomplete="username" required value="<?= e($email) ?>"></div>
        <div class="field"><label for="pass"><?= e(t('li.pass')) ?></label><input id="pass" name="pass" type="password" autocomplete="current-password" required></div>
        <button class="btn btn-lg btn-block" type="submit"><?= e(t('li.btn')) ?></button>
      </form>
      <p class="auth-links"><a href="/forgot-password.php"><?= e(t('li.forgot')) ?></a></p>
      <p class="auth-alt"><?= e(t('li.new')) ?> <a href="/#cards"><?= e(t('li.new.cta')) ?></a></p>
    </div>
  </div>
</section>
<?php page_footer();
