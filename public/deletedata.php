<?php
// Account deletion. The app links here (App Store / Play Store requirement), so the address stays the same.
require __DIR__ . '/../app/bootstrap.php';

$done = !empty($_SESSION['account_deleted']);
unset($_SESSION['account_deleted']);
$me = $done ? null : current_client();
$error = '';
if ($me && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $st = db()->prepare('SELECT password FROM clients WHERE id = :id');
    $st->execute(['id' => $me['id']]);
    if (!csrf_check()) {
        $error = t('co.err.session');
    } elseif (!password_verify((string) ($_POST['pass'] ?? ''), (string) $st->fetchColumn())) {
        login_failed((string) $me['email']);
        $error = t('del.err');
    } else {
        delete_client_account((int) $me['id']);
        client_logout();
        $_SESSION['account_deleted'] = 1;
        header('Location: /deletedata.php');
        exit;
    }
}

page_header(t('del.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap auth-wrap">
    <div class="box auth-box">
      <h1 class="co-h1"><?= e(t('del.title')) ?></h1>
      <?php if ($done): ?>
        <div class="ok-box" role="status"><?= icon('check', 20) ?> <?= e(t('del.done')) ?></div>
      <?php elseif (!$me): ?>
        <p><?= e(t('del.lead')) ?></p>
        <p class="notice"><?= e(t('del.login')) ?></p>
        <a class="btn btn-lg btn-block" href="/login.php?<?= e(http_build_query(['next' => '/deletedata.php'])) ?>"><?= e(t('li.title')) ?></a>
      <?php else: ?>
        <p><?= e(t('del.lead')) ?></p>
        <p class="muted"><?= e($me['email']) ?></p>
        <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="field"><label for="pass"><?= e(t('del.confirm')) ?></label><input id="pass" name="pass" type="password" autocomplete="current-password" required></div>
          <button class="btn btn-lg btn-block btn-danger" type="submit"><?= e(t('del.btn')) ?></button>
        </form>
        <p class="auth-links"><a href="/account.php"><?= icon('back', 16) ?> <?= e(t('ac.title')) ?></a></p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php page_footer();
