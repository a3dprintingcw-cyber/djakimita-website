<?php
require __DIR__ . '/../app/bootstrap.php';

$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120);
    if (!csrf_check()) {
        $error = t('co.err.session');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = t('co.err.email');
    } elseif (login_blocked('reset:' . $email)) {
        $sent = true; // same answer, but no more emails for a while
    } else {
        login_failed('reset:' . $email); // counts towards the limit, so nobody can flood an inbox
        $st = db()->prepare('SELECT id, email FROM clients WHERE LOWER(email) = LOWER(:e) AND active = 1 LIMIT 1');
        $st->execute(['e' => $email]);
        if ($row = $st->fetch()) {
            send_reset_email((int) $row['id'], (string) $row['email']);
        }
        $sent = true; // same answer whether the account exists or not
    }
}

page_header(t('fp.title') . " · Dj'aki mi ta!");
?>
<section class="section pt-sm">
  <div class="wrap auth-wrap">
    <div class="box auth-box">
      <h1 class="co-h1"><?= e(t('fp.title')) ?></h1>
      <?php if ($sent): ?>
        <div class="ok-box" role="status"><?= icon('mail', 20) ?> <?= e(t('fp.sent')) ?></div>
        <p class="auth-links"><a href="/login.php"><?= icon('back', 16) ?> <?= e(t('li.title')) ?></a></p>
      <?php else: ?>
        <p class="muted"><?= e(t('fp.lead')) ?></p>
        <?php if ($error): ?><p class="alert" role="alert"><?= e($error) ?></p><?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <div class="field"><label for="email"><?= e(t('li.email')) ?></label><input id="email" name="email" type="email" inputmode="email" autocomplete="email" required></div>
          <button class="btn btn-lg btn-block" type="submit"><?= e(t('fp.btn')) ?></button>
        </form>
        <p class="auth-links"><a href="/login.php"><?= icon('back', 16) ?> <?= e(t('li.title')) ?></a></p>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php page_footer();
